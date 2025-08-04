<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User; // Use User model
use App\Http\Resources\UserResource; // Use UserResource
use Arr;
use DB;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role; // Import Role model for validation
use Illuminate\Foundation\Auth\Access\AuthorizesRequests; // For policy checks

class UserController extends Controller
{
    use AuthorizesRequests; // Use trait for $this->authorize()

    /**
     * Display a listing of the users.
     */
    public function index(Request $request)
    {
        // Check authorization using UserPolicy@viewAny
        $this->authorize('viewAny', User::class);

        $query = User::with('roles:id,name'); // Eager load roles (only id and name)

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by role (optional)
        if ($roleName = $request->input('role')) {
            $query->whereHas('roles', function ($q) use ($roleName) {
                $q->where('name', $roleName);
            });
        }

        $users = $query->orderBy('name')->paginate($request->input('per_page', 15));

        return UserResource::collection($users);
    }

    /**
     * Store a newly created user in storage.
     */
    public function store(Request $request)
    {
        // Check authorization using UserPolicy@create
        $this->authorize('create', User::class);

        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            'roles' => 'required|array', // Roles are required on creation
            'roles.*' => ['required', 'string', Rule::exists('roles', 'name')], // Validate each role name exists
        ]);

        try {
            $user = DB::transaction(function () use ($validatedData) {
                // Create user
                $user = User::create([
                    'name' => $validatedData['name'],
                    'email' => $validatedData['email'],
                    'password' => Hash::make($validatedData['password']),
                    'email_verified_at' => now(), // Optionally verify immediately
                ]);



                // Assign roles
                $user->assignRole($validatedData['roles']);

                return $user;
            });

            $user->load('roles:id,name'); // Load roles for the response resource
            return response()->json(['user' => new UserResource($user)], Response::HTTP_CREATED);
        } catch (\Throwable $e) {
            Log::error("User creation failed: " . $e->getMessage());
            return response()->json(['message' => 'Failed to create user.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified user.
     */
    public function show(User $user) // Route model binding
    {
        // Check authorization using UserPolicy@view
        $this->authorize('view', $user);

        $user->load('roles:id,name', 'permissions:id,name'); // Load roles and permissions for detail view
        return new UserResource($user);
    }

    /**
     * Update the specified user in storage.
     * Handles name, email, and role updates. Does NOT handle password changes.
     */
    public function update(Request $request, User $user)
    {
        // Check authorization using UserPolicy@update
        $this->authorize('update', $user);

        $validatedData = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id), // Ignore self for unique check
            ],
            'roles' => 'sometimes|required|array', // Roles required if present
            'roles.*' => ['required', 'string', Rule::exists('roles', 'name')],
        ]);

        try {
            $user = DB::transaction(function () use ($validatedData, $user, $request) {
                $emailChanged = isset($validatedData['email']) && $user->email !== $validatedData['email'];

                // Update basic fields
                $user->fill(Arr::only($validatedData, ['name', 'email']));

                if ($emailChanged) {
                    $user->email_verified_at = null; // Mark as unverified if email changes
                }
                // Prevent assigning roles if 'admin' is missing, but only for user with ID 1
                if ($user->id === 1 && !in_array('admin', $validatedData['roles'])) {
                    throw new \Exception('The "admin" role cannot be removed for this user.');
                }
                // Update roles if provided
                if ($request->has('roles')) {
                    // syncRoles removes old roles and adds new ones
                    $user->syncRoles($validatedData['roles']);
                }

                $user->save();
                return $user;
            });

            $user->load('roles:id,name'); // Load roles for response
            return response()->json(['user' => new UserResource($user->fresh())]);
        } catch (\Throwable $e) {
            Log::error("User update failed for ID {$user->id}: " . $e->getMessage());
            return response()->json(['message' => 'Failed to update user.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy(Request $request, User $user) // Added Request for authenticated user check
    {
        // Check authorization using UserPolicy@delete
        $this->authorize('delete', $user);

        // Prevent user from deleting themselves
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], Response::HTTP_FORBIDDEN);
        }

        try {
            // Detach roles/permissions before deleting (optional, handled by package config usually)
            // $user->syncRoles([]);
            // $user->syncPermissions([]);

            $user->delete(); // Use soft deletes if enabled on User model

            return response()->json(['message' => 'User deleted successfully.'], Response::HTTP_OK);
            // Or return response()->noContent(); // 204

        } catch (\Throwable $e) {
            Log::error("User deletion failed for ID {$user->id}: " . $e->getMessage());
            return response()->json(['message' => 'Failed to delete user.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get a simple list of users for filters (no admin required)
     */
    public function listForFilters(Request $request)
    {
        $users = User::select('id', 'name')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $users
        ]);
    }
}
