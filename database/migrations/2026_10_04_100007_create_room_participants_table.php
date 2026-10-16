<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['joined', 'withdrawn', 'playing', 'finished', 'disqualified', 'refunded'])->default('joined');

            // Fee frozen at join time, in minor units.
            $table->unsignedBigInteger('entry_fee_minor')->default(0);
            $table->foreignId('entry_ledger_transaction_id')->nullable()->unique()->constrained('ledger_transactions')->restrictOnDelete();
            $table->foreignId('refund_ledger_transaction_id')->nullable()->unique()->constrained('ledger_transactions')->restrictOnDelete();

            $table->dateTime('joined_at', 3);
            $table->dateTime('withdrawn_at', 3)->nullable();
            $table->dateTime('refunded_at', 3)->nullable();
            $table->string('disqualification_reason', 255)->nullable();
            $table->datetimes();

            // One seat per user per room; a rejoin after withdrawal reuses this row.
            $table->unique(['room_id', 'user_id']);
            $table->index(['room_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_participants');
    }
};
