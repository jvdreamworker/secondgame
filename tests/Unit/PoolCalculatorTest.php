<?php

namespace Tests\Unit;

use App\Models\Entry;
use App\Models\Player;
use App\Models\Season;
use App\Models\WeeklyResult;
use App\Services\PoolCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * These assertions encode the exact numbers computeStats() in
 * public/js/pool-app.js produces for the same data. If this test and that
 * function ever disagree, one of them has a bug.
 */
class PoolCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private Season $season;
    private Player $a;
    private Player $b;
    private Player $c;

    protected function setUp(): void
    {
        parent::setUp();

        $this->season = Season::create([
            'label' => 'Test',
            'entry_fee' => 1,
            'start_week' => 1,
            'total_weeks' => 5,
        ]);

        $this->a = $this->player('A');
        $this->b = $this->player('B');
        $this->c = $this->player('C');

        // Week 1 — everyone pays. count 3, pot 3, no winner => carry 3.
        $this->entry($this->a, 1, 'paid', 1);
        $this->entry($this->b, 1, 'paid', 1);
        $this->entry($this->c, 1, 'paid', 1);

        // Week 2 — A covered by an earlier lump sum, B pays. count 2,
        // pot 3 + 2 = 5, A wins 5 => carry 0.
        $this->entry($this->a, 2, 'covered', null);
        $this->entry($this->b, 2, 'paid', 1);
        $this->weekResult(2, score: '150', winner: $this->a, payout: 5);

        // Week 3 — C is exempt (out sick, no charge) and must NOT count.
        // B pays. count 1, pot 0 + 1 = 1, no winner => carry 1.
        $this->entry($this->c, 3, 'exempt', null);
        $this->entry($this->b, 3, 'paid', 1);

        // Week 4 — nothing. count 0, pot 1, no winner => carry 1.

        // Week 5 — A pays. count 1, pot 1 + 1 = 2, B wins 2 => carry 0.
        $this->entry($this->a, 5, 'paid', 1);
        $this->weekResult(5, score: '200', winner: $this->b, payout: 2);
    }

    public function test_weeks_span_start_week_to_total_weeks(): void
    {
        $this->assertSame([1, 2, 3, 4, 5], (new PoolCalculator($this->season))->weeks());
    }

    public function test_pot_carryover_and_payout_math(): void
    {
        $stats = (new PoolCalculator($this->season))->stats();

        $this->assertSame([3, 3.0, 0.0, 3.0], $this->row($stats, 1));
        $this->assertSame([2, 5.0, 5.0, 0.0], $this->row($stats, 2));
        $this->assertSame([1, 1.0, 0.0, 1.0], $this->row($stats, 3)); // exempt C excluded
        $this->assertSame([0, 1.0, 0.0, 1.0], $this->row($stats, 4)); // rollover
        $this->assertSame([1, 2.0, 2.0, 0.0], $this->row($stats, 5));
    }

    public function test_exempt_entries_never_inflate_a_pot(): void
    {
        $stats = (new PoolCalculator($this->season))->stats();

        // C's week-3 exempt entry exists but the pot is 1 (only B), not 2.
        $this->assertSame(1, $stats[3]['count']);
        $this->assertSame(1.0, $stats[3]['pot']);
    }

    public function test_last_winner_week_before(): void
    {
        $calc = new PoolCalculator($this->season);

        $this->assertNull($calc->lastWinnerWeekBefore(2));
        $this->assertSame(2, $calc->lastWinnerWeekBefore(3));
        $this->assertSame(2, $calc->lastWinnerWeekBefore(5));
    }

    public function test_weeks_owed_counts_back_to_last_winner(): void
    {
        $calc = new PoolCalculator($this->season);
        $d = $this->player('D'); // brand new, no entries

        // No winner before week 2 => owes from the season start.
        $this->assertSame([1, 2], $calc->weeksOwed($d, 2));

        // Winner at week 2 => only owes weeks after that.
        $this->assertSame([3, 4], $calc->weeksOwed($d, 4));
    }

    public function test_weeks_owed_skips_weeks_already_on_record(): void
    {
        $calc = new PoolCalculator($this->season);
        $d = $this->player('D');
        $this->entry($d, 3, 'exempt', null); // any status counts as "on record"

        $this->assertSame([4], $calc->weeksOwed($d, 4));
    }

    /** @return array{0:int,1:float,2:float,3:float} [count, pot, payout, carry] */
    private function row(array $stats, int $week): array
    {
        return [
            $stats[$week]['count'],
            $stats[$week]['pot'],
            $stats[$week]['payout'],
            $stats[$week]['carry'],
        ];
    }

    private function player(string $name): Player
    {
        return Player::create([
            'season_id' => $this->season->id,
            'name' => $name,
            'team' => 'T',
            'active' => true,
        ]);
    }

    private function entry(Player $p, int $week, string $status, ?float $amount): void
    {
        Entry::create([
            'player_id' => $p->id,
            'week_number' => $week,
            'status' => $status,
            'amount' => $amount,
            'note' => '',
        ]);
    }

    private function weekResult(int $week, string $score, ?Player $winner, float $payout): void
    {
        WeeklyResult::create([
            'season_id' => $this->season->id,
            'week_number' => $week,
            'score' => $score,
            'winner_player_id' => $winner?->id,
            'payout' => $payout,
            'note' => '',
        ]);
    }
}
