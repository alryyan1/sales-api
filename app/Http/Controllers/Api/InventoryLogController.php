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
            'type' => 'nullable|string|in:purchase,sale,adjustment,requisition_issue,purchase_return,sale_return',
            'per_page' => 'nullable|integer|min:5|max:100',
            'search' => 'nullable|string|max:255', // Search on product name/sku, batch, ref
            'warehouse_id' => 'nullable|integer|exists:warehouses,id'
        ]);

        $perPage = $validated['per_page'] ?? 25;
        $startDate = isset($validated['start_date']) ? Carbon::parse($validated['start_date'])->startOfDay() : null;
        $endDate = isset($validated['end_date']) ? Carbon::parse($validated['end_date'])->endOfDay() : null;
        $productId = $validated['product_id'] ?? null;
        $type = $validated['type'] ?? null;
        $type = $validated['type'] ?? null;
        $search = $validated['search'] ?? null;
        $warehouseId = $validated['warehouse_id'] ?? null;


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
                'p.notes as reason_notes', // Use purchase notes as reason
                'w.name as warehouse_name',
                'w.id as warehouse_id'
            )
            ->join('warehouses as w', 'p.warehouse_id', '=', 'w.id')
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
                DB::raw("CONCAT('S-', s.id) as document_reference"),
                's.id as document_id',
                'u.name as user_name',
                DB::raw('NULL as reason_notes'),
                'w.name as warehouse_name',
                'w.id as warehouse_id'
            )
            ->join('warehouses as w', 's.warehouse_id', '=', 'w.id')
            ->whereNotNull('s.id'); // All sales (status column removed)

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
                'sa.notes as reason_notes',
                'w.name as warehouse_name',
                'w.id as warehouse_id'
            )
            ->join('warehouses as w', 'sa.warehouse_id', '=', 'w.id');

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
                DB::raw("CONCAT(sr.department_or_reason, ' (Requested by: ', u_req.name, ')') as reason_notes"),
                'w.name as warehouse_name',
                'w.id as warehouse_id'
            )
            ->leftJoin('purchases as p_origin', 'pi_batch.purchase_id', '=', 'p_origin.id')
            ->leftJoin('warehouses as w', 'p_origin.warehouse_id', '=', 'w.id')
            ->where('sri.status', 'issued') // Only issued items
            ->whereNotNull('sr.issue_date');

        // --- Purchase Returns (Stock Out - returned to supplier) ---
        $purchaseReturnsQuery = DB::table('purchase_return_items as pri')
            ->join('purchase_returns as pr', 'pri.purchase_return_id', '=', 'pr.id')
            ->join('products as prod', 'pri.product_id', '=', 'prod.id')
            ->join('users as u', 'pr.user_id', '=', 'u.id')
            ->leftJoin('purchases as p_orig', 'pr.purchase_id', '=', 'p_orig.id')
            ->leftJoin('purchase_items as pi_batch', function ($join) {
                $join->on('pi_batch.purchase_id', '=', 'p_orig.id')
                    ->on('pi_batch.product_id', '=', 'pri.product_id');
            })
            ->select(
                'pr.created_at as transaction_date',
                DB::raw("'purchase_return' as type"),
                'prod.id as product_id',
                'prod.name as product_name',
                'prod.sku as product_sku',
                'pi_batch.batch_number as batch_number',
                DB::raw('CAST(pri.quantity AS SIGNED) * -1 as quantity_change'), // Negative — stock returned to supplier
                DB::raw("CONCAT('PR-', pr.id) as document_reference"),
                'pr.id as document_id',
                'u.name as user_name',
                'pr.reason as reason_notes',
                'w.name as warehouse_name',
                'w.id as warehouse_id'
            )
            ->join('warehouses as w', 'pr.warehouse_id', '=', 'w.id');

        // --- Sale Returns (Stock In - returned by customer) ---
        $saleReturnsQuery = DB::table('sale_return_items as srit')
            ->join('sale_returns as sret', 'srit.sale_return_id', '=', 'sret.id')
            ->join('products as prod', 'srit.product_id', '=', 'prod.id')
            ->join('users as u', 'sret.user_id', '=', 'u.id')
            ->leftJoin('sales as s_orig', 'sret.sale_id', '=', 's_orig.id')
            ->leftJoin('warehouses as w', 's_orig.warehouse_id', '=', 'w.id')
            ->select(
                'sret.created_at as transaction_date',
                DB::raw("'sale_return' as type"),
                'prod.id as product_id',
                'prod.name as product_name',
                'prod.sku as product_sku',
                DB::raw('NULL as batch_number'), // Not tracked per-batch on sale returns
                'srit.quantity as quantity_change', // Positive — stock returned by customer
                DB::raw("CONCAT('SR-', sret.id) as document_reference"),
                'sret.id as document_id',
                'u.name as user_name',
                'sret.reason as reason_notes',
                'w.name as warehouse_name',
                'w.id as warehouse_id'
            );

        // Actual column names per query (aliases cannot be used in WHERE in MySQL)
        $dateColumns   = ['p.purchase_date', 's.sale_date', 'sa.created_at', 'sr.issue_date', 'pr.created_at', 'sret.created_at'];
        $batchColumns  = ['pi.batch_number', 'si.batch_number_sold', 'pi_batch.batch_number', 'sri.issued_batch_number', 'pi_batch.batch_number', null];
        $docRefColumns = ['p.reference_number', null, 'sa.reason', null, null, null]; // CONCAT expressions not searchable in subquery WHERE

        $queryList = [$purchasesQuery, $salesQuery, $adjustmentsQuery, $requisitionIssuesQuery, $purchaseReturnsQuery, $saleReturnsQuery];

        foreach ($queryList as $i => $query) {
            if ($startDate) {
                $query->whereDate($dateColumns[$i], '>=', $startDate);
            }
            if ($endDate) {
                $query->whereDate($dateColumns[$i], '<=', $endDate);
            }
            if ($productId) {
                $query->where('prod.id', $productId);
            }
            if ($warehouseId) {
                $query->where('w.id', $warehouseId);
            }
            if ($search) {
                $batchCol  = $batchColumns[$i];
                $docRefCol = $docRefColumns[$i];
                $query->where(function ($q) use ($search, $batchCol, $docRefCol) {
                    $q->where('prod.name', 'like', "%{$search}%")
                      ->orWhere('prod.sku', 'like', "%{$search}%");
                    if ($batchCol) {
                        $q->orWhere($batchCol, 'like', "%{$search}%");
                    }
                    if ($docRefCol) {
                        $q->orWhere($docRefCol, 'like', "%{$search}%");
                    }
                });
            }
        }

        // Apply type filter after preparing individual queries
        if ($type) {
            switch ($type) {
                case 'purchase':
                    $query = $purchasesQuery;
                    break;
                case 'sale':
                    $query = $salesQuery;
                    break;
                case 'adjustment':
                    $query = $adjustmentsQuery;
                    break;
                case 'requisition_issue':
                    $query = $requisitionIssuesQuery;
                    break;
                case 'purchase_return':
                    $query = $purchaseReturnsQuery;
                    break;
                case 'sale_return':
                    $query = $saleReturnsQuery;
                    break;
                default: // Build union if no specific type or invalid type
                    $query = $purchasesQuery
                        ->unionAll($salesQuery)
                        ->unionAll($adjustmentsQuery)
                        ->unionAll($requisitionIssuesQuery)
                        ->unionAll($purchaseReturnsQuery)
                        ->unionAll($saleReturnsQuery);
                    break;
            }
        } else {
            // Default: Union all queries
            $query = $purchasesQuery
                ->unionAll($salesQuery)
                ->unionAll($adjustmentsQuery)
                ->unionAll($requisitionIssuesQuery)
                ->unionAll($purchaseReturnsQuery)
                ->unionAll($saleReturnsQuery);
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
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'type' => 'nullable|string|in:purchase,sale,adjustment,requisition_issue,purchase_return,sale_return',
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
