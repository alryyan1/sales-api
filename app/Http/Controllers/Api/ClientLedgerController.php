<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\ClientLedgerPdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientLedgerController extends Controller
{
    /**
     * Get client ledger with sales and received payments.
     */
    public function getLedger(Client $client)
    {
        try {
            // Load related sales with their items and payments
            $client->load(['sales' => function ($q) {
                $q->orderBy('sale_date', 'desc')->orderBy('created_at', 'desc');
            }, 'sales.items.product:id,name', 'sales.payments']);

            $sales = $client->sales;

            // Build one entry per sale with aggregated payment info
            $ledgerEntries = $sales->map(function ($sale) {
                $itemsTotal = (float) $sale->items->sum('total_price');
                $discount   = (float) ($sale->discount_amount ?? 0);
                $total      = max(0.0, $itemsTotal - $discount);
                $paid       = (float) $sale->payments->sum('amount');
                $due        = max(0.0, $total - $paid);

                return [
                    'id'          => 'sale_' . $sale->id,
                    'sale_id'     => $sale->id,
                    'date'        => $sale->sale_date,
                    'total'       => $total,
                    'paid'        => $paid,
                    'due'         => $due,
                    'items_count' => $sale->items->count(),
                    'created_at'  => $sale->created_at,
                    'items'       => $sale->items->map(fn($item) => [
                        'product_id'   => $item->product_id,
                        'product_name' => $item->product?->name ?? '',
                    ])->values(),
                ];
            })->sortByDesc('created_at')->values();

            $totalSales    = (float) $ledgerEntries->sum('total');
            $totalPayments = (float) $ledgerEntries->sum('paid');
            $balance       = $totalSales - $totalPayments;

            return response()->json([
                'client' => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'email' => $client->email,
                    'phone' => $client->phone,
                    'address' => $client->address,
                ],
                'summary' => [
                    'total_sales' => $totalSales,
                    'total_payments' => $totalPayments,
                    'balance' => $balance,
                ],
                'ledger_entries' => $ledgerEntries->values(),
            ], Response::HTTP_OK);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to retrieve client ledger',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Settle client's debt by allocating a payment across oldest unpaid sales.
     */
    public function settleDebt(Request $request, Client $client)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date_format:Y-m-d',
            'method' => 'required',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:65535',
        ]);

        try {
            $result = DB::transaction(function () use ($client, $validated, $request) {
                $remaining = (float) $validated['amount'];
                $totalApplied = 0.0;
                $paymentsCreated = 0;
                $affectedSales = [];

                // Fetch sales ordered by oldest first (by sale_date then created_at)
                $sales = Sale::where('client_id', $client->id)
                    ->orderBy('sale_date', 'asc')
                    ->orderBy('created_at', 'asc')
                    ->lockForUpdate()
                    ->get();

                foreach ($sales as $sale) {
                    if ($remaining <= 0) break;
                    // Load relationships to compute due from items/discount/payments
                    $sale->loadMissing('items', 'payments');
                    $due = (float) $sale->calculated_due_amount;
                    if ($due <= 0) continue;

                    $apply = min($due, $remaining);

                    // Create payment record for this sale
                    Payment::create([
                        'sale_id' => $sale->id,
                        'user_id' => $request->user()->id ?? null,
                        'method' => $validated['method'],
                        'amount' => $apply,
                        'payment_date' => $validated['payment_date'],
                        'reference_number' => $validated['reference_number'] ?? null,
                        'notes' => $validated['notes'] ?? null,
                    ]);

                    $totalApplied += $apply;
                    $remaining -= $apply;
                    $paymentsCreated++;
                    $affectedSales[] = $sale->id;
                }

                return [
                    'payments_created' => $paymentsCreated,
                    'total_applied' => $totalApplied,
                    'remaining_unapplied' => max(0, (float) $validated['amount'] - $totalApplied),
                    'affected_sales' => $affectedSales,
                ];
            });

            return response()->json([
                'message' => 'Client debt settled successfully',
                'result' => $result,
            ], Response::HTTP_OK);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to settle client debt',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Generate and download a PDF of the client's ledger.
     */
    public function downloadLedgerPDF(Client $client)
    {
        try {
            $service = new ClientLedgerPdfService('P', 'mm', 'A4', true, 'UTF-8', false);
            $pdfContent = $service->generate($client);

            $fileName = 'client_ledger_' . $client->id . '_' . now()->format('Ymd') . '.pdf';

            return response($pdfContent, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', "inline; filename=\"{$fileName}\"");
        } catch (\Throwable $e) {
            Log::error('Failed to generate client ledger PDF', [
                'client_id' => $client->id,
                'error'     => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Failed to generate ledger PDF',
                'error'   => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
} 