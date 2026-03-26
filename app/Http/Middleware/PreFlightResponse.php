namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class PreFlightResponse
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->getMethod() === 'OPTIONS') {
            return response('')
                ->header('Access-Control-Allow-Origin', 'https://internetvergelijk.schaapontwerpers.nl,http://internetvergelijk.test')
                ->header('Access-Control-Allow-Methods', 'GET,OPTIONS, HEAD, DELETE')
                ->header('Access-Control-Allow-Headers', 'Content-Type');
        }

        return $next($request);
    }
}
<?php

