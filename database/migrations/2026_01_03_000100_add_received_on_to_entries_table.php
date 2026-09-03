<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entries', function (Blueprint $table) {
            // When the operator actually received the cash — distinct from
            // which weeks the payment is applied to. A lump-sum payment writes
            // the same date onto every week it covers.
            $table->date('received_on')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('entries', function (Blueprint $table) {
            $table->dropColumn('received_on');
        });
    }
};
