<?php

namespace Tests\Feature\Content;

use App\Enums\QuizPaperStatus;
use App\Enums\UserRole;
use App\Exceptions\AdministrativeActionException;
use App\Exceptions\ContentValidationException;
use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\QuizPaper;
use App\Models\Room;
use App\Models\SessionQuestion;
use App\Models\SessionQuestionOption;
use App\Models\User;
use App\Services\Content\PaperSnapshotService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SnapshotIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function publishedRoom(int $questions = 5): Room
    {
        $paper = QuizPaper::factory()->withQuestions($questions)->create(['default_time_limit_seconds' => 20]);
        $paper->forceFill(['status' => QuizPaperStatus::Published])->save();

        return Room::factory()->create(['quiz_paper_id' => $paper->id]);
    }

    protected function snapshot(Room $room)
    {
        return app(PaperSnapshotService::class)->snapshot($room);
    }

    public function test_snapshot_copies_text_order_timings_points_options_and_answer_key(): void
    {
        $room = $this->publishedRoom();
        $paper = $room->quizPaper;
        $second = $paper->items()->where('position', 2)->sole();
        $second->update(['time_limit_seconds' => 45, 'points' => 2]);
        $paper->items()->where('position', 3)->sole()->question->update(['default_time_limit_seconds' => 30]);

        $session = $this->snapshot($room);

        $snapshot = $session->questions()->with('options')->get();
        $this->assertCount(5, $snapshot);
        $this->assertSame([1, 2, 3, 4, 5], $snapshot->pluck('position')->all());
        $this->assertSame([20, 45, 30, 20, 20], $snapshot->pluck('time_limit_seconds')->all());
        $this->assertSame(2, $snapshot[1]->points);

        foreach ($paper->items()->with('question.options')->orderBy('position')->get() as $index => $item) {
            $copy = $snapshot[$index];
            $this->assertSame($item->question->body, $copy->body);
            $this->assertSame($item->question_id, $copy->source_question_id);
            $this->assertSame($item->question->options->pluck('body')->all(), $copy->options->pluck('body')->all());
            $this->assertSame(
                $item->question->options->firstWhere('is_correct', true)->position,
                $copy->options->firstWhere('is_correct', true)->position,
            );
        }

        $this->assertSame(1, AuditLog::where('action', 'room.snapshot_created')->count());
    }

    public function test_later_bank_and_paper_edits_never_change_the_snapshot(): void
    {
        $room = $this->publishedRoom();
        $session = $this->snapshot($room);
        $before = $session->questions()->with('options')->get()->map(fn ($q) => [$q->body, $q->time_limit_seconds, $q->options->pluck('body')->all(), $q->options->firstWhere('is_correct', true)->position])->all();

        $editor = User::factory()->role(UserRole::ContentManager)->create();
        $question = $room->quizPaper->items()->where('position', 1)->sole()->question;
        $this->actingAs($editor)->put(route('admin.questions.update', $question), [
            'category_id' => $question->category_id, 'body' => 'Completely rewritten?', 'difficulty' => 'hard', 'status' => 'active',
            'options' => ['New A', 'New B', 'New C', 'New D'], 'correct' => 3, 'default_time_limit_seconds' => 90,
        ])->assertSessionHasNoErrors();
        $question->delete(); // soft delete too

        $after = $session->questions()->with('options')->get()->map(fn ($q) => [$q->body, $q->time_limit_seconds, $q->options->pluck('body')->all(), $q->options->firstWhere('is_correct', true)->position])->all();
        $this->assertSame($before, $after);
    }

    public function test_snapshotting_is_idempotent(): void
    {
        $room = $this->publishedRoom();

        $first = $this->snapshot($room);
        $second = $this->snapshot($room);

        $this->assertTrue($first->is($second));
        $this->assertSame(5, SessionQuestion::count());
        $this->assertSame(1, AuditLog::where('action', 'room.snapshot_created')->count());
    }

    public function test_only_published_valid_papers_can_be_snapshotted(): void
    {
        $draft = Room::factory()->create(['quiz_paper_id' => QuizPaper::factory()->withQuestions(5)->create()->id]);

        try {
            $this->snapshot($draft);
            $this->fail('Draft paper was snapshotted.');
        } catch (AdministrativeActionException $e) {
            $this->assertStringContainsString('published', $e->getMessage());
        }

        $room = $this->publishedRoom(5);
        $room->quizPaper->items()->first()->question->options()->update(['is_correct' => false]);

        $this->expectException(ContentValidationException::class);
        $this->snapshot($room);
    }

    public function test_database_rejects_changes_to_snapshot_content_and_answer_keys(): void
    {
        $session = $this->snapshot($this->publishedRoom());
        $question = $session->questions()->first();
        $option = $question->options()->first();

        foreach ([
            fn () => DB::table('session_questions')->where('id', $question->id)->update(['body' => 'tampered']),
            fn () => DB::table('session_questions')->where('id', $question->id)->update(['time_limit_seconds' => 600]),
            fn () => DB::table('session_questions')->where('id', $question->id)->delete(),
            fn () => DB::table('session_question_options')->where('id', $option->id)->update(['is_correct' => ! $option->is_correct]),
            fn () => DB::table('session_question_options')->where('id', $option->id)->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A snapshot change was not rejected by the database.');
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/immutable|append-only/', $e->getMessage());
            }
        }
    }

    public function test_models_refuse_content_changes_but_allow_engine_timing(): void
    {
        $session = $this->snapshot($this->publishedRoom());
        $question = $session->questions()->first();

        $question->forceFill(['opens_at' => now(), 'closes_at' => now()->addSeconds(20)])->save();
        $this->assertNotNull($question->fresh()->opens_at);

        try {
            $question->forceFill(['body' => 'tampered'])->save();
            $this->fail('Model allowed a snapshot content change.');
        } catch (ImmutableRecordException) {
        }

        $option = SessionQuestionOption::first();
        $this->expectException(ImmutableRecordException::class);
        $option->forceFill(['is_correct' => ! $option->is_correct])->save();
    }

    public function test_answer_keys_and_image_paths_are_not_serialized(): void
    {
        $session = $this->snapshot($this->publishedRoom());

        $json = $session->questions()->with('options')->get()->toJson();

        $this->assertStringNotContainsString('is_correct', $json);
        $this->assertStringNotContainsString('correct_marker', $json);
        $this->assertStringNotContainsString('image_path', $json);
    }
}
