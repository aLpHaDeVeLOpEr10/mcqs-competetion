<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['deposit', 'withdrawal']);
            $table->enum('status', ['pending', 'processing', 'succeeded', 'failed', 'cancelled', 'expired'])->default('pending');
            // Gateway driver name from config('competition.payments.gateway').
            $table->string('gateway', 32);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('PKR');
            $table->string('idempotency_key', 191)->unique();
            $table->string('gateway_reference', 191)->nullable();
            // Set once, when the payment settles into the ledger.
            $table->foreignId('ledger_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message', 255)->nullable();
            // Sanitized gateway response; never store card data or secrets.
            $table->json('gateway_payload')->nullable();
            $table->dateTime('completed_at', 3)->nullable();
            $table->datetimes();

            $table->unique(['gateway', 'gateway_reference']);
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
        SchemaConstraints::check('payment_transactions', 'payment_transactions_amount_positive', 'amount_minor > 0');

        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_transaction_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['pending', 'processing', 'succeeded', 'failed'])->default('pending');
            $table->unsignedBigInteger('amount_minor');
            $table->string('idempotency_key', 191)->unique();
            $table->string('gateway_reference', 191)->nullable();
            $table->foreignId('ledger_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('reason', 255);
            $table->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('gateway_payload')->nullable();
            $table->dateTime('completed_at', 3)->nullable();
            $table->datetimes();

            $table->index(['payment_transaction_id', 'status']);
        });
        SchemaConstraints::check('payment_refunds', 'payment_refunds_amount_positive', 'amount_minor > 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('payment_transactions');
    }
};
