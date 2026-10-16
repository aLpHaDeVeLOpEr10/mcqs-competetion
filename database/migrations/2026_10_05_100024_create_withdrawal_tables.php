<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where a player's withdrawals are sent. Only what a payout needs is stored: the
        // account number is encrypted (APP_KEY), shown masked (last four), and fingerprinted
        // with a keyed hash so the same account cannot be added twice. No passwords/PINs.
        Schema::create('payout_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('method', ['bank_account', 'mobile_wallet']);
            // Bank name or wallet provider as chosen from the approved list.
            $table->string('provider', 40);
            $table->string('account_title', 100);
            $table->text('account_number_encrypted');
            $table->char('account_last4', 4);
            $table->char('fingerprint', 64);
            $table->enum('status', ['active', 'removed'])->default('active');
            // Risk rule: a new destination can be used only after a cooling-off period.
            $table->dateTime('usable_after', 3);
            $table->dateTime('removed_at', 3)->nullable();
            $table->datetimes();

            $table->unique(['user_id', 'fingerprint']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->char('reference', 26)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('payout_destination_id')->constrained()->restrictOnDelete();
            // Masked copy at request time (method, provider, title, last four) for the record.
            $table->json('destination_snapshot');
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('fee_minor')->default(0);
            $table->char('currency', 3)->default('PKR');
            $table->enum('status', ['requested', 'under_review', 'approved', 'processing', 'paid', 'rejected', 'failed', 'cancelled', 'reversed'])
                ->default('requested');
            $table->string('idempotency_key', 191)->unique();
            // The ledger hold (debit at request) and its single release (reversal).
            $table->foreignId('hold_ledger_transaction_id')->nullable()->unique()->constrained('ledger_transactions')->restrictOnDelete();
            $table->foreignId('release_ledger_transaction_id')->nullable()->unique()->constrained('ledger_transactions')->restrictOnDelete();
            $table->string('gateway', 32)->nullable();
            $table->string('provider_reference', 191)->nullable();
            $table->string('provider_status', 64)->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('review_started_at', 3)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at', 3)->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('rejected_at', 3)->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('processing_at', 3)->nullable();
            $table->dateTime('paid_at', 3)->nullable();
            $table->dateTime('failed_at', 3)->nullable();
            $table->dateTime('cancelled_at', 3)->nullable();
            $table->dateTime('reversed_at', 3)->nullable();
            $table->datetimes();

            $table->unique(['gateway', 'provider_reference']);
            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
        SchemaConstraints::check('withdrawals', 'withdrawals_amount_positive', 'amount_minor > 0');

        // Provider notifications for payouts are logged with the deposit callbacks.
        Schema::table('payment_callbacks', function (Blueprint $table) {
            $table->foreignId('withdrawal_id')->nullable()->after('payment_transaction_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_callbacks', fn (Blueprint $table) => $table->dropConstrainedForeignId('withdrawal_id'));
        Schema::dropIfExists('withdrawals');
        Schema::dropIfExists('payout_destinations');
    }
};
