<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(): View
    {
        $categories = Category::query()
            ->select(['id', 'parent_id', 'name', 'is_active'])
            ->withCount(['products' => fn ($q) => $q->whereNull('products.deleted_at')])
            ->orderBy('name')->get();

        return view('categories.index', [
            'categories' => $categories,
            'parentOptions' => $categories->pluck('name', 'id')->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        Category::create($data + ['slug' => $this->slug($data['name'])]);

        return back()->with('success', __('Category added.'));
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $data = $this->validated($request, $category);
        abort_if(($data['parent_id'] ?? null) === $category->id, 422);
        $category->update($data);

        return back()->with('success', __('Category saved.'));
    }

    public function destroy(Category $category): RedirectResponse
    {
        DB::transaction(function () use ($category) {
            // Products keep working without a category; children move up.
            DB::table('products')->where('category_id', $category->id)->update(['category_id' => null]);
            Category::where('parent_id', $category->id)->update(['parent_id' => $category->parent_id]);
            $category->delete();
        });

        return back()->with('success', __('Category deleted.'));
    }

    private function validated(Request $request, ?Category $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('categories', 'name')->ignore($category?->id)->whereNull('deleted_at')],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
        ]);
    }

    private function slug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        for ($n = 2; Category::withTrashed()->where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }
}
