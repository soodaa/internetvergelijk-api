<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class MonitoringAuth
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $username = env('MONITOR_USERNAME');
        $password = env('MONITOR_PASSWORD');

        if (!$username || !$password) {
            return $next($request);
        }

        if ($request->getUser() !== $username || $request->getPassword() !== $password) {
            return response('Unauthorized', 401, [
                'WWW-Authenticate' => 'Basic realm="Monitoring Dashboard"',
            ]);
        }

        return $next($request);
    }
}

