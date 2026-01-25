<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PdfDownload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PdfDownloadController extends Controller
{
    /**
     * Get full PDF URL using APP_URL from .env
     * 
     * @param string $relativePath Path relative to public (e.g., uploads/pdf-downloads/folder/file.pdf)
     * @return string Full absolute URL
     */
    private function getPdfUrl($relativePath)
    {
        $appUrl = rtrim(config('app.url'), '/');
        // Construct: APP_URL + / + relative path (files are directly in public/uploads)
        // Example: https://sdsapi.veeyaainnovatives.com/uploads/pdf-downloads/folder/file.pdf
        return $appUrl . '/' . ltrim($relativePath, '/');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $search = $request->get('search');

        $query = PdfDownload::with('user');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('file_name', 'like', "%{$search}%");
            });
        }

        $pdfs = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // Update URLs to use APP_URL from .env - always reconstruct from file_path
        $pdfs->getCollection()->transform(function ($pdf) {
            // Always reconstruct URL from file_path using APP_URL from .env
            // Files are now directly in public/uploads, so URL is just /uploads/...
            $pdf->file_url = $this->getPdfUrl($pdf->file_path);
            return $pdf;
        });

        return response()->json([
            'success' => true,
            'data' => $pdfs
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'short_description' => 'nullable|string|max:500',
            'file' => 'required|file|mimes:pdf|max:10240', // Max 10MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $file = $request->file('file');
            
            // Validate MIME type
            if ($file->getMimeType() !== 'application/pdf') {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid file type. Only PDF files are allowed.',
                    'errors' => ['file' => ['Only PDF files are allowed.']]
                ], 422);
            }

            // Generate folder name: random alphabets + date_time
            $randomString = Str::random(8);
            $dateTime = now()->format('Ymd_His');
            $folderName = $randomString . '_' . $dateTime;

            // Get original filename without extension
            $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $sanitizedName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $originalName);
            $fileName = $sanitizedName . '.pdf';

            // Create full path - save directly to public/uploads/pdf-downloads
            $folderPath = public_path('uploads/pdf-downloads/' . $folderName);
            $fullPath = $folderPath . '/' . $fileName;
            $relativePath = 'uploads/pdf-downloads/' . $folderName . '/' . $fileName;

            // Create directory if it doesn't exist
            if (!is_dir($folderPath)) {
                mkdir($folderPath, 0755, true);
            }

            // Move file to public folder
            $file->move($folderPath, $fileName);
            $fileSize = filesize($fullPath);

            // Get public URL - direct path from public folder
            $fileUrl = '/' . $relativePath;

            $pdfDownload = PdfDownload::create([
                'title' => $request->title,
                'short_description' => $request->short_description,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $relativePath,
                'file_url' => $fileUrl,
                'file_size' => $fileSize,
                'user_id' => auth('api')->id(),
            ]);

            // Update URL to use APP_URL from .env
            $pdfDownload->file_url = $this->getPdfUrl($pdfDownload->file_path);

            return response()->json([
                'success' => true,
                'message' => 'PDF uploaded successfully',
                'data' => $pdfDownload->load('user')
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload PDF: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $pdfDownload = PdfDownload::with('user')->find($id);

        if (!$pdfDownload) {
            return response()->json([
                'success' => false,
                'message' => 'PDF not found'
            ], 404);
        }

        // Update URL to use APP_URL from .env
        $pdfDownload->file_url = $this->getPdfUrl($pdfDownload->file_path);

        return response()->json([
            'success' => true,
            'data' => $pdfDownload
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $pdfDownload = PdfDownload::find($id);

        if (!$pdfDownload) {
            return response()->json([
                'success' => false,
                'message' => 'PDF not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'short_description' => 'nullable|string|max:500',
            'file' => 'nullable|file|mimes:pdf|max:10240', // Max 10MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $updateData = [
                'title' => $request->title,
                'short_description' => $request->short_description,
            ];

            // If new file is uploaded, replace the old one
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                
                // Validate MIME type
                if ($file->getMimeType() !== 'application/pdf') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid file type. Only PDF files are allowed.',
                        'errors' => ['file' => ['Only PDF files are allowed.']]
                    ], 422);
                }

                // Delete old file and folder
                $oldFilePath = $pdfDownload->file_path;
                $oldFolderPath = dirname($oldFilePath); // Gets: uploads/pdf-downloads/randomString_datetime
                $fullOldFolderPath = public_path($oldFolderPath);
                $fullOldFilePath = public_path($oldFilePath);

                // Delete the entire folder (which contains the random generated string)
                if (is_dir($fullOldFolderPath)) {
                    // Delete all files in the folder first
                    $files = glob($fullOldFolderPath . '/*');
                    foreach ($files as $fileToDelete) {
                        if (is_file($fileToDelete)) {
                            unlink($fileToDelete);
                        }
                    }
                    // Then delete the folder
                    rmdir($fullOldFolderPath);
                }

                // Also delete the file itself (in case folder deletion fails)
                if (file_exists($fullOldFilePath)) {
                    unlink($fullOldFilePath);
                }

                // Generate new folder name: random alphabets + date_time
                $randomString = Str::random(8);
                $dateTime = now()->format('Ymd_His');
                $folderName = $randomString . '_' . $dateTime;

                // Get original filename without extension
                $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $sanitizedName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $originalName);
                $fileName = $sanitizedName . '.pdf';

                // Create full path - save directly to public/uploads/pdf-downloads
                $folderPath = public_path('uploads/pdf-downloads/' . $folderName);
                $fullPath = $folderPath . '/' . $fileName;
                $relativePath = 'uploads/pdf-downloads/' . $folderName . '/' . $fileName;

                // Create directory if it doesn't exist
                if (!is_dir($folderPath)) {
                    mkdir($folderPath, 0755, true);
                }

                // Move file to public folder
                $file->move($folderPath, $fileName);
                $fileSize = filesize($fullPath);

                // Get public URL - direct path from public folder
                $fileUrl = '/' . $relativePath;

                $updateData['file_name'] = $file->getClientOriginalName();
                $updateData['file_path'] = $relativePath;
                $updateData['file_url'] = $fileUrl;
                $updateData['file_size'] = $fileSize;
            }

            $pdfDownload->update($updateData);
            $pdfDownload->refresh();

            // Update URL to use APP_URL from .env
            $pdfDownload->file_url = $this->getPdfUrl($pdfDownload->file_path);

            return response()->json([
                'success' => true,
                'message' => 'PDF updated successfully',
                'data' => $pdfDownload->load('user')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update PDF: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Public API: Display a listing of all PDF downloads (for website viewing)
     * Returns all PDFs (no pagination)
     * Supports search parameter
     */
    public function publicIndex(Request $request)
    {
        $search = $request->get('search');

        $query = PdfDownload::query();

        // Search functionality
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('file_name', 'like', "%{$search}%")
                  ->orWhere('short_description', 'like', "%{$search}%");
            });
        }

        // Return all PDFs ordered by created_at
        $pdfs = $query->orderBy('created_at', 'desc')->get();

        // Update URLs to use APP_URL from .env
        $pdfs->transform(function ($pdf) {
            $pdf->file_url = $this->getPdfUrl($pdf->file_path);
            return $pdf;
        });

        return response()->json([
            'success' => true,
            'data' => $pdfs,
            'count' => $pdfs->count()
        ]);
    }

    /**
     * Public API: Display a single PDF download by ID (for website viewing)
     */
    public function publicShow($id)
    {
        $pdfDownload = PdfDownload::find($id);

        if (!$pdfDownload) {
            return response()->json([
                'success' => false,
                'message' => 'PDF not found'
            ], 404);
        }

        // Update URL to use APP_URL from .env
        $pdfDownload->file_url = $this->getPdfUrl($pdfDownload->file_path);

        return response()->json([
            'success' => true,
            'data' => $pdfDownload
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $pdfDownload = PdfDownload::find($id);

        if (!$pdfDownload) {
            return response()->json([
                'success' => false,
                'message' => 'PDF not found'
            ], 404);
        }

        try {
            // Extract folder path from file_path
            // file_path format: uploads/pdf-downloads/randomString_datetime/filename.pdf
            // We need to delete the entire folder: uploads/pdf-downloads/randomString_datetime/
            $filePath = $pdfDownload->file_path;
            $folderPath = dirname($filePath); // Gets: uploads/pdf-downloads/randomString_datetime
            $fullFolderPath = public_path($folderPath);
            $fullFilePath = public_path($filePath);

            // Delete the entire folder (which contains the random generated string)
            if (is_dir($fullFolderPath)) {
                // Delete all files in the folder first
                $files = glob($fullFolderPath . '/*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                // Then delete the folder
                rmdir($fullFolderPath);
            }

            // Also delete the file itself (in case folder deletion fails)
            if (file_exists($fullFilePath)) {
                unlink($fullFilePath);
            }

            // Delete from database
            $pdfDownload->delete();

            return response()->json([
                'success' => true,
                'message' => 'PDF and folder deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete PDF: ' . $e->getMessage()
            ], 500);
        }
    }
}

