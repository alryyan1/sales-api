<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use App\Models\DeviceSession;
use App\Http\Resources\UserResource;

/**
 * @OA\Tag(
 *     name="Authentication",
 *     description="API endpoints for user authentication"
 * )
 */
class AuthController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/register",
     *     summary="Register a new user",
     *     description="Register a new user account and receive an authentication token",
     *     operationId="register",
     *     tags={"Authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "username", "password", "password_confirmation"},
     *             @OA\Property(property="name", type="string", example="John Doe", description="User's full name"),
     *             @OA\Property(property="username", type="string", example="johndoe", description="Unique username"),
     *             @OA\Property(property="password", type="string", format="password", example="password123", description="Password (min 8 characters)"),
     *             @OA\Property(property="password_confirmation", type="string", format="password", example="password123", description="Password confirmation")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="User registered successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="User registered successfully."),
     *             @OA\Property(property="user", type="object"),
     *             @OA\Property(property="access_token", type="string", example="1|xxxxxxxxxxxx"),
     *             @OA\Property(property="token_type", type="string", example="Bearer"),
     *             @OA\Property(property="roles", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="permissions", type="array", @OA\Items(type="string"))
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
    public function register(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $request->name,
            'username' => $request->username,
            'password' => Hash::make($request->password),
        ]);

        // Create a token for the new user
        // You can provide a name for the token (e.g., 'auth_token', 'device_name')
        $token = $user->createToken('auth_token')->plainTextToken;
        // Load relationships for UserResource
        $user->load('roles:id,name', 'permissions:id,name', 'warehouse:id,name');
        // Get role names
        $roles = $user->getRoleNames();
        return response()->json([
            'message' => 'User registered successfully.',
            'user' => new UserResource($user),
            'access_token' => $token, // Return the token
            'token_type' => 'Bearer',
            'roles' => $roles,
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ], 201);
    }

    /**
     * @OA\Post(
     *     path="/api/login",
     *     summary="Login user",
     *     description="Authenticate user with username and password, receive an authentication token",
     *     operationId="login",
     *     tags={"Authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"username", "password"},
     *             @OA\Property(property="username", type="string", example="admin", description="User's username"),
     *             @OA\Property(property="password", type="string", format="password", example="password123", description="User's password")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Login successful",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Logged in successfully."),
     *             @OA\Property(property="user", type="object"),
     *             @OA\Property(property="access_token", type="string", example="1|xxxxxxxxxxxx"),
     *             @OA\Property(property="token_type", type="string", example="Bearer"),
     *             @OA\Property(property="roles", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Invalid credentials",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The provided credentials are incorrect."),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */
    public function login(Request $request)
    {
        $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            // Client-generated id persisted in localStorage, identifying "this
            // browser". Optional so older/other clients (e.g. a mobile app) that
            // don't send it simply skip the same-device check below.
            'device_id' => ['nullable', 'string'],
        ]);

        // Attempt to find the user by username
        $user = User::where('username', $request->username)->first();

        // Check if user exists and password is correct
        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'username' => [__('auth.failed')],
            ]);
        }

        // Refuse to log a different user in on a device that already has another
        // user actively logged in — that user must log out first. A device is
        // considered "free" if there's no recorded session for it, it already
        // belongs to this same user, or its recorded user has no active tokens
        // left (a stale record from a session that ended without a clean logout).
        $deviceSession = null;
        if ($request->filled('device_id')) {
            $deviceSession = DeviceSession::firstOrNew(['device_id' => $request->device_id]);

            if ($deviceSession->exists && $deviceSession->user_id && $deviceSession->user_id !== $user->id) {
                $otherUser = User::find($deviceSession->user_id);
                if ($otherUser && $otherUser->tokens()->exists()) {
                    return response()->json([
                        'message' => "يوجد مستخدم آخر ({$otherUser->name}) مسجّل دخول بالفعل على هذا الجهاز. يجب تسجيل الخروج أولاً قبل تسجيل دخول مستخدم مختلف.",
                    ], 409);
                }
            }
        }

        // Load relationships for UserResource
        $user->load('roles:id,name', 'permissions:id,name', 'warehouse:id,name');

        // Get role names as array
        $roles = $user->getRoleNames()->toArray();

        // --- Remove previous tokens if you want only one active token per user ---
        // $user->tokens()->delete(); // Optional: Invalidate all old tokens

        // Create a new token for the authenticated user
        $token = $user->createToken('auth_token')->plainTextToken;

        if ($deviceSession) {
            $deviceSession->user_id = $user->id;
            $deviceSession->save();
        }

        return response()->json([
            'message' => 'Logged in successfully.',
            'user' => new UserResource($user),
            'access_token' => $token, // Return the token
            'token_type' => 'Bearer',
            'roles' => $roles,
            'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/user",
     *     summary="Get authenticated user",
     *     description="Get the currently authenticated user's information",
     *     operationId="getUser",
     *     tags={"Authentication"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="User information retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer", example=1),
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="username", type="string", example="johndoe"),
     *             @OA\Property(property="created_at", type="string", format="date-time"),
     *             @OA\Property(property="updated_at", type="string", format="date-time"),
     *             @OA\Property(property="roles", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthenticated",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Unauthenticated.")
     *         )
     *     )
     * )
     */
    public function user(Request $request)
    {
        $user = $request->user();
        // Load relationships for UserResource
        $user->load('roles:id,name', 'permissions:id,name', 'warehouse:id,name');
        // Get role names as array (same as login)
        $roles = $user->getRoleNames()->toArray();

        // Return same structure as login endpoint
        return response()->json([
            'user' => new UserResource($user),
            'roles' => $roles,
            'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/logout",
     *     summary="Logout user",
     *     description="Revoke the current authentication token",
     *     operationId="logout",
     *     tags={"Authentication"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Logout successful",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Logged out successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthenticated",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Unauthenticated.")
     *         )
     *     )
     * )
     */
    public function logout(Request $request)
    {
        // Free up this device's session record (only if it's still this same
        // user's — avoids one device's logout clobbering another device that
        // happens to reuse the same id, though that shouldn't normally happen).
        $deviceId = $request->input('device_id');
        if ($deviceId) {
            DeviceSession::where('device_id', $deviceId)
                ->where('user_id', $request->user()->id)
                ->update(['user_id' => null]);
        }

        // Revoke the token that was used to authenticate the current request
        $request->user()->currentAccessToken()->delete();

        // Or revoke all tokens for the user:
        // $request->user()->tokens()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }
}
