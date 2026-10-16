<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['user', 'system']);
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            // Identifies platform wallets, e.g. prize_pool, platform_revenue, gateway_clearing.
            $table->string('system_code', 50)->nullable();
            $table->char('currency', 3)->default('PKR');
            // Cached sum of ledger_entries; written only by the ledger service under row lock.
            $table->bigInteger('balance_minor')->default(0);
            $table->enum('status', ['active', 'frozen'])->default('active');
            $table->datetimes();

            $table->unique(['user_id', 'currency']);
            $table->unique(['system_code', 'currency']);
        });
        SchemaConstraints::check('wallets', 'wallets_owner_matches_type',
            "(type = 'user' AND user_id IS NOT NULL AND system_code IS NULL) OR (type = 'system' AND user_id IS NULL AND system_code IS NOT NULL)");
        // System wallets (e.g. gateway clearing) may go negative; user wallets never.
        SchemaConstraints::check('wallets', 'wallets_user_balance_non_negative',
            "type = 'system' OR balance_minor >= 0");
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
