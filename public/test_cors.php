<?php
/**
 * CORS Test Endpoint
 * 
 * This script tests CORS headers directly
 * Visit from your frontend to see if CORS works
 */

header('Access-Control-Allow-Origin: https://eb-develop.veeyaainnovatives.com');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Return test response
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => 'CORS test successful',
    'origin' => $_SERVER['HTTP_ORIGIN'] ?? 'Not set',
    'method' => $_SERVER['REQUEST_METHOD'],
    'headers' => [
        'Access-Control-Allow-Origin' => 'https://eb-develop.veeyaainnovatives.com',
        'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS, PATCH',
        'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN',
    ]
]);

