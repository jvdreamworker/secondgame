<?php

namespace App\Services;

use App\Models\Entry;
use App\Models\Player;
use App\Models\Season;
use App\Models\WeeklyResult;
use Illuminate\Support\Collection;

/**
 * PoolCalculator — the pot / carryover / owed math for a season.
 *
 * This is a line-for-line port of computeStats() and its helpers in
 * public/js/pool-app.js. The frontend computes these numbers locally for
 * offline use; this class lets the backend recompute and verify the same
 * numbers from its own copy of the data. If you change one, change both,
 * and keep tests/Unit/PoolCalculatorTest.php green.
 *
 * The rules:
 *  - count(week) = entries that week with status 'paid' or 'covered'
 *    ('exempt' is a record-keeping note only and never inflates a pot).
 *  - pot(week)   = carry(week - 1) + count(week)   ($1 per entry; entry_fee
 *    is season metadata and is deliberately NOT part of pot math).
 *  - payout(week) = winner recorded ? (result.payout ?? pot) : 0
 *  - carry(week) = pot(week) - payout(week)   (no winner => carry rolls forward)
 */
class PoolCalculator
{
    /** @var list<int> */
    private array $weeks;

    /** @var array<int, int> week_number => count of paid/covered entries */
    private array $counts;

    /** @var Collection<int, WeeklyResult> keyed by week_number */
    private Collection $results;

    public function __construct(private readonly Season $season)
    {
        $this->weeks = $this->buildWeeks();
        $this->counts = $this->loadCounts();
        $this->results = $this->season->weeklyResults()->get()->keyBy('week_number');
    }

    /** @return list<int> */
    public function weeks(): array
    {
        return $this->weeks;
    }

    /**
     * Per-week stats, keyed by week number.
     *
     * @return array<int, array{
     *   week:int, count:int, pot:float, payout:float, carry:float,
     *   winner_player_id:?string, score:string, note:string
     * }>
     */
    public function stats(): array
    {
        $stats = [];
        $prevCarry = 0.0;

        foreach ($this->weeks as $w) {
            $count = $this->counts[$w] ?? 0;
            $pot = $prevCarry + $count;

            $r = $this->results->get($w);
            $payout = ($r && $r->winner_player_id)
                ? (is_null($r->payout) ? $pot : (float) $r->payout)
                : 0.0;
            $carry = $pot - $payout;

            $stats[$w] = [
                'week' => $w,
                'count' => $count,
                'pot' => $pot,
                'payout' => $payout,
                'carry' => $carry,
                'winner_player_id' => $r->winner_player_id ?? null,
                'score' => $r->score ?? '',
                'note' => $r->note ?? '',
            ];

            $prevCarry = $carry;
        }

        return $stats;
    }

    /**
     * Latest week strictly before $week that has a recorded winner, or null.
     */
    public function lastWinnerWeekBefore(int $week): ?int
    {
        $stats = $this->stats();
        $last = null;
        foreach ($this->weeks as $w) {
            if ($w >= $week) {
                break;
            }
            if (! empty($stats[$w]['winner_player_id'])) {
                $last = $w;
            }
        }

        return $last;
    }

    /**
     * Weeks a player owes $1 for: every week after the last winner (or the
     * week before the season starts) up to $uptoWeek that has no entry of
     * any kind on record for that player.
     *
     * @return list<int>
     */
    public function weeksOwed(Player $player, int $uptoWeek, ?int $lastWinnerWeek = null): array
    {
        $lastWinnerWeek ??= $this->lastWinnerWeekBefore($uptoWeek);
        $floor = $lastWinnerWeek ?: ($this->weeks[0] - 1);

        $onRecord = $player->entries()->pluck('week_number')->flip();

        return array_values(array_filter(
            $this->weeks,
            fn (int $w) => $w > $floor && $w <= $uptoWeek && ! $onRecord->has($w)
        ));
    }

    /** @return list<int> */
    private function buildWeeks(): array
    {
        $out = [];
        for ($i = 0; $i < $this->season->total_weeks; $i++) {
            $out[] = $this->season->start_week + $i;
        }

        return $out;
    }

    /** @return array<int, int> */
    private function loadCounts(): array
    {
        $rows = Entry::query()
            ->whereIn('status', ['paid', 'covered'])
            ->whereHas('player', fn ($q) => $q->where('season_id', $this->season->id))
            ->selectRaw('week_number, COUNT(*) as aggregate')
            ->groupBy('week_number')
            ->pluck('aggregate', 'week_number');

        $counts = [];
        foreach ($this->weeks as $w) {
            $counts[$w] = (int) ($rows[$w] ?? 0);
        }

        return $counts;
    }
}
