<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Supplier; // Use Supplier model
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Resources\SupplierResource; // Use SupplierResource
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use App\Services\FirebaseService;

class SupplierController extends Controller
{
    /**
     * @OA\Tag(
     *     name="Suppliers",
     *     description="API Endpoints for managing Suppliers"
     * )
     */
    /**
     * @OA\Get(
     *     path="/api/suppliers",
     *     summary="List all suppliers",
     *     description="Retrieve a paginated list of suppliers with optional search.",
     *     tags={"Suppliers"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search by name, email, or contact person",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Supplier")),
     *             @OA\Property(property="links", type="object"),
     *             @OA\Property(property="meta", type="object")
     *         )
     *     )
     * )
     */
    public function index(Request $request)
    {
        // Add basic search capability (example)
        $query = Supplier::query();

        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('contact_person', 'like', "%{$search}%");
        }

        $suppliers = $query->latest()->paginate($request->input('per_page', 15));

        return $suppliers;
    }

    /**
     * @OA\Post(
     *     path="/api/suppliers",
     *     summary="Create a new supplier",
     *     description="Store a newly created supplier in storage.",
     *     tags={"Suppliers"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name"},
     *             @OA\Property(property="name", type="string", example="ABC Supplies"),
     *             @OA\Property(property="contact_person", type="string", example="John Doe"),
     *             @OA\Property(property="email", type="string", format="email", example="contact@abc.com"),
     *             @OA\Property(property="phone", type="string", example="1234567890"),
     *             @OA\Property(property="address", type="string", example="123 Main St")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Supplier created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="supplier", ref="#/components/schemas/Supplier")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|string|email|max:255|unique:suppliers,email',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:65535',
            'is_client' => 'sometimes|boolean',
        ]);

        $isClient = $request->boolean('is_client');
        $supplierData = array_diff_key($validated, ['is_client' => null]);

        $supplier = DB::transaction(function () use ($isClient, $supplierData) {
            $supplier = Supplier::create($supplierData);

            if ($isClient) {
                $emailInUse = $supplier->email && Client::where('email', $supplier->email)->exists();
                $client = Client::create([
                    'name'    => $supplier->name,
                    'email'   => $emailInUse ? null : $supplier->email,
                    'phone'   => $supplier->phone,
                    'address' => $supplier->address,
                ]);
                $supplier->update(['client_id' => $client->id]);
            }

            return $supplier->fresh();
        });

        return response()->json(['supplier' => new SupplierResource($supplier)], Response::HTTP_CREATED);
    }

    /**
     * @OA\Get(
     *     path="/api/suppliers/{supplier}",
     *     summary="Get supplier details",
     *     description="Display the specified supplier.",
     *     tags={"Suppliers"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="supplier",
     *         in="path",
     *         description="Supplier ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             @OA\Property(property="supplier", ref="#/components/schemas/Supplier")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Supplier not found"
     *     )
     * )
     */
    public function show(Supplier $supplier) // Route model binding
    {
        // Optionally load relationships: $supplier->load('purchases');
        return response()->json(['supplier' => new SupplierResource($supplier)]);
    }

    /**
     * @OA\Put(
     *     path="/api/suppliers/{supplier}",
     *     summary="Update supplier details",
     *     description="Update the specified supplier in storage.",
     *     tags={"Suppliers"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="supplier",
     *         in="path",
     *         description="Supplier ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="ABC Supplies Updated"),
     *             @OA\Property(property="contact_person", type="string"),
     *             @OA\Property(property="email", type="string", format="email"),
     *             @OA\Property(property="phone", type="string"),
     *             @OA\Property(property="address", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Supplier updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="supplier", ref="#/components/schemas/Supplier")
     *         )
     *     )
     * )
     */
    public function update(Request $request, Supplier $supplier) // Route model binding
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'contact_person' => 'sometimes|nullable|string|max:255',
            'email' => [
                'sometimes',
                'nullable',
                'string',
                'email',
                'max:255',
                Rule::unique('suppliers')->ignore($supplier->id),
            ],
            'phone' => 'sometimes|nullable|string|max:30',
            'address' => 'sometimes|nullable|string|max:65535',
            'is_client' => 'sometimes|boolean',
        ]);

        $wantsClient = $request->boolean('is_client');
        $supplierData = array_diff_key($validated, ['is_client' => null]);

        $supplier = DB::transaction(function () use ($wantsClient, $supplierData, $supplier) {
            $supplier->update($supplierData);
            $supplier->refresh();

            if ($wantsClient && !$supplier->client_id) {
                $emailInUse = $supplier->email && Client::where('email', $supplier->email)->exists();
                $client = Client::create([
                    'name'    => $supplier->name,
                    'email'   => $emailInUse ? null : $supplier->email,
                    'phone'   => $supplier->phone,
                    'address' => $supplier->address,
                ]);
                $supplier->update(['client_id' => $client->id]);

            } elseif (!$wantsClient && $supplier->client_id) {
                $supplier->update(['client_id' => null]);

            } elseif ($supplier->client_id) {
                $supplier->client()->update([
                    'name'    => $supplier->name,
                    'email'   => $supplier->email,
                    'phone'   => $supplier->phone,
                    'address' => $supplier->address,
                ]);
            }

            return $supplier->fresh();
        });

        return response()->json(['supplier' => new SupplierResource($supplier)]);
    }

    /**
     * @OA\Delete(
     *     path="/api/suppliers/{supplier}",
     *     summary="Delete a supplier",
     *     description="Remove the specified supplier from storage.",
     *     tags={"Suppliers"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="supplier",
     *         in="path",
     *         description="Supplier ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Supplier deleted successfully"
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="Conflict (e.g. existing purchases)"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server Error"
     *     )
     * )
     */
    public function destroy(Supplier $supplier) // Route model binding
    {
        // Add authorization checks if necessary (e.g., using Policies)

        try {
            // Add check if supplier has related records (e.g., purchases) before deleting if needed
            // if ($supplier->purchases()->exists()) {
            //     return response()->json(['message' => 'Cannot delete supplier with existing purchases.'], Response::HTTP_CONFLICT); // 409 Conflict
            // }

            $supplier->delete();
            return response()->json(['message' => 'Supplier deleted successfully.'], Response::HTTP_OK);
            // Or return response()->noContent(); // 204 No Content

        } catch (\Exception $e) {
            // Log the error
            Log::error('Error deleting supplier: ' . $supplier->id . '. Error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to delete supplier.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get summary of all suppliers with their debit, credit, and balance.
     * 
     * @OA\Get(
     *     path="/api/suppliers/summary",
     *     summary="Get suppliers summary",
     *     description="Retrieve all suppliers with their total debit (purchases), credit (payments), and balance.",
     *     tags={"Suppliers"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="ABC Supplies"),
     *                 @OA\Property(property="total_debit", type="number", format="float", example=5000.00),
     *                 @OA\Property(property="total_credit", type="number", format="float", example=3000.00),
     *                 @OA\Property(property="balance", type="number", format="float", example=2000.00)
     *             )
     *         )
     *     )
     * )
     */
    public function summary()
    {
        try {
            $suppliers = Supplier::with(['purchases', 'payments'])->get();

            $summary = $suppliers->map(function ($supplier) {
                $totalDebit = $supplier->purchases->sum('total_amount');
                $totalCredit = $supplier->payments->sum('amount');
                $balance = $totalDebit - $totalCredit;

                return [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                    'total_debit' => (float) $totalDebit,
                    'total_credit' => (float) $totalCredit,
                    'balance' => (float) $balance,
                ];
            });

            return response()->json($summary->values()->all());
        } catch (\Exception $e) {
            Log::error('Error fetching suppliers summary: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to retrieve suppliers summary',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Sync all suppliers (with financial balances) to Firestore.
     *
     * POST /api/suppliers/sync-to-firestore
     *
     * Optional body param:
     *   collection_name  — overrides the firebase_collection_name setting
     */
    public function syncToFirestore(Request $request)
    {
        $projectId = config('firebase.project_id');
        if (!$projectId) {
            return response()->json(['message' => 'Firebase project ID not configured.'], 500);
        }

        $accessToken = FirebaseService::getAccessToken();
        if (!$accessToken) {
            return response()->json(['message' => 'Failed to obtain Firebase access token.'], 500);
        }

        $collectionName = $request->input('collection_name');
        if (!$collectionName) {
            $settings = (new \App\Services\SettingsService())->getAll();
            $collectionName = $settings['firebase_collection_name'] ?? 'none';
        }

        // Load suppliers with purchases and payments for balance calculation
        $suppliers = Supplier::with(['purchases', 'payments'])->get();

        $syncedCount = 0;
        $batchSize   = 450;
        $now         = now()->toIso8601String();

        foreach ($suppliers->chunk($batchSize) as $chunk) {
            $writes = [];

            foreach ($chunk as $supplier) {
                $totalDebit  = (float) $supplier->purchases->sum('total_amount');
                $totalCredit = (float) $supplier->payments->sum('amount');
                $balance     = $totalDebit - $totalCredit;

                $docPath = "projects/{$projectId}/databases/(default)/documents/pharmacies/{$collectionName}/suppliers/{$supplier->id}";

                $writes[] = [
                    'update' => [
                        'name'   => $docPath,
                        'fields' => [
                            'id'             => ['integerValue' => (string) $supplier->id],
                            'name'           => ['stringValue'  => (string) ($supplier->name ?? '')],
                            'contact_person' => ['stringValue'  => (string) ($supplier->contact_person ?? '')],
                            'phone'          => ['stringValue'  => (string) ($supplier->phone ?? '')],
                            'email'          => ['stringValue'  => (string) ($supplier->email ?? '')],
                            'address'        => ['stringValue'  => (string) ($supplier->address ?? '')],
                            'total_debit'    => ['doubleValue'  => $totalDebit],
                            'total_credit'   => ['doubleValue'  => $totalCredit],
                            'balance'        => ['doubleValue'  => $balance],
                            'synced_at'      => ['timestampValue' => $now],
                        ],
                    ],
                ];
            }

            if (empty($writes)) {
                continue;
            }

            $commitUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents:commit";
            $response  = Http::withToken($accessToken)->post($commitUrl, ['writes' => $writes]);

            if (!$response->successful()) {
                Log::error('SupplierController@syncToFirestore: Firestore batch write failed.', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return response()->json([
                    'message'       => 'Firestore batch write failed.',
                    'synced_so_far' => $syncedCount,
                    'error'         => $response->json(),
                ], 502);
            }

            $syncedCount += count($writes);
        }

        Log::info("SupplierController@syncToFirestore: synced {$syncedCount} suppliers to Firestore collection '{$collectionName}'.");

        return response()->json([
            'message'         => "تمت مزامنة {$syncedCount} مورد بنجاح.",
            'synced_count'    => $syncedCount,
            'collection_name' => $collectionName,
        ]);
    }
}
