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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class QuestionImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->editor = User::factory()->role(UserRole::ContentManager)->create();
        Category::factory()->create(['name' => 'Science', 'slug' => 'science']);
        Category::factory()->create(['name' => 'History', 'slug' => 'history']);
    }

    protected function csv(string $contents, string $name = 'questions.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    protected function upload(string $contents): TestResponse
    {
        return $this->actingAs($this->editor)->post(route('admin.questions.import.store'), ['file' => $this->csv($contents)]);
    }

    protected function tokenFrom(TestResponse $response): string
    {
        $this->assertMatchesRegularExpression('#/admin/questions/import/([A-Za-z0-9]{40})$#', $response->headers->get('Location'));

        return basename($response->headers->get('Location'));
    }

    protected const HEADER = "category,question,option_a,option_b,option_c,option_d,correct,difficulty,explanation,tags,time_limit,status\n";

    public function test_valid_rows_are_previewed_then_imported(): void
    {
        $response = $this->upload(self::HEADER
            ."Science,What is H2O?,Water,Salt,Sugar,,A,easy,Basic chemistry,chemistry;basics,15,active\n"
            ."history,Who founded Pakistan?,Quaid-e-Azam,Allama Iqbal,,,a,,,,,\n");

        $token = $this->tokenFrom($response);
        $this->assertDatabaseCount('questions', 0);

        $this->actingAs($this->editor)->get(route('admin.questions.import.show', $token))
            ->assertOk()->assertSee('Ready to import')->assertSee('Import 2 question(s)');

        $this->actingAs($this->editor)->post(route('admin.questions.import.commit', $token))
            ->assertRedirect(route('admin.questions.index'))
            ->assertSessionHas('success', 'Imported 2 question(s); 0 row(s) were skipped.');

        $water = Question::where('body', 'What is H2O?')->with('options', 'tags')->sole();
        $this->assertSame(['Water', 'Salt', 'Sugar'], $water->options->pluck('body')->all());
        $this->assertSame('Water', $water->options->firstWhere('is_correct', true)->body);
        $this->assertSame(15, $water->default_time_limit_seconds);
        $this->assertSame(QuestionStatus::Active, $water->status);
        $this->assertSame(['basics', 'chemistry'], $water->tags->pluck('name')->sort()->values()->all());

        $founder = Question::where('body', 'Who founded Pakistan?')->sole();
        $this->assertSame(QuestionStatus::Draft, $founder->status);
        $this->assertSame(1, AuditLog::where('action', 'questions.imported')->count());
    }

    public function test_row_level_errors_are_reported_and_only_valid_rows_import(): void
    {
        $token = $this->tokenFrom($this->upload(self::HEADER
            ."Science,Valid question?,Yes,No,,,A,,,,,\n"
            ."Astrology,Unknown category?,Yes,No,,,A,,,,,\n"
            ."Science,Bad correct letter?,Yes,No,,,D,,,,,\n"
            ."Science,Gap in options?,Yes,,Maybe,,A,,,,,\n"
            ."Science,=HYPERLINK(\"http://evil\"),Yes,No,,,A,,,,,\n"
            ."Science,Bad difficulty?,Yes,No,,,A,extreme,,,,\n"
            ."Science,Time too long?,Yes,No,,,A,,,,9999,\n"
            ."Science,Negative numbers are fine?,-5,+7,,,A,,,,,\n"));

        $preview = $this->actingAs($this->editor)->get(route('admin.questions.import.show', $token))->assertOk();
        $preview->assertSee('Unknown or inactive category')
            ->assertSee('Correct answer must be the letter of a filled option')
            ->assertSee('Options must be filled in order with no gaps')
            ->assertSee('spreadsheet formula')
            ->assertSee('Difficulty must be easy, medium or hard.')
            ->assertSee('Time limit must be a whole number');

        $this->actingAs($this->editor)->post(route('admin.questions.import.commit', $token));

        $this->assertSame(['Negative numbers are fine?', 'Valid question?'], Question::orderBy('body')->pluck('body')->all());
    }

    public function test_duplicates_in_the_file_and_in_the_bank_are_skipped(): void
    {
        Question::factory()->withOptions()->create(['body' => 'Already   in the BANK?']);

        $token = $this->tokenFrom($this->upload(self::HEADER
            ."Science,already in the bank?,Yes,No,,,A,,,,,\n"
            ."Science,New question?,Yes,No,,,A,,,,,\n"
            ."Science,  new   QUESTION? ,Yes,No,,,B,,,,,\n"));

        $this->actingAs($this->editor)->get(route('admin.questions.import.show', $token))
            ->assertSee('Already in the question bank.')
            ->assertSee('Duplicate of line 3 in this file.');

        $this->actingAs($this->editor)->post(route('admin.questions.import.commit', $token))
            ->assertSessionHas('success', 'Imported 1 question(s); 2 row(s) were skipped.');
        $this->assertSame(2, Question::count());
    }

    public function test_file_level_problems_block_the_import(): void
    {
        $missing = $this->tokenFrom($this->upload("category,question,option_a\nScience,Q?,A\n"));
        $this->actingAs($this->editor)->get(route('admin.questions.import.show', $missing))
            ->assertSee('Missing required column(s): option_b, correct.')
            ->assertDontSee('Import 0');

        $unknown = $this->tokenFrom($this->upload("category,question,option_a,option_b,correct,file_path\nScience,Q?,A,B,A,/etc/passwd\n"));
        $this->actingAs($this->editor)->get(route('admin.questions.import.show', $unknown))->assertSee('Unknown column(s): file_path.');

        $empty = $this->tokenFrom($this->upload(''));
        $this->actingAs($this->editor)->get(route('admin.questions.import.show', $empty))->assertSee('The file is empty.');
    }

    public function test_row_limit_is_enforced(): void
    {
        config(['competition.questions.import_max_rows' => 3]);
        $rows = collect(range(1, 4))->map(fn ($i) => "Science,Question {$i}?,Yes,No,,,A,,,,,")->implode("\n");

        $token = $this->tokenFrom($this->upload(self::HEADER.$rows));

        $this->actingAs($this->editor)->get(route('admin.questions.import.show', $token))->assertSee('more than 3 question rows');
    }

    public function test_semicolon_delimiter_bom_and_windows_1252_are_handled(): void
    {
        $csv = "\xEF\xBB\xBFcategory;question;option_a;option_b;correct\n"
            ."Science;Caf\xE9 or tea?;Caf\xE9;Tea;A\n"; // Windows-1252 é

        $token = $this->tokenFrom($this->upload($csv));
        $this->actingAs($this->editor)->post(route('admin.questions.import.commit', $token));

        $this->assertSame('Café or tea?', Question::sole()->body);
    }

    public function test_a_preview_can_be_committed_once_and_only_by_its_owner(): void
    {
        $token = $this->tokenFrom($this->upload(self::HEADER."Science,Only once?,Yes,No,,,A,,,,,\n"));

        $other = User::factory()->role(UserRole::ContentManager)->create();
        $this->actingAs($other)->get(route('admin.questions.import.show', $token))->assertRedirect(route('admin.questions.import'));
        $this->actingAs($other)->post(route('admin.questions.import.commit', $token))->assertSessionHas('error');
        $this->assertSame(0, Question::count());

        $this->actingAs($this->editor)->post(route('admin.questions.import.commit', $token))->assertSessionHas('success');
        $this->actingAs($this->editor)->post(route('admin.questions.import.commit', $token))->assertSessionHas('error');
        $this->assertSame(1, Question::count());
    }

    public function test_non_csv_uploads_are_rejected(): void
    {
        $this->actingAs($this->editor)->post(route('admin.questions.import.store'), [
            'file' => UploadedFile::fake()->createWithContent('payload.php', '<?php system($_GET["c"]);'),
        ])->assertSessionHasErrors('file');
    }

    public function test_sample_csv_downloads_with_the_expected_header(): void
    {
        $response = $this->actingAs($this->editor)->get(route('admin.questions.import.sample'))->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFcategory,question,option_a,option_b,correct,option_c", $content);

        // The sample itself imports cleanly once its categories exist.
        Category::factory()->create(['name' => 'General Knowledge']);
        $token = $this->tokenFrom($this->upload($content));
        $this->actingAs($this->editor)->post(route('admin.questions.import.commit', $token))->assertSessionHas('success', 'Imported 2 question(s); 0 row(s) were skipped.');
    }

    public function test_import_requires_content_manage(): void
    {
        $this->actingAs(User::factory()->role(UserRole::CompetitionManager)->create())
            ->post(route('admin.questions.import.store'), ['file' => $this->csv(self::HEADER)])
            ->assertForbidden();
    }
}
