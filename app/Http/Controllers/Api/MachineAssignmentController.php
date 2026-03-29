<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerMachine;
use App\Models\Machine;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MachineAssignmentController extends Controller
{
    /**
     * Display a listing of machine assignments.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = $request->get('per_page', 15);
            
            // Build query with relationships (customer group for branch / master views)
            $query = CustomerMachine::with(['customer.customerGroup', 'machine'])
                ->whereNull('deleted_at');
            
            // Apply filters if provided
            if ($request->filled('customer_id')) {
                $query->where('cust_id', $request->customer_id);
            }
            
            if ($request->filled('machine_id')) {
                $query->where('machine_id', $request->machine_id);
            }
            
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->whereHas('customer', function($customerQuery) use ($search) {
                        $customerQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('company_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })->orWhereHas('machine', function($machineQuery) use ($search) {
                        $machineQuery->where('machine_alias', 'like', "%{$search}%")
                            ->orWhere('serial_number', 'like', "%{$search}%");
                    });
                });
            }
            
            // Get paginated results
            $assignments = $query->orderBy('created_at', 'desc')->paginate($perPage);
            
            // Transform the data to include customer name and machine name
            $transformedData = $assignments->getCollection()->map(function($assignment) {
                $customerPayload = null;
                if ($assignment->customer) {
                    $c = $assignment->customer;
                    $group = $c->customerGroup;
                    $customerPayload = array_merge($c->toArray(), [
                        'customer_group' => $group ? [
                            'id' => $group->id,
                            'name' => $group->name,
                        ] : null,
                    ]);
                }

                return [
                    'id' => $assignment->id,
                    'cust_id' => $assignment->cust_id,
                    'machine_id' => $assignment->machine_id,
                    'customer_name' => $assignment->customer ? ($assignment->customer->company_name ?: $assignment->customer->name) : null,
                    'machine_name' => $assignment->machine ? $assignment->machine->machine_alias : null,
                    'assigned_date' => $assignment->assigned_date,
                    'notes' => $assignment->notes,
                    'status' => $assignment->status,
                    'created_by' => $assignment->created_by,
                    'updated_by' => $assignment->updated_by,
                    'created_at' => $assignment->created_at,
                    'updated_at' => $assignment->updated_at,
                    'deleted_at' => $assignment->deleted_at,
                    'customer' => $customerPayload,
                    'machine' => $assignment->machine,
                ];
            });
            
            // Replace the collection with transformed data
            $assignments->setCollection($transformedData);
            
            return response()->json([
                'success' => true,
                'message' => 'Machine assignments retrieved successfully',
                'data' => $assignments
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving machine assignments',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created machine assignment.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,id',
                'machine_ids' => 'required|array|min:1',
                'machine_ids.*' => 'exists:machines,id',
                'status' => 'nullable|in:active,inactive,completed'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $createdAssignments = [];
            $conflictingMachines = [];

            // Check for existing assignments and create new ones
            foreach ($request->machine_ids as $machineId) {
                $existingAssignment = CustomerMachine::where('machine_id', $machineId)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')
                    ->first();

                if ($existingAssignment) {
                    $conflictingMachines[] = $machineId;
                    continue;
                }

                $assignment = CustomerMachine::create([
                    'cust_id' => $request->customer_id,
                    'machine_id' => $machineId,
                    'status' => $request->status ?? 'active',
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id()
                ]);

                $assignment->load(['customer', 'machine', 'creator', 'updater']);
                $createdAssignments[] = $assignment;
            }

            if (!empty($conflictingMachines)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Some machines are already assigned to other customers',
                    'conflicting_machines' => $conflictingMachines,
                    'created_assignments' => $createdAssignments
                ], 409);
            }

            return response()->json([
                'success' => true,
                'message' => 'Machine assignments created successfully',
                'data' => $createdAssignments
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating machine assignments',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified machine assignment.
     */
    public function show($id): JsonResponse
    {
        try {
            $assignment = CustomerMachine::with(['customer.customerGroup', 'machine', 'creator', 'updater'])
                ->whereNull('deleted_at')
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Machine assignment retrieved successfully',
                'data' => $assignment
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Machine assignment not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified machine assignment.
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $assignment = CustomerMachine::whereNull('deleted_at')->findOrFail($id);

            $validator = Validator::make($request->all(), [
                'customer_id' => 'sometimes|exists:customers,id',
                'machine_id' => 'sometimes|exists:machines,id',
                'status' => 'sometimes|in:active,inactive,completed'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check for conflicting assignments if machine_id is being updated
            if ($request->has('machine_id') && $request->machine_id != $assignment->machine_id) {
                $existingAssignment = CustomerMachine::where('machine_id', $request->machine_id)
                    ->where('id', '!=', $id)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')
                    ->first();

                if ($existingAssignment) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Machine is already assigned to another customer',
                        'conflicting_assignment' => $existingAssignment->id
                    ], 409);
                }
            }

            $updateData = $request->only(['cust_id', 'machine_id', 'status']);
            if ($request->has('customer_id')) {
                $updateData['cust_id'] = $request->customer_id;
            }
            $updateData['updated_by'] = auth()->id();

            $assignment->update($updateData);

            // Load relationships
            $assignment->load(['customer', 'machine', 'creator', 'updater']);

            return response()->json([
                'success' => true,
                'message' => 'Machine assignment updated successfully',
                'data' => $assignment
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating machine assignment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified machine assignment.
     */
    public function destroy($id): JsonResponse
    {
        try {
            $assignment = CustomerMachine::whereNull('deleted_at')->findOrFail($id);
            
            // Update updated_by before soft delete
            if (auth()->check()) {
                $assignment->updated_by = auth()->id();
                $assignment->save();
            }
            
            // Use SoftDeletes delete() method which automatically sets deleted_at
            $assignment->delete();

            return response()->json([
                'success' => true,
                'message' => 'Machine assignment deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting machine assignment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test endpoint to check raw data
     */
    public function testData(): JsonResponse
    {
        try {
            // Get all records without any filters
            $allRecords = CustomerMachine::all();
            
            // Check if customers and machines exist
            $customerIds = $allRecords->pluck('cust_id')->unique();
            $machineIds = $allRecords->pluck('machine_id')->unique();
            
            $existingCustomers = \App\Models\Customer::whereIn('id', $customerIds)->get();
            $existingMachines = \App\Models\Machine::whereIn('id', $machineIds)->get();
            
            return response()->json([
                'success' => true,
                'message' => 'Test data retrieved',
                'data' => [
                    'total_customer_machines' => $allRecords->count(),
                    'customer_machines_records' => $allRecords->toArray(),
                    'unique_customer_ids' => $customerIds->toArray(),
                    'unique_machine_ids' => $machineIds->toArray(),
                    'existing_customers' => $existingCustomers->toArray(),
                    'existing_machines' => $existingMachines->toArray(),
                    'missing_customers' => $customerIds->diff($existingCustomers->pluck('id'))->toArray(),
                    'missing_machines' => $machineIds->diff($existingMachines->pluck('id'))->toArray()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving test data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get machine assignment statistics.
     */
    public function stats(): JsonResponse
    {
        try {
            $stats = DB::select('CALL GetMachineAssignmentStats()')[0];

            return response()->json([
                'success' => true,
                'message' => 'Machine assignment statistics retrieved successfully',
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get assignments for a specific customer.
     */
    public function getCustomerAssignments($customerId): JsonResponse
    {
        try {
            $assignments = CustomerMachine::with(['customer', 'machine', 'creator', 'updater'])
                ->where('cust_id', $customerId)
                ->whereNull('deleted_at')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Customer assignments retrieved successfully',
                'data' => [
                    'data' => $assignments,
                    'total' => $assignments->count()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving customer assignments',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get assignments for a specific machine.
     */
    public function getMachineAssignments($machineId): JsonResponse
    {
        try {
            $assignments = CustomerMachine::with(['customer', 'machine', 'creator', 'updater'])
                ->where('machine_id', $machineId)
                ->whereNull('deleted_at')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Machine assignments retrieved successfully',
                'data' => [
                    'data' => $assignments,
                    'total' => $assignments->count()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving machine assignments',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
