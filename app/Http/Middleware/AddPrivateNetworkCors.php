<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AddPrivateNetworkCors
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $response->headers->set('Access-Control-Allow-Private-Network', 'true');
        return $response;
    }
}
