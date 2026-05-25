<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyEdgeBoxApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $configuredKey = config('edge_box.api_key');

        \Log::info('Edge Key Debug', [
        'configured_key' => $configuredKey,
        'request_key'    => $request->header('X-Edge-Api-Key'),
        'all_headers'    => $request->headers->all(),
    ]);
        if (empty($configuredKey)) {
            return response()->json([
                'message' => 'Edge Box API key is not configured.',
            ], 503);
        }

        $requestKey = $request->header('X-Edge-Api-Key');

        if (empty($requestKey) || !hash_equals((string) $configuredKey, (string) $requestKey)) {
            return response()->json([
                'message' => 'Invalid Edge Box API key.',
            ], 401);
        }

        return $next($request);
    }
}
