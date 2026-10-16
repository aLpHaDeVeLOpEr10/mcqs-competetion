<?php

namespace Tests\Feature\Content;

use App\Enums\QuestionDifficulty;
use App\Enums\QuestionStatus;
use App\Enums\QuizPaperStatus;
use App\Enums\RoomStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Question;
use App\Models\QuizPaper;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaperBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->editor = User::factory()->role(UserRole::ContentManager)->create();
    }

    protected function draftPaper(int $questions = 0, array $attributes = []): QuizPaper
    {
        $factory = QuizPaper::factory();

        return ($questions > 0 ? $factory->withQuestions($questions) : $factory)->create($attributes);
    }

    protected function questionIdsInOrder(QuizPaper $paper): array
    {
        return $paper->items()->orderBy('position')->pluck('question_id')->all();
    }

    public function test_papers_are_created_as_drafts_with_audit(): void
    {
        $this->actingAs($this->editor)->post(route('admin.papers.store'), [
            'title' => 'Weekly Science', 'default_time_limit_seconds' => 30, 'target_question_count' => 10,
        ])->assertRedirect();

        $paper = QuizPaper::sole();
        $this->assertSame(QuizPaperStatus::Draft, $paper->status);
        $this->assertSame(10, $paper->target_question_count);
        $this->assertSame(1, AuditLog::where('action', 'paper.created')->count());
    }

    public function test_settings_validation(): void
    {
        $this->actingAs($this->editor)->post(route('admin.papers.store'), ['title' => '', 'default_time_limit_seconds' => 1, 'target_question_count' => 999])
            ->assertSessionHasErrors(['title', 'default_time_limit_seconds', 'target_question_count']);
    }

    public function test_questions_are_added_in_selection_order_without_archived_or_duplicates(): void
    {
        $paper = $this->draftPaper();
        [$a, $b, $c] = Question::factory()->withOptions()->count(3)->create();
        $archived = Question::factory()->withOptions()->create(['status' => QuestionStatus::Archived]);

        $this->actingAs($this->editor)->post(route('admin.papers.items.store', $paper), ['question_ids' => [$c->id, $a->id, $archived->id]])
            ->assertSessionHas('success', 'Added 2 question(s).');
        $this->actingAs($this->editor)->post(route('admin.papers.items.store', $paper), ['question_ids' => [$a->id, $b->id]]);

        $this->assertSame([$c->id, $a->id, $b->id], $this->questionIdsInOrder($paper));
        $this->assertSame([1, 2, 3], $paper->items()->orderBy('position')->pluck('position')->all());
    }

    public function test_random_questions_match_filters_and_skip_inactive_or_already_added(): void
    {
        $paper = $this->draftPaper();
        $category = Category::factory()->create();
        $easy = Question::factory()->withOptions()->count(12)->create(['category_id' => $category->id, 'difficulty' => QuestionDifficulty::Easy]);
        Question::factory()->withOptions()->count(5)->create(['category_id' => $category->id, 'difficulty' => QuestionDifficulty::Hard]);
        Question::factory()->withOptions()->count(5)->create(['difficulty' => QuestionDifficulty::Easy]);
        $draft = Question::factory()->withOptions()->create(['category_id' => $category->id, 'difficulty' => QuestionDifficulty::Easy, 'status' => QuestionStatus::Draft]);
        $filters = ['category' => $category->id, 'difficulty' => 'easy'];

        $this->actingAs($this->editor)->post(route('admin.papers.items.random', $paper), ['count' => 10] + $filters)
            ->assertSessionHas('success', 'Added 10 random question(s).');
        $this->actingAs($this->editor)->post(route('admin.papers.items.random', $paper), ['count' => 10] + $filters)
            ->assertSessionHas('success', 'Added 2 random question(s). Only 2 matching question(s) were available.');

        $this->assertEqualsCanonicalizing($easy->pluck('id')->all(), $this->questionIdsInOrder($paper));
        $this->assertNotContains($draft->id, $this->questionIdsInOrder($paper));
        $this->assertSame(range(1, 12), $paper->items()->orderBy('position')->pluck('position')->all());

        $this->actingAs($this->editor)->post(route('admin.papers.items.random', $paper), ['count' => 10] + $filters)
            ->assertSessionHas('error');
    }

    public function test_random_count_must_be_one_of_the_offered_sizes(): void
    {
        $paper = $this->draftPaper();

        $this->actingAs($this->editor)->post(route('admin.papers.items.random', $paper), ['count' => 12])
            ->assertSessionHasErrors('count');
        $this->assertSame(0, $paper->items()->count());
    }

    public function test_reordering_and_removal_keep_positions_continuous(): void
    {
        $paper = $this->draftPaper(4);
        $original = $this->questionIdsInOrder($paper);
        $third = $paper->items()->where('position', 3)->sole();

        $this->actingAs($this->editor)->patch(route('admin.papers.items.move', [$paper, $third]), ['direction' => 'up']);
        $this->assertSame([$original[0], $original[2], $original[1], $original[3]], $this->questionIdsInOrder($paper));

        // Moving the first item up is a no-op.
        $first = $paper->items()->where('position', 1)->sole();
        $this->actingAs($this->editor)->patch(route('admin.papers.items.move', [$paper, $first]), ['direction' => 'up']);
        $this->assertSame($original[0], $this->questionIdsInOrder($paper)[0]);

        $this->actingAs($this->editor)->delete(route('admin.papers.items.destroy', [$paper, $paper->items()->where('position', 2)->sole()]));
        $this->assertSame([$original[0], $original[1], $original[3]], $this->questionIdsInOrder($paper));
        $this->assertSame([1, 2, 3], $paper->items()->orderBy('position')->pluck('position')->all());
    }

    public function test_items_of_another_paper_cannot_be_manipulated(): void
    {
        $mine = $this->draftPaper(2);
        $other = $this->draftPaper(2);
        $foreign = $other->items()->first();

        $this->actingAs($this->editor)->delete(route('admin.papers.items.destroy', [$mine, $foreign]))->assertNotFound();
        $this->assertSame(2, $other->items()->count());
    }

    public function test_per_item_time_override_and_points(): void
    {
        $paper = $this->draftPaper(1, ['default_time_limit_seconds' => 20]);
        $item = $paper->items()->sole();

        $this->actingAs($this->editor)->patch(route('admin.papers.items.update', [$paper, $item]), ['time_limit_seconds' => 45, 'points' => 3])->assertSessionHasNoErrors();
        $this->assertSame([45, 3], [$item->fresh()->time_limit_seconds, $item->fresh()->points]);
        $this->assertSame(45, $item->fresh()->effectiveTimeLimitSeconds());

        $this->actingAs($this->editor)->patch(route('admin.papers.items.update', [$paper, $item]), ['time_limit_seconds' => 4, 'points' => 0])
            ->assertSessionHasErrors(['time_limit_seconds', 'points']);
    }

    public function test_publishing_validates_question_count_target_and_question_integrity(): void
    {
        $tooFew = $this->draftPaper(3);
        $this->actingAs($this->editor)->patch(route('admin.papers.publish', $tooFew))->assertSessionHas('error')->assertSessionHas('problems');
        $this->assertSame(QuizPaperStatus::Draft, $tooFew->fresh()->status);

        $targetMismatch = $this->draftPaper(5, ['target_question_count' => 6]);
        $this->actingAs($this->editor)->patch(route('admin.papers.publish', $targetMismatch));
        $this->assertContains('The paper is set to 6 questions but contains 5.', session('problems'));

        $withDraftQuestion = $this->draftPaper(5);
        $withDraftQuestion->questions()->first()->update(['status' => QuestionStatus::Draft]);
        $this->actingAs($this->editor)->patch(route('admin.papers.publish', $withDraftQuestion));
        $this->assertTrue(collect(session('problems'))->contains(fn ($p) => str_contains($p, 'is draft, not active')));

        $noCorrect = $this->draftPaper(5);
        $noCorrect->questions()->first()->options()->update(['is_correct' => false]);
        $this->actingAs($this->editor)->patch(route('admin.papers.publish', $noCorrect));
        $this->assertTrue(collect(session('problems'))->contains(fn ($p) => str_contains($p, 'exactly one correct option')));
    }

    public function test_valid_papers_publish_and_edits_return_them_to_draft(): void
    {
        $paper = $this->draftPaper(5);

        $this->actingAs($this->editor)->patch(route('admin.papers.publish', $paper))->assertSessionHas('success');
        $this->assertSame(QuizPaperStatus::Published, $paper->fresh()->status);
        $this->assertNotNull($paper->fresh()->published_at);

        $this->actingAs($this->editor)->patch(route('admin.papers.items.update', [$paper, $paper->items()->first()]), ['time_limit_seconds' => 30, 'points' => 1]);
        $this->assertSame(QuizPaperStatus::Draft, $paper->fresh()->status);

        // Renaming alone does not unpublish.
        $this->actingAs($this->editor)->patch(route('admin.papers.publish', $paper));
        $this->actingAs($this->editor)->put(route('admin.papers.update', $paper), ['title' => 'Renamed', 'default_time_limit_seconds' => $paper->default_time_limit_seconds]);
        $this->assertSame(['Renamed', QuizPaperStatus::Published], [$paper->fresh()->title, $paper->fresh()->status]);
    }

    public function test_papers_used_by_published_rooms_are_locked(): void
    {
        $paper = $this->draftPaper(5);
        $this->actingAs($this->editor)->patch(route('admin.papers.publish', $paper));
        Room::factory()->status(RoomStatus::Scheduled)->create(['quiz_paper_id' => $paper->id]);
        $before = $this->questionIdsInOrder($paper);
        $extra = Question::factory()->withOptions()->create();

        $this->actingAs($this->editor)->post(route('admin.papers.items.store', $paper), ['question_ids' => [$extra->id]])->assertSessionHas('error');
        $this->actingAs($this->editor)->delete(route('admin.papers.items.destroy', [$paper, $paper->items()->first()]))->assertSessionHas('error');
        $this->actingAs($this->editor)->patch(route('admin.papers.items.move', [$paper, $paper->items()->first()]), ['direction' => 'down'])->assertSessionHas('error');
        $this->actingAs($this->editor)->put(route('admin.papers.update', $paper), ['title' => $paper->title, 'default_time_limit_seconds' => 99])->assertSessionHas('error');
        $this->actingAs($this->editor)->patch(route('admin.papers.unpublish', $paper))->assertSessionHas('error');

        $this->assertSame($before, $this->questionIdsInOrder($paper));
        $this->assertSame(QuizPaperStatus::Published, $paper->fresh()->status);

        // Title edits remain possible, and the builder explains the lock.
        $this->actingAs($this->editor)->put(route('admin.papers.update', $paper), ['title' => 'New title', 'default_time_limit_seconds' => $paper->default_time_limit_seconds])->assertSessionHas('success');
        $this->actingAs($this->editor)->get(route('admin.papers.edit', $paper))->assertSee('Locked')->assertDontSee('Add selected to paper');
    }

    public function test_draft_rooms_do_not_lock_a_paper(): void
    {
        $paper = $this->draftPaper(5);
        Room::factory()->status(RoomStatus::Draft)->create(['quiz_paper_id' => $paper->id]);

        $this->assertFalse($paper->isLocked());
    }

    public function test_duplicate_creates_an_editable_draft_copy(): void
    {
        $paper = $this->draftPaper(5);
        $this->actingAs($this->editor)->patch(route('admin.papers.publish', $paper));
        Room::factory()->status(RoomStatus::Open)->create(['quiz_paper_id' => $paper->id]);

        $this->actingAs($this->editor)->post(route('admin.papers.duplicate', $paper))->assertRedirect();

        $copy = QuizPaper::latest('id')->first();
        $this->assertNotSame($paper->id, $copy->id);
        $this->assertSame(QuizPaperStatus::Draft, $copy->status);
        $this->assertSame($this->questionIdsInOrder($paper), $this->questionIdsInOrder($copy));
        $this->assertFalse($copy->isLocked());
    }

    public function test_archived_papers_are_read_only_until_restored(): void
    {
        $paper = $this->draftPaper(2);
        $this->actingAs($this->editor)->patch(route('admin.papers.archive', $paper))->assertSessionHas('success');

        $this->actingAs($this->editor)->delete(route('admin.papers.items.destroy', [$paper, $paper->items()->first()]))->assertSessionHas('error');
        $this->actingAs($this->editor)->patch(route('admin.papers.publish', $paper))->assertSessionHas('error');

        $this->actingAs($this->editor)->patch(route('admin.papers.restore', $paper))->assertSessionHas('success');
        $this->assertSame(QuizPaperStatus::Draft, $paper->fresh()->status);
    }

    public function test_preview_shows_the_answer_key_to_content_staff_only(): void
    {
        $paper = $this->draftPaper(5);
        $correct = $paper->questions()->first()->options()->where('is_correct', true)->sole();

        $this->actingAs($this->editor)->get(route('admin.papers.preview', $paper))
            ->assertOk()->assertSee($correct->body)->assertSee('(correct)')->assertSee('All publishing checks pass.');

        $this->actingAs(User::factory()->role(UserRole::FinanceManager)->create())->get(route('admin.papers.preview', $paper))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('admin.papers.preview', $paper))->assertForbidden();
    }

    public function test_builder_changes_are_audited(): void
    {
        $paper = $this->draftPaper(3);
        $this->actingAs($this->editor)->patch(route('admin.papers.items.move', [$paper, $paper->items()->where('position', 2)->sole()]), ['direction' => 'down']);
        $this->actingAs($this->editor)->delete(route('admin.papers.items.destroy', [$paper, $paper->items()->first()]));

        $this->assertSame(['paper.question_moved', 'paper.question_removed'], AuditLog::orderBy('id')->pluck('action')->all());
    }

    public function test_competition_managers_can_view_but_not_build(): void
    {
        $paper = $this->draftPaper(5);
        $manager = User::factory()->role(UserRole::CompetitionManager)->create();

        $this->actingAs($manager)->get(route('admin.papers.preview', $paper))->assertOk();
        $this->actingAs($manager)->patch(route('admin.papers.publish', $paper))->assertForbidden();
        $this->actingAs($manager)->post(route('admin.papers.store'), ['title' => 'X', 'default_time_limit_seconds' => 20])->assertForbidden();
    }
}
