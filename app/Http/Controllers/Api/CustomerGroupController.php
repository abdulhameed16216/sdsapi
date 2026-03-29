<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerGroup;
use App\Models\Customer;
use App\Models\CustomerFloor;
use App\Models\Employee;
use App\Models\EmployeeFloor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class CustomerGroupController extends Controller
{
    /**
     * List all customer groups (for tree first level)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = CustomerGroup::orderBy('name');
            $groups = $query->get(['id', 'name', 'description', 'status', 'created_at']);
            $data = $groups->map(function ($g) {
                $locationsCount = Customer::where('customer_group_id', $g->id)->count();
                return [
                    'id' => $g->id,
                    'name' => $g->name,
                    'description' => $g->description,
                    'status' => $g->status ?? 'active',
                    'locations_count' => $locationsCount,
                ];
            });
            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('CustomerGroup index: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load customer groups',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get one group with its locations (customers) and floors (for tree expand)
     */
    public function show(int $id): JsonResponse
    {
        try {
            $group = CustomerGroup::find($id);
            if (!$group) {
                return response()->json(['success' => false, 'message' => 'Customer group not found'], 404);
            }
            $locations = Customer::where('customer_group_id', $id)
                ->orderBy('name')
                ->get(['id', 'name', 'customer_group_id']);
            $locationIds = $locations->pluck('id')->toArray();
            $floorsByLocation = CustomerFloor::whereIn('location_id', $locationIds)
                ->orderBy('location_id')
                ->orderBy('name')
                ->get()
                ->groupBy('location_id');
            $locationsData = $locations->map(function ($loc) use ($floorsByLocation) {
                $floors = $floorsByLocation->get($loc->id, collect())->map(function ($f) {
                    return ['id' => $f->id, 'name' => $f->name, 'location_id' => $f->location_id];
                })->values();
                return [
                    'id' => $loc->id,
                    'name' => $loc->name,
                    'groupId' => $loc->customer_group_id,
                    'floors' => $floors,
                    'floors_count' => $floors->count(),
                ];
            });
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'description' => $group->description,
                    'status' => $group->status ?? 'active',
                    'locations' => $locationsData,
                    'locations_count' => $locationsData->count(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('CustomerGroup show: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load customer group',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create customer group
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:1000',
            'status' => 'nullable|in:active,inactive',
        ], [
            'name.required' => 'Customer group name is required.',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
        try {
            $user = auth()->user();
            $createdBy = $user && isset($user->employee_id) ? $user->employee_id : null;
            $group = CustomerGroup::create([
                'name' => $request->name,
                'description' => $request->input('description'),
                'status' => $request->input('status', 'active'),
                'created_by' => $createdBy,
                'updated_by' => $createdBy,
            ]);
            return response()->json([
                'success' => true,
                'message' => 'Customer group created successfully',
                'data' => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'description' => $group->description,
                    'status' => $group->status,
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('CustomerGroup store: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create customer group',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update customer group
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:1000',
            'status' => 'nullable|in:active,inactive',
        ], [
            'name.required' => 'Customer group name is required.',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
        try {
            $group = CustomerGroup::find($id);
            if (!$group) {
                return response()->json(['success' => false, 'message' => 'Customer group not found'], 404);
            }
            $user = auth()->user();
            $updatedBy = $user && isset($user->employee_id) ? $user->employee_id : null;
            $group->update([
                'name' => $request->name,
                'description' => $request->input('description'),
                'status' => $request->input('status', 'active'),
                'updated_by' => $updatedBy,
            ]);
            return response()->json([
                'success' => true,
                'message' => 'Customer group updated successfully',
                'data' => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'description' => $group->description,
                    'status' => $group->status,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('CustomerGroup update: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update customer group',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete (soft-delete) customer group
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $group = CustomerGroup::find($id);
            if (!$group) {
                return response()->json(['success' => false, 'message' => 'Customer group not found'], 404);
            }
            $group->delete();
            return response()->json([
                'success' => true,
                'message' => 'Customer group deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('CustomerGroup destroy: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete customer group',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a floor/section under a location (customer with customer_group_id).
     */
    public function storeFloor(Request $request, int $locationId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
        ], [
            'name.required' => 'Floor or section name is required.',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
        try {
            $customer = Customer::where('id', $locationId)->whereNotNull('customer_group_id')->first();
            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Location not found or is not part of a customer group.',
                ], 404);
            }
            $user = auth()->user();
            $empId = $user && isset($user->employee_id) ? $user->employee_id : null;
            $floor = CustomerFloor::create([
                'location_id' => $locationId,
                'name' => $request->name,
                'created_by' => $empId,
                'updated_by' => $empId,
            ]);
            return response()->json([
                'success' => true,
                'message' => 'Floor / section created successfully',
                'data' => [
                    'id' => $floor->id,
                    'name' => $floor->name,
                    'location_id' => $floor->location_id,
                    'groupId' => (int) $customer->customer_group_id,
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('CustomerGroup storeFloor: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create floor / section',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a floor/section name.
     */
    public function updateFloor(Request $request, int $floorId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
        ], [
            'name.required' => 'Floor or section name is required.',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
        try {
            $floor = CustomerFloor::find($floorId);
            if (!$floor) {
                return response()->json(['success' => false, 'message' => 'Floor / section not found'], 404);
            }
            $customer = Customer::where('id', $floor->location_id)->whereNotNull('customer_group_id')->first();
            if (!$customer) {
                return response()->json(['success' => false, 'message' => 'Invalid location for this floor'], 404);
            }
            $user = auth()->user();
            $empId = $user && isset($user->employee_id) ? $user->employee_id : null;
            $floor->update([
                'name' => $request->name,
                'updated_by' => $empId,
            ]);
            return response()->json([
                'success' => true,
                'message' => 'Floor / section updated successfully',
                'data' => [
                    'id' => $floor->id,
                    'name' => $floor->name,
                    'location_id' => $floor->location_id,
                    'groupId' => (int) $customer->customer_group_id,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('CustomerGroup updateFloor: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update floor / section',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Soft-delete a floor/section.
     */
    public function destroyFloor(int $floorId): JsonResponse
    {
        try {
            $floor = CustomerFloor::find($floorId);
            if (!$floor) {
                return response()->json(['success' => false, 'message' => 'Floor / section not found'], 404);
            }
            $floor->delete();
            return response()->json([
                'success' => true,
                'message' => 'Floor / section deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('CustomerGroup destroyFloor: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete floor / section',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Assign employee to floor (stores group/location/floor/employee).
     */
    public function storeFloorEmployee(Request $request, int $floorId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|integer|exists:employees,id',
        ], [
            'employee_id.required' => 'Employee is required.',
            'employee_id.exists' => 'Selected employee does not exist.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $floor = CustomerFloor::find($floorId);
            if (!$floor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Floor / section not found',
                ], 404);
            }

            $customer = Customer::where('id', $floor->location_id)->whereNotNull('customer_group_id')->first();
            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Location not found for this floor',
                ], 404);
            }

            $employee = Employee::find($request->employee_id);
            if (!$employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee not found',
                ], 404);
            }

            $assignment = EmployeeFloor::firstOrCreate([
                'group_id' => (int) $customer->customer_group_id,
                'location_id' => (int) $floor->location_id,
                'floor_id' => (int) $floor->id,
                'employee_id' => (int) $employee->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => $assignment->wasRecentlyCreated
                    ? 'Employee assigned to floor successfully'
                    : 'Employee already assigned to this floor',
                'data' => [
                    'id' => $assignment->id,
                    'group_id' => (int) $assignment->group_id,
                    'location_id' => (int) $assignment->location_id,
                    'floor_id' => (int) $assignment->floor_id,
                    'employee_id' => (int) $assignment->employee_id,
                    'employee_name' => $employee->employeeName ?? $employee->name ?? ('Employee #' . $employee->id),
                    'location_name' => $customer->name ?? ('Location #' . $floor->location_id),
                ],
            ], $assignment->wasRecentlyCreated ? 201 : 200);
        } catch (\Exception $e) {
            Log::error('CustomerGroup storeFloorEmployee: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign employee to floor',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get employees assigned to a floor.
     */
    public function floorEmployees(int $floorId): JsonResponse
    {
        try {
            $floor = CustomerFloor::find($floorId);
            if (!$floor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Floor / section not found',
                ], 404);
            }

            $rows = EmployeeFloor::where('floor_id', $floorId)
                ->orderByDesc('id')
                ->get();

            $employeeIds = $rows->pluck('employee_id')->unique()->values()->toArray();
            $employees = Employee::whereIn('id', $employeeIds)->get(['id', 'name', 'employee_code'])->keyBy('id');

            $customer = Customer::find($floor->location_id);
            $locationName = $customer ? ($customer->name ?? ('Location #' . $floor->location_id)) : ('Location #' . $floor->location_id);

            $data = $rows->map(function ($row) use ($employees, $locationName) {
                $emp = $employees->get($row->employee_id);
                return [
                    'id' => (int) $row->id,
                    'group_id' => (int) $row->group_id,
                    'location_id' => (int) $row->location_id,
                    'floor_id' => (int) $row->floor_id,
                    'employee_id' => (int) $row->employee_id,
                    'employee_name' => $emp ? ($emp->name ?? ('Employee #' . $row->employee_id)) : ('Employee #' . $row->employee_id),
                    'employee_code' => $emp ? ($emp->employee_code ?? null) : null,
                    'location_name' => $locationName,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('CustomerGroup floorEmployees: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load floor employees',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove employee assignment from floor.
     */
    public function destroyFloorEmployee(int $floorId, int $assignmentId): JsonResponse
    {
        try {
            $assignment = EmployeeFloor::where('id', $assignmentId)
                ->where('floor_id', $floorId)
                ->first();

            if (!$assignment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Floor employee assignment not found',
                ], 404);
            }

            $assignment->delete();

            return response()->json([
                'success' => true,
                'message' => 'Employee assignment removed from floor',
            ]);
        } catch (\Exception $e) {
            Log::error('CustomerGroup destroyFloorEmployee: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove employee assignment from floor',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
