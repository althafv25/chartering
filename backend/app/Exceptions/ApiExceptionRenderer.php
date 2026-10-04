<?php

namespace App\Exceptions;

use App\Http\Middleware\ApiSecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Converts every exception on API routes into the standard error envelope:
 * { success:false, message, error_code, errors?, request_id }.
 *
 * Stack traces / internal messages are never returned when APP_DEBUG=false.
 */
class ApiExceptionRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null; // let Laravel render non-API responses
        }

        if ($e instanceof HttpResponseException) {
            return null; // already a response
        }

        [$status, $code, $message, $errors] = $this->map($e);

        $body = [
            'success' => false,
            'message' => $message,
            'error_code' => $code,
            'request_id' => $request->attributes->get('request_id'),
        ];

        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        if ($status >= 500 && config('app.debug')) {
            $body['debug'] = ['exception' => $e::class, 'message' => $e->getMessage()];
        }

        $response = new JsonResponse($body, $status);
        ApiSecurityHeaders::apply($response);

        if ($e instanceof ThrottleRequestsException) {
            $response->headers->add($e->getHeaders());
        }

        return $response;
    }

    /**
     * @return array{0:int,1:string,2:string,3:array<string, mixed>}
     */
    private function map(Throwable $e): array
    {
        return match (true) {
            $e instanceof ValidationException => [422, 'validation_failed', 'The given data was invalid.', $e->errors()],
            $e instanceof AuthenticationException => [401, 'unauthenticated', 'Your session has expired. Please sign in again.', []],
            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => [403, 'forbidden', 'You do not have permission to perform this action.', []],
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => [404, 'not_found', 'The requested record was not found.', []],
            $e instanceof BusinessRuleException => [$e->status, $e->errorCode, $e->getMessage(), $e->errors],
            $e instanceof IntegrationException => $this->integration($e),
            $e instanceof ThrottleRequestsException => [429, 'too_many_requests', 'Too many requests. Please wait and try again.', []],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), 'http_error', $e->getMessage() ?: 'Request could not be processed.', []],
            default => [500, 'server_error', 'An unexpected error occurred. Please contact support with the request ID.', []],
        };
    }

    /** @return array{0:int,1:string,2:string,3:array<string, mixed>} */
    private function integration(IntegrationException $e): array
    {
        Log::warning('Integration failure', ['provider' => $e->provider, 'message' => $e->getPrevious()?->getMessage()]);

        return [502, 'integration_failed', $e->getMessage(), ['provider' => [$e->provider]]];
    }
}
