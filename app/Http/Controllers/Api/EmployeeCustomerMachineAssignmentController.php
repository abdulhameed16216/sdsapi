<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeCustomerMachineAssignment;
use App\Models\Employee;
use App\Models\Customer;
use App\Models\Machine;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class EmployeeCustomerMachineAssignmentController extends Controller
{
    /**
     * Display a listing of employee-customer-machine assignments.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = $request->get('per_page', 15);
            
            // Build query with relationships
            $query = EmployeeCustomerMachineAssignment::with(['employee', 'customer', 'machine', 'creator', 'updater'])
                ->whereNull('deleted_at');
            
            // Apply filters if provided
            if ($request->filled('employee_id')) {
                $query->where('employee_id', $request->employee_id);
            }
            
            if ($request->filled('customer_id')) {
                $query->where('customer_id', $request->customer_id);
            }

            if ($request->filled('floor_id')) {
                $query->where('floor_id', $request->floor_id);
            }
            
            if ($request->filled('machine_id')) {
                $query->where('assigned_machine_id', $request->machine_id);
            }
            
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->whereHas('employee', function($employeeQuery) use ($search) {
                        $employeeQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('employee_code', 'like', "%{$search}%");
                    })->orWhereHas('customer', function($customerQuery) use ($search) {
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
            
            // Transform the data to include names
            $transformedData = $assignments->getCollection()->map(function($assignment) {
                return [
                    'id' => $assignment->id,
                    'employee_id' => $assignment->employee_id,
                    'customer_id' => $assignment->customer_id,
                    'floor_id' => $assignment->floor_id,
                    'assigned_machine_id' => $assignment->assigned_machine_id,
                    'employee_name' => $assignment->employee ? $assignment->employee->name : null,
                    'employee_code' => $assignment->employee ? $assignment->employee->employee_code : null,
                    'customer_name' => $assignment->customer ? ($assignment->customer->company_name ?: $assignment->customer->name) : null,
                    'machine_name' => $assignment->machine ? $assignment->machine->machine_alias : null,
                    'serial_number' => $assignment->machine ? $assignment->machine->serial_number : null,
                    'assigned_date' => $assignment->assigned_date,
                    'notes' => $assignment->notes,
                    'status' => $assignment->status,
                    'created_by' => $assignment->created_by,
                    'updated_by' => $assignment->updated_by,
                    'created_at' => $assignment->created_at,
                    'updated_at' => $assignment->updated_at,
                    'deleted_at' => $assignment->deleted_at,
                    // Include full relationships if needed
                    'employee' => $assignment->employee,
                    'customer' => $assignment->customer,
                    'machine' => $assignment->machine,
                ];
            });
            
            // Replace the collection with transformed data
            $assignments->setCollection($transformedData);
            
            return response()->json([
                'success' => true,
                'message' => 'Employee-customer-machine assignments retrieved successfully',
                'data' => $assignments
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving assignments',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store newly created employee-customer-machine assignments.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'assignments' => 'required|array|min:1',
                'assignments.*.employee_id' => 'required|exists:employees,id',
                'assignments.*.customer_id' => 'required|exists:customers,id',
                'assignments.*.floor_id' => 'nullable|exists:customers_floor,id',
                'assignments.*.assigned_machine_id' => 'nullable|exists:machines,id',
                'assignments.*.assigned_date' => 'nullable|date',
                'assignments.*.notes' => 'nullable|string|max:500',
                'assignments.*.status' => 'nullable|in:active,inactive,completed'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $createdAssignments = [];
            $conflictingAssignments = [];

            // Create assignments
            foreach ($request->assignments as $assignmentData) {
                // Check if assignment already exists (only check if machine_id is provided)
                $query = EmployeeCustomerMachineAssignment::where('employee_id', $assignmentData['employee_id'])
                    ->where('customer_id', $assignmentData['customer_id'])
                    ->where('floor_id', $assignmentData['floor_id'] ?? null)
                    ->where('status', 'active')
                    ->whereNull('deleted_at');
                
                // If machine_id is provided, check for exact match including machine
                // If machine_id is null, check for employee-customer assignment without machine
                if (isset($assignmentData['assigned_machine_id']) && $assignmentData['assigned_machine_id'] !== null) {
                    $query->where('assigned_machine_id', $assignmentData['assigned_machine_id']);
                } else {
                    $query->whereNull('assigned_machine_id');
                }
                
                $existingAssignment = $query->first();

                if ($existingAssignment) {
                    $conflictingAssignments[] = $assignmentData;
                    continue;
                }

                $userId = auth()->check() ? auth()->id() : null;
                
                $assignment = EmployeeCustomerMachineAssignment::create([
                    'employee_id' => $assignmentData['employee_id'],
                    'customer_id' => $assignmentData['customer_id'],
                    'floor_id' => $assignmentData['floor_id'] ?? null,
                    'assigned_machine_id' => $assignmentData['assigned_machine_id'],
                    'assigned_date' => $assignmentData['assigned_date'] ?? now()->toDateString(),
                    'notes' => $assignmentData['notes'] ?? null,
                    'status' => $assignmentData['status'] ?? 'active',
                    'created_by' => $userId,
                    'updated_by' => $userId
                ]);

                $assignment->load(['employee', 'customer', 'machine', 'creator', 'updater']);
                $createdAssignments[] = $assignment;
            }

            if (!empty($conflictingAssignments)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Some assignments already exist',
                    'conflicting_assignments' => $conflictingAssignments,
                    'created_assignments' => $createdAssignments
                ], 409);
            }

            return response()->json([
                'success' => true,
                'message' => 'Assignments created successfully',
                'data' => $createdAssignments
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating assignments',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified assignment.
     */
    public function show($id): JsonResponse
    {
        try {
            $assignment = EmployeeCustomerMachineAssignment::with(['employee', 'customer', 'machine', 'creator', 'updater'])
                ->whereNull('deleted_at')
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Assignment retrieved successfully',
                'data' => $assignment
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Assignment not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified assignment.
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $assignment = EmployeeCustomerMachineAssignment::whereNull('deleted_at')->findOrFail($id);

            $validator = Validator::make($request->all(), [
                'employee_id' => 'sometimes|exists:employees,id',
                'customer_id' => 'sometimes|exists:customers,id',
                'floor_id' => 'nullable|exists:customers_floor,id',
                'assigned_machine_id' => 'sometimes|exists:machines,id',
                'assigned_date' => 'nullable|date',
                'notes' => 'nullable|string|max:500',
                'status' => 'sometimes|in:active,inactive,completed'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check for conflicting assignments if updating key fields
            if ($request->has(['employee_id', 'customer_id', 'assigned_machine_id'])) {
                $existingAssignment = EmployeeCustomerMachineAssignment::where('employee_id', $request->employee_id ?? $assignment->employee_id)
                    ->where('customer_id', $request->customer_id ?? $assignment->customer_id)
                    ->where('floor_id', $request->floor_id ?? $assignment->floor_id)
                    ->where('assigned_machine_id', $request->assigned_machine_id ?? $assignment->assigned_machine_id)
                    ->where('id', '!=', $id)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')
                    ->first();

                if ($existingAssignment) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This assignment already exists',
                        'conflicting_assignment' => $existingAssignment->id
                    ], 409);
                }
            }

            $updateData = $request->only(['employee_id', 'customer_id', 'floor_id', 'assigned_machine_id', 'assigned_date', 'notes', 'status']);
            $updateData['updated_by'] = auth()->check() ? auth()->id() : null;

            $assignment->update($updateData);

            // Load relationships
            $assignment->load(['employee', 'customer', 'machine', 'creator', 'updater']);

            return response()->json([
                'success' => true,
                'message' => 'Assignment updated successfully',
                'data' => $assignment
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating assignment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified assignment.
     */
    public function destroy($id): JsonResponse
    {
        try {
            // Find the assignment by ID, excluding already soft-deleted records
            $assignment = EmployeeCustomerMachineAssignment::whereNull('deleted_at')
                ->where('id', $id)
                ->firstOrFail();
            
            // Update updated_by before deleting
            if (auth()->check()) {
                $assignment->updated_by = auth()->id();
            }
            
            // Use the delete() method which properly handles SoftDeletes
            // This will set the deleted_at timestamp automatically
            $assignment->delete();

            // Verify the deletion by checking if deleted_at is set
            $assignment->refresh();
            
            if ($assignment->deleted_at === null) {
                Log::warning("Assignment ID {$id} was not properly soft-deleted. deleted_at is still null.");
            }

            return response()->json([
                'success' => true,
                'message' => 'Assignment deleted successfully',
                'data' => [
                    'id' => $assignment->id,
                    'deleted_at' => $assignment->deleted_at
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Assignment not found or already deleted',
                'error' => 'Assignment with ID ' . $id . ' not found'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Error deleting assignment: ' . $e->getMessage(), [
                'assignment_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error deleting assignment',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

