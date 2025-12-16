<?php

namespace App\Http\Controllers;

/**
 * @OA\Info(
 *     title="Sales Management API",
 *     version="1.0.0",
 *     description="API documentation for Sales Management System. Use this API to interact with the sales system endpoints.",
 *     @OA\Contact(
 *         email="support@example.com"
 *     ),
 *     @OA\License(
 *         name="MIT",
 *         url="https://opensource.org/licenses/MIT"
 *     )
 * )
 *
 * @OA\Server(
 *     url="{protocol}://{host}",
 *     description="API Server",
 *     variables={
 *         @OA\ServerVariable(
 *             serverVariable="protocol",
 *             enum={"http", "https"},
 *             default="http"
 *         ),
 *         @OA\ServerVariable(
 *             serverVariable="host",
 *             default="localhost:8000"
 *         )
 *     }
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT",
 *     description="Enter your Bearer token in the format: Bearer {token}"
 * )
 *
 * @OA\Tag(
 *     name="Authentication",
 *     description="Authentication endpoints"
 * )
 * @OA\Tag(
 *     name="Products",
 *     description="Product management endpoints"
 * )
 * @OA\Tag(
 *     name="Categories",
 *     description="Category management endpoints"
 * )
 * @OA\Tag(
 *     name="Sales",
 *     description="Sales management endpoints"
 * )
 * @OA\Tag(
 *     name="Users",
 *     description="User management endpoints"
 * )
 */
abstract class Controller
{
    //
}
