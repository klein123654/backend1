<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(
            fn (Request $request) => null
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $exception) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            $status = 500;
            $type = 'server_error';
            $message = 'An unexpected server error occurred.';

            if ($exception instanceof ValidationException) {
                $status = 422;
                $type = 'validation_error';
                $message = 'Please review the form and fix the highlighted fields.';
            } elseif ($exception instanceof AuthenticationException) {
                $status = 401;
                $type = 'authentication_error';
                $message = 'Please sign in again to continue.';
            } elseif ($exception instanceof HttpExceptionInterface) {
                $status = $exception->getStatusCode();
                $type = 'http_error';
                $message = $exception->getMessage() ?: 'The request could not be processed.';
            }

            if (str_contains($exception->getMessage(), 'storage') || str_contains($exception->getMessage(), 'Supabase') || str_contains($exception->getMessage(), 'bucket')) {
                $status = 500;
                $type = 'storage_error';
                $message = 'Image upload failed. Please check the Supabase storage configuration, bucket permissions, and credentials.';
            }

            $payload = [
                'message' => $message,
                'type' => $type,
            ];

            if ($exception instanceof ValidationException) {
                $payload['errors'] = $exception->errors();
            }

            if (config('app.debug')) {
                $payload['details'] = $exception->getMessage();
            }

            return response()->json($payload, $status);
        });
    })
    ->create();
