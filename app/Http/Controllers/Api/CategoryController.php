<?php // app/Http/Controllers/Api/CategoryController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Http\Resources\CategoryResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB; // If needed for complex parent/child updates
use Illuminate\Foundation\Auth\Access\AuthorizesRequests; // For Policies

class CategoryController extends Controller {
    use AuthorizesRequests; // Assuming you'll create CategoryPolicy

    public function index(Request $request) {
        // $this->authorize('viewAny', Category::class); // Requires CategoryPolicy

        // Fetch top-level categories, or all, or allow filtering by parent_id
        $query = Category::query()->withCount(['products', 'children']);

        if ($request->boolean('top_level_only')) {
            $query->whereNull('parent_id');
        }
        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->input('search') . '%');
        }
        // For product form dropdowns, you might want a flat list of all categories
        if ($request->boolean('all_flat')) {
             $categories = $query->orderBy('name')->get();
             return CategoryResource::collection($categories);
        }

        $categories = $query->orderBy('name')->paginate($request->input('per_page', 20));
        return CategoryResource::collection($categories);
    }

    public function store(Request $request) {
        // $this->authorize('create', Category::class);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('categories', 'name')],
            'description' => 'nullable|string',
            'parent_id' => 'nullable|integer|exists:categories,id', // Ensure parent exists
        ]);
        $category = Category::create($validated);
        return response()->json(['category' => new CategoryResource($category)], Response::HTTP_CREATED);
    }

    public function show(Category $category) {
        // $this->authorize('view', $category);
        $category->loadCount(['products', 'children'])->load('parent:id,name');
        return new CategoryResource($category);
    }

    public function update(Request $request, Category $category) {
        // $this->authorize('update', $category);
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('categories', 'name')->ignore($category->id)],
            'description' => 'sometimes|nullable|string',
            'parent_id' => 'sometimes|nullable|integer|exists:categories,id' . ($category->id ? ',id,!' . $category->id : ''), // Prevent self-parenting
        ]);
        // Prevent making a category its own descendant (more complex logic needed for deep trees)
        if (isset($validated['parent_id']) && $validated['parent_id'] == $category->id) {
             return response()->json(['message' => 'A category cannot be its own parent.'], 422);
        }
        $category->update($validated);
        return new CategoryResource($category->fresh()->loadCount(['products', 'children'])->load('parent:id,name'));
    }

    public function destroy(Category $category) {
        // $this->authorize('delete', $category);
        // Handle products assigned to this category (reassign to 'Uncategorized' or prevent deletion)
        if ($category->products()->count() > 0) {
            return response()->json(['message' => 'Cannot delete category. It has products assigned. Reassign products first.'], Response::HTTP_CONFLICT);
        }
         // Handle subcategories (delete them, reassign their parent, or prevent deletion)
        if ($category->children()->count() > 0) {
             return response()->json(['message' => 'Cannot delete category. It has subcategories. Delete or reassign them first.'], Response::HTTP_CONFLICT);
        }
        $category->delete();
        return response()->noContent();
    }
}