namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class HorizonUriPrefix
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (str_contains($request->url(), 'horizon') && ! str_contains($request->url(), 'check/public/horizon')) {
            return redirect('/check/public/horizon');
        }

        return $next($request);
    }
}
<?php

