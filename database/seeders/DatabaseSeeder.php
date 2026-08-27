<?php

namespace Database\Seeders;

use App\Models\Season;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds the single operator user and the 2026 season. The player roster
     * is NOT seeded here — the operator imports it from the frontend's
     * "Import last season's roster" button (which generates client UUIDs).
     */
    public function run(): void
    {
        $email = env('OPERATOR_EMAIL', 'jvercelletto@gmail.com');
        $password = env('OPERATOR_PASSWORD') ?: Str::password(16, symbols: false);

        $user = User::firstOrNew(['email' => $email]);
        if (! $user->exists) {
            $user->fill([
                'name' => 'Pool Operator',
                'password' => Hash::make($password),
            ])->save();

            $this->command->warn('──────────────────────────────────────────────');
            $this->command->warn("Operator user created:  {$email}");
            $this->command->warn("Temporary password:     {$password}");
            $this->command->warn('Sign in, then change it at /password');
            $this->command->warn('──────────────────────────────────────────────');
        } else {
            $this->command->info("Operator user {$email} already exists — left untouched.");
        }

        Season::firstOrCreate(
            ['label' => env('SEASON_LABEL', '2026 Second Game Pool')],
            [
                'entry_fee' => 1,
                'start_week' => 1,
                'total_weeks' => 33,
            ],
        );
    }
}
