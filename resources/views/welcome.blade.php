<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Sales API') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <style>
            body {
                font-family: 'Figtree', sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                margin: 0;
                padding: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .container {
                background: white;
                padding: 3rem;
                border-radius: 1rem;
                box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
                text-align: center;
                max-width: 500px;
                width: 90%;
            }
            h1 {
                color: #1f2937;
                margin-bottom: 1rem;
                font-size: 2.5rem;
                font-weight: 600;
            }
            p {
                color: #6b7280;
                margin-bottom: 2rem;
                font-size: 1.1rem;
                line-height: 1.6;
            }
            .status {
                background: #10b981;
                color: white;
                padding: 0.5rem 1rem;
                border-radius: 0.5rem;
                display: inline-block;
                font-weight: 500;
            }
            .version {
                margin-top: 2rem;
                padding-top: 1rem;
                border-top: 1px solid #e5e7eb;
                color: #9ca3af;
                font-size: 0.9rem;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>{{ config('app.name', 'Sales API') }}</h1>
            <p>Welcome to the Sales Management API. This is a Laravel-based REST API that provides endpoints for managing sales, inventory, and business operations.</p>
            <div class="status">API is running</div>
            <div class="version">
                Laravel {{ app()->version() }} | PHP {{ phpversion() }}
            </div>
        </div>
    </body>
</html>