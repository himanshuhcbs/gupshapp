<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Illuminate\Auth\AuthenticationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Register Sanctum middleware for API
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Helper function to return JSON for API routes
        $apiResponse = function ($message, $errors = [], $status = 500) {
            return response()->json([
                'message' => $message,
                'errors' => $errors,
            ], $status);
        };

        // Handle ValidationException (e.g., incorrect credentials, invalid input)
        $exceptions->renderable(function (ValidationException $e, $request) use ($apiResponse) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return $apiResponse('Validation failed', $e->errors(), 422);
            }
            return redirect()->back()->withErrors($e->errors())->withInput();
        });

        // Handle AuthenticationException (e.g., invalid/missing Bearer token)
        $exceptions->renderable(function (AuthenticationException $e, $request) use ($apiResponse) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return $apiResponse('Unauthenticated', ['auth' => [$e->getMessage()]], 401);
            }
            return redirect()->guest(route('login'));
        });

        // Handle MethodNotAllowedHttpException (e.g., GET on POST route)
        $exceptions->renderable(function (MethodNotAllowedHttpException $e, $request) use ($apiResponse) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return $apiResponse('Method not allowed', ['method' => [$e->getMessage()]], 405);
            }
            return redirect()->back()->with('error', $e->getMessage());
        });

        // Handle NotFoundHttpException (e.g., route or resource not found)
        $exceptions->renderable(function (NotFoundHttpException $e, $request) use ($apiResponse) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return $apiResponse('Resource not found', ['resource' => [$e->getMessage() ?: 'Not found']], 404);
            }
            return redirect()->back()->with('error', 'Page not found');
        });

        // Handle generic HttpException (e.g., 403 Forbidden, 429 Too Many Requests)
        $exceptions->renderable(function (HttpException $e, $request) use ($apiResponse) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return $apiResponse($e->getMessage() ?: 'HTTP error', [], $e->getStatusCode());
            }
            return redirect()->back()->with('error', $e->getMessage());
        });

        // Fallback for unhandled exceptions
        $exceptions->renderable(function (Throwable $e, $request) use ($apiResponse) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return $apiResponse('Server error', ['error' => [$e->getMessage()]], 500);
            }
            throw $e; // Let Laravel handle web requests
        });
    })->create();
