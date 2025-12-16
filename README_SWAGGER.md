# Swagger API Documentation

## Quick Access

**Swagger UI URL:** `http://localhost:8000/api/documentation`

## For Mobile Developers

This Swagger documentation allows you to:

1. **Browse all available API endpoints**
2. **Test endpoints directly** without writing code
3. **See request/response formats** for each endpoint
4. **Get authentication tokens** by testing the login endpoint
5. **Understand API structure** before implementing in your mobile app

## Quick Start Guide

### Step 1: Access Swagger
Open your browser and go to: `http://localhost:8000/api/documentation`

### Step 2: Get Authentication Token
1. Find **POST /api/login** endpoint
2. Click **"Try it out"**
3. Enter your credentials:
   ```json
   {
     "username": "your-username",
     "password": "your-password"
   }
   ```
4. Click **"Execute"**
5. Copy the `access_token` from the response

### Step 3: Authorize
1. Click the **"Authorize"** button (top right)
2. Paste your token (or enter: `Bearer your-token-here`)
3. Click **"Authorize"** then **"Close"**

### Step 4: Test Protected Endpoints
Now you can test any protected endpoint. For example:
- **GET /api/user** - Get current user info
- **GET /api/products** - List products
- **POST /api/products** - Create a product

## Currently Documented Endpoints

### Authentication
- ✅ POST /api/register - Register new user
- ✅ POST /api/login - Login and get token
- ✅ GET /api/user - Get authenticated user
- ✅ POST /api/logout - Logout

### More Endpoints
Other endpoints are available but need Swagger annotations added to their controllers. You can still test them, but documentation will be auto-generated from route definitions.

## Adding More Documentation

To document additional endpoints, add Swagger annotations to controller methods. See `SWAGGER_SETUP.md` for examples.

## Troubleshooting

- **Page not loading?** Make sure Laravel server is running
- **401 Unauthorized?** Make sure you've authorized with a valid token
- **Documentation outdated?** Run: `php artisan l5-swagger:generate`

