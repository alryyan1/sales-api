<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InsuranceCompany;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InsuranceCompanyController extends Controller
{
    public function index(Request $request)
    {
        $query = InsuranceCompany::query()->with('subCompanies:id,insurance_company_id,name');

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
            'invoice_cap' => ['nullable', 'numeric', 'min:0'],
            ...$this->copayRules(),
            ...$this->subCompanyRules(),
        ]);
        $validated = $this->normalizeCopay($validated);

        $company = DB::transaction(function () use ($validated) {
            $company = InsuranceCompany::create($validated);
            $this->syncSubCompanies($company, $validated['sub_companies'] ?? []);

            return $company;
        });

        return response()->json($company->load('subCompanies'), Response::HTTP_CREATED);
    }

    public function show(InsuranceCompany $insuranceCompany)
    {
        return response()->json($insuranceCompany->load('subCompanies'));
    }

    public function update(Request $request, InsuranceCompany $insuranceCompany)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('insurance_companies', 'name')->ignore($insuranceCompany->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'invoice_cap' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            ...$this->copayRules(),
            ...$this->subCompanyRules(),
        ]);
        $validated = $this->normalizeCopay($validated);

        DB::transaction(function () use ($insuranceCompany, $validated, $request) {
            $insuranceCompany->update($validated);
            // Only touch sub-companies when the client sent the list.
            if ($request->has('sub_companies')) {
                $this->syncSubCompanies($insuranceCompany, $validated['sub_companies'] ?? []);
            }
        });

        return response()->json($insuranceCompany->fresh()->load('subCompanies'));
    }

    public function destroy(InsuranceCompany $insuranceCompany)
    {
        if ($insuranceCompany->patients()->exists()) {
            return response()->json(['message' => 'Cannot delete insurance company with patients.'], Response::HTTP_CONFLICT);
        }

        $insuranceCompany->delete();

        return response()->noContent();
    }

    private function copayRules(): array
    {
        return [
            'copay_type' => ['sometimes', Rule::in([InsuranceCompany::COPAY_FIXED, InsuranceCompany::COPAY_VARIABLE])],
            'copay_percentage' => ['nullable', 'required_if:copay_type,'.InsuranceCompany::COPAY_FIXED, 'numeric', 'min:0', 'max:100'],
        ];
    }

    /** A percentage is only kept for fixed co-pay companies. */
    private function normalizeCopay(array $validated): array
    {
        if (($validated['copay_type'] ?? null) === InsuranceCompany::COPAY_VARIABLE) {
            $validated['copay_percentage'] = null;
        }

        return $validated;
    }

    private function subCompanyRules(): array
    {
        return [
            'sub_companies' => ['sometimes', 'nullable', 'array'],
            'sub_companies.*.id' => ['nullable', 'integer'],
            'sub_companies.*.name' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
        ];
    }

    /**
     * Replace the company's sub-companies with the given list: rows with a
     * known id are renamed, rows without one are created, the rest deleted.
     */
    private function syncSubCompanies(InsuranceCompany $company, array $items): void
    {
        $existing = $company->subCompanies()->get()->keyBy('id');
        $keepIds = collect($items)->pluck('id')->filter(fn ($id) => $existing->has($id))->all();

        // Delete removed rows first so a removed name can be re-added in the same save.
        $company->subCompanies()->whereNotIn('id', $keepIds)->delete();

        foreach ($items as $item) {
            $name = trim($item['name']);
            $sub = isset($item['id']) ? $existing->get($item['id']) : null;

            if ($sub) {
                $sub->update(['name' => $name]);
            } else {
                $company->subCompanies()->create(['name' => $name]);
            }
        }
    }
}
