<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryRequest;
use App\Models\Category;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Categories are deactivated rather than deleted, so questions and past
 * competitions keep their category.
 */
class CategoryController extends Controller
{
    public function __construct(protected AuditLogger $audit) {}

    public function store(CategoryRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $category = Category::create($this->attributes($request));
            $this->audit->record('category.created', $category, null, $category->only(['name', 'slug', 'parent_id', 'is_active']));
        });

        return redirect()->route('admin.categories.index')->with('success', 'Category created.');
    }

    public function edit(Category $category): View
    {
        return view('admin.content.category-form', [
            'category' => $category,
            'parents' => Category::query()->whereKeyNot($category->id)->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        DB::transaction(function () use ($request, $category) {
            $before = $category->only(['name', 'slug', 'parent_id', 'is_active', 'sort_order']);
            $category->update($this->attributes($request));

            if ($category->wasChanged()) {
                $this->audit->record('category.updated', $category, $before, $category->only(array_keys($before)));
            }
        });

        return redirect()->route('admin.categories.index')->with('success', 'Category updated.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function attributes(CategoryRequest $request): array
    {
        $data = $request->validated();

        return [
            'name' => $data['name'],
            'slug' => $data['slug'],
            'parent_id' => $data['parent_id'] ?? null,
            'description' => $data['description'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
