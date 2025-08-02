<?php // app/Http/Controllers/Api/InventoryLogController.php (New Controller)

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use App\Http\Resources\InventoryLogEntryResource; // Create this
use App\Services\InventoryLogPdfService;

class InventoryLogController extends Controller
{
    public function index(Request $request)
    {
        // $this->authorize('view-inventory-log'); // Permission check

        $validated = $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'product_id' => 'nullable|integer|exists:products,id',
            'type' => 'nullable|string|in:purchase,sale,adjustment,requisition_issue',
            'per_page' => 'nullable|integer|min:5|max:100',
            'search' => 'nullable|string|max:255' // Search on product name/sku, batch, ref
        ]);

        $perPage = $validated['per_page'] ?? 25;
        $startDate = isset($validated['start_date']) ? Carbon::parse($validated['start_date'])->startOfDay() : null;
        $endDate = isset($validated['end_date']) ? Carbon::parse($validated['end_date'])->endOfDay() : null;
        $productId = $validated['product_id'] ?? null;
        $type = $validated['type'] ?? null;
        $search = $validated['search'] ?? null;


        // --- Purchase Items (Stock In) ---
        $purchasesQuery = DB::table('purchase_items as pi')
            ->join('purchases as p', 'pi.purchase_id', '=', 'p.id')
            ->join('products as prod', 'pi.product_id', '=', 'prod.id')
            ->join('users as u', 'p.user_id', '=', 'u.id') // Assuming purchase has user_id
            ->select(
                'p.purchase_date as transaction_date',
                DB::raw("'purchase' as type"),
                'prod.id as product_id',
                'prod.name as product_name',
                'prod.sku as product_sku',
                'pi.batch_number',
                'pi.quantity as quantity_change', // Positive for purchases
                'p.reference_number as document_reference',
                'p.id as document_id',
                'u.name as user_name',
                'p.notes as reason_notes' // Use purchase notes as reason
            )
            ->where('p.status', 'received'); // Only count received purchases

        // --- Sale Items (Stock Out) ---
        $salesQuery = DB::table('sale_items as si')
            ->join('sales as s', 'si.sale_id', '=', 's.id')
            ->join('products as prod', 'si.product_id', '=', 'prod.id')
            ->join('users as u', 's.user_id', '=', 'u.id')
            ->leftJoin('purchase_items as pi_batch', 'si.purchase_item_id', '=', 'pi_batch.id') // Batch sold from
            ->select(
                's.sale_date as transaction_date',
                DB::raw("'sale' as type"),
                'prod.id as product_id',
                'prod.name as product_name',
                'prod.sku as product_sku',
                'si.batch_number_sold as batch_number', // Or pi_batch.batch_number
                DB::raw('si.quantity * -1 as quantity_change'), // Negative for sales
                's.invoice_number as document_reference',
                's.id as document_id',
                'u.name as user_name',
                's.notes as reason_notes'
            )
            ->whereIn('s.status', ['completed', 'pending']); // Count completed/pending sales

        // --- Stock Adjustments ---
        $adjustmentsQuery = DB::table('stock_adjustments as sa')
            ->join('products as prod', 'sa.product_id', '=', 'prod.id')
            ->join('users as u', 'sa.user_id', '=', 'u.id')
            ->leftJoin('purchase_items as pi_batch', 'sa.purchase_item_id', '=', 'pi_batch.id') // If adjusting specific batch
            ->select(
                'sa.created_at as transaction_date', // Use created_at of adjustment
                DB::raw("'adjustment' as type"),
                'prod.id as product_id',
                'prod.name as product_name',
                'prod.sku as product_sku',
                'pi_batch.batch_number as batch_number', // Batch adjusted, if any
                'sa.quantity_change', // Already positive or negative
                'sa.reason as document_reference', // Use reason as reference
                'sa.id as document_id',
                'u.name as user_name',
                'sa.notes as reason_notes'
            );

        // --- Stock Requisition Issues ---
        $requisitionIssuesQuery = DB::table('stock_requisition_items as sri')
            ->join('stock_requisitions as sr', 'sri.stock_requisition_id', '=', 'sr.id')
            ->join('products as prod', 'sri.product_id', '=', 'prod.id')
            ->join('users as u_req', 'sr.requester_user_id', '=', 'u_req.id') // Requester
            ->leftJoin('users as u_app', 'sr.approved_by_user_id', '=', 'u_app.id') // Approver/Issuer
            ->leftJoin('purchase_items as pi_batch', 'sri.issued_from_purchase_item_id', '=', 'pi_batch.id')
            ->select(
                'sr.issue_date as transaction_date',
                DB::raw("'requisition_issue' as type"),
                'prod.id as product_id',
                'prod.name as product_name',
                'prod.sku as product_sku',
                'sri.issued_batch_number as batch_number', // Or pi_batch.batch_number
                DB::raw('sri.issued_quantity * -1 as quantity_change'), // Negative for issues
                DB::raw("CONCAT('REQ-', sr.id) as document_reference"),
                'sr.id as document_id',
                'u_app.name as user_name', // User who issued
                DB::raw("CONCAT(sr.department_or_reason, ' (Requested by: ', u_req.name, ')') as reason_notes")
            )
            ->where('sri.status', 'issued') // Only issued items
            ->whereNotNull('sr.issue_date');


        // Apply common filters to each query before union
        foreach ([$purchasesQuery, $salesQuery, $adjustmentsQuery, $requisitionIssuesQuery] as $query) {
            if ($startDate) { $query->whereDate(DB::raw($query->grammar->wrap('transaction_date')), '>=', $startDate); }
            if ($endDate) { $query->whereDate(DB::raw($query->grammar->wrap('transaction_date')), '<=', $endDate); }
            if ($productId) { $query->where('prod.id', $productId); }
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('prod.name', 'like', "%{$search}%")
                      ->orWhere('prod.sku', 'like', "%{$search}%")
                      ->orWhere(DB::raw($q->grammar->wrap('batch_number')), 'like', "%{$search}%") // Correct way to wrap alias/raw
                      ->orWhere(DB::raw($q->grammar->wrap('document_reference')), 'like', "%{$search}%");
                });
            }
        }

        // Apply type filter after preparing individual queries
        if ($type) {
            switch ($type) {
                case 'purchase': $query = $purchasesQuery; break;
                case 'sale': $query = $salesQuery; break;
                case 'adjustment': $query = $adjustmentsQuery; break;
                case 'requisition_issue': $query = $requisitionIssuesQuery; break;
                default: // Build union if no specific type or invalid type
                    $query = $purchasesQuery
                        ->unionAll($salesQuery)
                        ->unionAll($adjustmentsQuery)
                        ->unionAll($requisitionIssuesQuery);
                    break;
            }
        } else {
            // Default: Union all queries
            $query = $purchasesQuery
                ->unionAll($salesQuery)
                ->unionAll($adjustmentsQuery)
                ->unionAll($requisitionIssuesQuery);
        }

        // Order the final combined result set
        // Note: Ordering a UNION query requires wrapping it or applying order to each subquery if possible
        // For simplicity, we order after fetching, but for large datasets, this is inefficient.
        // A more robust way is to order within a subquery or use a materialized view.
        // For pagination to work correctly with UNION, you need to order the final result set.
        // This might require creating a temporary table or a more complex subquery structure.

        // Simple ordering (less performant for large unions before pagination)
        // $results = $query->orderBy('transaction_date', 'desc')->orderBy('product_name')->get();
        // return response()->json(['data' => $results]); // If not paginating

        // For pagination on a UNION, it's more complex.
        // One common approach is to wrap the UNION in a subquery.
        $unionQuery = DB::query()->fromSub($query, 'inventory_log');
        $paginatedResults = $unionQuery->orderBy('transaction_date', 'desc')->orderBy('product_name')->paginate($perPage);

        // Format with a resource if desired (InventoryLogEntryResource would be simple)
        // return InventoryLogEntryResource::collection($paginatedResults);
        return response()->json($paginatedResults); // Return paginated data directly
    }

    public function generatePdf(Request $request)
    {
        // $this->authorize('view-inventory-log'); // Permission check

        $validated = $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'product_id' => 'nullable|integer|exists:products,id',
            'type' => 'nullable|string|in:purchase,sale,adjustment,requisition_issue',
            'search' => 'nullable|string|max:255'
        ]);

        try {
            $pdfService = new InventoryLogPdfService();
            $pdf = $pdfService->generatePdf($validated);

            return response($pdf)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="inventory-log-' . date('Y-m-d') . '.pdf"');
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to generate PDF: ' . $e->getMessage()], 500);
        }
    }
}