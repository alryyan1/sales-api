# Swagger API Documentation Setup

## Access Swagger UI

Once your Laravel server is running, access the Swagger documentation at:

**URL:** `http://localhost:8000/api/documentation`

Or if using a different port/host:
- `http://your-domain.com/api/documentation`

## Features

✅ **Interactive API Testing** - Test all endpoints directly from the browser
✅ **Authentication Support** - Bearer token authentication is configured
✅ **Complete Endpoint Documentation** - All API endpoints are documented
✅ **Request/Response Examples** - See example requests and responses

## How to Use

### 1. Access the Documentation
Navigate to `/api/documentation` in your browser

### 2. Authenticate (for protected endpoints)
1. Click the **"Authorize"** button at the top right
2. Enter your Bearer token in the format: `Bearer your-token-here`
   - Or just enter: `your-token-here` (the "Bearer " prefix is added automatically)
3. Click **"Authorize"** then **"Close"**

### 3. Test Endpoints
1. Expand any endpoint section (e.g., "Authentication")
2. Click **"Try it out"** on any endpoint
3. Fill in the required parameters
4. Click **"Execute"**
5. View the response below

## Example: Testing Login Endpoint

1. Go to **Authentication** → **POST /api/login**
2. Click **"Try it out"**
3. Enter JSON body:
   ```json
   {
     "username": "admin",
     "password": "your-password"
   }
   ```
4. Click **"Execute"**
5. Copy the `access_token` from the response
6. Use this token to authorize other endpoints

## Regenerating Documentation

After adding or updating Swagger annotations in controllers, regenerate the docs:

```bash
php artisan l5-swagger:generate
```

## Adding Documentation to New Controllers

Add Swagger annotations to your controller methods. Example:

```php
/**
 * @OA\Post(
 *     path="/api/products",
 *     summary="Create a new product",
 *     tags={"Products"},
 *     security={{"bearerAuth": {}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(...)
 *     ),
 *     @OA\Response(response=201, description="Product created"),
 *     @OA\Response(response=422, description="Validation error")
 * )
 */
public function store(Request $request) { ... }
```

## Current Documentation Status

- ✅ Authentication endpoints (Login, Register, Logout, Get User)
- ⏳ Other endpoints can be documented by adding annotations to their controllers

## Troubleshooting

### Swagger page not loading?
- Make sure Laravel server is running: `php artisan serve`
- Check if route exists: `php artisan route:list | findstr documentation`
- Clear cache: `php artisan cache:clear`

### Documentation not updating?
- Regenerate: `php artisan l5-swagger:generate`
- Clear browser cache and hard refresh

### 404 errors on font/assets?
- Make sure `storage/api-docs` directory exists and is writable
- Run: `php artisan l5-swagger:generate` to regenerate assets

