<?php

namespace App\Http\Controllers\Admin;

use App\Enums\QuestionDifficulty;
use App\Enums\QuestionStatus;
use App\Enums\QuizPaperStatus;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Question;
use App\Models\QuizPaper;
use App\Models\Tag;
use App\Support\QueryFilters;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Read-only views of the question bank. Editing arrives with the question
 * bank phase; the views mark those actions as unavailable.
 */
class ContentController extends Controller
{
    public function categories(Request $request): View
    {
        $filters = QueryFilters::from($request, ['q' => ['string', 'max:100']]);

        $query = Category::query()->with('parent:id,name')->withCount('questions');
        $filters->search($query, 'q', ['name', 'slug']);

        return view('admin.content.categories', [
            'categories' => $query->orderBy('sort_order')->orderBy('name')->paginate(25)->withQueryString(),
            'parentOptions' => Category::query()->orderBy('name')->pluck('name', 'id')->all(),
            'filters' => $filters,
        ]);
    }

    public function questions(Request $request): View
    {
        $filters = QueryFilters::from($request, [
            'q' => ['string', 'max:100'],
            'category' => ['integer', Rule::exists('categories', 'id')],
            'status' => [Rule::enum(QuestionStatus::class)],
            'difficulty' => [Rule::enum(QuestionDifficulty::class)],
            'tag' => ['string', Rule::exists('tags', 'slug')],
        ]);

        $query = Question::query()->with(['category:id,name', 'tags:id,name,slug'])->withCount('options');
        $filters->search($query, 'q', ['body']);
        $query->when($filters->get('category'), fn ($q, $id) => $q->where('category_id', $id))
            ->when($filters->get('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($filters->get('difficulty'), fn ($q, $d) => $q->where('difficulty', $d))
            ->when($filters->get('tag'), fn ($q, $slug) => $q->whereHas('tags', fn ($t) => $t->where('slug', $slug)));

        return view('admin.content.questions', [
            'questions' => $query->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
            'categoryOptions' => Category::query()->orderBy('name')->pluck('name', 'id')->all(),
            'tagOptions' => Tag::query()->orderBy('name')->pluck('name', 'slug')->all(),
        ]);
    }

    public function question(Question $question): View
    {
        return view('admin.content.question', [
            'question' => $question->load(['category:id,name', 'options', 'tags', 'creator:id,name']),
            'papers' => $question->quizPapers()->get(['quiz_papers.id', 'title', 'status']),
        ]);
    }

    public function papers(Request $request): View
    {
        $filters = QueryFilters::from($request, [
            'q' => ['string', 'max:100'],
            'status' => [Rule::enum(QuizPaperStatus::class)],
        ]);

        $query = QuizPaper::query()->withCount(['items', 'rooms']);
        $filters->search($query, 'q', ['title']);
        $query->when($filters->get('status'), fn ($q, $s) => $q->where('status', $s));

        return view('admin.content.papers', [
            'papers' => $query->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function paper(QuizPaper $paper): View
    {
        return view('admin.content.paper', [
            'paper' => $paper->load(['items.question.category:id,name', 'creator:id,name'])->loadCount('rooms'),
        ]);
    }
}
