<?php

namespace App\Support\Http;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes money/stock-creating POSTs safe to retry. When the client sends an
 * `Idempotency-Key` header, the first request runs normally and its result
 * is stored; a repeat (double-click, network retry, impatient refresh)
 * gets the stored result back instead of creating a second order/payment.
 * Without the header the request runs as before, so this is opt-in and
 * breaks no existing client.
 *
 *  - same key, same body, finished   -> replay stored response (Idempotent-Replayed: true)
 *  - same key, still processing      -> 409 (client should wait and retry)
 *  - same key, different body        -> 422 (the key is being reused wrongly)
 *  - first request fails (4xx/5xx)  -> key released so a corrected retry can run
 */
class EnsureIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null || $key === '' || ! $request->isMethod('POST')) {
            return $next($request);
        }

        if (strlen($key) < 8 || strlen($key) > 100) {
            return response()->json(['success' => false, 'message' => 'Idempotency-Key must be 8-100 characters.'], 422);
        }

        $caller = $request->user()?->getAuthIdentifier() ?? $request->ip();
        $scopeHash = hash('sha256', $caller.'|'.$request->method().'|'.$request->path().'|'.$key);
        $requestHash = hash('sha256', $request->getContent());

        try {
            DB::table('idempotency_keys')->insert([
                'scope_hash' => $scopeHash,
                'request_hash' => $requestHash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->replay($scopeHash, $requestHash);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            DB::table('idempotency_keys')->where('scope_hash', $scopeHash)->delete();
            throw $e;
        }

        // Laravel turns exceptions into responses inside the pipeline, so a
        // failed request arrives here as a 4xx/5xx response, not a throw.
        // Only a success is worth replaying: a failed attempt changed
        // nothing (its transaction rolled back), and the client must be
        // able to correct the request and retry under the same key.
        if (! $response->isSuccessful()) {
            DB::table('idempotency_keys')->where('scope_hash', $scopeHash)->delete();

            return $response;
        }

        DB::table('idempotency_keys')->where('scope_hash', $scopeHash)->update([
            'response_status' => $response->getStatusCode(),
            'response_body' => $response->getContent(),
            'updated_at' => now(),
        ]);

        return $response;
    }

    private function replay(string $scopeHash, string $requestHash): Response
    {
        $row = DB::table('idempotency_keys')->where('scope_hash', $scopeHash)->first();

        if ($row === null) {
            // Released between our insert failing and this read — let the client retry.
            return response()->json(['success' => false, 'message' => 'Please retry the request.'], 409);
        }

        if ($row->request_hash !== $requestHash) {
            return response()->json(['success' => false, 'message' => 'This Idempotency-Key was already used with a different request.'], 422);
        }

        if ($row->response_status === null) {
            return response()->json(['success' => false, 'message' => 'The original request is still being processed.'], 409);
        }

        return response($row->response_body, $row->response_status)
            ->header('Content-Type', 'application/json')
            ->header('Idempotent-Replayed', 'true');
    }
}
