<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InsuranceCompany;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class InsuranceCompanyController extends Controller
{
    public function index(Request $request)
    {
        $query = InsuranceCompany::query();

        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('insurance_companies', 'name')],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $company = InsuranceCompany::create($validated);

        return response()->json($company, Response::HTTP_CREATED);
    }

    public function show(InsuranceCompany $insuranceCompany)
    {
        return response()->json($insuranceCompany);
    }

    public function update(Request $request, InsuranceCompany $insuranceCompany)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('insurance_companies', 'name')->ignore($insuranceCompany->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ]);

        $insuranceCompany->update($validated);

        return response()->json($insuranceCompany->fresh());
    }

    public function destroy(InsuranceCompany $insuranceCompany)
    {
        if ($insuranceCompany->patients()->exists()) {
            return response()->json(['message' => 'Cannot delete insurance company with patients.'], Response::HTTP_CONFLICT);
        }

        $insuranceCompany->delete();

        return response()->noContent();
    }
}
