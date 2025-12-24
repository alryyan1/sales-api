<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash; // For password hashing and checking
use Illuminate\Validation\Rule;       // For unique username validation
use Illuminate\Validation\Rules\Password; // For strong password validation rules
use Illuminate\Validation\ValidationException;
use App\Models\User; // Use User model for type hinting if not using resource
use App\Http\Resources\UserResource;

class ProfileController extends Controller
{
    /**
     * Show the authenticated user's profile information.
     * Uses the 'auth:sanctum' middleware implicitly via route definition.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse | UserResource
     * 
     */
    public function show(Request $request)
    {
        $user = $request->user(); // Get the authenticated user model instance

        // Eager load roles, permissions, and warehouse if needed in the response
        $user->load('roles:id,name', 'permissions:id,name', 'warehouse:id,name');

        // Option 1: Return specific data directly
        // return response()->json([
        //     'id' => $user->id,
        //     'name' => $user->name,
        //     'email' => $user->email,
        //     'email_verified_at' => $user->email_verified_at,
        //     'roles' => $user->getRoleNames(),
        //     'permissions' => $user->getAllPermissions()->pluck('name'),
        //     'created_at' => $user->created_at,
        // ]);

        // Option 2: Use an API Resource (Recommended)
        // Create UserResource if it doesn't exist: php artisan make:resource UserResource
        // Ensure UserResource includes roles/permissions if needed.
         return new UserResource($user);

    }

    /**
     * Update the authenticated user's profile information (name and username).
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse | UserResource
     */
    public function update(Request $request)
    {
        $user = $request->user(); // Get authenticated user

        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'username' => [
                'required',
                'string',
                'max:255',
                Rule::unique('users')->ignore($user->id), // Username must be unique, ignoring the current user
            ],
        ]);

        // Update the user model
        $user->fill($validatedData);

        $user->save();

         // Eager load for the response resource
         $user->load('roles:id,name', 'permissions:id,name', 'warehouse:id,name');

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => new UserResource($user->fresh()) // Return fresh data using resource
        ]);
    }

    /**
     * Update the authenticated user's password.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $validatedData = $request->validate([
            'current_password' => ['required', 'string', function ($attribute, $value, $fail) use ($user) {
                // Custom rule to verify current password
                if (!Hash::check($value, $user->password)) {
                    $fail('The :attribute is incorrect.'); // Or use translation key
                }
            }],
            'password' => [ // Renamed from new_password for consistency with validation rules
                'required',
                'string',
                Password::defaults(), // Use Laravel's default strong password rules
                'confirmed' // Requires 'password_confirmation' field in request
            ],
        ]);

        // Update the password
        $user->password = Hash::make($validatedData['password']);
        $user->save();

        // Optional: Invalidate other sessions/tokens for security after password change
        // Auth::logoutOtherDevices($validatedData['password']); // Requires session handling or specific token invalidation

        return response()->json(['message' => 'Password updated successfully.']);
    }
}