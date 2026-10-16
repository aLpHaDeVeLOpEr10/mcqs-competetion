<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_papers', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->unsignedSmallInteger('default_time_limit_seconds')->default(20);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->datetimes();
            $table->softDeletesDatetime();

            $table->index('status');
        });
        SchemaConstraints::check('quiz_papers', 'quiz_papers_time_limit_range',
            'default_time_limit_seconds BETWEEN 5 AND 600');

        Schema::create('quiz_paper_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_paper_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            // NULL = inherit the question default, then the paper default.
            $table->unsignedSmallInteger('time_limit_seconds')->nullable();
            $table->unsignedSmallInteger('points')->default(1);
            $table->datetimes();

            $table->unique(['quiz_paper_id', 'position']);
            $table->unique(['quiz_paper_id', 'question_id']);
        });
        SchemaConstraints::check('quiz_paper_questions', 'quiz_paper_questions_time_limit_range',
            'time_limit_seconds IS NULL OR time_limit_seconds BETWEEN 5 AND 600');
        SchemaConstraints::check('quiz_paper_questions', 'quiz_paper_questions_position_positive', 'position >= 1');
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_paper_questions');
        Schema::dropIfExists('quiz_papers');
    }
};
