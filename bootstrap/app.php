<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // The site lives on the frontend; the API root just reports what it
            // is. Registered outside the web group so it sets no cookies.
            Route::get('/', fn () => response()->json([
                'name' => config('app.name'),
                'status' => 'ok',
            ]));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

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

                // Route model binding failures carry an internal message
                // ("No query results for model [App\\Models\\...]").
                if ($status === 404 && ($exception->getPrevious() instanceof ModelNotFoundException || str_starts_with($message, 'No query results'))) {
                    $message = 'The requested item could not be found.';
                }
            }

            // Photo storage failures: the details go to the log, never to the visitor.
            $isRequestError = $exception instanceof ValidationException
                || $exception instanceof AuthenticationException
                || $exception instanceof HttpExceptionInterface;
            if (! $isRequestError && preg_match('/storage|supabase|bucket|s3/i', $exception->getMessage())) {
                $status = 500;
                $type = 'storage_error';
                $message = 'Your photos could not be uploaded right now. Please try again in a few minutes.';
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
