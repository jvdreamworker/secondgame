<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entries', function (Blueprint $table) {
            // Normal auto-increment PK — entries are always looked up by the
            // unique (player_id, week_number) pair, never by their own id, so
            // there is no offline-id problem here.
            $table->id();
            $table->uuid('player_id');
            $table->unsignedInteger('week_number');
            $table->decimal('amount', 8, 2)->nullable();
            $table->enum('status', ['paid', 'covered', 'exempt']);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->foreign('player_id')->references('id')->on('players')->cascadeOnDelete();
            $table->unique(['player_id', 'week_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entries');
    }
};
