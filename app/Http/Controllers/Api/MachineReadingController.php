<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MachineReading;
use App\Models\MachineReadingCategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MachineReadingController extends Controller
{
    /**
     * Store a newly created machine reading with categories
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|exists:customers,id',
            'machine_id' => 'required|exists:machines,id',
            'reading_date' => 'required|date',
            'reading_type' => 'nullable|string|max:255',
            'categories' => 'required|array|min:1',
            'categories.*.category' => 'required|string|max:255',
            'categories.*.reading_value' => 'required|numeric|min:0',
            'categories.*.notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            DB::beginTransaction();

            // Format the date to ensure proper storage (Y-m-d format)
            $readingDate = Carbon::parse($request->reading_date)->format('Y-m-d');

            // Check if reading already exists, update or create
            $machineReading = MachineReading::updateOrCreate(
                [
                    'customer_id' => $request->customer_id,
                    'machine_id' => $request->machine_id,
                    'reading_date' => $readingDate,
                ],
                [
                    'user_id' => $user->id,
                    'reading_type' => $request->reading_type,
                ]
            );

            // Delete existing categories and create new ones
            MachineReadingCategory::where('machine_readings_id', $machineReading->id)->delete();

            // Create category records
            $categories = [];
            foreach ($request->categories as $categoryData) {
                $category = MachineReadingCategory::create([
                    'machine_readings_id' => $machineReading->id,
                    'category' => $categoryData['category'],
                    'reading_value' => $categoryData['reading_value'],
                    'notes' => $categoryData['notes'] ?? null,
                ]);
                $categories[] = $category;
            }

            DB::commit();

            // Load relationships
            $machineReading->load(['user', 'customer', 'machine', 'categories']);

            return response()->json([
                'success' => true,
                'message' => 'Machine reading saved successfully',
                'data' => $machineReading
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error saving machine reading: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to save machine reading',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of machine readings
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = MachineReading::with(['user', 'customer', 'machine', 'categories']);

            // Filter by customer
            if ($request->has('customer_id') && $request->customer_id) {
                $query->where('customer_id', $request->customer_id);
            }

            // Filter by machine
            if ($request->has('machine_id') && $request->machine_id) {
                $query->where('machine_id', $request->machine_id);
            }

            // Filter by date range
            if ($request->has('start_date') && $request->start_date) {
                $query->where('reading_date', '>=', $request->start_date);
            }

            if ($request->has('end_date') && $request->end_date) {
                $query->where('reading_date', '<=', $request->end_date);
            }

            // Filter by reading type
            if ($request->has('reading_type') && $request->reading_type) {
                $query->where('reading_type', $request->reading_type);
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            $readings = $query->orderBy('reading_date', 'desc')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Machine readings retrieved successfully',
                'data' => $readings
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching machine readings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve machine readings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get existing reading by customer, machine, and date
     */
    public function getExistingReading(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|exists:customers,id',
            'machine_id' => 'required|exists:machines,id',
            'reading_date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Format the date to ensure proper comparison (Y-m-d format)
            $readingDate = Carbon::parse($request->reading_date)->format('Y-m-d');
            
            // Debug logging
            Log::info('Checking for existing reading', [
                'customer_id' => $request->customer_id,
                'machine_id' => $request->machine_id,
                'reading_date_input' => $request->reading_date,
                'reading_date_formatted' => $readingDate
            ]);
            
            // Use whereDate for proper date comparison (ignores time component)
            $machineReading = MachineReading::with(['user', 'customer', 'machine', 'categories'])
                ->where('customer_id', $request->customer_id)
                ->where('machine_id', $request->machine_id)
                ->whereDate('reading_date', $readingDate)
                ->first();
            
            // Debug logging
            if ($machineReading) {
                Log::info('Found existing reading', [
                    'id' => $machineReading->id,
                    'stored_date' => $machineReading->reading_date
                ]);
            } else {
                Log::info('No existing reading found');
                // Try to see what dates exist for debugging
                $existingDates = MachineReading::where('customer_id', $request->customer_id)
                    ->where('machine_id', $request->machine_id)
                    ->select('reading_date')
                    ->get()
                    ->pluck('reading_date')
                    ->map(function($date) {
                        return $date ? Carbon::parse($date)->format('Y-m-d') : null;
                    })
                    ->toArray();
                Log::info('Existing dates for this customer/machine', ['dates' => $existingDates]);
            }

            if ($machineReading) {
                return response()->json([
                    'success' => true,
                    'message' => 'Machine reading found',
                    'data' => $machineReading
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'No reading found for this combination',
                    'data' => null
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error fetching existing machine reading: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve machine reading',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified machine reading
     */
    public function show($id): JsonResponse
    {
        try {
            $machineReading = MachineReading::with(['user', 'customer', 'machine', 'categories'])
                ->find($id);

            if (!$machineReading) {
                return response()->json([
                    'success' => false,
                    'message' => 'Machine reading not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Machine reading retrieved successfully',
                'data' => $machineReading
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching machine reading: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve machine reading',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
