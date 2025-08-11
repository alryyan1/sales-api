<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use App\Services\Pdf\MyCustomTCPDF;
use Carbon\Carbon;

class ClientLedgerController extends Controller
{
    /**
     * Get client ledger with sales and received payments.
     */
    public function getLedger(Client $client)
    {
        try {
            // Load related sales and payments
            $client->load(['sales' => function ($q) {
                $q->orderBy('sale_date', 'desc')->orderBy('created_at', 'desc');
            }, 'sales.payments']);

            $sales = $client->sales;

            // Flatten payments across sales with dates
            $payments = $sales->flatMap(function ($sale) {
                return $sale->payments->map(function ($payment) use ($sale) {
                    return [
                        'id' => $payment->id,
                        'date' => $payment->payment_date,
                        'type' => 'payment',
                        'description' => 'Payment - ' . ($payment->method ?? 'N/A'),
                        'debit' => 0,
                        'credit' => (float) $payment->amount,
                        'balance' => null,
                        'reference' => $payment->reference_number,
                        'notes' => $payment->notes,
                        'created_at' => $payment->created_at,
                    ];
                });
            });

            // Build sale entries
            $saleEntries = $sales->map(function ($sale) {
                return [
                    'id' => 'sale_' . $sale->id,
                    'sale_id' => $sale->id,
                    'date' => $sale->sale_date,
                    'type' => 'sale',
                    'description' => 'Sale #' . $sale->id,
                    'debit' => (float) $sale->total_amount,
                    'credit' => 0,
                    'balance' => null,
                    'reference' => $sale->invoice_number,
                    'notes' => $sale->notes,
                    'created_at' => $sale->created_at,
                ];
            });

            $ledgerEntries = $saleEntries->concat($payments);

            // Sort and calculate running balance
            // Compute running balance in chronological ascending order,
            // then return entries in descending order for display
            $entriesAsc = $ledgerEntries->sortBy([
                ['date', 'asc'],
                ['created_at', 'asc'],
            ]);

            $runningBalance = 0.0;
            $entriesWithBalance = $entriesAsc->values()->map(function ($entry) use (&$runningBalance) {
                $runningBalance += ((float)($entry['debit'] ?? 0) - (float)($entry['credit'] ?? 0));
                $entry['balance'] = $runningBalance;
                return $entry;
            });

            $ledgerEntries = $entriesWithBalance->sortBy([
                ['date', 'desc'],
                ['created_at', 'desc'],
            ]);

            $totalSales = (float) $sales->sum('total_amount');
            $totalPayments = (float) $payments->sum('credit');
            $balance = $totalSales - $totalPayments;

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
            'method' => [
                'required',
                Rule::in(['cash', 'visa', 'mastercard', 'bank_transfer', 'mada', 'other', 'store_credit'])
            ],
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:65535',
        ]);

