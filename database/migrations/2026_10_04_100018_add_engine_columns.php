<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competition_sessions', function (Blueprint $table) {
            // Authoritative start moment and the configuration frozen at preparation.
            $table->dateTime('starts_at', 3)->nullable()->after('current_question_position');
            $table->dateTime('prepared_at', 3)->nullable()->after('starts_at');
            $table->json('config')->nullable()->after('abort_reason');
        });

        // Engine bookkeeping columns. They are not part of the immutable snapshot
        // content, so the session_questions content trigger allows updating them.
        Schema::table('session_questions', function (Blueprint $table) {
            $table->dateTime('delivered_at', 3)->nullable()->after('closes_at');
            $table->dateTime('closed_at', 3)->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('session_questions', fn (Blueprint $table) => $table->dropColumn(['delivered_at', 'closed_at']));
        Schema::table('competition_sessions', fn (Blueprint $table) => $table->dropColumn(['starts_at', 'prepared_at', 'config']));
    }
};
