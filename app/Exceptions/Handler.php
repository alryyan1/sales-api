<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Render an exception into an HTTP response.
     *
     * For API requests, include extra debug information (file, line)
     * for unexpected errors to make troubleshooting easier.
     */
    public function render($request, Throwable $e)
    {
        // Let parent handle non-API or HTML requests as usual
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return parent::render($request, $e);
        }

        // Base JSON structure
        $payload = [
            'message' => $e->getMessage() ?: 'Server Error',
        ];

        // Always include debug info for API requests when debug is enabled
        if (config('app.debug')) {
            $payload['exception'] = class_basename($e);
            $payload['file'] = $e->getFile();
            $payload['line'] = $e->getLine();
            $payload['trace'] = collect($e->getTrace())->take(10)->map(function ($trace) {
                return [
                    'file' => $trace['file'] ?? null,
                    'line' => $trace['line'] ?? null,
                    'function' => $trace['function'] ?? null,
                    'class' => $trace['class'] ?? null,
                ];
            })->toArray();
            
            // Include previous exception if it exists
            if ($e->getPrevious()) {
                $payload['previous'] = [
                    'exception' => class_basename($e->getPrevious()),
                    'message' => $e->getPrevious()->getMessage(),
                    'file' => $e->getPrevious()->getFile(),
                    'line' => $e->getPrevious()->getLine(),
                ];
            }
        }

        // Determine status code
        $status = 500;
        if ($e instanceof \Illuminate\Validation\ValidationException) {
            $status = 422;
            $payload['errors'] = $e->errors();
        } elseif ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            $status = 404;
        } elseif ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            $status = $e->getStatusCode();
        }

        return response()->json($payload, $status);
    }
}
