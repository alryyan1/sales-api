<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\Supplier;
use App\Services\SupplierLedgerPdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SupplierPaymentController extends Controller
{
    /**
     * Get supplier ledger with payments and purchases.
     * Refactored to be Purchase-Centric.
     */
    public function getLedger(Supplier $supplier)
    {
        try {
            // Get all purchases for this supplier with their payments
            $purchases = $supplier->purchases()
                ->with(['payments.user'])
                ->orderBy('purchase_date', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();

            // Get direct payments (not linked to any purchase)
            $directPayments = $supplier->payments()
                ->whereNull('purchase_id')
                ->with('user')
                ->get();

            $totalPurchases = $purchases->sum('total_amount');
            
            // Total payments = payments linked to purchases + direct payments
            $totalPaymentsAcrossPurchases = $purchases->sum(function($p) {
                return $p->payments->sum('amount');
            });
            $totalDirectPayments = $directPayments->sum('amount');
            
            $totalPayments = $totalPaymentsAcrossPurchases + $totalDirectPayments;
            $balance = $totalPurchases - $totalPayments;

            $ledgerEntries = collect();

            // 1. Add Purchases as the main ledger entries
            foreach ($purchases as $purchase) {
                $purchasePaid = $purchase->payments->sum('amount');
                $purchaseBalance = $purchase->total_amount - $purchasePaid;

                $ledgerEntries->push([
                    'id' => 'purchase_' . $purchase->id,
                    'purchase_id' => $purchase->id,
                    'date' => $purchase->purchase_date->format('Y-m-d'),
                    'type' => 'purchase',
                    'description' => 'Purchase #' . $purchase->id . ($purchase->reference_number ? ' (' . $purchase->reference_number . ')' : ''),
                    'debit' => $purchase->total_amount,
                    'credit' => $purchasePaid,
                    'balance' => $purchaseBalance,
                    'reference' => $purchase->reference_number,
                    'created_at' => $purchase->created_at,
                    'payments' => $purchase->payments->map(function($p) {
                        return [
                            'id' => $p->id,
                            'amount' => $p->amount,
                            'method' => $p->method,
                            'payment_date' => $p->payment_date->format('Y-m-d'),
                            'reference_number' => $p->reference_number,
                            'user' => $p->user ? ['name' => $p->user->name] : null,
                        ];
                    }),
                ]);
            }

            // 2. Add a virtual entry for Direct Payments if any exist
            if ($directPayments->isNotEmpty()) {
                $ledgerEntries->push([
                    'id' => 'direct_payments',
                    'date' => now()->format('Y-m-d'),
                    'type' => 'payment',
                    'description' => 'المدفوعات المباشرة (غير مرتبطة بمشتريات)',
                    'debit' => 0,
                    'credit' => $totalDirectPayments,
                    'balance' => -$totalDirectPayments,
                    'reference' => '-',
                    'created_at' => $directPayments->first()->created_at,
                    'payments' => $directPayments->map(function($p) {
                        return [
                            'id' => $p->id,
                            'amount' => $p->amount,
                            'method' => $p->method,
                            'payment_date' => $p->payment_date->format('Y-m-d'),
                            'reference_number' => $p->reference_number,
                            'user' => $p->user ? ['name' => $p->user->name] : null,
                        ];
                    }),
                ]);
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
     * Export supplier ledger as PDF (web route — inline display).
     */
    public function exportLedgerPdf(Supplier $supplier)
    {
        try {
            $service    = new SupplierLedgerPdfService();
            $pdfContent = $service->generate($supplier);

            return response($pdfContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="supplier_ledger_' . $supplier->id . '.pdf"');
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to generate PDF',
                'error'   => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Settle supplier debt by allocating a payment across oldest unpaid purchases (FIFO).
     */
    public function settleDebt(Request $request, Supplier $supplier)
    {
        $validated = $request->validate([
            'amount'           => 'required|numeric|min:0.01',
            'method'           => ['required', Rule::in(['cash', 'visa', 'mastercard', 'bank_transfer', 'mada', 'refund', 'other', 'bankak', 'fawry', 'ocash'])],
            'payment_date'     => 'required|date_format:Y-m-d',
            'reference_number' => 'nullable|string|max:255',
        ]);

        try {
            $result = DB::transaction(function () use ($supplier, $validated, $request) {
                $remaining      = (float) $validated['amount'];
                $totalApplied   = 0.0;
                $paymentsCreated = 0;
                $affectedPurchases = [];

                // Fetch purchases oldest-first with a lock
                $purchases = Purchase::where('supplier_id', $supplier->id)
                    ->orderBy('purchase_date', 'asc')
                    ->orderBy('created_at', 'asc')
                    ->lockForUpdate()
                    ->get();

                foreach ($purchases as $purchase) {
                    if ($remaining <= 0) break;

                    $paid = (float) PurchasePayment::where('purchase_id', $purchase->id)->sum('amount');
                    $due  = max(0.0, (float) $purchase->total_amount - $paid);

                    if ($due <= 0) continue;

                    $apply = min($due, $remaining);

                    PurchasePayment::create([
                        'supplier_id'      => $supplier->id,
                        'purchase_id'      => $purchase->id,
                        'user_id'          => auth()->id(),
                        'amount'           => $apply,
                        'method'           => $validated['method'],
                        'payment_date'     => $validated['payment_date'],
                        'reference_number' => $validated['reference_number'] ?? null,
                    ]);

                    $totalApplied      += $apply;
                    $remaining         -= $apply;
                    $paymentsCreated++;
                    $affectedPurchases[] = $purchase->id;
                }

                return [
                    'payments_created'    => $paymentsCreated,
                    'total_applied'       => $totalApplied,
                    'remaining_unapplied' => max(0, (float) $validated['amount'] - $totalApplied),
                    'affected_purchases'  => $affectedPurchases,
                ];
            });

            return response()->json([
                'message' => 'Supplier debt settled successfully',
                'result'  => $result,
            ], Response::HTTP_OK);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to settle supplier debt',
                'error'   => $e->getMessage(),
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
            'method' => ['required', Rule::in(['cash', 'visa', 'mastercard', 'bank_transfer', 'mada', 'refund', 'other', 'bankak', 'fawry', 'ocash'])],
            'reference_number' => 'nullable|string|max:255',
            'payment_date' => 'required|date',
            'purchase_id' => 'nullable|exists:purchases,id', // Optional purchase linking
        ]);

        try {
            DB::beginTransaction();

            $payment = PurchasePayment::create([
                'supplier_id' => $supplier->id,
                'purchase_id' => $request->purchase_id,
                'user_id' => auth()->id(),
                'amount' => $request->amount,
                'method' => $request->input('method'),
                'reference_number' => $request->reference_number,
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
    public function update(Request $request, $id)
    {
        $payment = PurchasePayment::find($id);

        if (!$payment) {
            return response()->json([
                'message' => 'Payment not found',
                'payment_id' => $id,
            ], Response::HTTP_NOT_FOUND);
        }

        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => ['required', Rule::in(['cash', 'visa', 'mastercard', 'bank_transfer', 'mada', 'refund', 'other', 'bankak', 'fawry', 'ocash'])],
            'reference_number' => 'nullable|string|max:255',
            'payment_date' => 'required|date',
            'purchase_id' => 'nullable|exists:purchases,id',
        ]);

        try {
            DB::beginTransaction();

            $payment->update([
                'amount' => $request->amount,
                'method' => $request->input('method'),
                'reference_number' => $request->reference_number,
                'payment_date' => $request->payment_date,
                'purchase_id' => $request->purchase_id,
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
        $payment = PurchasePayment::find($id);

        if (!$payment) {
            return response()->json([
                'message' => 'Payment not found',
                'payment_id' => $id,
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            DB::beginTransaction();

            $deleted = $payment->forceDelete();

            DB::commit();

            if ($deleted) {
                return response()->json([
                    'message' => 'Payment deleted successfully',
                    'payment_id' => $id,
                ]);
            }

            return response()->json([
                'message' => 'Failed to delete payment',
            ], Response::HTTP_BAD_REQUEST);

        } catch (\Exception $e) {
            DB::rollBack();
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
                ['value' => 'bankak', 'label' => 'Bankak'],
                ['value' => 'fawry', 'label' => 'Fawry'],
                ['value' => 'ocash', 'label' => 'oCash'],
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
            ]
        ]);
    }
}
