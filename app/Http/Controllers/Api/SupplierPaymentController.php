<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SupplierPaymentController extends Controller
{
    /**
     * Get supplier ledger with payments and purchases.
     */
    public function getLedger(Supplier $supplier)
    {
        try {
            // Get supplier with relationships
            $supplier->load(['payments.user', 'purchases']);

            // Get payments
            $payments = $supplier->payments()
                ->with('user')
                ->orderBy('payment_date', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();

            // Get purchases
            $purchases = $supplier->purchases()
                ->orderBy('purchase_date', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();

            // Calculate totals
            $totalPurchases = $purchases->sum('total_amount');
            $totalPayments = $payments->sum('amount');
            $balance = $totalPurchases - $totalPayments;

            // Create ledger entries
            $ledgerEntries = collect();

            // Add purchases to ledger
            foreach ($purchases as $purchase) {
                $ledgerEntries->push([
                    'id' => 'purchase_' . $purchase->id, // Keep prefix for purchases
                    'purchase_id' => $purchase->id, // Add actual purchase ID
                    'date' => $purchase->purchase_date,
                    'type' => 'purchase',
                    'description' => 'Purchase #' . $purchase->id,
                    'debit' => $purchase->total_amount,
                    'credit' => 0,
                    'balance' => null, // Will be calculated
                    'reference' => $purchase->reference_number,
                    'notes' => $purchase->notes,
                    'created_at' => $purchase->created_at,
                ]);
            }

            // Add payments to ledger
            foreach ($payments as $payment) {
                $ledgerEntries->push([
                    'id' => $payment->id, // Use actual payment ID
                    'date' => $payment->payment_date,
                    'type' => 'payment',
                    'description' => ucfirst($payment->type) . ' - ' . ucfirst($payment->method),
                    'debit' => 0,
                    'credit' => $payment->amount,
                    'balance' => null, // Will be calculated
                    'reference' => $payment->reference_number,
                    'notes' => $payment->notes,
                    'created_at' => $payment->created_at,
                    'user' => $payment->user->name,
                ]);
            }

            // Sort by date and calculate running balance
            $ledgerEntries = $ledgerEntries->sortByDesc('date')->sortByDesc('created_at');
            $runningBalance = 0;

            foreach ($ledgerEntries as $entry) {
                $runningBalance += $entry['debit'] - $entry['credit'];
                $entry['balance'] = $runningBalance;
            }

            return response()->json([
                'supplier' => [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                    'contact_person' => $supplier->contact_person,
                    'email' => $supplier->email,
                    'phone' => $supplier->phone,
                ],
                'summary' => [
                    'total_purchases' => $totalPurchases,
                    'total_payments' => $totalPayments,
                    'balance' => $balance,
                ],
                'ledger_entries' => $ledgerEntries->values(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve supplier ledger',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Store a new supplier payment.
     */
    public function store(Request $request, Supplier $supplier)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'type' => ['required', Rule::in(['payment', 'credit', 'adjustment'])],
            'method' => ['required', Rule::in(['cash', 'bank_transfer', 'check', 'credit_card', 'other'])],
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'payment_date' => 'required|date',
        ]);

        try {
            DB::beginTransaction();

            $payment = SupplierPayment::create([
                'supplier_id' => $supplier->id,
                'user_id' => auth()->id(),
                'amount' => $request->amount,
                'type' => $request->type,
                'method' => $request->method,
                'reference_number' => $request->reference_number,
                'notes' => $request->notes,
                'payment_date' => $request->payment_date,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Payment recorded successfully',
                'payment' => $payment->load('user'),
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to record payment',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update a supplier payment.
     */
    public function update(Request $request, SupplierPayment $payment)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'type' => ['required', Rule::in(['payment', 'credit', 'adjustment'])],
            'method' => ['required', Rule::in(['cash', 'bank_transfer', 'check', 'credit_card', 'other'])],
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'payment_date' => 'required|date',
        ]);

        try {
            DB::beginTransaction();

            $payment->update([
                'amount' => $request->amount,
                'type' => $request->type,
                'method' => $request->method,
                'reference_number' => $request->reference_number,
                'notes' => $request->notes,
                'payment_date' => $request->payment_date,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Payment updated successfully',
                'payment' => $payment->load('user'),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update payment',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete a supplier payment.
     */
    public function destroy($id)
    {
        // Find the payment manually to debug route model binding
        $payment = SupplierPayment::find($id);
        
        if (!$payment) {
            \Log::error('Payment not found for deletion:', ['payment_id' => $id]);
            return response()->json([
                'message' => 'Payment not found',
                'payment_id' => $id,
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            DB::beginTransaction();

            // Log the payment being deleted for debugging
            \Log::info('Attempting to delete payment:', [
                'payment_id' => $payment->id,
                'supplier_id' => $payment->supplier_id,
                'amount' => $payment->amount,
                'type' => $payment->type,
                'method' => $payment->method,
                'exists_before_delete' => $payment->exists,
            ]);

            // Force delete to bypass any soft deletes if they exist
            $deleted = $payment->forceDelete();

            DB::commit();

            // Check if payment still exists after deletion
            $paymentStillExists = SupplierPayment::find($payment->id);
            
            \Log::info('Payment deletion result:', [
                'payment_id' => $payment->id,
                'delete_returned' => $deleted,
                'payment_still_exists' => $paymentStillExists ? true : false,
            ]);

            if ($deleted && !$paymentStillExists) {
                return response()->json([
                    'message' => 'Payment deleted successfully',
                    'payment_id' => $payment->id,
                ]);
            } else {
                return response()->json([
                    'message' => 'Payment was not deleted or still exists',
                    'payment_id' => $payment->id,
                    'delete_returned' => $deleted,
                    'still_exists' => $paymentStillExists ? true : false,
                ], Response::HTTP_BAD_REQUEST);
            }

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to delete payment:', [
                'payment_id' => $payment->id ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => 'Failed to delete payment',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get payment methods for dropdown.
     */
    public function getPaymentMethods()
    {
        return response()->json([
            'methods' => [
                ['value' => 'cash', 'label' => 'Cash'],
                ['value' => 'bank_transfer', 'label' => 'Bank Transfer'],
                ['value' => 'check', 'label' => 'Check'],
                ['value' => 'credit_card', 'label' => 'Credit Card'],
                ['value' => 'other', 'label' => 'Other'],
            ]
        ]);
    }

    /**
     * Get payment types for dropdown.
     */
    public function getPaymentTypes()
    {
        return response()->json([
            'types' => [
                ['value' => 'payment', 'label' => 'Payment'],
                ['value' => 'credit', 'label' => 'Credit'],
                ['value' => 'adjustment', 'label' => 'Adjustment'],
            ]
        ]);
    }
}
