<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseCategoryResource;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ExpenseCategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = ExpenseCategory::query()->withCount('expenses');

        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->boolean('all_flat')) {
            $categories = $query->orderBy('name')->get();
            return ExpenseCategoryResource::collection($categories);
        }

        $perPage = (int) $request->input('per_page', 15);
        $categories = $query->orderBy('name')->paginate($perPage);
        return ExpenseCategoryResource::collection($categories);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('expense_categories', 'name')],
            'description' => ['nullable', 'string'],
        ]);

        $category = ExpenseCategory::create($validated);
        return response()->json(['category' => new ExpenseCategoryResource($category)], Response::HTTP_CREATED);
    }

    public function show(ExpenseCategory $expenseCategory)
    {
        $expenseCategory->loadCount('expenses');
        return new ExpenseCategoryResource($expenseCategory);
    }

    public function update(Request $request, ExpenseCategory $expenseCategory)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('expense_categories', 'name')->ignore($expenseCategory->id)],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        $expenseCategory->update($validated);
        return new ExpenseCategoryResource($expenseCategory->fresh()->loadCount('expenses'));
    }

    public function destroy(ExpenseCategory $expenseCategory)
    {
        if ($expenseCategory->expenses()->count() > 0) {
            return response()->json(['message' => 'Cannot delete category with expenses.'], Response::HTTP_CONFLICT);
        }
        $expenseCategory->delete();
        return response()->noContent();
    }
}


