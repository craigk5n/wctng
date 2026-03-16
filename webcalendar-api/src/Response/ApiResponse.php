<?php

declare(strict_types=1);

namespace App\Response;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Standard API response envelope: {data, meta, error}.
 *
 * All API endpoints MUST use these methods to ensure consistent response format.
 */
final class ApiResponse
{
    /**
     * Successful response wrapping data.
     *
     * @param mixed                    $data   The response payload
     * @param array<string, mixed>|null $meta  Optional metadata
     * @param int                       $status HTTP status code (default 200)
     */
    public static function success(mixed $data, ?array $meta = null, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse([
            'data' => $data,
            'meta' => $meta,
            'error' => null,
        ], $status);
    }

    /**
     * Error response.
     *
     * @param int           $code    HTTP status code
     * @param string        $message Human-readable error message
     * @param list<string>  $details Optional detail messages (e.g. validation errors)
     */
    public static function error(int $code, string $message, array $details = []): JsonResponse
    {
        return new JsonResponse([
            'data' => null,
            'meta' => null,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
        ], $code);
    }

    /**
     * Paginated list response.
     *
     * @param list<array<string, mixed>> $items Items for this page
     * @param int                        $total Total items matching the query
     * @param int                        $page  Current page number
     * @param int                        $limit Items per page
     */
    public static function paginated(array $items, int $total, int $page, int $limit): JsonResponse
    {
        return new JsonResponse([
            'data' => $items,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
            ],
            'error' => null,
        ]);
    }

    /**
     * No-content response (204).
     */
    public static function noContent(): Response
    {
        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
