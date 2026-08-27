<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('week_number');
            // nullable string, not numeric — the operator may enter "187?" while
            // a pulled score is still being confirmed.
            $table->string('score')->nullable();
            $table->uuid('winner_player_id')->nullable();
            $table->decimal('payout', 8, 2)->default(0);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->foreign('winner_player_id')->references('id')->on('players')->nullOnDelete();
            $table->unique(['season_id', 'week_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_results');
    }
};
