<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * Display a listing of all available roles.
     * Used for populating selection inputs in user forms.
     */
    public function index(Request $request)
    {
        // Optional: Add authorization check - who can see the list of roles?
        // if ($request->user()->cannot('viewAny', Role::class)) { abort(403); }

        // Fetch only id and name, order by name
        $roles = Role::select(['id', 'name'])->orderBy('name')->get();

        return response()->json(['data' => $roles]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
