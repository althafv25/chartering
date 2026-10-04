<?php

namespace App\Http\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The only way controllers produce JSON.
 * Envelope: { success, message, data, meta? }.
 */
trait ApiResponse
{
    protected function ok(mixed $data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        $body = ['success' => true, 'message' => $message];

        if ($data instanceof ResourceCollection && $data->resource instanceof LengthAwarePaginator) {
            return $this->paginated($data, $message);
        }

        if ($data instanceof JsonResource) {
            $data = $data->resolve(request());
        }

        $body['data'] = $data;

        return new JsonResponse($body, $status);
    }

    protected function created(mixed $data = null, string $message = 'Created.'): JsonResponse
    {
        return $this->ok($data, $message, 201);
    }

    protected function deleted(string $message = 'Deleted.'): JsonResponse
    {
        return new JsonResponse(['success' => true, 'message' => $message, 'data' => null]);
    }

    protected function paginated(ResourceCollection $collection, string $message = 'OK'): JsonResponse
    {
        /** @var LengthAwarePaginator $paginator */
        $paginator = $collection->resource;

        return $this->page($paginator, $collection->resolve(request()), $message);
    }

    /**
     * Envelope for a paginator whose items were already transformed to arrays.
     *
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  array<int, mixed>|null  $items
     */
    protected function page(LengthAwarePaginator $paginator, ?array $items = null, string $message = 'OK'): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'message' => $message,
            'data' => $items ?? $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }
}
