<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FileUpload;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class FileUploadController extends Controller
{
    /**
     * Display a listing of file uploads.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = FileUpload::with(['uploader', 'updater', 'customer']);

            // Search filter
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                        $q->where('original_name', 'like', "%{$search}%")
                          ->orWhere('description', 'like', "%{$search}%");
                });
            }


            // Customer filter
            if ($request->has('customer_id') && $request->customer_id) {
                $query->where('customer_id', $request->customer_id);
            }

            // Status filter
            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            // File type filter
            if ($request->has('file_type') && $request->file_type) {
                $query->where('file_type', 'like', "%{$request->file_type}%");
            }

            // Date range filter
            if ($request->has('date_from') && $request->date_from) {
                $query->where('created_at', '>=', $request->date_from);
            }
            if ($request->has('date_to') && $request->date_to) {
                $query->where('created_at', '<=', $request->date_to);
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            $files = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'File uploads retrieved successfully',
                'data' => $files
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching file uploads: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve file uploads',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created file upload in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
                $validator = Validator::make($request->all(), [
                    'files' => 'required|array|min:1',
                    'files.*' => 'required|file|max:10240', // 10MB max per file
                    'description' => 'nullable|string',
                    'customer_id' => 'nullable|exists:customers,id',
                    'upload_type' => 'nullable|in:single,multiple'
                ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            $uploadedFiles = [];
            $uploadType = $request->get('upload_type', 'multiple');

            foreach ($request->file('files') as $file) {
                $fileData = $this->storeFile($file, $request);
                $uploadedFiles[] = $fileData;
            }

            return response()->json([
                'success' => true,
                'message' => 'Files uploaded successfully',
                'data' => $uploadedFiles
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error uploading files: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload files',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified file upload.
     */
    public function show(FileUpload $fileUpload): JsonResponse
    {
        try {
            $fileUpload->load(['uploader', 'updater']);
            return response()->json([
                'success' => true,
                'message' => 'File upload retrieved successfully',
                'data' => $fileUpload
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching file upload: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve file upload',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified file upload in storage.
     */
    public function update(Request $request, FileUpload $fileUpload): JsonResponse
    {
        try {
                $validator = Validator::make($request->all(), [
                    'description' => 'nullable|string',
                    'status' => 'nullable|in:active,inactive,deleted',
                    'notes' => 'nullable|string'
                ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

                $fileUpload->update([
                    'description' => $request->get('description', $fileUpload->description),
                    'status' => $request->get('status', $fileUpload->status),
                    'notes' => $request->get('notes', $fileUpload->notes),
                    'updated_by' => Auth::id()
                ]);

            $fileUpload->load(['uploader', 'updater']);

            return response()->json([
                'success' => true,
                'message' => 'File upload updated successfully',
                'data' => $fileUpload
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating file upload: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update file upload',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified file upload from storage.
     */
    public function destroy(FileUpload $fileUpload): JsonResponse
    {
        try {
            // Delete the physical file
            if (Storage::disk('public')->exists($fileUpload->file_path)) {
                Storage::disk('public')->delete($fileUpload->file_path);
            }

            // Soft delete the record
            $fileUpload->delete();

            return response()->json([
                'success' => true,
                'message' => 'File upload deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting file upload: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete file upload',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download the specified file.
     */
    public function download(FileUpload $fileUpload): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        try {
            Log::info('Download request for file: ' . $fileUpload->id);
            Log::info('File path: ' . $fileUpload->file_path);
            Log::info('Original name: ' . $fileUpload->original_name);
            
            // Check if file exists using the public disk
            if (!Storage::disk('public')->exists($fileUpload->file_path)) {
                Log::error('File not found at path: ' . $fileUpload->file_path);
                abort(404, 'File not found');
            }

            Log::info('File exists, proceeding with download');
            return Storage::disk('public')->download($fileUpload->file_path, $fileUpload->original_name);
        } catch (\Exception $e) {
            Log::error('Error downloading file: ' . $e->getMessage());
            Log::error('File path: ' . $fileUpload->file_path);
            abort(500, 'Failed to download file');
        }
    }

    /**
     * Get file categories.
     */
    public function categories(): JsonResponse
    {
        try {
            $categories = FileUpload::getCategoryOptions();
            return response()->json([
                'success' => true,
                'message' => 'Categories retrieved successfully',
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching categories: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve categories',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get file status options.
     */
    public function statusOptions(): JsonResponse
    {
        try {
            $statusOptions = FileUpload::getStatusOptions();
            return response()->json([
                'success' => true,
                'message' => 'Status options retrieved successfully',
                'data' => $statusOptions
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching status options: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve status options',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a single file and return file upload data.
     */
    private function storeFile($file, Request $request): FileUpload
    {
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $mimeType = $file->getMimeType();
        $fileSize = $file->getSize();
        
        // Generate unique filename
        $fileName = Str::uuid() . '_' . time() . '.' . $extension;
        
        // Determine category based on file type
        $category = $request->get('category');
        if (!$category) {
            $category = $this->determineCategory($mimeType);
        }
        
        // Store file in public/files/uploads/{category}/
        $folderPath = public_path("files/uploads/{$category}");
        if (!file_exists($folderPath)) {
            mkdir($folderPath, 0755, true);
        }
        $file->move($folderPath, $fileName);
        $filePath = "files/uploads/{$category}/{$fileName}";
        
        // Create database record
        $fileUpload = FileUpload::create([
            'original_name' => $originalName,
            'file_name' => $fileName,
            'file_path' => $filePath,
            'file_type' => $mimeType,
            'file_extension' => $extension,
            'file_size' => $fileSize,
            'category' => $category,
            'description' => $request->get('description'),
            'customer_id' => $request->get('customer_id'),
            'upload_type' => $request->get('upload_type', 'multiple'),
            'status' => 'active',
            'notes' => $request->get('notes'),
            'uploaded_by' => Auth::id(),
            'updated_by' => Auth::id()
        ]);

        $fileUpload->load(['uploader', 'updater']);
        return $fileUpload;
    }

    /**
     * Determine file category based on MIME type.
     */
    private function determineCategory(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        } elseif (str_starts_with($mimeType, 'video/')) {
            return 'video';
        } elseif (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        } elseif (in_array($mimeType, [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain'
        ])) {
            return 'document';
        } elseif (in_array($mimeType, [
            'application/zip',
            'application/x-rar-compressed',
            'application/x-7z-compressed'
        ])) {
            return 'archive';
        } else {
            return 'other';
        }
    }
}
