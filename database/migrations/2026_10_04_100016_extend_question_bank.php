<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('slug', 60)->unique();
            $table->datetimes();
        });

        Schema::create('question_tag', function (Blueprint $table) {
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['question_id', 'tag_id']);
            $table->index('tag_id');
        });

        Schema::table('questions', function (Blueprint $table) {
            // Relative path on the private "local" disk; never a user-supplied path.
            $table->string('image_path', 255)->nullable()->after('explanation');
            $table->string('image_alt', 255)->nullable()->after('image_path');
            // sha256 of the normalized question text, for duplicate detection.
            $table->char('body_hash', 64)->nullable()->after('body');
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->restrictOnDelete();
            $table->index('body_hash');
        });

        Schema::table('quiz_papers', function (Blueprint $table) {
            // When set, a paper can only be published with exactly this many questions.
            $table->unsignedSmallInteger('target_question_count')->nullable()->after('default_time_limit_seconds');
            $table->dateTime('published_at')->nullable()->after('status');
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->restrictOnDelete();
        });
        SchemaConstraints::check('quiz_papers', 'quiz_papers_target_count_range',
            'target_question_count IS NULL OR target_question_count BETWEEN 1 AND 200');

        Schema::table('session_questions', function (Blueprint $table) {
            $table->string('image_path', 255)->nullable()->after('explanation');
        });

        // Backfill hashes with the same normalization as Question::hashBody().
        DB::table('questions')->select(['id', 'body'])->orderBy('id')->each(function ($row) {
            DB::table('questions')->where('id', $row->id)->update([
                'body_hash' => hash('sha256', mb_strtolower(trim(preg_replace('/\s+/u', ' ', $row->body)))),
            ]);
        });

        $this->createSnapshotTriggers();
    }

    public function down(): void
    {
        $this->dropSnapshotTriggers();

        Schema::table('session_questions', fn (Blueprint $table) => $table->dropColumn('image_path'));

        Schema::table('quiz_papers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['target_question_count', 'published_at']);
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->dropIndex(['body_hash']);
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['image_path', 'image_alt', 'body_hash']);
        });

        Schema::dropIfExists('question_tag');
        Schema::dropIfExists('tags');
    }

    /**
     * Competition snapshots are immutable. Options (including the answer key)
     * can never change; for questions only the engine's timing columns may.
     */
    protected function createSnapshotTriggers(): void
    {
        if (! SchemaConstraints::supported()) {
            return;
        }

        SchemaConstraints::appendOnly('session_question_options');

        DB::unprepared('DROP TRIGGER IF EXISTS `session_questions_content_immutable`');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `session_questions_content_immutable` BEFORE UPDATE ON `session_questions` FOR EACH ROW
            BEGIN
                IF NOT (NEW.competition_session_id <=> OLD.competition_session_id)
                    OR NOT (NEW.source_question_id <=> OLD.source_question_id)
                    OR NOT (NEW.position <=> OLD.position)
                    OR NOT (NEW.body <=> OLD.body)
                    OR NOT (NEW.explanation <=> OLD.explanation)
                    OR NOT (NEW.image_path <=> OLD.image_path)
                    OR NOT (NEW.time_limit_seconds <=> OLD.time_limit_seconds)
                    OR NOT (NEW.points <=> OLD.points) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'session_questions content is immutable';
                END IF;
            END
            SQL);

        DB::unprepared('DROP TRIGGER IF EXISTS `session_questions_no_delete`');
        DB::unprepared("CREATE TRIGGER `session_questions_no_delete` BEFORE DELETE ON `session_questions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'session_questions is append-only; delete is not allowed'");
    }

    protected function dropSnapshotTriggers(): void
    {
        if (! SchemaConstraints::supported()) {
            return;
        }

        SchemaConstraints::dropAppendOnly('session_question_options');
        DB::unprepared('DROP TRIGGER IF EXISTS `session_questions_content_immutable`');
        DB::unprepared('DROP TRIGGER IF EXISTS `session_questions_no_delete`');
    }
};
