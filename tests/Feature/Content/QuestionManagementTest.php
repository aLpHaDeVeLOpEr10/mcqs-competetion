<?php

namespace Tests\Feature\Content;

use App\Enums\QuestionStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QuestionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $editor;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->editor = User::factory()->role(UserRole::ContentManager)->create();
        $this->category = Category::factory()->create(['name' => 'Science']);
    }

    protected function payload(array $overrides = []): array
    {
        return $overrides + [
            'category_id' => $this->category->id,
            'body' => '  What is the chemical symbol for gold? ',
            'explanation' => 'From the Latin aurum.',
            'difficulty' => 'medium',
            'status' => 'active',
            'default_time_limit_seconds' => 25,
            'tags' => 'chemistry, Elements; chemistry',
            'options' => ['Ag', 'Au', 'Gd', 'Go'],
            'correct' => 1,
        ];
    }

    public function test_content_manager_creates_a_question_with_options_tags_and_audit(): void
    {
        $this->actingAs($this->editor)->post(route('admin.questions.store'), $this->payload())
            ->assertRedirect()->assertSessionHas('success');

        $question = Question::with('options', 'tags')->sole();
        $this->assertSame('What is the chemical symbol for gold?', $question->body);
        $this->assertSame(Question::hashBody('what is the chemical symbol for GOLD?'), $question->body_hash);
        $this->assertSame(['Ag', 'Au', 'Gd', 'Go'], $question->options->pluck('body')->all());
        $this->assertSame([2], $question->options->where('is_correct', true)->pluck('position')->values()->all());
        $this->assertSame(['chemistry', 'Elements'], $question->tags->pluck('name')->all());
        $this->assertSame($this->editor->id, $question->created_by);
        $this->assertSame(1, AuditLog::where('action', 'question.created')->count());
    }

    public function test_empty_option_fields_are_dropped_and_correct_is_remapped(): void
    {
        $this->actingAs($this->editor)->post(route('admin.questions.store'), $this->payload([
            'options' => ['Ag', '', 'Au', '', 'Gd'],
            'correct' => 2,
        ]))->assertSessionHasNoErrors();

        $options = Question::sole()->options;
        $this->assertSame(['Ag', 'Au', 'Gd'], $options->pluck('body')->all());
        $this->assertSame('Au', $options->firstWhere('is_correct', true)->body);
    }

    public function test_option_validation(): void
    {
        $cases = [
            'too few' => [['options' => ['Only one', '', '', ''], 'correct' => 0], 'options'],
            'duplicates' => [['options' => ['Same', 'same', 'Other', ''], 'correct' => 0], 'options'],
            'correct points at empty option' => [['options' => ['A', 'B', '', ''], 'correct' => 3], 'correct'],
            'too many options' => [['options' => array_fill(0, 7, 'x'), 'correct' => 0], 'options'],
            'time limit out of range' => [['default_time_limit_seconds' => 2], 'default_time_limit_seconds'],
            'archived status not allowed here' => [['status' => 'archived'], 'status'],
        ];

        foreach ($cases as $name => [$override, $field]) {
            $this->actingAs($this->editor)->post(route('admin.questions.store'), $this->payload($override))
                ->assertSessionHasErrors($field, null, 'default');
        }

        $this->assertDatabaseCount('questions', 0);
    }

    public function test_inactive_categories_are_rejected(): void
    {
        $inactive = Category::factory()->create(['is_active' => false]);

        $this->actingAs($this->editor)->post(route('admin.questions.store'), $this->payload(['category_id' => $inactive->id]))
            ->assertSessionHasErrors('category_id');
    }

    public function test_update_keeps_option_ids_and_moves_the_correct_answer(): void
    {
        $question = Question::factory()->withOptions(4)->create(['category_id' => $this->category->id]);
        $ids = $question->options()->pluck('id')->all();

        $this->actingAs($this->editor)->put(route('admin.questions.update', $question), $this->payload([
            'options' => ['One', 'Two', 'Three', 'Four'],
            'correct' => 3,
        ]))->assertSessionHasNoErrors();

        $options = $question->fresh()->options;
        $this->assertSame($ids, $options->pluck('id')->all());
        $this->assertSame('Four', $options->firstWhere('is_correct', true)->body);
        $this->assertSame(1, $options->where('is_correct', true)->count());

        $log = AuditLog::where('action', 'question.updated')->sole();
        $this->assertSame(4, $log->new_values['correct_position']);
    }

    public function test_shrinking_options_removes_extra_rows(): void
    {
        $question = Question::factory()->withOptions(4)->create(['category_id' => $this->category->id]);

        $this->actingAs($this->editor)->put(route('admin.questions.update', $question), $this->payload(['options' => ['Yes', 'No'], 'correct' => 0]));

        $this->assertSame(['Yes', 'No'], $question->fresh()->options->pluck('body')->all());
    }

    public function test_archive_and_restore_are_audited_and_archived_questions_are_read_only(): void
    {
        $question = Question::factory()->withOptions()->create(['category_id' => $this->category->id]);

        $this->actingAs($this->editor)->patch(route('admin.questions.archive', $question))->assertSessionHas('success');
        $this->assertSame(QuestionStatus::Archived, $question->fresh()->status);

        $this->actingAs($this->editor)->put(route('admin.questions.update', $question), $this->payload())->assertSessionHas('error');

        $this->actingAs($this->editor)->patch(route('admin.questions.restore', $question))->assertSessionHas('success');
        $this->assertSame(QuestionStatus::Draft, $question->fresh()->status);

        $this->assertSame(['question.archived', 'question.restored'], AuditLog::orderBy('id')->pluck('action')->all());
    }

    public function test_images_are_re_encoded_stored_privately_and_served_only_to_content_staff(): void
    {
        Storage::fake('local');

        $this->actingAs($this->editor)->post(route('admin.questions.store'), $this->payload([
            'image' => UploadedFile::fake()->image('diagram.png', 300, 200),
            'image_alt' => 'A diagram',
        ]))->assertSessionHasNoErrors();

        $question = Question::sole();
        $this->assertMatchesRegularExpression('#^question-images/[A-Za-z0-9]{40}\.webp$#', $question->image_path);
        Storage::disk('local')->assertExists($question->image_path);
        $this->assertSame('RIFF', substr(Storage::disk('local')->get($question->image_path), 0, 4));

        $this->actingAs($this->editor)->get(route('admin.questions.image', $question))
            ->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs(User::factory()->role(UserRole::FinanceManager)->create())->get(route('admin.questions.image', $question))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('admin.questions.image', $question))->assertForbidden();
        $this->assertArrayNotHasKey('image_path', $question->toArray());
    }

    public function test_non_image_uploads_are_rejected(): void
    {
        Storage::fake('local');

        foreach ([
            UploadedFile::fake()->createWithContent('evil.png', '<?php echo "hi"; ?>'),
            UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ] as $file) {
            $this->actingAs($this->editor)->post(route('admin.questions.store'), $this->payload(['image' => $file]))
                ->assertSessionHasErrors('image');
        }

        $this->assertDatabaseCount('questions', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_replacing_an_image_deletes_the_old_file(): void
    {
        Storage::fake('local');
        $this->actingAs($this->editor)->post(route('admin.questions.store'), $this->payload(['image' => UploadedFile::fake()->image('a.png')]));
        $question = Question::sole();
        $old = $question->image_path;

        $this->actingAs($this->editor)->put(route('admin.questions.update', $question), $this->payload(['image' => UploadedFile::fake()->image('b.jpg')]));

        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists($question->fresh()->image_path);
    }

    public function test_only_content_managers_can_write(): void
    {
        $question = Question::factory()->withOptions()->create();

        foreach ([UserRole::CompetitionManager, UserRole::FinanceManager, UserRole::Player] as $role) {
            $user = User::factory()->role($role)->create();
            $this->actingAs($user)->post(route('admin.questions.store'), $this->payload())->assertForbidden();
            $this->actingAs($user)->put(route('admin.questions.update', $question), $this->payload())->assertForbidden();
            $this->actingAs($user)->patch(route('admin.questions.archive', $question))->assertForbidden();
        }

        $this->assertSame(1, Question::count());
    }

    public function test_listing_filters_by_tag_and_difficulty(): void
    {
        $this->actingAs($this->editor)->post(route('admin.questions.store'), $this->payload());
        $this->actingAs($this->editor)->post(route('admin.questions.store'), $this->payload(['body' => 'Speed of light?', 'tags' => 'physics', 'difficulty' => 'hard']));

        $this->actingAs($this->editor)->get(route('admin.questions.index', ['tag' => 'physics']))->assertSee('Speed of light?')->assertDontSee('symbol for gold');
        $this->actingAs($this->editor)->get(route('admin.questions.index', ['difficulty' => 'medium']))->assertSee('symbol for gold')->assertDontSee('Speed of light?');
    }
}
