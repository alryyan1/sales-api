<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Resources\ClientResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule; 
use PharIo\Manifest\Author;// Import Rule for unique validation on update

class ClientController extends Controller
{
    use AuthorizesRequests;
    /**
     * Display a listing of the resource.
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
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
     * Store a newly created resource in storage.
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
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
     * Display the specified resource.
     * @param \App\Models\Client $client (Route model binding)
     * @return \Illuminate\Http\JsonResponse
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
     * Update the specified resource in storage.
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Client $client (Route model binding)
     * @return \Illuminate\Http\JsonResponse
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
     * Remove the specified resource from storage.
     * @param \App\Models\Client $client (Route model binding)
     * @return \Illuminate\Http\JsonResponse
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