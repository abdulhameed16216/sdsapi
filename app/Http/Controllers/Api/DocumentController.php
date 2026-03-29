<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    /**
     * Display a listing of documents
     */
    public function index(Request $request): JsonResponse
    {
        $query = Document::with('vendor');

        // Filter by vendor
        if ($request->has('vendor_id')) {
            $query->where('vendor_id', $request->get('vendor_id'));
        }

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->get('type'));
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        // Filter expiring documents
        if ($request->has('expiring_soon') && $request->get('expiring_soon')) {
            $query->expiringSoon();
        }

        $documents = $query->orderBy('document_date', 'desc')
                         ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $documents
        ]);
    }

    /**
     * Store a newly created document
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'vendor_id' => 'required|exists:vendors,id',
            'title' => 'required|string|max:255',
            'type' => 'required|string|max:255',
            'document_date' => 'required|date',
            'amount' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'expiry_date' => 'nullable|date|after:document_date',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx|max:10240', // 10MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $file = $request->file('file');
        $fileName = time() . '_' . $file->getClientOriginalName();
        
        // Store file in public/files/documents/
        $folderPath = public_path('files/documents');
        if (!file_exists($folderPath)) {
            mkdir($folderPath, 0755, true);
        }
        $file->move($folderPath, $fileName);
        $filePath = "files/documents/{$fileName}";

        $document = Document::create([
            'vendor_id' => $request->vendor_id,
            'title' => $request->title,
            'type' => $request->type,
            'file_path' => $filePath,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'document_date' => $request->document_date,
            'amount' => $request->amount,
            'description' => $request->description,
            'expiry_date' => $request->expiry_date,
            'status' => 'active',
        ]);

        $document->load('vendor');

        return response()->json([
            'success' => true,
            'message' => 'Document uploaded successfully',
            'data' => [
                'document' => $document
            ]
        ], 201);
    }

    /**
     * Display the specified document
     */
    public function show(Document $document): JsonResponse
    {
        $document->load('vendor');
        
        return response()->json([
            'success' => true,
            'data' => [
                'document' => $document
            ]
        ]);
    }

    /**
     * Update the specified document
     */
    public function update(Request $request, Document $document): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|string|max:255',
            'document_date' => 'sometimes|required|date',
            'amount' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'expiry_date' => 'nullable|date|after:document_date',
            'status' => 'sometimes|required|in:active,archived,expired',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $document->update($request->all());
        $document->load('vendor');

        return response()->json([
            'success' => true,
            'message' => 'Document updated successfully',
            'data' => [
                'document' => $document
            ]
        ]);
    }

    /**
     * Remove the specified document
     */
    public function destroy(Document $document): JsonResponse
    {
        // Delete file from storage
        if (Storage::disk('public')->exists($document->file_path)) {
            Storage::disk('public')->delete($document->file_path);
        }

        $document->delete();

        return response()->json([
            'success' => true,
            'message' => 'Document deleted successfully'
        ]);
    }

    /**
     * Download document
     */
    public function download(Document $document): JsonResponse
    {
        if (!Storage::disk('public')->exists($document->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'File not found'
            ], 404);
        }

        $filePath = Storage::disk('public')->path($document->file_path);
        
        return response()->download($filePath, $document->file_name);
    }

    /**
     * Get document alerts (expiring soon, expired)
     */
    public function alerts(): JsonResponse
    {
        $expiringSoon = Document::with('vendor')
            ->expiringSoon()
            ->get();

        $expired = Document::with('vendor')
            ->expired()
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'expiring_soon' => $expiringSoon,
                'expired' => $expired,
                'total_alerts' => $expiringSoon->count() + $expired->count()
            ]
        ]);
    }

    /**
     * Get document types
     */
    public function types(): JsonResponse
    {
        $types = Document::distinct()
            ->pluck('type')
            ->filter()
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'types' => $types
            ]
        ]);
    }
}
