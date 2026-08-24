<?php

use App\Http\Middleware\DetectSpamSubmission;
use App\Http\Middleware\EnsureIdempotentRequest;
use App\Http\Middleware\RejectDuplicateSubmission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            HandleCors::class,
        ]);

        /*
         * Bot signals run on every API write, globally rather than per route.
         *
         * Opting routes in one at a time was the alternative and it fails by
         * omission: the next endpoint someone adds is unprotected by default, and
         * nobody finds out until it is being abused. Applied globally, the default
         * is protected and an exemption has to be argued for.
         *
         * The middleware no-ops on GET and on requests that carry no honeypot
         * field, so read traffic and non-browser clients are unaffected.
         */
        $middleware->appendToGroup('api', [
            DetectSpamSubmission::class,
        ]);

        /*
         * Opt-in per route, because both change response semantics: idempotency can
         * replay a stored response, and duplicate detection can return 429. Those
         * belong on endpoints chosen deliberately, not everywhere by default.
         */
        $middleware->alias([
            'idempotent' => EnsureIdempotentRequest::class,
            'no-duplicates' => RejectDuplicateSubmission::class,
        ]);

        // This is an API-only app with no "login" named route — without this,
        // an unauthenticated request crashes trying to redirect to one.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors' => $e->errors(),
                ], 422);
            }

            if ($e instanceof AuthorizationException
                || $e instanceof AccessDeniedHttpException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'This action is unauthorized.',
                    'errors' => [],
                ], 403);
            }

            if ($e instanceof ModelNotFoundException
                || $e instanceof NotFoundHttpException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource not found.',
                    'errors' => [],
                ], 404);
            }

            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                    'errors' => [],
                ], 401);
            }

            // Generic abort()/abort_unless() calls throw a plain HttpException
            // carrying their own intended status code and message (e.g. the
            // 403 from `abort_unless($user->isOwner(), 403, '...')`) — these
            // are deliberate, safe responses and must be rendered as-is, not
            // swallowed by the catch-all below.
            if ($e instanceof HttpException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'Request failed.',
                    'errors' => [],
                ], $e->getStatusCode());
            }

            // Catch-all: any exception not explicitly handled above must
            // still never leak a raw Laravel/PHP/database error (stack
            // trace, file paths, SQL, etc.) to the client — regardless of
            // APP_DEBUG. Specific, friendlier handling belongs above this;
            // this is the last line of defense, not the primary fix.
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong. Please try again.',
                'errors' => [],
            ], 500);
        });
    })->create();
