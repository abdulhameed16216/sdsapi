<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Mail\QuoteNotificationMail;
use App\Mail\QuoteThankYouMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class QuoteController extends Controller
{
    /**
     * Display a listing of quotes (for admin dashboard)
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $search = $request->get('search');
        $status = $request->get('status');
        $requestType = $request->get('request_type');

        $query = Quote::query();

        // Search filter
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('mobile_number', 'like', "%{$search}%")
                  ->orWhere('service', 'like', "%{$search}%")
                  ->orWhere('project_details', 'like', "%{$search}%");
            });
        }

        // Status filter
        if ($status) {
            $query->where('status', $status);
        }

        // Request type filter
        if ($requestType) {
            $query->where('request_type', $requestType);
        }

        $quotes = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $quotes
        ]);
    }

    /**
     * Display the specified quote
     */
    public function show($id)
    {
        $quote = Quote::find($id);

        if (!$quote) {
            return response()->json([
                'success' => false,
                'message' => 'Quote not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $quote
        ]);
    }

    /**
     * Store a new quote request
     */
    public function store(Request $request)
    {
        // Auto-detect request type from route path
        $path = $request->path();
        if (str_contains($path, 'public/contact') && !$request->has('request_type')) {
            $request->merge(['request_type' => 'contactus']);
        } elseif (str_contains($path, 'public/subscription') && !$request->has('request_type')) {
            $request->merge(['request_type' => 'subscription']);
        } elseif (str_contains($path, 'public/partner') && !$request->has('request_type')) {
            $request->merge(['request_type' => 'partner']);
        }

        // Map 'mobile' to 'mobile_number' if provided
        if ($request->has('mobile') && !$request->has('mobile_number')) {
            $request->merge(['mobile_number' => $request->mobile]);
        }

        // Map 'subscription_id' or 'partner_id' to 'ref_id' if provided
        if ($request->has('subscription_id') && !$request->has('ref_id')) {
            $request->merge(['ref_id' => $request->subscription_id]);
        } elseif ($request->has('partner_id') && !$request->has('ref_id')) {
            $request->merge(['ref_id' => $request->partner_id]);
        }
        
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'mobile_number' => 'required|string|max:20',
            'email' => 'required|email|max:255',
            'request_type' => 'nullable|string|in:quote,contactus,subscription,partner', // quote, contactus, subscription, or partner
            'ref_id' => 'nullable|integer', // subscription_id or partner_id
            'service' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'project_type' => 'nullable|string|max:255',
            'budget' => 'nullable|string|max:255',
            'project_details' => 'nullable|string',
            'project_date' => 'nullable|date',
            'files' => 'nullable|array|max:10', // Maximum 10 files
            'files.*' => [
                'file',
                'mimes:pdf,png,jpg,jpeg,gif,webp', // Only allowed formats - PDF, PNG, and images
                'mimetypes:application/pdf,image/png,image/jpeg,image/jpg,image/gif,image/webp', // Validate MIME types for security - Only PDF, PNG, and images
                'max:10240', // 10MB max per file
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $files = [];
            
            // Handle file uploads if provided
            if ($request->hasFile('files')) {
                $uploadedFiles = $request->file('files');
                
                // Create folder for this quote
                $randomString = Str::random(8);
                $dateTime = now()->format('Ymd_His');
                $folderName = 'quote_' . $randomString . '_' . $dateTime;
                $folderPath = public_path('uploads/quotes/' . $folderName);
                
                // Create directory if it doesn't exist
                if (!is_dir($folderPath)) {
                    mkdir($folderPath, 0755, true);
                }

                foreach ($uploadedFiles as $file) {
                    // Additional security: Validate MIME type
                    $allowedMimeTypes = [
                        'application/pdf',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'image/jpeg',
                        'image/png',
                        'image/gif',
                    ];
                    
                    $fileMimeType = $file->getMimeType();
                    if (!in_array($fileMimeType, $allowedMimeTypes)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Invalid file type. Only PDF, DOC, DOCX, and image files are allowed.',
                            'errors' => ['files' => ['File ' . $file->getClientOriginalName() . ' has invalid MIME type.']]
                        ], 422);
                    }
                    
                    // Sanitize filename to prevent directory traversal
                    $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                    $extension = strtolower($file->getClientOriginalExtension());
                    
                    // Validate extension matches MIME type
                    $allowedExtensions = ['pdf', 'doc', 'docx', 'jpeg', 'jpg', 'png', 'gif'];
                    if (!in_array($extension, $allowedExtensions)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Invalid file extension. Only PDF, DOC, DOCX, and image files are allowed.',
                            'errors' => ['files' => ['File ' . $file->getClientOriginalName() . ' has invalid extension.']]
                        ], 422);
                    }
                    
                    // Sanitize filename
                    $sanitizedName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $originalName);
                    $fileName = $sanitizedName . '_' . time() . '_' . Str::random(4) . '.' . $extension;
                    $fullPath = $folderPath . '/' . $fileName;
                    $relativePath = 'uploads/quotes/' . $folderName . '/' . $fileName;
                    
                    $file->move($folderPath, $fileName);
                    $files[] = [
                        'name' => $file->getClientOriginalName(),
                        'path' => $relativePath,
                        'full_path' => $fullPath, // Store full path for email attachments
                        'url' => '/' . $relativePath,
                        'size' => filesize($fullPath),
                        'mime_type' => $fileMimeType,
                    ];
                }
            }

            // Create quote record
            $quote = Quote::create([
                'name' => $request->name,
                'mobile_number' => $request->mobile_number,
                'email' => $request->email,
                'request_type' => $request->request_type ?? 'quote', // Default to 'quote' if not provided
                'ref_id' => $request->ref_id ?? null, // subscription_id or partner_id
                'service' => $request->service,
                'state' => $request->state,
                'project_type' => $request->project_type,
                'budget' => $request->budget,
                'project_details' => $request->project_details,
                'project_date' => $request->project_date,
                'files' => $files,
                'status' => 'pending',
            ]);

            // Load related subscription or partner program based on request_type
            if ($quote->request_type === 'subscription' && $quote->ref_id) {
                $quote->load('subscription');
            } elseif ($quote->request_type === 'partner' && $quote->ref_id) {
                $quote->load('partnerProgram');
            }

            // Get client mail ID from config (where quote notifications will be sent)
            $clientMailId = config('app.client_mail_id');

            // Send notification email to client with all quote details
            try {
                Mail::to($clientMailId)->send(new QuoteNotificationMail($quote));
            } catch (\Exception $e) {
                \Log::error('Failed to send quote notification email: ' . $e->getMessage());
            }

            // Send thank you email to customer
            try {
                Mail::to($quote->email)->send(new QuoteThankYouMail($quote));
                \Log::info('Thank you email sent successfully to: ' . $quote->email);
            } catch (\Exception $e) {
                \Log::error('Failed to send thank you email to ' . $quote->email . ': ' . $e->getMessage());
                \Log::error('Email error details: ' . $e->getTraceAsString());
            }

            // Generate appropriate success message based on request type
            $messageMap = [
                'contactus' => 'Contact request submitted successfully. We will contact you shortly.',
                'subscription' => 'Subscription request submitted successfully. We will contact you shortly.',
                'partner' => 'Partner program request submitted successfully. We will contact you shortly.',
                'quote' => 'Quote request submitted successfully. We will contact you shortly.'
            ];
            $message = $messageMap[$quote->request_type] ?? 'Request submitted successfully. We will contact you shortly.';

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $quote
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Quote submission error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit quote request. Please try again later.'
            ], 500);
        }
    }

    /**
     * Store a new quote from admin (no emails sent)
     */
    public function adminStore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'mobile_number' => 'required|string|max:20',
            'email' => 'required|email|max:255',
            'request_type' => 'nullable|string|in:quote,contactus',
            'service' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'project_type' => 'nullable|string|max:255',
            'budget' => 'nullable|string|max:255',
            'project_details' => 'nullable|string',
            'project_date' => 'nullable|date',
            'status' => 'nullable|string|in:pending,approved',
            'files' => 'nullable|array|max:10',
            'files.*' => [
                'file',
                'mimes:pdf,doc,docx,jpeg,jpg,png,gif',
                'mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/jpeg,image/png,image/gif',
                'max:10240',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $files = [];
            
            // Handle file uploads if provided
            if ($request->hasFile('files')) {
                $uploadedFiles = $request->file('files');
                
                // Create folder for this quote
                $randomString = Str::random(8);
                $dateTime = now()->format('Ymd_His');
                $folderName = 'quote_' . $randomString . '_' . $dateTime;
                $folderPath = public_path('uploads/quotes/' . $folderName);
                
                // Create directory if it doesn't exist
                if (!is_dir($folderPath)) {
                    mkdir($folderPath, 0755, true);
                }

                foreach ($uploadedFiles as $file) {
                    // Additional security: Validate MIME type
                    $allowedMimeTypes = [
                        'application/pdf',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'image/jpeg',
                        'image/png',
                        'image/gif',
                    ];
                    
                    $fileMimeType = $file->getMimeType();
                    if (!in_array($fileMimeType, $allowedMimeTypes)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Invalid file type. Only PDF, DOC, DOCX, and image files are allowed.',
                            'errors' => ['files' => ['File ' . $file->getClientOriginalName() . ' has invalid MIME type.']]
                        ], 422);
                    }
                    
                    // Sanitize filename to prevent directory traversal
                    $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                    $extension = strtolower($file->getClientOriginalExtension());
                    
                    // Validate extension matches MIME type
                    $allowedExtensions = ['pdf', 'doc', 'docx', 'jpeg', 'jpg', 'png', 'gif'];
                    if (!in_array($extension, $allowedExtensions)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Invalid file extension. Only PDF, DOC, DOCX, and image files are allowed.',
                            'errors' => ['files' => ['File ' . $file->getClientOriginalName() . ' has invalid extension.']]
                        ], 422);
                    }
                    
                    // Sanitize filename
                    $sanitizedName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $originalName);
                    $fileName = $sanitizedName . '_' . time() . '_' . Str::random(4) . '.' . $extension;
                    $fullPath = $folderPath . '/' . $fileName;
                    $relativePath = 'uploads/quotes/' . $folderName . '/' . $fileName;
                    
                    $file->move($folderPath, $fileName);
                    $files[] = [
                        'name' => $file->getClientOriginalName(),
                        'path' => $relativePath,
                        'full_path' => $fullPath,
                        'url' => '/' . $relativePath,
                        'size' => filesize($fullPath),
                        'mime_type' => $fileMimeType,
                    ];
                }
            }

            // Create quote record (no emails sent for admin creation)
            $quote = Quote::create([
                'name' => $request->name,
                'mobile_number' => $request->mobile_number,
                'email' => $request->email,
                'request_type' => $request->request_type ?? 'quote',
                'service' => $request->service,
                'state' => $request->state,
                'project_type' => $request->project_type,
                'budget' => $request->budget,
                'project_details' => $request->project_details,
                'project_date' => $request->project_date,
                'files' => $files,
                'status' => $request->status ?? 'pending',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Customer added successfully',
                'data' => $quote
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Admin quote creation error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create customer. Please try again later.'
            ], 500);
        }
    }

    /**
     * Update the specified quote
     */
    public function update(Request $request, $id)
    {
        $quote = Quote::find($id);

        if (!$quote) {
            return response()->json([
                'success' => false,
                'message' => 'Quote not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'mobile_number' => 'required|string|max:20',
            'email' => 'required|email|max:255',
            'request_type' => 'nullable|string|in:quote,contactus',
            'service' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'project_type' => 'nullable|string|max:255',
            'budget' => 'nullable|string|max:255',
            'project_details' => 'nullable|string',
            'project_date' => 'nullable|date',
            'status' => 'nullable|string|in:pending,approved',
            'files' => 'nullable|array|max:10',
            'files.*' => [
                'file',
                'mimes:pdf,doc,docx,jpeg,jpg,png,gif',
                'mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/jpeg,image/png,image/gif',
                'max:10240',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Start with existing files from database
            $files = $quote->files ?? [];
            
            // Handle new file uploads if provided
            if ($request->hasFile('files')) {
                $uploadedFiles = $request->file('files');
                
                // Create folder for this quote
                $randomString = Str::random(8);
                $dateTime = now()->format('Ymd_His');
                $folderName = 'quote_' . $randomString . '_' . $dateTime;
                $folderPath = public_path('uploads/quotes/' . $folderName);
                
                // Create directory if it doesn't exist
                if (!is_dir($folderPath)) {
                    mkdir($folderPath, 0755, true);
                }

                foreach ($uploadedFiles as $file) {
                    // Additional security: Validate MIME type
                    $allowedMimeTypes = [
                        'application/pdf',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'image/jpeg',
                        'image/png',
                        'image/gif',
                    ];
                    
                    $fileMimeType = $file->getMimeType();
                    if (!in_array($fileMimeType, $allowedMimeTypes)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Invalid file type. Only PDF, DOC, DOCX, and image files are allowed.',
                            'errors' => ['files' => ['File ' . $file->getClientOriginalName() . ' has invalid MIME type.']]
                        ], 422);
                    }
                    
                    // Sanitize filename to prevent directory traversal
                    $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                    $extension = strtolower($file->getClientOriginalExtension());
                    
                    // Validate extension matches MIME type
                    $allowedExtensions = ['pdf', 'doc', 'docx', 'jpeg', 'jpg', 'png', 'gif'];
                    if (!in_array($extension, $allowedExtensions)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Invalid file extension. Only PDF, DOC, DOCX, and image files are allowed.',
                            'errors' => ['files' => ['File ' . $file->getClientOriginalName() . ' has invalid extension.']]
                        ], 422);
                    }
                    
                    // Sanitize filename
                    $sanitizedName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $originalName);
                    $fileName = $sanitizedName . '_' . time() . '_' . Str::random(4) . '.' . $extension;
                    $fullPath = $folderPath . '/' . $fileName;
                    $relativePath = 'uploads/quotes/' . $folderName . '/' . $fileName;
                    
                    $file->move($folderPath, $fileName);
                    $files[] = [
                        'name' => $file->getClientOriginalName(),
                        'path' => $relativePath,
                        'full_path' => $fullPath,
                        'url' => '/' . $relativePath,
                        'size' => filesize($fullPath),
                        'mime_type' => $fileMimeType,
                    ];
                }
            }

            // Update quote record
            $quote->update([
                'name' => $request->name,
                'mobile_number' => $request->mobile_number,
                'email' => $request->email,
                'request_type' => $request->request_type ?? $quote->request_type,
                'service' => $request->service,
                'state' => $request->state,
                'project_type' => $request->project_type,
                'budget' => $request->budget,
                'project_details' => $request->project_details,
                'project_date' => $request->project_date,
                'files' => $files,
                'status' => $request->status ?? $quote->status,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Customer updated successfully',
                'data' => $quote
            ]);

        } catch (\Exception $e) {
            \Log::error('Quote update error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update customer. Please try again later.'
            ], 500);
        }
    }

    /**
     * Remove the specified quote from storage.
     */
    public function destroy($id)
    {
        $quote = Quote::find($id);

        if (!$quote) {
            return response()->json([
                'success' => false,
                'message' => 'Quote not found'
            ], 404);
        }

        // Delete associated files if they exist
        if ($quote->files && is_array($quote->files)) {
            foreach ($quote->files as $file) {
                if (isset($file['full_path']) && file_exists($file['full_path'])) {
                    @unlink($file['full_path']);
                }
                // Also try to delete the directory if it's empty
                if (isset($file['path'])) {
                    $fileDir = dirname(public_path($file['path']));
                    if (is_dir($fileDir) && count(glob($fileDir . '/*')) === 0) {
                        @rmdir($fileDir);
                    }
                }
            }
        }

        $quote->delete();

        return response()->json([
            'success' => true,
            'message' => 'Quote deleted successfully'
        ]);
    }
}
