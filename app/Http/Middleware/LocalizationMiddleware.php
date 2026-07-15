<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class LocalizationMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        // 1. Detect language from query parameter, request payload, or headers, default is 'vi'
        $locale = $request->input('isCheckLanguage') ?: $request->header('isCheckLanguage') ?: $request->header('Accept-Language') ?: 'vi';
        app()->setLocale($locale);

        $response = $next($request);

        // 2. Translate the response message dynamically for API responses
        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            if (is_array($data) && isset($data['message']) && is_string($data['message'])) {
                $data['message'] = __($data['message']);
                $response->setData($data);
            }
        }

        return $response;
    }
}
