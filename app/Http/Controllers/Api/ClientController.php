<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Response;
use App\Http\Resources\ClientResource;
use App\Services\FirebaseService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    /**
     * @OA\Tag(
     *     name="Clients",
     *     description="Client management endpoints"
     * )
     */
    use AuthorizesRequests;
    /**
     * @OA\Get(
     *     path="/api/clients",
     *     summary="List all clients",
     *     description="Get a paginated list of clients",
     *     operationId="getClients",
     *     tags={"Clients"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Items per page",
     *         required=false,
     *         @OA\Schema(type="integer", default=15)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Clients retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="current_page", type="integer"),
     *             @OA\Property(property="per_page", type="integer"),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     )
     * )
     */
    public function index(Request $request) 
    {
        $search = $request->get('search'); // Use get() directly
        
        // Debugging: Log the search term to see if it reaches the backend
        Log::info('Client search triggered', ['search_term' => $search]);

        $query = Client::with(['sales.items', 'payments']);

        if (!empty($search)) {
            $query = $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('id', 'like', "%{$search}%");
            });
        }

        $query = $query->latest();

        // Basic pagination with financial data
        $clients = $query
            ->paginate($request->input('per_page', 15)) // Default 15 per page
            ->withQueryString()
            ->through(function ($client) {
                // Calculate total debit by summing the items of each sale and subtracting discount
                $totalDebit = $client->sales->sum(function ($sale) {
                    $itemsTotal = $sale->items->sum('total_price');
                    $discount = (float) ($sale->discount_amount ?? 0);
                    return $itemsTotal - $discount;
                });

                $totalCredit = $client->payments->sum('amount');
                $balance = $totalDebit - $totalCredit;

                // Add financial data to client object
                $client->total_debit = (float) $totalDebit;
                $client->total_credit = (float) $totalCredit;
                $client->balance = (float) $balance;

                return $client;
            });

        // Consider adding search/filtering capabilities later based on query parameters
        // e.g., if ($request->has('search')) { ... }

        return $clients;
    }

    /**
     * @OA\Post(
     *     path="/api/clients",
     *     summary="Create a new client",
     *     description="Create a new client record",
     *     operationId="createClient",
     *     tags={"Clients"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name"},
     *             @OA\Property(property="name", type="string", example="John Doe", description="Client name"),
     *             @OA\Property(property="email", type="string", format="email", nullable=true, example="john@example.com", description="Client email (unique)"),
     *             @OA\Property(property="phone", type="string", nullable=true, example="+1234567890", description="Client phone number"),
     *             @OA\Property(property="address", type="string", nullable=true, example="123 Main St", description="Client address")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Client created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="client", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */
    public function store(Request $request)
    {

        // Validation rules matching the form requirements
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            // Ensure email is unique in the 'clients' table, case-insensitive check might be good depending on DB collation
            'email' => 'nullable|string|email|max:255|unique:clients,email',
            'phone' => 'nullable|string|max:30', // Allow slightly longer phone numbers
            'address' => 'nullable|string|max:65535', // Max length for TEXT type
        ]);

        // Create the client using mass assignment (requires $fillable in Model)
        $client = Client::create($validatedData);

        // Return the newly created resource with 201 status code
        // Wrapping with 'client' key is a common practice for single resources
        return response()->json(['client' => new ClientResource($client)], Response::HTTP_CREATED);
        // Or return directly if preferred:
        // return (new ClientResource($client))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * @OA\Get(
     *     path="/api/clients/{id}",
     *     summary="Get a single client",
     *     description="Retrieve details of a specific client by ID",
     *     operationId="getClient",
     *     tags={"Clients"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Client ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Client retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="client", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Client not found"
     *     )
     * )
     */
    public function show(Client $client)
    {

        // Route model binding automatically finds the client by ID or throws 404
        // Optionally load relations: $client->load('sales');
        return response()->json(new ClientResource($client));
        // Or return directly:
        // return new ClientResource($client);
    }

    /**
     * @OA\Put(
     *     path="/api/clients/{id}",
     *     summary="Update a client",
     *     description="Update existing client details",
     *     operationId="updateClient",
     *     tags={"Clients"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Client ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="John Doe Updated"),
     *             @OA\Property(property="email", type="string", format="email", nullable=true, example="john_updated@example.com"),
     *             @OA\Property(property="phone", type="string", nullable=true, example="+0987654321"),
     *             @OA\Property(property="address", type="string", nullable=true, example="456 Other St")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Client updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="client", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Client not found"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function update(Request $request, Client $client)
    {

        // Validation rules for update
        // 'sometimes' means validate only if the field is present in the request
        $validatedData = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes', // Validate only if present
                'nullable',
                'string',
                'email',
                'max:255',
                Rule::unique('clients')->ignore($client->id), // Ignore the current client's ID when checking uniqueness
            ],
            'phone' => 'sometimes|nullable|string|max:30',
            'address' => 'sometimes|nullable|string|max:65535',
        ]);

        // Update the client
        $client->update($validatedData);

        // Return the updated resource
        return response()->json(['client' => new ClientResource($client->fresh())]); // Use fresh() to get updated timestamps etc.
        // Or return directly:
        // return new ClientResource($client->fresh());
    }

    /**
     * @OA\Delete(
     *     path="/api/clients/{id}",
     *     summary="Delete a client",
     *     description="Remove a client record",
     *     operationId="deleteClient",
     *     tags={"Clients"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Client ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Client deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Client deleted successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Client not found"
     *     )
     * )
     */
    public function destroy(Client $client)
    {
        // Optional: Add authorization check here (e.g., using Policies)
        // $this->authorize('delete', $client);

        $client->delete();

        // Return a success response (200 OK with message or 204 No Content)
        return response()->json(['message' => 'Client deleted successfully.'], Response::HTTP_OK);
        // Or:
        // return response()->noContent(); // Returns 204
    }
    /**
     * @OA\Get(
     *     path="/api/clients/autocomplete",
     *     summary="Client autocomplete",
     *     description="Get a list of clients for search/autocomplete",
     *     operationId="autocompleteClients",
     *     tags={"Clients"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search term (name or email)",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="Maximum number of results",
     *         required=false,
     *         @OA\Schema(type="integer", default=15)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Clients retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     )
     * )
     */
    public function autocomplete(Request $request)
    {
        $search = $request->input('search', '');
        $limit = $request->input('limit', 15);

        if (empty($search)) {
            return response()->json(['data' => []]); // Return empty if no search term
        }

        $clients = Client::select(['id', 'name', 'email']) // Select only needed fields
            ->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
                // Add phone search if needed
                // ->orWhere('phone', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return response()->json(['data' => $clients]);
    }

    /**
     * Sync all clients (with financial balances) to Firestore.
     *
     * POST /api/clients/sync-to-firestore
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

        // Resolve collection name: request param → settings → fallback
        $collectionName = $request->input('collection_name');
        if (!$collectionName) {
            $settings = (new \App\Services\SettingsService())->getAll();
            $collectionName = $settings['firebase_collection_name'] ?? 'none';
        }

        // Load all clients with their sales items and payments for balance calculation
        $clients = Client::with(['sales.items', 'payments'])->get();

        $syncedCount = 0;
        $batchSize   = 450; // Firestore limit is 500 writes per commit
        $now         = now()->toIso8601String();

        $chunks = $clients->chunk($batchSize);

        foreach ($chunks as $chunk) {
            $writes = [];

            foreach ($chunk as $client) {
                // Calculate financial totals (same logic as index())
                $totalDebit = $client->sales->sum(function ($sale) {
                    $itemsTotal = $sale->items->sum('total_price');
                    $discount   = (float) ($sale->discount_amount ?? 0);
                    return $itemsTotal - $discount;
                });
                $totalCredit = $client->payments->sum('amount');
                $balance     = $totalDebit - $totalCredit;

                $docPath = "projects/{$projectId}/databases/(default)/documents/pharmacies/{$collectionName}/clients/{$client->id}";

                $writes[] = [
                    'update' => [
                        'name'   => $docPath,
                        'fields' => [
                            'id'           => ['integerValue' => (string) $client->id],
                            'name'         => ['stringValue'  => (string) ($client->name ?? '')],
                            'phone'        => ['stringValue'  => (string) ($client->phone ?? '')],
                            'email'        => ['stringValue'  => (string) ($client->email ?? '')],
                            'address'      => ['stringValue'  => (string) ($client->address ?? '')],
                            'balance'      => ['doubleValue'  => (float) $balance],
                            'total_debit'  => ['doubleValue'  => (float) $totalDebit],
                            'total_credit' => ['doubleValue'  => (float) $totalCredit],
                            'synced_at'    => ['timestampValue' => $now],
                        ],
                    ],
                ];
            }

            if (empty($writes)) {
                continue;
            }

            $commitUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents:commit";

            $response = Http::withToken($accessToken)->post($commitUrl, ['writes' => $writes]);

            if (!$response->successful()) {
                Log::error('ClientController@syncToFirestore: Firestore batch write failed.', [
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

        Log::info("ClientController@syncToFirestore: synced {$syncedCount} clients to Firestore collection '{$collectionName}'.");

        return response()->json([
            'message'         => "تمت مزامنة {$syncedCount} عميل بنجاح.",
            'synced_count'    => $syncedCount,
            'collection_name' => $collectionName,
        ]);
    }
}
