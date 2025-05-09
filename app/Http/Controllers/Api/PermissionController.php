<?php // app/Http/Controllers/Api/PermissionController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission; // Import Permission model
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class PermissionController extends Controller
{
    use AuthorizesRequests; // Use trait

    /**
     * Display a listing of all available permissions.
     */
    public function index(Request $request)
    {
        // Authorization: Ensure only users who can manage roles/permissions can see this list
        if ($request->user()->cannot('manage-roles') && $request->user()->cannot('manage-permissions')) {
            abort(403, 'This action is unauthorized.');
        }
        // Or use a dedicated permission like 'view-permissions'

        $permissions = Permission::select(['id', 'name'])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $permissions]);
    }
}
