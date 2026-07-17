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
        $rawLocale = $request->input('isCheckLanguage') ?: $request->header('isCheckLanguage') ?: $request->header('Accept-Language') ?: 'vi';
        
        $locale = 'vi';
        if ($rawLocale) {
            $parts = explode(',', $rawLocale);
            $first = trim($parts[0]);
            $subParts = explode(';', $first);
            $langWithRegion = trim($subParts[0]);
            $langParts = preg_split('/[-_]/', $langWithRegion);
            $primaryLang = strtolower(trim($langParts[0]));
            
            if ($primaryLang === 'ja') {
                $primaryLang = 'jp';
            }
            
            if (in_array($primaryLang, ['vi', 'en', 'jp'])) {
                $locale = $primaryLang;
            }
        }
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
