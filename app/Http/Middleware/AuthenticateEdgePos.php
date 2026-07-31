<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

class AuthenticateEdgePos
{
    public function handle(Request $request, Closure $next)
    {
        $token = null;

        if ($bearer = $request->bearerToken()) {
            $token = $bearer;
        }

        if (!$token && $cookie = $request->cookie('edge_pos_token')) {
            $token = $cookie;
        }

        if (!$token) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }
            return redirect()->route('pos.login');
        }

        try {
            $user = JWTAuth::setToken($token)->authenticate();
            if (!$user) {
                throw new JWTException('User not found');
            }
            $request->merge(['auth_user' => $user]);
            $request->setUserResolver(fn() => $user);
            auth()->setUser($user);
        } catch (JWTException $e) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['success' => false, 'message' => 'Token expired or invalid'], 401);
            }
            return redirect()->route('pos.login');
        }

        return $next($request);
    }
}
