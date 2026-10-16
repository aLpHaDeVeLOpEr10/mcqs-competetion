<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('answer_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_question_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_participant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('session_question_option_id');
            // Server receive time; the client's clock is never used.
            $table->dateTime('received_at', 3);
            $table->unsignedInteger('response_time_ms');
            $table->boolean('is_correct');
            $table->unsignedSmallInteger('points_awarded')->default(0);
            $table->datetimes();

            // One answer per participant per question.
            $table->unique(['session_question_id', 'room_participant_id'], 'answer_submissions_one_per_question_unique');
            $table->index('room_participant_id');
            // The selected option must belong to the answered question.
            $table->foreign(['session_question_id', 'session_question_option_id'], 'answer_submissions_option_belongs_to_question')
                ->references(['session_question_id', 'id'])->on('session_question_options')
                ->restrictOnDelete();
        });

        Schema::create('participant_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_participant_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('score')->default(0);
            $table->unsignedSmallInteger('correct_count')->default(0);
            $table->unsignedSmallInteger('answered_count')->default(0);
            // Sum of response times of correct answers; first tie-breaker.
            $table->unsignedBigInteger('correct_response_time_ms')->default(0);
            // Ties share a rank, so rank is not unique.
            $table->unsignedInteger('rank')->nullable();
            $table->dateTime('computed_at', 3);
            $table->datetimes();

            $table->index(['competition_session_id', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participant_results');
        Schema::dropIfExists('answer_submissions');
    }
};
