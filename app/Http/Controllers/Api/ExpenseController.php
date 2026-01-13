<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $query = Expense::query()->with(['category:id,name', 'user:id,name', 'shift:id,opened_at,closed_at']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%");
            });
        }

        if ($categoryId = $request->input('expense_category_id')) {
            $query->where('expense_category_id', $categoryId);
        }

        if ($shiftId = $request->input('shift_id')) {
            $query->where('shift_id', $shiftId);
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('expense_date', '>=', $dateFrom);
        }
        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('expense_date', '<=', $dateTo);
        }

        if ($minAmount = $request->input('min_amount')) {
            $query->where('amount', '>=', $minAmount);
        }
        if ($maxAmount = $request->input('max_amount')) {
            $query->where('amount', '<=', $maxAmount);
        }

        $sortBy = $request->input('sort_by', 'expense_date');
        $sortDirection = $request->input('sort_direction', 'desc');
        $sortable = ['expense_date', 'amount', 'created_at'];
        if (in_array($sortBy, $sortable)) {
            $query->orderBy($sortBy, $sortDirection);
        } else {
            $query->orderBy('expense_date', 'desc');
        }

        $perPage = (int) $request->input('per_page', 15);
        $expenses = $query->paginate($perPage);
        return ExpenseResource::collection($expenses);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'amount' => ['required', 'numeric', 'min:0'],
            'expense_date' => ['required', 'date'],
            'payment_method' => ['nullable', 'string', 'in:cash,bank'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $validated['user_id'] = $request->user()->id ?? null;

        $expense = Expense::create($validated);
        $expense->load(['category:id,name', 'user:id,name', 'shift:id,opened_at,closed_at']);
        return response()->json(['expense' => new ExpenseResource($expense)], Response::HTTP_CREATED);
    }

    public function show(Expense $expense)
    {
        $expense->load(['category:id,name', 'user:id,name', 'shift:id,opened_at,closed_at']);
        return new ExpenseResource($expense);
    }

    public function update(Request $request, Expense $expense)
    {
        $validated = $request->validate([
            'expense_category_id' => ['sometimes', 'nullable', 'integer', 'exists:expense_categories,id'],
            'shift_id' => ['sometimes', 'nullable', 'integer', 'exists:shifts,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'expense_date' => ['sometimes', 'required', 'date'],
            'payment_method' => ['sometimes', 'nullable', 'string', 'in:cash,bank'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $expense->update($validated);
        $expense->load(['category:id,name', 'user:id,name', 'shift:id,opened_at,closed_at']);
        return new ExpenseResource($expense);
    }

    public function destroy(Expense $expense)
    {
        $expense->delete();
        return response()->noContent();
    }
}
