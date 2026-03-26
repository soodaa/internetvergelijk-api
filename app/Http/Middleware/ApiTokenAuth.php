<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Simple API token authentication without database dependency.
 * Checks api_token parameter or Authorization Bearer header against .env value.
 */
class ApiTokenAuth
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $expectedToken = env('API_TOKEN');

        if (!$expectedToken) {
            // If no token configured, allow all (backward compatibility)
            return $next($request);
        }

        // Check query parameter first
        $providedToken = $request->query('api_token');

        // If not in query, check Authorization Bearer header
        if (!$providedToken) {
            $authHeader = $request->header('Authorization');
            if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
                $providedToken = substr($authHeader, 7);
            }
        }

        if ($providedToken !== $expectedToken) {
            return response()->json([
                'error' => 1,
                'error_message' => 'Unauthorized - Invalid API token',
            ], 401);
        }

        return $next($request);
    }
}

