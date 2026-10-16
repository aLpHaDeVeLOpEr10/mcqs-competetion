<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_sessions', function (Blueprint $table) {
            $table->id();
            // A room runs at most once; a failed run is aborted and the room cancelled/refunded.
            $table->foreignId('room_id')->unique()->constrained()->restrictOnDelete();
            $table->enum('status', ['pending', 'countdown', 'in_progress', 'scoring', 'completed', 'aborted'])->default('pending');
            $table->unsignedSmallInteger('current_question_position')->nullable();
            $table->dateTime('countdown_started_at', 3)->nullable();
            $table->dateTime('started_at', 3)->nullable();
            $table->dateTime('ended_at', 3)->nullable();
            $table->dateTime('scored_at', 3)->nullable();
            $table->dateTime('results_published_at', 3)->nullable();
            $table->dateTime('aborted_at', 3)->nullable();
            $table->string('abort_reason', 255)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->datetimes();

            $table->index('status');
        });

        // Immutable snapshot of each question at publish time; later bank edits do not affect it.
        Schema::create('session_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_question_id')->nullable()->constrained('questions')->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->text('body');
            $table->text('explanation')->nullable();
            $table->unsignedSmallInteger('time_limit_seconds');
            $table->unsignedSmallInteger('points')->default(1);
            // Server-authoritative window, set when the question is opened.
            $table->dateTime('opens_at', 3)->nullable();
            $table->dateTime('closes_at', 3)->nullable();
            $table->dateTime('revealed_at', 3)->nullable();
            $table->datetimes();

            $table->unique(['competition_session_id', 'position']);
        });
        SchemaConstraints::check('session_questions', 'session_questions_time_limit_range', 'time_limit_seconds BETWEEN 5 AND 600');
        SchemaConstraints::check('session_questions', 'session_questions_window_valid',
            'opens_at IS NULL OR closes_at IS NULL OR closes_at > opens_at');

        Schema::create('session_question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_option_id')->nullable()->constrained('question_options')->nullOnDelete();
            $table->text('body');
            $table->boolean('is_correct')->default(false);
            $table->unsignedTinyInteger('correct_marker')->nullable()->storedAs('IF(`is_correct`, 1, NULL)');
            $table->unsignedTinyInteger('position');
            $table->datetimes();

            $table->unique(['session_question_id', 'position'], 'sq_options_question_position_unique');
            $table->unique(['session_question_id', 'correct_marker'], 'sq_options_one_correct_unique');
            // Target of the composite FK on answer_submissions.
            $table->unique(['session_question_id', 'id'], 'sq_options_question_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_question_options');
        Schema::dropIfExists('session_questions');
        Schema::dropIfExists('competition_sessions');
    }
};
