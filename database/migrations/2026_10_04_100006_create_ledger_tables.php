<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 191)->unique();
            $table->enum('type', ['deposit', 'withdrawal', 'entry_fee', 'entry_refund', 'prize_payout', 'adjustment', 'reversal']);
            $table->char('currency', 3)->default('PKR');
            $table->string('description', 255)->nullable();
            $table->nullableMorphs('reference');
            // A transaction can be reversed at most once.
            $table->foreignId('reverses_transaction_id')->nullable()->unique()->constrained('ledger_transactions')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('metadata')->nullable();
            $table->dateTime('created_at', 3);

            $table->index(['type', 'created_at']);
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_transaction_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            // Signed: credit > 0, debit < 0. Entries of one transaction sum to zero.
            $table->bigInteger('amount_minor');
            $table->bigInteger('balance_after_minor');
            $table->dateTime('created_at', 3);

            $table->index(['wallet_id', 'id']);
            $table->unique(['ledger_transaction_id', 'wallet_id']);
        });
        SchemaConstraints::check('ledger_entries', 'ledger_entries_amount_non_zero', 'amount_minor <> 0');

        SchemaConstraints::appendOnly('ledger_transactions');
        SchemaConstraints::appendOnly('ledger_entries');
    }

    public function down(): void
    {
        SchemaConstraints::dropAppendOnly('ledger_entries');
        SchemaConstraints::dropAppendOnly('ledger_transactions');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_transactions');
    }
};
