<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AdministrativeActionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\QuestionRequest;
use App\Models\Category;
use App\Models\Question;
use App\Services\Content\QuestionImageStore;
use App\Services\Content\QuestionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Question editing. Listing and viewing live in ContentController.
 */
class QuestionController extends Controller
{
    public function __construct(protected QuestionService $questions) {}

    public function create(): View
    {
        return view('admin.content.question-form', [
            'question' => new Question(['difficulty' => 'medium', 'status' => 'draft']),
            'options' => array_fill(0, (int) config('competition.questions.default_options'), ''),
            'correct' => 0,
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function store(QuestionRequest $request): RedirectResponse
    {
        try {
            $question = $this->questions->create($request->user(), $request->payload(), $request->file('image'));
        } catch (AdministrativeActionException $e) {
            return back()->withInput()->withErrors(['image' => $e->getMessage()]);
        }

        return redirect()->route('admin.questions.show', $question)->with('success', 'Question created.');
    }

    public function edit(Question $question): View
    {
        $options = $question->options()->get();

        return view('admin.content.question-form', [
            'question' => $question->load('tags'),
            'options' => array_pad($options->pluck('body')->all(), (int) config('competition.questions.default_options'), ''),
            'correct' => max(0, (int) $options->search(fn ($o) => $o->is_correct)),
            'categories' => $this->categoryOptions(),
            'lockedPapers' => $question->quizPapers()->get()->filter->isLocked(),
        ]);
    }

    public function update(QuestionRequest $request, Question $question): RedirectResponse
    {
        try {
            $this->questions->update($request->user(), $question, $request->payload(), $request->file('image'), $request->boolean('remove_image'));
        } catch (AdministrativeActionException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.questions.show', $question)->with('success', 'Question updated.');
    }

    public function archive(Request $request, Question $question): RedirectResponse
    {
        return $this->run(fn () => $this->questions->archive($request->user(), $question), $question, 'Question archived. It can no longer be added to papers.');
    }

    public function restore(Request $request, Question $question): RedirectResponse
    {
        return $this->run(fn () => $this->questions->restore($request->user(), $question), $question, 'Question restored as a draft.');
    }

    /**
     * Staff-only image preview. Images are on the private disk, never public.
     */
    public function image(Question $question, QuestionImageStore $images): StreamedResponse
    {
        abort_if($question->image_path === null, 404);

        return $images->response($question->image_path);
    }

    protected function run(callable $action, Question $question, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (AdministrativeActionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.questions.show', $question)->with('success', $success);
    }

    /**
     * @return array<int, string>
     */
    protected function categoryOptions(): array
    {
        return Category::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }
}
