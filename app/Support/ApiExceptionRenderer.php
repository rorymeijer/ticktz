<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * One error shape for the whole API.
 *
 * Laravel's defaults are fine per exception type and inconsistent taken
 * together: a 422 is `{errors: {...}}`, a 404 is `{message: ...}`, a 403 is
 * something else again. A caller then writes three handlers, or — more often —
 * one that works until it meets the second shape. Everything here comes back
 * as:
 *
 *     {"error": {"code": "not_found", "message": "..."}}
 *
 * with `error.fields` added for validation failures.
 *
 * `code` is a stable string, chosen here, and deliberately not the exception
 * class: renaming a class must not break somebody's integration.
 *
 * Nothing internal is ever echoed. A 500 says so and nothing more — the stack
 * trace goes to the log, where the operator can see it and the caller cannot.
 */
final class ApiExceptionRenderer
{
    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        return match (true) {
            $exception instanceof ValidationException => self::validation($exception),
            $exception instanceof AuthenticationException => self::make(
                'unauthenticated', __('api.errors.unauthenticated'), 401,
            ),
            $exception instanceof AuthorizationException => self::make(
                'forbidden', __('api.errors.forbidden'), 403,
            ),
            // A record the caller may not see and a record that does not exist
            // answer the same way. Telling them apart is a way to enumerate
            // ticket keys and find out which ones exist.
            $exception instanceof ModelNotFoundException,
            $exception instanceof NotFoundHttpException => self::make(
                'not_found', __('api.errors.not_found'), 404,
            ),
            $exception instanceof TooManyRequestsHttpException => self::make(
                'rate_limited', __('api.errors.rate_limited'), 429,
            ),
            $exception instanceof HttpExceptionInterface => self::make(
                'http_error',
                $exception->getMessage() ?: __('api.errors.http_error'),
                $exception->getStatusCode(),
            ),
            // Everything else: report it, say nothing. In debug mode the
            // message comes through, because a developer running locally
            // needs it and no external caller is watching.
            default => self::make(
                'server_error',
                config('app.debug') ? $exception->getMessage() : __('api.errors.server_error'),
                500,
            ),
        };
    }

    private static function validation(ValidationException $exception): JsonResponse
    {
        return self::make('validation_failed', __('api.errors.validation'), 422, [
            'fields' => $exception->errors(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private static function make(string $code, string $message, int $status, array $extra = []): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message] + $extra], $status);
    }
}
