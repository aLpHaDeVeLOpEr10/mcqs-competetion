<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prize_distributions', function (Blueprint $table) {
            $table->id();
            // One distribution per session prevents paying a competition twice.
            $table->foreignId('competition_session_id')->unique()->constrained()->restrictOnDelete();
            $table->enum('status', ['calculated', 'approved', 'paid', 'cancelled'])->default('calculated');
            $table->unsignedBigInteger('total_amount_minor');
            $table->char('currency', 3)->default('PKR');
            // The rule inputs and outputs used to compute payouts, kept for audit.
            $table->json('calculation');
            $table->dateTime('calculated_at', 3);
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at', 3)->nullable();
            $table->datetimes();
        });

        Schema::create('prize_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prize_distribution_id')->constrained()->restrictOnDelete();
            // One payout per participant prevents duplicate prizes.
            $table->foreignId('room_participant_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('rank');
            $table->unsignedBigInteger('amount_minor');
            $table->enum('status', ['pending', 'paid', 'failed', 'cancelled', 'withheld'])->default('pending');
            $table->string('idempotency_key', 191)->unique();
            $table->foreignId('ledger_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('status_reason', 255)->nullable();
            $table->dateTime('paid_at', 3)->nullable();
            $table->datetimes();

            $table->index(['prize_distribution_id', 'status']);
        });
        SchemaConstraints::check('prize_payouts', 'prize_payouts_amount_positive', 'amount_minor > 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('prize_payouts');
        Schema::dropIfExists('prize_distributions');
    }
};
