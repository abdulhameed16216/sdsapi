<?php

namespace App\Http\Controllers;

use App\Models\ProjectStepDocument;
use App\Models\Project;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProjectDocumentController extends Controller
{
    /**
     * View project document in UI screen
     * Requires token and document_id as query parameters
     * 
     * @param  Request  $request
     * @return \Illuminate\View\View|\Illuminate\Http\JsonResponse
     */
    public function viewDocument(Request $request)
    {
        // Get token and document_id from query parameters
        $token = $request->query('token');
        $documentId = $request->query('document_id');
        
        // URL decode the token if needed (query parameters are auto-decoded, but ensure it's clean)
        $token = urldecode($token);

        // Validate required parameters
        if (!$token || !$documentId) {
            return view('errors.document', [
                'error' => 'Missing required parameters. Please provide token and document_id.',
                'user' => null,
                'document' => null
            ]);
        }

        // Find document by ID
        $document = ProjectStepDocument::find($documentId);

        if (!$document) {
            return view('errors.document', [
                'error' => 'Document not found.',
                'user' => null,
                'document' => null
            ]);
        }

        // Authenticate customer using token
        $customer = null;

        try {
            // Create a request with Authorization header to simulate API call
            $tempRequest = Request::create('/api/test', 'GET', [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token
            ]);
            
            // Set the request and try to authenticate
            $customer = auth('customer-api')->setRequest($tempRequest)->user();
            
            // If that doesn't work, try JWTAuth directly with guard
            if (!$customer) {
                $customer = JWTAuth::guard('customer-api')->setToken($token)->authenticate();
            }
        } catch (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e) {
            Log::error('Customer token expired', [
                'error' => $e->getMessage(),
                'token_preview' => substr($token, 0, 20) . '...'
            ]);
        } catch (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e) {
            Log::error('Customer token invalid', [
                'error' => $e->getMessage(),
                'token_preview' => substr($token, 0, 20) . '...'
            ]);
        } catch (\Exception $e) {
            // Token authentication failed - try manual parsing as last resort
            try {
                $payload = JWTAuth::guard('customer-api')->setToken($token)->getPayload();
                $customerId = $payload->get('customer_id') ?? $payload->get('sub');
                if ($customerId) {
                    $customer = Customer::find($customerId);
                }
            } catch (\Exception $e2) {
                Log::error('Customer token authentication failed', [
                    'error' => $e->getMessage(),
                    'error_class' => get_class($e),
                    'fallback_error' => $e2->getMessage(),
                    'token_preview' => substr($token, 0, 20) . '...'
                ]);
            }
        }

        // Check if customer is authenticated
        if (!$customer) {
            return view('errors.document', [
                'error' => 'Invalid or expired token. Please login again.',
                'user' => null,
                'document' => $document
            ]);
        }

        // Verify access: Customer can only access documents from their assigned projects
        $project = Project::find($document->project_id);
        if (!$project || $project->customer_id != $customer->id) {
            return view('errors.document', [
                'error' => 'You do not have access to this document.',
                'user' => [
                    'name' => $customer->name ?? $customer->username ?? 'User',
                    'email' => $customer->email ?? '',
                    'type' => 'customer'
                ],
                'document' => $document
            ]);
        }

        // Get file path - files are stored in public/uploads/project/...
        $filePath = $document->file_path;
        
        // Remove 'public/' prefix if present (public folder is web root)
        if (strpos($filePath, 'public/') === 0) {
            $filePath = substr($filePath, 7);
        }
        
        // Full path to file in public directory
        $fullPath = public_path($filePath);
        
        // Check if file exists
        if (!file_exists($fullPath)) {
            return view('errors.document', [
                'error' => 'File not found on server.',
                'user' => [
                    'name' => $customer->name ?? $customer->username ?? 'User',
                    'email' => $customer->email ?? '',
                    'type' => 'customer'
                ],
                'document' => $document
            ]);
        }

        // Get MIME type
        $mimeType = $document->mime_type ?? mime_content_type($fullPath);
        
        // If MIME type is not available, try to detect from extension
        if (!$mimeType) {
            $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $mimeTypes = [
                'pdf' => 'application/pdf',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
            ];
            $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';
        }

        // Determine if it's an image or PDF
        $isImage = strpos($mimeType, 'image/') === 0;
        $isPdf = $mimeType === 'application/pdf';

        // Get file URL for embedding
        // For PDFs, use a route that serves with proper headers
        // For images, use direct URL
        $appUrl = rtrim(config('app.url'), '/');
        if ($isPdf) {
            // Use serve route for PDFs to allow iframe embedding
            $fileUrl = $appUrl . '/serve-document?token=' . urlencode($token) . '&document_id=' . $documentId;
        } else {
            // Direct URL for images
            $fileUrl = $appUrl . '/' . $filePath;
        }

        // Prepare user info
        $userInfo = [
            'name' => $customer->name ?? $customer->username ?? 'User',
            'email' => $customer->email ?? '',
            'type' => 'customer',
            'id' => $customer->id ?? null
        ];

        // Return view with document
        return view('documents.view', [
            'document' => $document,
            'user' => $userInfo,
            'fileUrl' => $fileUrl,
            'mimeType' => $mimeType,
            'isImage' => $isImage,
            'isPdf' => $isPdf,
            'fileName' => $document->name
        ]);
    }

    /**
     * Serve document file with proper headers for iframe embedding
     * 
     * @param  Request  $request
     * @return \Illuminate\Http\Response
     */
    public function serveDocument(Request $request)
    {
        // Get token and document_id from query parameters
        $token = $request->query('token');
        $documentId = $request->query('document_id');
        
        // URL decode the token if needed
        $token = urldecode($token);

        // Validate required parameters
        if (!$token || !$documentId) {
            abort(400, 'Missing required parameters');
        }

        // Find document by ID
        $document = ProjectStepDocument::find($documentId);

        if (!$document) {
            abort(404, 'Document not found');
        }

        // Authenticate customer using token
        $customer = null;

        try {
            $tempRequest = Request::create('/api/test', 'GET', [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token
            ]);
            
            $customer = auth('customer-api')->setRequest($tempRequest)->user();
            
            if (!$customer) {
                $customer = JWTAuth::guard('customer-api')->setToken($token)->authenticate();
            }
        } catch (\Exception $e) {
            try {
                $payload = JWTAuth::guard('customer-api')->setToken($token)->getPayload();
                $customerId = $payload->get('customer_id') ?? $payload->get('sub');
                if ($customerId) {
                    $customer = Customer::find($customerId);
                }
            } catch (\Exception $e2) {
                abort(401, 'Invalid or expired token');
            }
        }

        // Check if customer is authenticated
        if (!$customer) {
            abort(401, 'Invalid or expired token');
        }

        // Verify access: Customer can only access documents from their assigned projects
        $project = Project::find($document->project_id);
        if (!$project || $project->customer_id != $customer->id) {
            abort(403, 'You do not have access to this document');
        }

        // Get file path
        $filePath = $document->file_path;
        
        // Remove 'public/' prefix if present
        if (strpos($filePath, 'public/') === 0) {
            $filePath = substr($filePath, 7);
        }
        
        // Full path to file in public directory
        $fullPath = public_path($filePath);
        
        // Check if file exists
        if (!file_exists($fullPath)) {
            abort(404, 'File not found on server');
        }

        // Get MIME type
        $mimeType = $document->mime_type ?? mime_content_type($fullPath);
        
        if (!$mimeType) {
            $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $mimeTypes = [
                'pdf' => 'application/pdf',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
            ];
            $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';
        }

        // Return file with proper headers for embedding
        // Remove restrictive headers to allow embedding
        $response = response()->file($fullPath, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . $document->name . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
        
        // Remove headers that block embedding
        $response->headers->remove('X-Frame-Options');
        $response->headers->remove('Content-Security-Policy');
        
        // Add CORS headers to allow access from frontend
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        $response->headers->set('Access-Control-Expose-Headers', 'Content-Type, Content-Disposition');
        
        return $response;
    }
}

