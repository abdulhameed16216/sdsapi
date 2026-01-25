<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PartnerProgram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class PartnerProgramController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $search = $request->get('search');

        $query = PartnerProgram::with('user');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('short_desc', 'like', "%{$search}%");
            });
        }

        $partnerPrograms = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $partnerPrograms
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'short_desc' => 'nullable|string',
            'list_items' => 'required|array|min:1',
            'list_items.*' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Filter out empty list items
        $filteredListItems = array_filter(
            array_map('trim', $request->list_items),
            function($item) {
                return !empty($item);
            }
        );

        if (empty($filteredListItems)) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => [
                    'list_items' => ['At least one list item is required.']
                ]
            ], 422);
        }

        $partnerProgram = PartnerProgram::create([
            'name' => $request->name,
            'short_desc' => $request->short_desc,
            'list_items' => array_values($filteredListItems), // Re-index array
            'user_id' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Partner program created successfully',
            'data' => $partnerProgram->load('user')
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $partnerProgram = PartnerProgram::with('user')->find($id);

        if (!$partnerProgram) {
            return response()->json([
                'success' => false,
                'message' => 'Partner program not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $partnerProgram
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $partnerProgram = PartnerProgram::find($id);

        if (!$partnerProgram) {
            return response()->json([
                'success' => false,
                'message' => 'Partner program not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'short_desc' => 'nullable|string',
            'list_items' => 'required|array|min:1',
            'list_items.*' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Filter out empty list items
        $filteredListItems = array_filter(
            array_map('trim', $request->list_items),
            function($item) {
                return !empty($item);
            }
        );

        if (empty($filteredListItems)) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => [
                    'list_items' => ['At least one list item is required.']
                ]
            ], 422);
        }

        $partnerProgram->update([
            'name' => $request->name,
            'short_desc' => $request->short_desc,
            'list_items' => array_values($filteredListItems), // Re-index array
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Partner program updated successfully',
            'data' => $partnerProgram->fresh(['user'])
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $partnerProgram = PartnerProgram::find($id);

        if (!$partnerProgram) {
            return response()->json([
                'success' => false,
                'message' => 'Partner program not found'
            ], 404);
        }

        $partnerProgram->delete();

        return response()->json([
            'success' => true,
            'message' => 'Partner program deleted successfully'
        ]);
    }

    /**
     * Public API: Display a listing of all partner programs (for website viewing)
     * Returns all partner programs (no pagination)
     * Supports search parameter
     */
    public function publicIndex(Request $request)
    {
        $search = $request->get('search');

        $query = PartnerProgram::query();

        // Search functionality
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('short_desc', 'like', "%{$search}%");
            });
        }

        // Return all partner programs ordered by created_at
        $partnerPrograms = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $partnerPrograms,
            'count' => $partnerPrograms->count()
        ]);
    }

    /**
     * Public API: Display a single partner program by ID (for website viewing)
     */
    public function publicShow($id)
    {
        $partnerProgram = PartnerProgram::find($id);

        if (!$partnerProgram) {
            return response()->json([
                'success' => false,
                'message' => 'Partner program not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $partnerProgram
        ]);
    }
}
