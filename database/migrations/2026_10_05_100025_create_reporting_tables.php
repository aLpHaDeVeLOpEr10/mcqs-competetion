<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Correlation id: every audit record of one request (or one console command / job
        // chain) shares it, and the same id is in the application log and the X-Request-Id header.
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->char('request_id', 36)->nullable()->after('user_agent');
            $table->index('request_id');
            // (auditable_type, auditable_id) is already indexed by nullableMorphs().
        });

        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('report', 40);
            $table->json('filters');
            $table->enum('status', ['queued', 'running', 'done', 'failed'])->default('queued');
            $table->string('path', 191)->nullable();
            $table->unsignedInteger('rows')->default(0);
            $table->string('error', 255)->nullable();
            $table->dateTime('finished_at', 3)->nullable();
            $table->datetimes();

            $table->index(['user_id', 'created_at']);
        });

        // Date-range reporting indexes.
        Schema::table('users', fn (Blueprint $t) => $t->index('created_at'));
        Schema::table('rooms', fn (Blueprint $t) => $t->index('created_at'));
        Schema::table('room_participants', fn (Blueprint $t) => $t->index('confirmed_at'));
        Schema::table('competition_sessions', fn (Blueprint $t) => $t->index('scored_at'));
        Schema::table('payment_transactions', fn (Blueprint $t) => $t->index(['type', 'status', 'completed_at']));
        Schema::table('withdrawals', fn (Blueprint $t) => $t->index('paid_at'));
    }

    public function down(): void
    {
        Schema::table('withdrawals', fn (Blueprint $t) => $t->dropIndex(['paid_at']));
        Schema::table('payment_transactions', fn (Blueprint $t) => $t->dropIndex(['type', 'status', 'completed_at']));
        Schema::table('competition_sessions', fn (Blueprint $t) => $t->dropIndex(['scored_at']));
        Schema::table('room_participants', fn (Blueprint $t) => $t->dropIndex(['confirmed_at']));
        Schema::table('rooms', fn (Blueprint $t) => $t->dropIndex(['created_at']));
        Schema::table('users', fn (Blueprint $t) => $t->dropIndex(['created_at']));
        Schema::dropIfExists('report_exports');
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['request_id']);
            $table->dropColumn('request_id');
        });
    }
};
