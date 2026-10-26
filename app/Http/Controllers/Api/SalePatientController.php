<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SaleResource;
use App\Models\Patient;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Links an insured patient to a sale and manages the insurance co-pay
 * percentage (the share of the invoice the patient pays themselves).
 */
class SalePatientController extends Controller
{
    /**
     * Register a patient (or reuse an existing one with the same card number at
     * the same insurance company) and attach them to the sale.
     */
    public function attach(Request $request, Sale $sale)
    {
        $this->authorizeSaleOwner($sale);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'insurance_card_number' => ['required', 'string', 'max:255'],
            'insurance_company_id' => ['required', 'integer', 'exists:insurance_companies,id'],
        ]);

        DB::transaction(function () use ($validated, $sale) {
            $patient = Patient::updateOrCreate(
                [
                    'insurance_company_id' => $validated['insurance_company_id'],
                    'insurance_card_number' => $validated['insurance_card_number'],
                ],
                ['name' => $validated['name']]
            );

            $sale->update(['patient_id' => $patient->id]);
        });

        return $this->saleResponse($sale);
    }

    /**
     * Unlink the patient from the sale. The co-pay only applies to insured
     * sales, so it is cleared too.
     */
    public function detach(Sale $sale)
    {
        $this->authorizeSaleOwner($sale);

        $sale->update(['patient_id' => null, 'copay_percentage' => null]);

        return $this->saleResponse($sale);
    }

    /**
     * Set (or clear with null) the co-pay percentage. Only allowed once a
     * patient is linked.
     */
    public function updateCopay(Request $request, Sale $sale)
    {
        $this->authorizeSaleOwner($sale);

        $validated = $request->validate([
            'copay_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        if (! $sale->patient_id) {
            throw ValidationException::withMessages([
                'copay_percentage' => ['Register an insured patient on this sale first.'],
            ]);
        }

        $copay = $validated['copay_percentage'];

        // The patient's share must not drop below what has already been paid.
        $totalBeforeCopay = (float) $sale->items()->sum('total_price') - (float) ($sale->discount_amount ?? 0);
        $newTotal = $copay === null ? $totalBeforeCopay : $totalBeforeCopay * (float) $copay / 100;
        $paid = (float) $sale->payments()->sum('amount');
        if ($newTotal + 1e-6 < $paid) {
            throw ValidationException::withMessages([
                'copay_percentage' => ['Co-pay amount cannot be less than the amount already paid.'],
            ]);
        }

        $sale->update(['copay_percentage' => $copay]);

        return $this->saleResponse($sale);
    }

    private function authorizeSaleOwner(Sale $sale): void
    {
        // Only the cashier who created the sale may edit it (same rule as discount).
        if ((int) $sale->user_id !== (int) Auth::id()) {
            abort(403, 'Only the user who created this sale can edit it.');
        }
    }

    private function saleResponse(Sale $sale)
    {
        $sale->refresh()->load([
            'client:id,name,email,phone',
            'user:id,name',
            'warehouse:id,name',
            'patient.insuranceCompany:id,name',
            'items',
            'items.product:id,name,sku,scientific_name,stock_alert_level,sellable_unit_id,image_url,is_service',
            'items.product.sellableUnit:id,name',
            'items.product.warehouses',
            'items.product.purchaseItemsWithStock:id,product_id,batch_number,expiry_date,sale_price,unit_cost',
            'items.purchaseItemBatch:id,batch_number,unit_cost,expiry_date',
            'payments.user:id,name,username',
            'returns:id,sale_id',
            'returns.items:id,sale_return_id,product_id,quantity,price',
        ]);

        return response()->json(['sale' => new SaleResource($sale)]);
    }
}
