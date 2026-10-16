<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->datetimes();
            $table->softDeletesDatetime();

            $table->index(['parent_id', 'sort_order']);
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->text('explanation')->nullable();
            $table->enum('difficulty', ['easy', 'medium', 'hard'])->default('medium');
            $table->enum('status', ['draft', 'active', 'archived'])->default('draft');
            // NULL = inherit the quiz paper default.
            $table->unsignedSmallInteger('default_time_limit_seconds')->nullable();
            $table->datetimes();
            $table->softDeletesDatetime();

            $table->index(['category_id', 'status']);
        });
        SchemaConstraints::check('questions', 'questions_time_limit_range',
            'default_time_limit_seconds IS NULL OR default_time_limit_seconds BETWEEN 5 AND 600');

        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_correct')->default(false);
            // 1 when correct, NULL otherwise, so the unique index below allows at most one correct option.
            $table->unsignedTinyInteger('correct_marker')->nullable()->storedAs('IF(`is_correct`, 1, NULL)');
            $table->unsignedTinyInteger('position');
            $table->datetimes();

            $table->unique(['question_id', 'position']);
            $table->unique(['question_id', 'correct_marker']);
        });
        SchemaConstraints::check('question_options', 'question_options_position_positive', 'position >= 1');
    }

    public function down(): void
    {
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('categories');
    }
};
