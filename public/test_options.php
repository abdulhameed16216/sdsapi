<?php
/**
 * Test OPTIONS Preflight Request
 * 
 * This script specifically tests OPTIONS preflight handling
 * Access from browser console or use curl with Origin header
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
} else if ($origin) {
    // If origin is set but not in allowed list, show it for debugging
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    // Default for testing
    header('Access-Control-Allow-Origin: *');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'OPTIONS preflight request handled',
        'origin' => $origin ?? 'Not set',
        'method' => 'OPTIONS',
        'cors_headers_set' => true,
    ]);
    exit;
}

// Return test response for GET requests
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => 'Test endpoint - use OPTIONS method to test preflight',
    'origin' => $origin ?? 'Not set',
    'method' => $_SERVER['REQUEST_METHOD'],
    'note' => 'To test OPTIONS, make a request with method OPTIONS and Origin header',
]);