        try {
            $result = DB::transaction(function () use ($client, $validated, $request) {
                $remaining = (float) $validated['amount'];
                $totalApplied = 0.0;
                $paymentsCreated = 0;
                $affectedSales = [];

                // Fetch unpaid sales ordered by oldest first (by sale_date then created_at)
                $sales = Sale::where('client_id', $client->id)
                    ->whereRaw('(total_amount - paid_amount) > 0')
                    ->orderBy('sale_date', 'asc')
                    ->orderBy('created_at', 'asc')
                    ->lockForUpdate()
                    ->get();

                foreach ($sales as $sale) {
                    if ($remaining <= 0) break;
                    $due = (float) $sale->total_amount - (float) $sale->paid_amount;
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

                    // Update sale paid_amount to reflect sum of payments
                    $sale->paid_amount = $sale->payments()->sum('amount');
                    $sale->save();

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
            // Reuse the ledger generation logic
            $response = $this->getLedger($client);
            $data = $response->getData(true);

            // Prepare PDF
            $pdf = new MyCustomTCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            $pdf->SetTitle('Client Ledger - ' . $client->name);
            $pdf->AddPage();
            $pdf->setRTL(true);

            // Header
            $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 14);
            $pdf->Cell(0, 10, 'كشف حساب العميل', 0, 1, 'C');
            $pdf->Ln(2);

            // Client Info
            $pdf->SetFont($pdf->getDefaultFontFamily(), '', 10);
            $pdf->Cell(0, 6, 'العميل: ' . ($data['client']['name'] ?? ''), 0, 1, 'R');
            if (!empty($data['client']['phone'])) {
                $pdf->Cell(0, 6, 'الهاتف: ' . $data['client']['phone'], 0, 1, 'R');
            }
            if (!empty($data['client']['email'])) {
                $pdf->Cell(0, 6, 'البريد الإلكتروني: ' . $data['client']['email'], 0, 1, 'R');
            }
            if (!empty($data['client']['address'])) {
                $pdf->MultiCell(0, 6, 'العنوان: ' . $data['client']['address'], 0, 'R', 0, 1);
            }
            $pdf->Ln(4);

            // Summary
            $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 11);
            $pdf->Cell(0, 7, 'الملخص', 0, 1, 'R');
            $pdf->SetFont($pdf->getDefaultFontFamily(), '', 10);
            $pdf->Cell(0, 6, 'إجمالي المبيعات: ' . number_format((float)($data['summary']['total_sales'] ?? 0), 2), 0, 1, 'R');
            $pdf->Cell(0, 6, 'إجمالي المدفوعات: ' . number_format((float)($data['summary']['total_payments'] ?? 0), 2), 0, 1, 'R');
            $pdf->Cell(0, 6, 'الرصيد: ' . number_format((float)($data['summary']['balance'] ?? 0), 2), 0, 1, 'R');
            $pdf->Ln(4);

            // Table header - auto fit to page width (after margins)
            $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 9);
            $pdf->SetFillColor(220, 220, 220);
            $numCols = 6;
            $margins = $pdf->getMargins();
            $usableWidth = $pdf->getPageWidth() - ($margins['left'] ?? 0) - ($margins['right'] ?? 0);
            $baseColWidth = round($usableWidth / $numCols, 2);
            $colWidths = array_fill(0, $numCols, $baseColWidth);
            // Adjust last column to absorb rounding diff
            $colWidths[$numCols - 1] = $usableWidth - array_sum(array_slice($colWidths, 0, $numCols - 1));

            // Ensure we start at left margin (works with RTL too)
            $pdf->SetX($margins['left'] ?? 0);
            $pdf->Cell($colWidths[0], 7, 'التاريخ', 1, 0, 'C', true);
            $pdf->Cell($colWidths[1], 7, 'النوع', 1, 0, 'C', true);
            $pdf->Cell($colWidths[2], 7, 'الوصف', 1, 0, 'C', true);
            $pdf->Cell($colWidths[3], 7, 'مدين', 1, 0, 'C', true);
            $pdf->Cell($colWidths[4], 7, 'دائن', 1, 0, 'C', true);
            $pdf->Cell($colWidths[5], 7, 'الرصيد', 1, 1, 'C', true);

            // Table rows
            $pdf->SetFont($pdf->getDefaultFontFamily(), '', 8);
            foreach ($data['ledger_entries'] as $entry) {
                $dateRaw = isset($entry['date']) ? (string)$entry['date'] : '';
                $date = $dateRaw ? Carbon::parse($dateRaw)->format('d m Y') : '';
                $type = ($entry['type'] ?? '') === 'sale' ? 'مبيع' : 'سداد';
                $description = (string)($entry['description'] ?? '');
                $debit = number_format((float)($entry['debit'] ?? 0), 2);
                $credit = number_format((float)($entry['credit'] ?? 0), 2);
                $balance = number_format((float)($entry['balance'] ?? 0), 2);

                // Start at margin for each row
                $pdf->SetX($margins['left'] ?? 0);
                $pdf->Cell($colWidths[0], 6, $date, 1, 0, 'C');
                $pdf->Cell($colWidths[1], 6, $type, 1, 0, 'C');
                $pdf->Cell($colWidths[2], 6, $description, 1, 0, 'R');
                $pdf->Cell($colWidths[3], 6, $debit, 1, 0, 'R');
                $pdf->Cell($colWidths[4], 6, $credit, 1, 0, 'R');
                $pdf->Cell($colWidths[5], 6, $balance, 1, 1, 'R');
            }

            $fileName = 'client_ledger_' . $client->id . '_' . now()->format('Ymd') . '.pdf';
            $pdfContent = $pdf->Output($fileName, 'S');

            return response($pdfContent, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', "inline; filename=\"{$fileName}\"");
        } catch (\Throwable $e) {
            Log::error('Failed to generate client ledger PDF', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Failed to generate ledger PDF',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
} 