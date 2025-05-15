<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SaleItemResource;
use App\Models\Sale;
use App\Models\SaleItem; // Though items are created via relationship
use App\Models\Product;
use App\Models\PurchaseItem; // Needed for batch selection
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Resources\SaleResource;
use App\Models\SaleReturnItem;
use App\Services\Pdf\MyCustomTCPDF;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    /**
     * Display a listing of the sales.
     */
    public function index(Request $request)
    {
        $query = Sale::with(['client:id,name', 'user:id,name']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('client', fn($clientQuery) => $clientQuery->where('name', 'like', "%{$search}%"));
            });
        }
        if ($status = $request->input('status')) {
            if (in_array($status, ['completed', 'pending', 'draft', 'cancelled'])) {
            $query->where('status', $status);
            }
        }

        if ($clientId = $request->input('client_id')) {
            $query->where('client_id', $clientId);
        }
        if ($startDate = $request->input('start_date')) {
            $query->whereDate('sale_date', '>=', $startDate);
        }
        if ($endDate = $request->input('end_date')) {
            $query->whereDate('sale_date', '<=', $endDate);
        }

        $sales = $query->latest('sale_date')->latest('id')->paginate($request->input('per_page', 15));
        return SaleResource::collection($sales);
    }
    public function getReturnableItems(Sale $sale)
    {
        // $this->authorize('createReturn', $sale); // Policy check if user can create return for this sale

        // Fetch items, calculate already returned quantity for each original sale item
        $items = $sale->items()->with('product:id,name,sku')->get()->map(function ($saleItem) {
            $alreadyReturnedQty = SaleReturnItem::where('original_sale_item_id', $saleItem->id)
                ->whereHas('saleReturn', fn($q) => $q->where('status', '!=', 'cancelled'))
                ->sum('quantity_returned');
            $saleItem->max_returnable_quantity = $saleItem->quantity - $alreadyReturnedQty;
            $saleItem->age = 99;
            return $saleItem;
        })->filter(fn($item) => $item->max_returnable_quantity > 0); // Only items that can still be returned

        return SaleItemResource::collection($items); // Or a custom resource
    }

    /**
     * Store a newly created sale in storage.
     * Handles creating sale header, items (FIFO from batches), payments, and decrementing stock.
     */
    public function store(Request $request)
    {
        $validatedData = $this->validateSaleRequest($request);

        $this->performStockPreCheck($validatedData);

        $calculatedTotals = $this->calculateTotals($validatedData);

        $this->validatePaidAmount($calculatedTotals);

        try {
            $sale = DB::transaction(function () use ($validatedData, $request, $calculatedTotals) {
                $saleHeader = $this->createSaleHeader($validatedData, $request, $calculatedTotals);

                $this->processSaleItems($validatedData, $saleHeader);

                $this->createPaymentRecords($validatedData, $saleHeader, $request);

                return $saleHeader;
            });

            $sale->load([
                'client:id,name',
                'user:id,name',
                'items.product:id,name,sku',
                'items.purchaseItemBatch:id,batch_number,unit_cost',
                'payments.user:id,name'
            ]);

            return response()->json(['sale' => new SaleResource($sale)], Response::HTTP_CREATED);
        } catch (ValidationException $e) {
            Log::warning("Sale creation validation failed: " . json_encode($e->errors()));
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            Log::error("Sale creation critical error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json(['message' => 'Failed to create sale. ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function validateSaleRequest(Request $request)
    {
        return $request->validate([
            'client_id' => 'required|exists:clients,id',
            'sale_date' => 'required|date_format:Y-m-d',
            'invoice_number' => 'nullable|string|max:255|unique:sales,invoice_number',
            'status' => ['required', Rule::in(['completed', 'pending', 'draft', 'cancelled'])],
            'notes' => 'nullable|string|max:65535',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0|max:99999999.99',
            'payments' => 'present|array',
            'payments.*.method' => [
                'required_with:payments.*.amount',
                Rule::in(['cash', 'visa', 'mastercard', 'bank_transfer', 'mada', 'other', 'store_credit'])
            ],
            'payments.*.amount' => 'required_with:payments.*.method|numeric|min:0.01',
            'payments.*.payment_date' => 'required_with:payments.*.amount|date_format:Y-m-d',
            'payments.*.reference_number' => 'nullable|string|max:255',
            'payments.*.notes' => 'nullable|string|max:65535',
        ]);
    }

    private function performStockPreCheck(array $validatedData)
    {
        $stockErrors = [];
        foreach ($validatedData['items'] as $index => $itemData) {
            $product = Product::find($itemData['product_id']);
            if ($product) {
                $totalAvailableStock = $product->purchaseItems()->sum('remaining_quantity');
                if ($totalAvailableStock < $itemData['quantity']) {
                    $stockErrors["items.{$index}.quantity"] = ["الكمية الإجمالية المتوفرة للمنتج '{$product->name}' غير كافية. المتوفر: {$totalAvailableStock}، المطلوب: {$itemData['quantity']}."];
                }
            } else {
                $stockErrors["items.{$index}.product_id"] = ["Product ID {$itemData['product_id']} not found."];
            }
        }
        if (!empty($stockErrors)) {
            throw ValidationException::withMessages($stockErrors);
        }
    }

    private function calculateTotals(array $validatedData)
    {
        $calculatedTotalSaleAmount = 0;
        foreach ($validatedData['items'] as $itemData) {
            $calculatedTotalSaleAmount += ($itemData['quantity'] * $itemData['unit_price']);
        }

        $calculatedTotalPaidAmount = 0;
        if (!empty($validatedData['payments'])) {
            foreach ($validatedData['payments'] as $paymentData) {
                if (isset($paymentData['amount']) && is_numeric($paymentData['amount'])) {
                    $calculatedTotalPaidAmount += (float) $paymentData['amount'];
                }
            }
        }

        return [
            'totalSaleAmount' => $calculatedTotalSaleAmount,
            'totalPaidAmount' => $calculatedTotalPaidAmount,
        ];
    }

    private function validatePaidAmount(array $calculatedTotals)
    {
        if ($calculatedTotals['totalPaidAmount'] > $calculatedTotals['totalSaleAmount']) {
            throw ValidationException::withMessages(['payments' => ['Total paid amount cannot exceed the total sale amount.']]);
        }
    }

    private function createSaleHeader(array $validatedData, Request $request, array $calculatedTotals)
    {
        return Sale::create([
            'client_id' => $validatedData['client_id'],
            'user_id' => $request->user()->id,
            'sale_date' => $validatedData['sale_date'],
            'invoice_number' => $validatedData['invoice_number'] ?? null,
            'status' => $validatedData['status'],
            'notes' => $validatedData['notes'] ?? null,
            'total_amount' => $calculatedTotals['totalSaleAmount'],
            'paid_amount' => $calculatedTotals['totalPaidAmount'],
        ]);
    }

    private function processSaleItems(array $validatedData, Sale $saleHeader)
    {
        foreach ($validatedData['items'] as $itemData) {
            $product = Product::findOrFail($itemData['product_id']);
            // resolve(WhatsAppService::class)->sendLowStockAlert($product);

            $quantityToSellForThisItem = $itemData['quantity'];
            $unitPrice = $itemData['unit_price'];
            $quantityFulfilled = 0;

            $availableBatches = PurchaseItem::where('product_id', $product->id)
                ->where('remaining_quantity', '>', 0)
                ->orderBy('expiry_date', 'asc')
                ->orderBy('created_at', 'asc')
                ->lockForUpdate()
                ->get();

            $currentTotalStockForItem = $availableBatches->sum('remaining_quantity');
            if ($currentTotalStockForItem < $quantityToSellForThisItem) {
                throw ValidationException::withMessages([
                    "items" => ["خطأ في المعاملة: الكمية المتوفرة من المنتج '{$product->name}' غير كافية. المتوفر: {$currentTotalStockForItem}، المطلوب: {$quantityToSellForThisItem}."]
                ]);
            }

            foreach ($availableBatches as $batch) {
                if ($quantityFulfilled >= $quantityToSellForThisItem) break;

                $canSellFromBatch = min($quantityToSellForThisItem - $quantityFulfilled, $batch->remaining_quantity);

                if ($canSellFromBatch > 0) {
                    $saleHeader->items()->create([
                        'product_id' => $product->id,
                        'purchase_item_id' => $batch->id,
                        'batch_number_sold' => $batch->batch_number,
                        'quantity' => $canSellFromBatch,
                        'unit_price' => $unitPrice,
                        'total_price' => $canSellFromBatch * $unitPrice,
                    ]);

                    $batch->decrement('remaining_quantity', $canSellFromBatch);
                    // Example in SaleController@store, inside the transaction, after batch->decrement()
                    // (This assumes the Product model has been re-fetched or observer ran)
                    $productModel = $batch->product()->first(); // Get the parent Product model
                    if (
                        $productModel->stock_alert_level !== null &&
                        $productModel->calculated_total_stock <= $productModel->stock_alert_level 
                    ) {
                        resolve(WhatsAppService::class)->sendLowStockAlert($productModel);
                    }
                }
                $quantityFulfilled += $canSellFromBatch;
            }

            if ($quantityFulfilled < $quantityToSellForThisItem) {
                throw new \Exception("حدث خطأ منطقي أثناء تخصيص المخزون بطريقة الوارد أولاً يصرف أولاً (FIFO) للمنتج '{$product->name}'.");
            }
        }
    }

    private function createPaymentRecords(array $validatedData, Sale $saleHeader, Request $request)
    {
        if (!empty($validatedData['payments'])) {
            foreach ($validatedData['payments'] as $paymentData) {
                if (isset($paymentData['amount']) && is_numeric($paymentData['amount']) && (float)$paymentData['amount'] > 0) {
                    $saleHeader->payments()->create([
                        'user_id' => $request->user()->id,
                        'method' => $paymentData['method'],
                        'amount' => (float) $paymentData['amount'],
                        'payment_date' => $paymentData['payment_date'],
                        'reference_number' => $paymentData['reference_number'] ?? null,
                        'notes' => $paymentData['notes'] ?? null,
                    ]);
                }
            }
        }
    }
    /**
     * Display the specified sale.
     */
    public function show(Sale $sale)
    {
        $sale->load([
            'client:id,name,email',
            'user:id,name',
            'items',
            'items.product:id,name,sku',
            'items.purchaseItemBatch:id,batch_number,unit_cost,expiry_date', // Load batch info for each sale item
            'payments'
        ]);
        return response()->json(['sale' => new SaleResource($sale)]);
    }

    /**
     * Update the specified sale in storage.
     * SIMPLIFIED: Only allows updating header info, NOT items.
     * Full item update with stock reversal is very complex and often replaced by credit notes.
     */
    public function update(Request $request, Sale $sale)
    {
        // Prevent editing if sale is, for example, cancelled or too old
        // if ($sale->status === 'cancelled' || $sale->sale_date < Carbon::now()->subMonths(1)) {
        //     return response()->json(['message' => 'This sale cannot be updated.'], Response::HTTP_FORBIDDEN);
        // }

        $validatedData = $request->validate([
            'client_id' => 'sometimes|required|exists:clients,id',
            'sale_date' => 'sometimes|required|date_format:Y-m-d',
            'invoice_number' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('sales')->ignore($sale->id)],
            'status' => ['sometimes', 'required', Rule::in(['completed', 'pending', 'draft', 'cancelled'])],
            'paid_amount' => 'sometimes|required|numeric|min:0|max:99999999.99', // Validate against new total if items were editable
            'notes' => 'sometimes|nullable|string|max:65535',
            // 'items' validation would be here if item editing was allowed
        ]);

        // If items were editable, you'd need complex logic here to:
        // 1. Calculate current total_amount before item changes.
        // 2. Calculate stock changes (revert old items, apply new items).
        // 3. Update/delete/create items.
        // 4. Recalculate new total_amount.
        // 5. ALL WITHIN A TRANSACTION.

        // For this simplified version, we only update header fields.
        // The frontend form should also restrict item editing for completed/non-draft sales.
        if (isset($validatedData['paid_amount']) && $validatedData['paid_amount'] > $sale->total_amount) {
            // If items are not editable, total_amount doesn't change.
            // If total_amount could change, this check needs to be against the new total.
            throw ValidationException::withMessages(['paid_amount' => ['Paid amount cannot exceed the sale total.']]);
        }

        $sale->update($validatedData);

        $sale->load(['client:id,name', 'user:id,name', 'items', 'items.product:id,name,sku', 'items.purchaseItemBatch:id,batch_number,unit_cost']);
        return response()->json(['sale' => new SaleResource($sale->fresh())]);
    }


    /**
     * Remove the specified sale from storage.
     * Strongly discouraged. Implement stock reversal if allowed.
     */
    public function destroy(Sale $sale)
    {
        return response()->json(['message' => 'Deleting sales records is generally not allowed due to inventory and accounting implications. Consider cancelling the sale instead.'], Response::HTTP_FORBIDDEN);
        // If deletion with stock reversal is implemented, it would be similar to PurchaseController::destroy
        // but incrementing stock on PurchaseItem batches.
    }
    /**
     * Generate and download a PDF invoice for a specific sale.
     *
     * @param Request $request
     * @param Sale $sale (Route Model Binding)
     * @return \Illuminate\Http\Response
     */
    public function downloadInvoicePDF(Request $request, Sale $sale)
    {
        // Authorization check (e.g., can user view this sale's invoice?)
        // if ($request->user()->cannot('viewInvoice', $sale)) { // Define 'viewInvoice' in SalePolicy
        //     abort(403, 'Unauthorized to view this invoice.');
        // }

        // Eager load all necessary data for the invoice
        $sale->load([
            'client:id,name,email,phone,address', // Load more client details
            'user:id,name', // Salesperson
            'items.product:id,name,sku', // Product details for each item
            'items.purchaseItemBatch:id,batch_number', // Batch number sold from
            'payments' // Load payments made against this invoice
        ]);

        // --- Create PDF using your custom TCPDF class ---
        // P for Portrait, L for Landscape
        $pdf = new MyCustomTCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

        // --- Company & Invoice Info (from config and Sale) ---
        $companyName = config('app_settings.company_name', 'Your Company LLC');
        $companyAddress = config('app_settings.company_address', '123 Business Rd, Suite 404, City, Country');
        $companyPhone = config('app_settings.company_phone', 'N/A');
        $companyEmail = config('app_settings.company_email', 'N/A');
        $invoicePrefix = config('app_settings.invoice_prefix', 'INV-');


        // --- Set PDF Metadata ---
        $pdf->SetTitle('فاتورة مبيعات - ' . ($sale->invoice_number ?: $sale->id));
        $pdf->SetSubject('فاتورة مبيعات');
        // SetAuthor is done in MyCustomTCPDF constructor

        $pdf->AddPage();
        $pdf->setRTL(true); // Ensure RTL for the content

        // --- Invoice Header ---
        $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 16);
        $pdf->Cell(0, 12, 'فاتورة ضريبية مبسطة', 0, 1, 'C'); // Simplified Tax Invoice
        $pdf->Ln(5);

        // Company Details (Right Side in RTL)
        $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 10);
        $pdf->Cell(90, 6, $companyName, 0, 0, 'R'); // Width 90mm, align right
        $pdf->SetFont($pdf->getDefaultFontFamily(), '', 9);
        $pdf->Cell(0, 6, 'رقم الفاتورة: ' . ($sale->invoice_number ?: $invoicePrefix . $sale->id), 0, 1, 'L'); // Align left

        $pdf->SetFont($pdf->getDefaultFontFamily(), '', 9);
        $pdf->MultiCell(90, 5, $companyAddress, 0, 'R', 0, 0, '', '', true, 0, false, true, 0, 'T');
        $pdf->Cell(0, 5, 'تاريخ الفاتورة: ' . Carbon::parse($sale->sale_date)->format('Y-m-d'), 0, 1, 'L');

        $currentY = $pdf->GetY(); // Store Y after address
        $pdf->SetXY(15, $currentY); // Reset X for next line on right, use stored Y
        $pdf->Cell(90, 5, 'الهاتف: ' . $companyPhone, 0, 0, 'R');
        $pdf->SetXY(105, $currentY); // Move X for next cell on left, use stored Y
        $pdf->Cell(0, 5, 'تاريخ الاستحقاق: ' . Carbon::parse($sale->sale_date)->format('Y-m-d'), 0, 1, 'L'); // Assuming due date is sale date for now

        $currentY = $pdf->GetY();
        $pdf->SetXY(15, $currentY);
        $pdf->Cell(90, 5, 'البريد الإلكتروني: ' . $companyEmail, 0, 0, 'R');
        // Optional: VAT Number if applicable
        // $pdf->Cell(0, 5, 'الرقم الضريبي: ' . config('app_settings.vat_number', 'N/A'), 0, 1, 'L');
        $pdf->Ln(8);


        // --- Bill To (Client Details) ---
        $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 10);
        $pdf->Cell(0, 7, 'فاتورة إلى:', 0, 1, 'R'); // "Bill To:"
        $pdf->SetFont($pdf->getDefaultFontFamily(), '', 9);
        if ($sale->client) {
            $pdf->Cell(0, 5, $sale->client->name, 0, 1, 'R');
            if ($sale->client->address) {
                $pdf->MultiCell(0, 5, $sale->client->address, 0, 'R', 0, 1, '', '', true, 0, false, true, 0, 'T');
            }
            if ($sale->client->phone) {
                $pdf->Cell(0, 5, 'الهاتف: ' . $sale->client->phone, 0, 1, 'R');
            }
            if ($sale->client->email) {
                $pdf->Cell(0, 5, 'البريد الإلكتروني: ' . $sale->client->email, 0, 1, '');
            }
        } else {
            $pdf->Cell(0, 5, 'عميل نقدي / غير محدد', 0, 1, 'R');
        }
        $pdf->Ln(8);

        // --- Items Table ---
        $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 9);
        $pdf->SetFillColor(220, 220, 220); // Header fill color
        $pdf->SetTextColor(0);
        $pdf->SetDrawColor(128, 128, 128);
        $pdf->SetLineWidth(0.1);

        // Column widths for items table (adjust as needed)
        // Desc, Qty, Unit Price, Total
        $w_items = [35, 20, 30, 100];
        $header_items = ['الإجمالي', 'سعر الوحدة', 'الكمية', 'الوصف / المنتج']; // Reversed for RTL

        for ($i = 0; $i < count($header_items); ++$i) {
            $pdf->Cell($w_items[$i], 7, $header_items[$i], 1, 0, 'C', true);
        }
        $pdf->Ln();

        $pdf->SetFont($pdf->getDefaultFontFamily(), '', 8);
        $pdf->SetFillColor(245, 245, 245); // Row fill
        $fill = false;
        foreach ($sale->items as $item) {
            // Product Name & SKU & Batch
            $productDescription = $item->product?->name ?: 'منتج غير معروف';
            if ($item->product?->sku) {
                $productDescription .= "\n" . ' (SKU: ' . $item->product->sku . ')';
            }
            if ($item->batch_number_sold) {
                $productDescription .= "\n" . 'دفعة: ' . $item->batch_number_sold;
            }

            $lineHeight = $pdf->getStringHeight($w_items[3], $productDescription); // Calculate height needed for description
            $lineHeight = max(6, $lineHeight); // Minimum height of 6

            $pdf->Cell($w_items[0], $lineHeight, number_format((float)$item->total_price, 2), 'LRB', 0, 'R', $fill);
            $pdf->Cell($w_items[1], $lineHeight, number_format((float)$item->unit_price, 2), 'LRB', 0, 'R', $fill);
            $pdf->Cell($w_items[2], $lineHeight, $item->quantity, 'LRB', 0, 'C', $fill);

            $x = $pdf->GetX(); $y = $pdf->GetY(); // Store current position
            $pdf->MultiCell($w_items[3], $lineHeight, $productDescription, 'LRB', 'R', $fill, 1, $x, $y, true, 0, false, true, $lineHeight, 'M');
            // $pdf->Ln($lineHeight); // MultiCell with ln=1 already adds line break

            $fill = !$fill;
        }
        // $pdf->Cell(array_sum($w_items), 0, '', 'T'); // Top border for summary
        $pdf->Ln(0.1); // Tiny break to ensure totals are visually distinct

        // --- Totals Section ---
        $yBeforeTotals = $pdf->GetY();
        $col1Width = array_sum($w_items) - $w_items[0]; // Width for labels
        $col2Width = $w_items[0]; // Width for amounts

        $pdf->SetFont($pdf->getDefaultFontFamily(), '', 9);
        $pdf->Cell($col1Width, 6, 'المجموع الفرعي:', 'LTR', 0, 'L', false); // Subtotal Label
        $pdf->Cell($col2Width, 6, number_format((float)$sale->total_amount, 2), 'TR', 1, 'R', false); // Subtotal Value

        // Example: Discount (if you have it)
        // $pdf->Cell($col1Width, 6, 'الخصم:', 'LR', 0, 'L', false);
        // $pdf->Cell($col2Width, 6, number_format(0, 2), 'R', 1, 'R', false);

        // Example: VAT/Tax (if you have it)
        // $pdf->Cell($col1Width, 6, 'ضريبة القيمة المضافة (15%):', 'LR', 0, 'L', false);
        // $pdf->Cell($col2Width, 6, number_format($sale->total_amount * 0.15, 2), 'R', 1, 'R', false);

        $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 10);
        $pdf->SetFillColor(220, 220, 220);
        $pdf->Cell($col1Width, 7, 'الإجمالي المستحق:', 'LTRB', 0, 'L', true); // Grand Total Label
        $pdf->Cell($col2Width, 7, number_format((float)$sale->total_amount, 2), 'TRB', 1, 'R', true); // Grand Total Value

        $pdf->SetFont($pdf->getDefaultFontFamily(), '', 9);
        $pdf->Cell($col1Width, 6, 'المبلغ المدفوع:', 'LR', 0, 'L', false); // Paid Amount Label
        $pdf->Cell($col2Width, 6, number_format((float)$sale->paid_amount, 2), 'R', 1, 'R', false); // Paid Amount Value

        $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 10);
        $due = (float)$sale->total_amount - (float)$sale->paid_amount;
        $pdf->Cell($col1Width, 7, 'المبلغ المتبقي:', 'LTRB', 0, 'L', false); // Amount Due Label
        $pdf->Cell($col2Width, 7, number_format($due, 2), 'TRB', 1, 'R', false); // Amount Due Value


        // --- Payments Information ---
        if ($sale->payments && $sale->payments->count() > 0) {
            $pdf->Ln(6);
            $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 10);
            $pdf->Cell(0, 7, 'تفاصيل الدفع:', 0, 1, 'R');
            $pdf->SetFont($pdf->getDefaultFontFamily(), '', 8);
            foreach ($sale->payments as $payment) {
                $paymentText = "طريقة الدفع: " . config('app_settings.payment_methods.' . $payment->method, $payment->method); // Assuming payment_methods in config
                $paymentText .= "  |  المبلغ: " . number_format((float)$payment->amount, 2);
                $paymentText .= "  |  التاريخ: " . Carbon::parse($payment->payment_date)->format('Y-m-d');
                if($payment->reference_number) $paymentText .= "  |  مرجع: " . $payment->reference_number;
                $pdf->MultiCell(0, 5, $paymentText, 0, 'R', 0, 1);
            }
        }


        // --- Notes / Terms & Conditions ---
        if ($sale->notes) {
            $pdf->Ln(6);
            $pdf->SetFont($pdf->getDefaultFontFamily(), 'B', 9);
            $pdf->Cell(0, 6, 'ملاحظات:', 0, 1, 'R');
            $pdf->SetFont($pdf->getDefaultFontFamily(), '', 8);
            $pdf->MultiCell(0, 5, $sale->notes, 0, 'R', 0, 1);
        }
        $pdf->Ln(10);
        $pdf->SetFont($pdf->getDefaultFontFamily(), 'I', 8);
        $pdf->MultiCell(0, 5, config('app_settings.invoice_terms', 'شكراً لتعاملكم معنا. تطبق الشروط والأحكام.'), 0, 'C', 0, 1); // Example terms

        // --- Output PDF ---
        $pdfFileName = 'invoice_' . ($sale->invoice_number ?: $sale->id) . '_' . now()->format('Ymd') . '.pdf';
        $pdfContent = $pdf->Output($pdfFileName, 'S'); // 'S' returns as string

        return response($pdfContent, 200)
                     ->header('Content-Type', 'application/pdf')
                     ->header('Content-Disposition', "inline; filename=\"{$pdfFileName}\""); // inline to display in browser
                    //  ->header('Content-Disposition', "attachment; filename=\"{$pdfFileName}\""); // to force download
    }
    public function downloadThermalInvoicePDF(Request $request, Sale $sale)
    {
        // Authorization check
        // if ($request->user()->cannot('printThermalInvoice', $sale)) { abort(403); }

        $sale->load([
            'client:id,name', // Load only what's needed for receipt
            'user:id,name',
            'items.product:id,name,sku',
            // No need to load purchaseItemBatch for thermal receipt unless showing batch no.
        ]);

        // --- PDF Setup for Thermal (e.g., 80mm width) ---
        $pdf = new MyCustomTCPDF('P', 'mm', [80, 250], true, 'UTF-8', false); // Custom page size [width, height]
        // $pdf->setThermalDefaults(80, 250); // Or use your preset method

        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(4, 5, 4); // L, T, R
        $pdf->SetAutoPageBreak(TRUE, 5); // Bottom margin
        $pdf->AddPage();
        $pdf->setRTL(true); // Ensure RTL for Arabic content

        // --- Company Info (Simplified for Thermal) ---
        $companyName = config('app_settings.company_name', 'Your Company');
        $companyPhone = config('app_settings.company_phone', '');
        // $vatNumber = config('app_settings.vat_number', ''); // If applicable

        $pdf->SetFont('dejavusans', 'B', 10); // Or your preferred Arabic thermal font
        $pdf->MultiCell(0, 5, $companyName, 0, 'C', 0, 1);
        if ($companyPhone) {
            $pdf->SetFont('dejavusans', '', 8);
            $pdf->MultiCell(0, 4, 'الهاتف: ' . $companyPhone, 0, 'C', 0, 1);
        }
        // if ($vatNumber) {
        //     $pdf->MultiCell(0, 4, 'الرقم الضريبي: ' . $vatNumber, 0, 'C', 0, 1);
        // }
        $pdf->Ln(2);

        // --- Invoice Info ---
        $pdf->SetFont('dejavusans', '', 7);
        $pdf->Cell(0, 4, 'فاتورة رقم: ' . ($sale->invoice_number ?: 'S-'.$sale->id), 0, 1, 'R');
        $pdf->Cell(0, 4, 'التاريخ: ' . Carbon::parse($sale->sale_date)->format('Y/m/d') . ' ' . Carbon::parse($sale->created_at)->format('H:i'), 0, 1, 'R');
        if ($sale->client) {
            $pdf->Cell(0, 4, 'العميل: ' . $sale->client->name, 0, 1, 'R');
        }
        if ($sale->user) {
             $pdf->Cell(0, 4, 'البائع: ' . $sale->user->name, 0, 1, 'R');
        }
        $pdf->Ln(1);
        $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->getPageWidth() - $pdf->GetX(), $pdf->GetY()); // Separator
        $pdf->Ln(1);

        // --- Items Header ---
        // Text: Item | Qty | Price | Total
        // Align: R  | C   | R     | R
        $pdf->SetFont('dejavusans', 'B', 7);
        $pdf->Cell(18, 5, 'الإجمالي', 0, 0, 'R'); // Total
        $pdf->Cell(18, 5, 'السعر', 0, 0, 'R');    // Price
        $pdf->Cell(10, 5, 'كمية', 0, 0, 'C');   // Qty
        $pdf->Cell(26, 5, 'الصنف', 0, 1, 'R');    // Item Name (remaining width)
        $pdf->Ln(0.5);
        $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->getPageWidth() - $pdf->GetX(), $pdf->GetY()); // Separator
        $pdf->Ln(0.5);

        // --- Items Loop ---
        $pdf->SetFont('dejavusans', '', 7);
        foreach ($sale->items as $item) {
            $productName = $item->product?->name ?: 'Product N/A';
            // Truncate or wrap product name if too long for thermal width
            if (mb_strlen($productName) > 20) { // Example length check
                $productName = mb_substr($productName, 0, 18) . '..';
            }

            $itemTotal = number_format((float)$item->total_price, 2);
            $itemPrice = number_format((float)$item->unit_price, 2);
            $itemQty = (string)$item->quantity;

            // Using MultiCell for name to handle potential (though short) wrapping
            $currentY = $pdf->GetY();
            $pdf->Cell(18, 4, $itemTotal, 0, 0, 'R');
            $pdf->Cell(18, 4, $itemPrice, 0, 0, 'R');
            $pdf->Cell(10, 4, $itemQty, 0, 0, 'C');
            $pdf->MultiCell(26, 4, $productName, 0, 'C', 0, 1); // Set X explicitly
            $pdf->SetX(4); // Reset X for next line to start from left margin (for RTL Cell flow)
            // $pdf->Ln(0.5); // Small space between items
        }
        $pdf->Ln(0.5);
        $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->getPageWidth() - $pdf->GetX(), $pdf->GetY()); // Separator
        $pdf->Ln(1);

        // --- Totals ---
        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->Cell(46, 5, 'الإجمالي الفرعي:', 0, 0, 'R'); // Total Amount Label (spans 2 cols)
        $pdf->Cell(26, 5, number_format((float)$sale->total_amount, 2), 0, 1, 'R'); // Total Amount

        // Add Discount, Tax if applicable

        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->Cell(46, 6, 'الإجمالي النهائي:', 0, 0, 'R');
        $pdf->Cell(26, 6, number_format((float)$sale->total_amount, 2), 0, 1, 'R');

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->Cell(46, 5, 'المدفوع:', 0, 0, 'R');
        $pdf->Cell(26, 5, number_format((float)$sale->paid_amount, 2), 0, 1, 'R');

        $due = (float)$sale->total_amount - (float)$sale->paid_amount;
        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->Cell(46, 5, 'المتبقي:', 0, 0, 'R');
        $pdf->Cell(26, 5, number_format($due, 2), 0, 1, 'R');
        $pdf->Ln(1);

        // --- Payment Methods Used ---
        if ($sale->payments && $sale->payments->count() > 0) {
            $pdf->SetFont('dejavusans', 'B', 7);
            $pdf->Cell(0, 4, 'طرق الدفع:', 0, 1, 'R');
            $pdf->SetFont('dejavusans', '', 7);
            foreach($sale->payments as $payment) {
                $pdf->Cell(46, 4, config('app_settings.payment_methods_ar.' . $payment->method, $payment->method) . ':', 0, 0, 'R'); // Translate method
                $pdf->Cell(26, 4, number_format((float)$payment->amount, 2), 0, 1, 'R');
            }
            $pdf->Ln(1);
        }


        // --- Footer Message ---
        $pdf->SetFont('dejavusans', '', 7);
        $pdf->MultiCell(0, 4, config('app_settings.invoice_thermal_footer', 'شكراً لزيارتكم!'), 0, 'C', 0, 1);

        // --- Barcode/QR Code (Optional) ---
        // if ($sale->invoice_number) {
        //     $style = array(
        //         'border' => false,
        //         'padding' => 1,
        //         'fgcolor' => array(0,0,0),
        //         'bgcolor' => false, //array(255,255,255)
        //     );
        //     $pdf->Ln(2);
        //     // $pdf->write1DBarcode($sale->invoice_number, 'C128', '', '', '', 12, 0.4, $style, 'N');
        //      $pdf->write2DBarcode('SaleID:'.$sale->id . ';Invoice:'.$sale->invoice_number, 'QRCODE,M', '', '', 25, 25, $style, 'N');
        //      $pdf->Cell(0, 0, 'امسح للمزيد من التفاصيل', 0, 1, 'C');
        // }


        // --- Output PDF ---
        $pdfFileName = 'thermal_invoice_' . ($sale->invoice_number ?: $sale->id) . '.pdf';
        $pdfContent = $pdf->Output($pdfFileName, 'S'); // 'S' returns as string

        return response($pdfContent, 200)
                     ->header('Content-Type', 'application/pdf')
                     // No 'Content-Disposition: attachment' - frontend will handle display
                     ;
    }
}


