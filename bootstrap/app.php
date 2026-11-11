<?php

use App\Exceptions\InvalidTransitionException;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [ForceJsonResponse::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        $error = fn (string $message, string $code, int $status, array $errors = []) => response()->json([
            'message' => $message,
            'code' => $code,
            'errors' => $errors === [] ? new stdClass : $errors,
        ], $status);

        $exceptions->render(fn (AuthenticationException $e) => $error(
            $e->getMessage() ?: 'Unauthenticated.', 'unauthenticated', 401
        ));

        $exceptions->render(fn (AccessDeniedHttpException $e) => $error(
            'You are not allowed to perform this action.', 'forbidden', 403
        ));

        $exceptions->render(fn (NotFoundHttpException $e) => $error(
            'Resource not found.', 'not_found', 404
        ));

        $exceptions->render(fn (ValidationException $e) => $error(
            'The given data was invalid.', 'validation_failed', 422, $e->errors()
        ));

        $exceptions->render(fn (InvalidTransitionException $e) => $error(
            $e->getMessage(), 'invalid_transition', 422
        ));

        $exceptions->render(fn (TooManyRequestsHttpException $e) => $error(
            'Too many attempts. Please try again later.', 'too_many_requests', 429
        ));
    })->create();
