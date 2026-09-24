<?php

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every endpoint in the system follows exactly one of the four response shapes
 * defined in zurie-api-contract.md §1 — this trait is the single place that
 * builds them, so no controller can drift into an ad-hoc shape.
 */
trait ApiResponse
{
    /**
     * Single resource: { "success": true, "data": {...} }
     * No data: { "success": true }
     */
    protected function ok(mixed $data = null, int $status = 200): JsonResponse
    {
        if ($data === null) {
            return response()->json(['success' => true], $status);
        }

        return response()->json(['success' => true, 'data' => $data], $status);
    }

    protected function created(mixed $data = null): JsonResponse
    {
        if ($data === null) {
            return $this->ok(null, 201);
        }

        return $this->ok($data, 201);
    }

    /** Largest page any list endpoint will serve in one request. */
    protected const MAX_PAGE_SIZE = 100;

    /**
     * The `pageSize` query parameter, clamped to 1..MAX_PAGE_SIZE so one
     * request (e.g. `?pageSize=1000000` on the public product list) can't
     * make the server load and serialize an entire table.
     */
    protected function pageSize(Request $request, int $default = 20): int
    {
        return min(self::MAX_PAGE_SIZE, max(1, (int) $request->query('pageSize', $default)));
    }

    /**
     * List / paginated: { "success": true, "data": [...], "meta": {...} }
     */
    protected function paginated(iterable $data, array $meta): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => $meta,
        ]);
    }

    /**
     * Error: { "success": false, "message": "...", "errors"?: {...} }
     * "errors" is only present on 422 validation failures.
     */
    protected function fail(string $message, int $status = 400, ?array $errors = null): JsonResponse
    {
        $payload = ['success' => false, 'message' => $message];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}
