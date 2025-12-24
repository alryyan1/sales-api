<?php // app/Http/Controllers/Api/RoleController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role; // Import Custom Role model
use Spatie\Permission\Models\Permission; // Import Permission
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\RoleResource; // Create this resource
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class RoleController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the roles.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Role::class); // Assumes RolePolicy exists or checks manage-roles

        // Eager load permission count or specific permissions if needed for list display
        $roles = Role::with('permissions')->withCount(['permissions', 'users'])->orderBy('name')->paginate($request->input('per_page', 20));

        // Use a RoleResource to format output
        return RoleResource::collection($roles);
    }

    /**
     * Store a newly created role in storage.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Role::class);

        $validatedData = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')],
            'permissions' => 'present|array', // Permissions array must be present, can be empty
            'permissions.*' => ['string', Rule::exists('permissions', 'name')], // Each item must be an existing permission name
        ]);

        try {
            $role = DB::transaction(function () use ($validatedData) {
                $role = Role::create(['name' => $validatedData['name'], 'guard_name' => 'web']); // Create role first
                if (!empty($validatedData['permissions'])) {
                    $role->syncPermissions($validatedData['permissions']); // Assign permissions
                }
                return $role;
            });

            $role->load('permissions:id,name'); // Load for response
            return response()->json(['role' => new RoleResource($role)], Response::HTTP_CREATED);
        } catch (\Throwable $e) {
            Log::error("Role creation failed: " . $e->getMessage());
            return response()->json(['message' => 'Failed to create role.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified role (including its permissions).
     */
    public function show(Role $role) // Route model binding
    {
        $this->authorize('view', $role);

        $role->load('permissions:id,name'); // Eager load permissions
        return new RoleResource($role);
    }

    /**
     * Update the specified role in storage.
     */
    public function update(Request $request, Role $role)
    {
        $this->authorize('update', $role);

        // Prevent editing critical roles maybe?
        if (in_array($role->name, ['admin'])) { // Example: Protect 'admin' role
            return response()->json(['message' => "Cannot modify the '{$role->name}' role."], Response::HTTP_FORBIDDEN);
        }


        $validatedData = $request->validate([
            // Don't usually allow changing role name, or be careful if you do.
            // 'name' => ['sometimes','required', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($role->id)],
            'permissions' => 'required|array', // Always require permissions array on update
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        try {
            DB::transaction(function () use ($validatedData, $role) {
                // Sync permissions - this removes old ones and adds new ones
                $role->syncPermissions($validatedData['permissions']);
                // If allowing name change: $role->update(Arr::only($validatedData, ['name']));
            });

            $role->load('permissions:id,name');
            return response()->json(['role' => new RoleResource($role->fresh())]);
        } catch (\Throwable $e) {
            Log::error("Role update failed for ID {$role->id}: " . $e->getMessage());
            return response()->json(['message' => 'Failed to update role.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified role from storage.
     * CAUTION: Need to handle users assigned to this role.
     */
    public function destroy(Role $role)
    {
        $this->authorize('delete', $role);

        // Prevent deleting critical roles
        if (in_array($role->name, ['admin'])) { // Example
            return response()->json(['message' => "Cannot delete the '{$role->name}' role."], Response::HTTP_FORBIDDEN);
        }

        // Check if users are assigned this role
        if ($role->users()->count() > 0) {
            return response()->json(['message' => "Cannot delete role. {$role->users()->count()} user(s) are currently assigned this role."], Response::HTTP_CONFLICT); // 409 Conflict
        }

        try {
            $role->delete();
            return response()->json(['message' => 'Role deleted successfully.'], Response::HTTP_OK);
            // return response()->noContent(); // 204
        } catch (\Throwable $e) {
            Log::error("Role deletion failed for ID {$role->id}: " . $e->getMessage());
            return response()->json(['message' => 'Failed to delete role.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
