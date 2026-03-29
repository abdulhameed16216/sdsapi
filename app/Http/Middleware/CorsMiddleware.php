<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CorsMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');
        
        // Get CORS configuration from env with defaults
        $allowedMethods = env('CORS_ALLOWED_METHODS', 'GET,POST,PUT,DELETE,OPTIONS,PATCH');
        $allowedHeaders = env('CORS_ALLOWED_HEADERS', 'Content-Type,Authorization,X-Requested-With,Accept,Origin,X-CSRF-TOKEN');
        
        // Handle preflight OPTIONS request
        if ($request->getMethod() === 'OPTIONS') {
            $response = response('', 200);
            
            // Allow all origins - use origin if present, otherwise *
            $allowOrigin = $origin ?: '*';
            $response->headers->set('Access-Control-Allow-Origin', $allowOrigin);
            $response->headers->set('Access-Control-Allow-Methods', $allowedMethods);
            $response->headers->set('Access-Control-Allow-Headers', $allowedHeaders);
            
            // Only set credentials if not using wildcard
            if ($allowOrigin !== '*') {
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
            }
            
            $response->headers->set('Access-Control-Max-Age', '86400');
            
            return $response;
        }
        
        // Handle actual request
        $response = $next($request);
        
        // Allow all origins - use origin if present, otherwise *
        $allowOrigin = $origin ?: '*';
        $response->headers->set('Access-Control-Allow-Origin', $allowOrigin);
        $response->headers->set('Access-Control-Allow-Methods', $allowedMethods);
        $response->headers->set('Access-Control-Allow-Headers', $allowedHeaders);
        
        // Only set credentials if not using wildcard
        if ($allowOrigin !== '*') {
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }
        
        $response->headers->set('Access-Control-Max-Age', '86400');
        $response->headers->set('Access-Control-Expose-Headers', 'Authorization');

        return $response;
    }
}
