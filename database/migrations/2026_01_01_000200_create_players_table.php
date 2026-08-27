<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            // UUID primary key — generated client-side (crypto.randomUUID) so a
            // payment recorded offline can reference the player before any sync.
            $table->uuid('id')->primary();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('team')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['season_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
