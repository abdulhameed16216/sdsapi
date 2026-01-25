<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Career;
use App\Mail\CareerNotificationMail;
use App\Mail\CareerThankYouMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CareerController extends Controller
{
    /**
     * Store a new career application
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'mobile_number' => 'required|string|max:20',
            'position_applied_for' => 'required|string|max:255',
            'more_about_you' => 'nullable|string',
            'resume' => [
                'required',
                'file',
                'mimes:pdf',
                'mimetypes:application/pdf', // Only PDF files allowed
                'max:10240', // 10MB max
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
            $resumeData = null;
            
            // Handle resume file upload
            if ($request->hasFile('resume')) {
                $file = $request->file('resume');
                
                // Additional security: Validate MIME type
                $allowedMimeTypes = [
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                ];
                
                $fileMimeType = $file->getMimeType();
                if (!in_array($fileMimeType, $allowedMimeTypes)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid file type. Only PDF, DOC, and DOCX files are allowed.',
                        'errors' => ['resume' => ['Resume file has invalid MIME type.']]
                    ], 422);
                }
                
                // Sanitize filename to prevent directory traversal
                $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $extension = strtolower($file->getClientOriginalExtension());
                
                // Validate extension matches MIME type
                $allowedExtensions = ['pdf', 'doc', 'docx'];
                if (!in_array($extension, $allowedExtensions)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid file extension. Only PDF, DOC, and DOCX files are allowed.',
                        'errors' => ['resume' => ['Resume file has invalid extension.']]
                    ], 422);
                }
                
                // Create folder for careers
                $folderPath = public_path('uploads/careers');
                
                // Create directory if it doesn't exist
                if (!is_dir($folderPath)) {
                    mkdir($folderPath, 0755, true);
                }
                
                // Sanitize filename
                $sanitizedName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $originalName);
                $fileName = $sanitizedName . '_' . time() . '_' . Str::random(4) . '.' . $extension;
                $fullPath = $folderPath . '/' . $fileName;
                $relativePath = 'uploads/careers/' . $fileName;
                
                $file->move($folderPath, $fileName);
                
                $resumeData = [
                    'name' => $file->getClientOriginalName(),
                    'path' => $relativePath,
                    'full_path' => $fullPath,
                    'url' => '/' . $relativePath,
                    'size' => filesize($fullPath),
                    'mime_type' => $fileMimeType,
                ];
            }

            // Create career application record
            $career = Career::create([
                'full_name' => $request->full_name,
                'email' => $request->email,
                'mobile_number' => $request->mobile_number,
                'position_applied_for' => $request->position_applied_for,
                'more_about_you' => $request->more_about_you,
                'resume' => $resumeData,
                'status' => 'pending',
            ]);

            // Get client mail ID from config (where career notifications will be sent)
            $clientMailId = config('app.client_mail_id');

            // Send notification email to client with all career application details
            try {
                Mail::to($clientMailId)->send(new CareerNotificationMail($career));
            } catch (\Exception $e) {
                \Log::error('Failed to send career notification email: ' . $e->getMessage());
            }

            // Send thank you email to candidate
            try {
                Mail::to($career->email)->send(new CareerThankYouMail($career));
            } catch (\Exception $e) {
                \Log::error('Failed to send thank you email: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Application submitted successfully. We will contact you shortly.',
                'data' => $career
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Career application submission error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit application. Please try again later.'
            ], 500);
        }
    }
}

