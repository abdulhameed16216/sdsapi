<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class FaqController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $search = $request->get('search');
        $serviceId = $request->get('service_id');

        $query = Faq::with(['service', 'user']);

        // Filter by service if provided
        if ($serviceId) {
            $query->where('service_id', $serviceId);
        }

        // Search functionality
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('question', 'like', '%' . $search . '%')
                  ->orWhere('answer', 'like', '%' . $search . '%')
                  ->orWhereHas('service', function($serviceQuery) use ($search) {
                      $serviceQuery->where('title', 'like', '%' . $search . '%');
                  });
            });
        }

        $faqs = $query->orderBy('order', 'asc')
                     ->orderBy('created_at', 'desc')
                     ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $faqs
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'service_id' => 'required|exists:services,id',
            'question' => 'required|string|max:1000',
            'answer' => 'required|string',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $faq = Faq::create([
            'service_id' => $request->service_id,
            'question' => $request->question,
            'answer' => $request->answer,
            'order' => $request->order ?? 0,
            'is_active' => $request->is_active ?? true,
            'user_id' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'FAQ created successfully',
            'data' => $faq->load(['service', 'user'])
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $faq = Faq::with(['service', 'user'])->find($id);

        if (!$faq) {
            return response()->json([
                'success' => false,
                'message' => 'FAQ not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $faq
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $faq = Faq::find($id);

        if (!$faq) {
            return response()->json([
                'success' => false,
                'message' => 'FAQ not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'service_id' => 'required|exists:services,id',
            'question' => 'required|string|max:1000',
            'answer' => 'required|string',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $faq->update([
            'service_id' => $request->service_id,
            'question' => $request->question,
            'answer' => $request->answer,
            'order' => $request->order ?? $faq->order,
            'is_active' => $request->is_active ?? $faq->is_active,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'FAQ updated successfully',
            'data' => $faq->fresh(['service', 'user'])
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $faq = Faq::find($id);

        if (!$faq) {
            return response()->json([
                'success' => false,
                'message' => 'FAQ not found'
            ], 404);
        }

        $faq->delete();

        return response()->json([
            'success' => true,
            'message' => 'FAQ deleted successfully'
        ]);
    }

    /**
     * Public API: Display a listing of active FAQs (for website viewing)
     * Returns all active FAQs (no pagination)
     * Supports search and service_id filter
     */
    public function publicIndex(Request $request)
    {
        $search = $request->get('search');
        $serviceId = $request->get('service_id');

        $query = Faq::with('service')
            ->where('is_active', true); // Only active FAQs

        // Filter by service if provided
        if ($serviceId) {
            $query->where('service_id', $serviceId);
        }

        // Search functionality
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('question', 'like', '%' . $search . '%')
                  ->orWhere('answer', 'like', '%' . $search . '%')
                  ->orWhereHas('service', function($serviceQuery) use ($search) {
                      $serviceQuery->where('title', 'like', '%' . $search . '%');
                  });
            });
        }

        // Return all FAQs ordered by order and created_at
        $faqs = $query->orderBy('order', 'asc')
                      ->orderBy('created_at', 'desc')
                      ->get();

        return response()->json([
            'success' => true,
            'data' => $faqs,
            'count' => $faqs->count()
        ]);
    }

    /**
     * Public API: Display a single active FAQ by ID (for website viewing)
     */
    public function publicShow($id)
    {
        $faq = Faq::with('service')
            ->where('id', $id)
            ->where('is_active', true) // Only active FAQs
            ->first();

        if (!$faq) {
            return response()->json([
                'success' => false,
                'message' => 'FAQ not found'
            ], 404);
        }

        // Get related FAQs from the same service (excluding current FAQ)
        $relatedFaqs = Faq::with('service')
            ->where('is_active', true)
            ->where('service_id', $faq->service_id)
            ->where('id', '!=', $faq->id)
            ->orderBy('order', 'asc')
            ->limit(5)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'faq' => $faq,
                'related_faqs' => $relatedFaqs
            ]
        ]);
    }
}

