<?php
/**
 * Test CORS Headers Script
 * 
 * This script tests if CORS headers are being set correctly
 * Visit from your frontend to see CORS headers
 */

// Get origin from request
$origin = $_SERVER['HTTP_ORIGIN'] ?? null;

// Allowed origins
$allowedOrigins = [
    'https://eb-develop.veeyaainnovatives.com',
    'https://eb-develop-api.veeyaainnovatives.com',
    'http://localhost:3000',
    'http://localhost:4200',
    'http://localhost:8100',
];

// Set CORS headers
if ($origin && in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    // Default to first production origin
    header('Access-Control-Allow-Origin: https://eb-develop.veeyaainnovatives.com');
}

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
    'message' => 'CORS headers test',
    'origin' => $origin ?? 'Not set',
    'method' => $_SERVER['REQUEST_METHOD'],
    'headers_sent' => [
        'Access-Control-Allow-Origin' => $origin && in_array($origin, $allowedOrigins) ? $origin : 'https://eb-develop.veeyaainnovatives.com',
        'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS, PATCH',
        'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN',
        'Access-Control-Allow-Credentials' => 'true',
    ],
    'allowed_origins' => $allowedOrigins,
]);