// Key Changes and Considerations for Batch Tracking in store():
//     Stock Pre-Check (Optional): Added an initial check before the transaction to see if the total stock for each product is sufficient. This provides faster feedback to the user.
//     Transaction: The core logic is wrapped in DB::transaction.
//     FIFO Batch Selection:
//     Inside the loop for each item in the sale request:
//     It fetches PurchaseItem records (batches) for the given product_id that have remaining_quantity > 0.
//     It orders these batches by expiry_date (oldest first) and then created_at (oldest purchase first) to achieve FIFO.
//     It uses lockForUpdate() on these batches to prevent race conditions if multiple sales are processed concurrently for the same product.
//     It iterates through these fetched batches, fulfilling the requested quantity for the sale item from the oldest batches first.
//     Stock Check Inside Transaction: A critical stock check is performed again inside the transaction for the sum of available batches for that specific product to ensure atomicity.
//     Create SaleItem: For each portion taken from a batch, a new SaleItem record is created.
//     It now stores purchase_item_id to link back to the specific batch.
//     It stores batch_number_sold (copied from the PurchaseItem batch) for easier display on invoices/reports.
//     Decrement PurchaseItem.remaining_quantity: Instead of decrementing Product.stock_quantity directly, we now decrement remaining_quantity on the specific PurchaseItem (batch) that the stock was taken from.
//     PurchaseItemObserver: This observer (created earlier) should automatically listen for saves/updates on PurchaseItem and recalculate and save the total stock_quantity on the parent Product model. This keeps the products.stock_quantity as an accurate aggregate.
//     Error Handling: If stock is insufficient at any point within the transaction (either total for the product or from available batches), a ValidationException is thrown, which rolls back the entire transaction.
//     update() Method (Simplified):
//     The provided update() method only allows updating header information of the sale (like status, notes, client, date).
//     It explicitly DOES NOT handle item modifications (add/edit/delete items within an existing sale). This is because the logic for reversing stock from original batches and reapplying stock for new/modified items, while ensuring stock availability, is extremely complex and prone to errors.
//     For a real-world system, if item modification on completed sales is required, it's usually handled by:
//     A "Return" process (creates a new return record, increments stock).
//     Issuing a "Credit Note".
//     Then creating a new, corrected Sale.
//     Or, only allowing item edits if the sale is in a "Draft" status.
//     destroy(): Remains a forbidden action.
//     This SaleController with FIFO batch logic is significantly more complex but provides accurate stock depletion from specific batches. The frontend will need to be adapted if you want users to manually select which batch to sell from; otherwise, this FIFO logic handles it automatically on the backend.