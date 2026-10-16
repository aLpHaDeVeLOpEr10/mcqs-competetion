<?php

namespace App\Http\Controllers\Admin;

use App\Enums\QuestionDifficulty;
use App\Enums\QuestionStatus;
use App\Exceptions\AdministrativeActionException;
use App\Exceptions\ContentValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PaperItemRequest;
use App\Http\Requests\Admin\PaperRequest;
use App\Models\Category;
use App\Models\Question;
use App\Models\QuizPaper;
use App\Models\QuizPaperQuestion;
use App\Services\Content\PaperBuilder;
use App\Services\Content\PaperValidator;
use App\Support\QueryFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Quiz paper builder. Listing and the read-only view live in ContentController.
 */
class PaperController extends Controller
{
    public const RANDOM_COUNTS = [10, 15, 20, 30];

    public function __construct(
        protected PaperBuilder $builder,
        protected PaperValidator $validator,
    ) {}

    public function create(): View
    {
        return view('admin.content.paper-form', ['paper' => new QuizPaper(['default_time_limit_seconds' => 20])]);
    }

    public function store(PaperRequest $request): RedirectResponse
    {
        $paper = $this->builder->create($request->user(), $request->validated());

        return redirect()->route('admin.papers.edit', $paper)->with('success', 'Paper created. Now add questions.');
    }

    /**
     * The builder: settings, ordered questions and a question picker.
     */
    public function edit(Request $request, QuizPaper $paper): View
    {
        $filters = QueryFilters::from($request, [
            'q' => ['string', 'max:100'],
            'category' => ['integer', Rule::exists('categories', 'id')],
        ]);

        $inPaper = $paper->items()->pluck('question_id');
        $candidates = Question::query()
            ->with('category:id,name')
            ->where('status', '!=', QuestionStatus::Archived)
            ->whereNotIn('id', $inPaper);
        $filters->search($candidates, 'q', ['body']);
        $candidates->when($filters->get('category'), fn ($q, $id) => $q->where('category_id', $id));

        return view('admin.content.paper-builder', [
            'paper' => $paper,
            'items' => $paper->items()->with('question.category:id,name')->orderBy('position')->get(),
            'locked' => $paper->isLocked(),
            'candidates' => $candidates->latest('id')->paginate(10, ['*'], 'qpage')->withQueryString(),
            'filters' => $filters,
            'categoryOptions' => Category::query()->orderBy('name')->pluck('name', 'id')->all(),
            'randomCountOptions' => array_combine(self::RANDOM_COUNTS, self::RANDOM_COUNTS),
            'difficultyOptions' => collect(QuestionDifficulty::cases())->mapWithKeys(fn ($d) => [$d->value => ucfirst($d->value)])->all(),
            'problems' => $this->validator->problems($paper),
        ]);
    }

    public function update(PaperRequest $request, QuizPaper $paper): RedirectResponse
    {
        return $this->run(fn () => $this->builder->update($request->user(), $paper, $request->validated()), $paper, 'Paper settings saved.');
    }

    public function addQuestions(Request $request, QuizPaper $paper): RedirectResponse
    {
        $data = $request->validate([
            'question_ids' => ['required', 'array', 'min:1', 'max:50'],
            'question_ids.*' => ['integer', 'distinct', Rule::exists('questions', 'id')->whereNull('deleted_at')],
        ]);

        return $this->run(function () use ($request, $paper, $data) {
            $added = $this->builder->addQuestions($request->user(), $paper, array_map('intval', $data['question_ids']));
            session()->flash('success', "Added {$added} question(s).");
        }, $paper);
    }

    /**
     * Add a random pick of active questions not already in the paper,
     * optionally narrowed to one category and difficulty.
     */
    public function addRandomQuestions(Request $request, QuizPaper $paper): RedirectResponse
    {
        $data = $request->validate([
            'count' => ['required', 'integer', Rule::in(self::RANDOM_COUNTS)],
            'category' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'difficulty' => ['nullable', Rule::enum(QuestionDifficulty::class)],
        ]);

        $ids = Question::query()
            ->where('status', QuestionStatus::Active)
            ->whereNotIn('id', $paper->items()->pluck('question_id'))
            ->when($data['category'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($data['difficulty'] ?? null, fn ($q, $difficulty) => $q->where('difficulty', $difficulty))
            ->inRandomOrder()
            ->limit((int) $data['count'])
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return back()->with('error', 'No active questions match that category and difficulty that are not already in this paper.');
        }

        return $this->run(function () use ($request, $paper, $ids, $data) {
            $added = $this->builder->addQuestions($request->user(), $paper, $ids);
            $short = $added < (int) $data['count'] ? " Only {$added} matching question(s) were available." : '';
            session()->flash('success', "Added {$added} random question(s).{$short}");
        }, $paper);
    }

    public function updateItem(PaperItemRequest $request, QuizPaper $paper, QuizPaperQuestion $item): RedirectResponse
    {
        return $this->run(fn () => $this->builder->updateItem($request->user(), $paper, $item, [
            'time_limit_seconds' => $request->validated('time_limit_seconds'),
            'points' => (int) $request->validated('points'),
        ]), $paper, 'Question settings saved.');
    }

    public function removeItem(Request $request, QuizPaper $paper, QuizPaperQuestion $item): RedirectResponse
    {
        return $this->run(fn () => $this->builder->removeItem($request->user(), $paper, $item), $paper, 'Question removed from the paper.');
    }

    public function moveItem(Request $request, QuizPaper $paper, QuizPaperQuestion $item): RedirectResponse
    {
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];

        return $this->run(fn () => $this->builder->move($request->user(), $paper, $item, $direction), $paper);
    }

    /**
     * Full preview as it would be snapshotted, with the answer key for staff.
     */
    public function preview(QuizPaper $paper): View
    {
        return view('admin.content.paper-preview', [
            'paper' => $paper,
            'items' => $paper->items()->with(['question' => fn ($q) => $q->withTrashed()->with('options')])->orderBy('position')->get(),
            'problems' => $this->validator->problems($paper),
        ]);
    }

    public function publish(Request $request, QuizPaper $paper): RedirectResponse
    {
        return $this->run(fn () => $this->builder->publish($request->user(), $paper), $paper, 'Paper published. It can now be used for competitions.');
    }

    public function unpublish(Request $request, QuizPaper $paper): RedirectResponse
    {
        return $this->run(fn () => $this->builder->unpublish($request->user(), $paper), $paper, 'Paper returned to draft.');
    }

    public function archive(Request $request, QuizPaper $paper): RedirectResponse
    {
        return $this->run(fn () => $this->builder->archive($request->user(), $paper), $paper, 'Paper archived.');
    }

    public function restore(Request $request, QuizPaper $paper): RedirectResponse
    {
        return $this->run(fn () => $this->builder->restore($request->user(), $paper), $paper, 'Paper restored as a draft.');
    }

    public function duplicate(Request $request, QuizPaper $paper): RedirectResponse
    {
        $copy = $this->builder->duplicate($request->user(), $paper);

        return redirect()->route('admin.papers.edit', $copy)->with('success', 'Draft copy created. Edit it freely; the original is unchanged.');
    }

    protected function run(callable $action, QuizPaper $paper, ?string $success = null): RedirectResponse
    {
        try {
            $action();
        } catch (ContentValidationException $e) {
            return back()->with('error', $e->getMessage())->with('problems', $e->problems);
        } catch (AdministrativeActionException $e) {
            return back()->with('error', $e->getMessage());
        }

        $redirect = redirect()->route('admin.papers.edit', $paper);

        return $success ? $redirect->with('success', $success) : $redirect;
    }
}
