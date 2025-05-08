<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Models\User;

class AuthController extends Controller
{
    /**
     * Register a new user and return a token.
     */
    public function register(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // Create a token for the new user
        // You can provide a name for the token (e.g., 'auth_token', 'device_name')
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'User registered successfully.',
            'user' => $user,
            'access_token' => $token, // Return the token
            'token_type' => 'Bearer',
        ], 201);
    }

    /**
     * Handle a login request and return a token.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Attempt to find the user by email
        $user = User::where('email', $request->email)->first();

        // Check if user exists and password is correct
        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        // --- Include permissions/roles ---
        // Option 1: Get all permission names directly assigned AND via roles
        $permissions = $user->getAllPermissions()->pluck('name');
        // Option 2: Get only role names
        $roles = $user->getRoleNames();
        // --- Remove previous tokens if you want only one active token per user ---
        // $user->tokens()->delete(); // Optional: Invalidate all old tokens

        // Create a new token for the authenticated user
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Logged in successfully.',
            'user' => $user,
            'access_token' => $token, // Return the token
            'token_type' => 'Bearer',
            'roles' => $roles,             // Send role names
            'permissions' => $permissions, // Send permission names
        ]);
    }

    /**
     * Get the authenticated user (works with token).
     */
    public function user(Request $request)
    {
        $user = $request->user();
        // --- Include permissions/roles ---
        $permissions = $user->getAllPermissions()->pluck('name');
        $roles = $user->getRoleNames();
    
        // You might want to use an API Resource for the user here
        return response()->json([
             'id' => $user->id,
             'name' => $user->name,
             'email' => $user->email,
             'email_verified_at' => $user->email_verified_at,
             'created_at' => $user->created_at,
             'updated_at' => $user->updated_at,
             'roles' => $roles,             // Send role names
             'permissions' => $permissions, // Send permission names
        ]);
        // Or return new UserResource($user->load('roles', 'permissions')); if using Resource
    }

    /**
     * Log the user out by revoking the current token.
     */
    public function logout(Request $request)
    {
        // Revoke the token that was used to authenticate the current request
        $request->user()->currentAccessToken()->delete();

        // Or revoke all tokens for the user:
        // $request->user()->tokens()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }
}
