<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Resources\ClientResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use PharIo\Manifest\Author; // Import Rule for unique validation on update

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
    public function index(Request $request) // Added Request for potential filtering/searching later
    {

        // Basic pagination
        $clients = Client::latest()->paginate($request->input('per_page', 15)); // Default 15 per page

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
        $this->authorize('create', Client::class); // Checks ClientPolicy@create

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
        $this->authorize('view', $client); // Checks ClientPolicy@view

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
        $this->authorize('update', $client); // Checks ClientPolicy@update

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
        $this->authorize('delete', $client); // Checks ClientPolicy@delete

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
}
