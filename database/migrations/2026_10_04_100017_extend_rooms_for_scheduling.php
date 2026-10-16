<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('quiz_paper_id')->constrained()->restrictOnDelete();
            // scheduled: start at scheduled_start_at; fill: start when full;
            // hybrid: start when full or at scheduled_start_at, whichever comes first.
            $table->enum('type', ['scheduled', 'fill', 'hybrid'])->change();
            $table->enum('status', ['draft', 'scheduled', 'open', 'full', 'starting', 'in_progress', 'completed', 'cancelled', 'refunded'])
                ->default('draft')->change();
            $table->enum('tie_break_policy', ['fastest_correct', 'shared'])->default('fastest_correct')->after('question_gap_seconds');
            // Applied when the start time or registration deadline arrives before the room is full.
            $table->enum('under_capacity_policy', ['cancel', 'start_if_minimum'])->default('start_if_minimum')->after('tie_break_policy');
            $table->boolean('allow_withdrawal')->default(true)->after('under_capacity_policy');
            // Server-decided start moment once the room is triggered (status "starting").
            $table->dateTime('starts_at', 3)->nullable()->after('scheduled_start_at');
        });

        Schema::table('room_participants', function (Blueprint $table) {
            $table->enum('status', ['reserved', 'joined', 'withdrawn', 'expired', 'playing', 'finished', 'disqualified', 'refunded'])
                ->default('joined')->change();
            // A reserved seat (payment pending) is held only until this moment.
            $table->dateTime('reserved_until', 3)->nullable()->after('joined_at');
            $table->dateTime('confirmed_at', 3)->nullable()->after('reserved_until');
            $table->dateTime('expired_at', 3)->nullable()->after('withdrawn_at');
            $table->string('payment_reference', 191)->nullable()->after('refund_ledger_transaction_id');

            $table->index(['status', 'reserved_until']);
        });
    }

    public function down(): void
    {
        DB::table('room_participants')->whereIn('status', ['reserved', 'expired'])->update(['status' => 'withdrawn']);
        DB::table('rooms')->where('status', 'starting')->update(['status' => 'full']);
        DB::table('rooms')->where('type', 'hybrid')->update(['type' => 'scheduled']);

        Schema::table('room_participants', function (Blueprint $table) {
            $table->dropIndex(['status', 'reserved_until']);
            $table->dropColumn(['reserved_until', 'confirmed_at', 'expired_at', 'payment_reference']);
            $table->enum('status', ['joined', 'withdrawn', 'playing', 'finished', 'disqualified', 'refunded'])->default('joined')->change();
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn(['tie_break_policy', 'under_capacity_policy', 'allow_withdrawal', 'starts_at']);
            $table->enum('type', ['scheduled', 'fill'])->change();
            $table->enum('status', ['draft', 'scheduled', 'open', 'full', 'in_progress', 'completed', 'cancelled', 'refunded'])->default('draft')->change();
        });
    }
};
