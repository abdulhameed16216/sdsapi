<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiResponseMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only apply to API routes
        if ($request->is('api/*')) {
            // Add common headers for API responses
            $response->headers->set('Content-Type', 'application/json');
            $response->headers->set('X-API-Version', '1.0.0');
            $response->headers->set('X-Response-Time', microtime(true) - LARAVEL_START);
        }

        return $response;
    }
}
