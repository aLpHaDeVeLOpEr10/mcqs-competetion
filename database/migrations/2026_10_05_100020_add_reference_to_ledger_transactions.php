<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A public, non-guessable reference (ULID) for every ledger transaction,
     * shown to players and support instead of internal ids or idempotency keys.
     */
    public function up(): void
    {
        // Ledger rows are append-only (triggers block UPDATE), so existing rows could not be
        // backfilled. The ledger has been empty in every environment until this phase.
        if (DB::table('ledger_transactions')->exists()) {
            throw new RuntimeException('ledger_transactions already has rows; add the reference column as nullable instead.');
        }

        Schema::table('ledger_transactions', function (Blueprint $table) {
            $table->char('reference', 26)->after('id')->unique();
        });
    }

    public function down(): void
    {
        Schema::table('ledger_transactions', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->dropColumn('reference');
        });
    }
};
