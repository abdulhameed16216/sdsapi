<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Customer;
use App\Models\EmployeeCustomerMachineAssignment;
use App\Models\Machine;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    /**
     * Display a listing of attendance records
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Attendance::with(['employee', 'customer', 'creator', 'updater']);

            // Search filter
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->whereHas('employee', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('employee_code', 'like', "%{$search}%");
                })->orWhereHas('customer', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('company_name', 'like', "%{$search}%");
                });
            }

            // Filter by employee
            if ($request->has('emp_id') && $request->emp_id) {
                $query->where('emp_id', $request->emp_id);
            }

            // Filter by customer
            if ($request->has('customer_id') && $request->customer_id) {
                $query->where('customer_id', $request->customer_id);
            }

            // Filter by date range
            if ($request->has('date_from') && $request->date_from) {
                $query->where('date', '>=', $request->date_from);
            }

            if ($request->has('date_to') && $request->date_to) {
                $query->where('date', '<=', $request->date_to);
            }

            // Filter by type
            if ($request->has('type') && $request->type) {
                $query->where('type', $request->type);
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            $attendance = $query->orderBy('date', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Attendance records retrieved successfully',
                'data' => $attendance
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching attendance: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch attendance records',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created attendance record
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'emp_id' => 'required|exists:employees,id',
                'date' => 'required|date',
                'in_time' => 'required|date_format:H:i',
                'out_time' => 'nullable|date_format:H:i|after:in_time',
                'customer_id' => 'nullable|exists:customers,id',
                'notes' => 'nullable|string|max:1000'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check if attendance already exists for this employee on this date
            $existingAttendance = Attendance::where('emp_id', $request->emp_id)
                ->where('date', $request->date)
                ->first();

            if ($existingAttendance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Attendance record already exists for this employee on this date'
                ], 400);
            }

            $attendance = Attendance::create([
                'emp_id' => $request->emp_id,
                'date' => $request->date,
                'in_time' => $request->in_time,
                'out_time' => $request->out_time,
                'customer_id' => $request->customer_id,
                'type' => 'regularized', // Manual regularization
                'notes' => $request->notes,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id()
            ]);

            $attendance->load(['employee', 'customer', 'creator', 'updater']);

            return response()->json([
                'success' => true,
                'message' => 'Attendance record created successfully',
                'data' => $attendance
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating attendance: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create attendance record',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified attendance record
     */
    public function show(Attendance $attendance): JsonResponse
    {
        try {
            $attendance->load(['employee', 'customer', 'creator', 'updater']);

            return response()->json([
                'success' => true,
                'message' => 'Attendance record retrieved successfully',
                'data' => $attendance
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching attendance: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch attendance record',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified attendance record
     */
    public function update(Request $request, Attendance $attendance): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'emp_id' => 'required|exists:employees,id',
                'date' => 'required|date',
                'in_time' => 'required|date_format:H:i',
                'out_time' => 'nullable|date_format:H:i|after:in_time',
                'customer_id' => 'nullable|exists:customers,id',
                'notes' => 'nullable|string|max:1000'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            $attendance->update([
                'emp_id' => $request->emp_id,
                'date' => $request->date,
                'in_time' => $request->in_time,
                'out_time' => $request->out_time,
                'customer_id' => $request->customer_id,
                'notes' => $request->notes,
                'updated_by' => auth()->id()
            ]);

            $attendance->load(['employee', 'customer', 'creator', 'updater']);

            return response()->json([
                'success' => true,
                'message' => 'Attendance record updated successfully',
                'data' => $attendance
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating attendance: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update attendance record',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified attendance record
     */
    public function destroy(Attendance $attendance): JsonResponse
    {
        try {
            $attendance->delete();

            return response()->json([
                'success' => true,
                'message' => 'Attendance record deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting attendance: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete attendance record',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get employees list for dropdown
     */
    public function getEmployees(): JsonResponse
    {
        try {
            $employees = Employee::select('id', 'name', 'employee_code')
                ->where('status', 'active')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Employees retrieved successfully',
                'data' => $employees
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching employees: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch employees',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customers list for dropdown
     */
    public function getCustomers(): JsonResponse
    {
        try {
            $customers = Customer::select('id', 'company_name', 'name')
                ->where('status', 'active')
                ->orderBy('company_name')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Customers retrieved successfully',
                'data' => $customers
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching customers: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch customers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get assigned customers for current logged-in employee
     */
    public function getMyAssignedCustomers(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user || !$user->employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee not found. Please login again.'
                ], 401);
            }

            $employeeId = $user->employee->id;

            // Get active assignments for this employee
            $assignments = EmployeeCustomerMachineAssignment::with(['customer.customerGroup'])
                ->where('employee_id', $employeeId)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->get();

            // Extract unique customers from assignments
            $customers = $assignments
                ->pluck('customer')
                ->filter() // Remove null values
                ->unique('id') // Get unique customers by ID
                ->values() // Reset array keys
                ->map(function ($customer) {
                    // Handle logo URL - ensure it references public/files folder
                    $logoUrl = null;
                    if ($customer->logo) {
                        $logoPath = $customer->logo;
                        // Ensure path starts with 'files/'
                        if (!str_starts_with($logoPath, 'files/')) {
                            $logoPath = 'files/' . ltrim($logoPath, 'files/');
                        }
                        $logoUrl = url($logoPath);
                    }
                    
                    return [
                        'id' => $customer->id,
                        'name' => $customer->company_name ?? $customer->name,
                        'company_name' => $customer->company_name,
                        'logo' => $logoUrl,
                        'customer_group_id' => $customer->customer_group_id,
                        'customer_group_name' => $customer->customerGroup?->name,
                    ];
                })
                ->sortBy('name')
                ->values();

            return response()->json([
                'success' => true,
                'message' => 'Assigned customers retrieved successfully',
                'data' => $customers,
                'count' => $customers->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching assigned customers: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch assigned customers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Single payload for mobile stock scope: employee_floor joined with customer group, branch (customer), and floor.
     * Frontend splits into three arrays (groups, branches, floors) and filters dependent dropdowns client-side.
     */
    public function getMyStockScope(): JsonResponse
    {
        try {
            $user = Auth::user();

            if (! $user || ! $user->employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee not found. Please login again.',
                ], 401);
            }

            $employeeId = $user->employee->id;

            $rows = DB::table('employee_floor as ef')
                ->join('customer_groups as cg', function ($join) {
                    $join->on('cg.id', '=', 'ef.group_id')->whereNull('cg.deleted_at');
                })
                ->join('customers as c', function ($join) {
                    $join->on('c.id', '=', 'ef.location_id')->whereNull('c.deleted_at');
                })
                ->join('customers_floor as cf', function ($join) {
                    $join->on('cf.id', '=', 'ef.floor_id')->whereNull('cf.deleted_at');
                })
                ->where('ef.employee_id', $employeeId)
                ->select([
                    'ef.group_id',
                    'cg.name as group_name',
                    'ef.location_id',
                    'c.company_name',
                    'c.name as customer_name',
                    'ef.floor_id',
                    'cf.name as floor_name',
                ])
                ->orderBy('cg.name')
                ->orderBy('c.company_name')
                ->orderBy('cf.name')
                ->get();

            $groupsMap = [];
            $branchesMap = [];
            $floorsList = [];

            foreach ($rows as $r) {
                $gid = (int) $r->group_id;
                if (! isset($groupsMap[$gid])) {
                    $groupsMap[$gid] = [
                        'id' => $gid,
                        'name' => $r->group_name,
                    ];
                }

                $branchKey = $gid.'-'.(int) $r->location_id;
                if (! isset($branchesMap[$branchKey])) {
                    $branchesMap[$branchKey] = [
                        'id' => (int) $r->location_id,
                        'name' => $r->company_name ?: $r->customer_name,
                        'company_name' => $r->company_name,
                        'group_id' => $gid,
                    ];
                }

                $floorsList[] = [
                    'id' => (int) $r->floor_id,
                    'name' => $r->floor_name,
                    'location_id' => (int) $r->location_id,
                    'group_id' => $gid,
                    'is_branch_pool' => false,
                ];
            }

            $floorsUnique = [];
            $seenFloor = [];
            foreach ($floorsList as $f) {
                $k = $f['group_id'].'-'.$f['location_id'].'-'.$f['id'];
                if (! isset($seenFloor[$k])) {
                    $seenFloor[$k] = true;
                    $floorsUnique[] = $f;
                }
            }

            $branchPoolRows = [];
            foreach ($branchesMap as $b) {
                $branchPoolRows[] = [
                    'id' => null,
                    'name' => 'Branch Inventory',
                    'location_id' => $b['id'],
                    'group_id' => $b['group_id'],
                    'is_branch_pool' => true,
                ];
            }

            $floors = array_merge($branchPoolRows, $floorsUnique);
            usort($floors, function ($a, $b) {
                $cmp = $a['group_id'] <=> $b['group_id'];
                if ($cmp !== 0) {
                    return $cmp;
                }
                $cmp = $a['location_id'] <=> $b['location_id'];
                if ($cmp !== 0) {
                    return $cmp;
                }
                $ia = ! empty($a['is_branch_pool']) ? 0 : 1;
                $ib = ! empty($b['is_branch_pool']) ? 0 : 1;
                if ($ia !== $ib) {
                    return $ia <=> $ib;
                }

                return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
            });

            return response()->json([
                'success' => true,
                'message' => 'Stock scope retrieved successfully',
                'data' => [
                    'groups' => array_values($groupsMap),
                    'branches' => array_values($branchesMap),
                    'floors' => $floors,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching stock scope: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch stock scope',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Machine readings: scope from employee_customer_machine_assignments joined with customer_groups, customers, customers_floor.
     * Same shape as my-stock-scope (groups, branches, floors) plus machines_by_scope for client-side filtering after group/branch/floor selection.
     */
    public function getMyMachineReadingsScope(): JsonResponse
    {
        try {
            $user = Auth::user();

            if (! $user || ! $user->employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee not found. Please login again.',
                ], 401);
            }

            $employeeId = $user->employee->id;

            $assignments = EmployeeCustomerMachineAssignment::query()
                ->with([
                    'machine',
                    'customer.customerGroup',
                    'floor',
                ])
                ->where('employee_id', $employeeId)
                ->where('status', 'active')
                ->whereNotNull('assigned_machine_id')
                ->whereNull('deleted_at')
                ->get();

            $groupsMap = [];
            $branchesMap = [];
            $floorsMap = [];
            $machinesByScope = [];
            $seenMachineScope = [];

            foreach ($assignments as $a) {
                $customer = $a->customer;
                $machine = $a->machine;
                if (! $customer || ! $machine) {
                    continue;
                }

                $gid = (int) ($customer->customer_group_id ?? 0);
                $groupName = $customer->customerGroup?->name ?? 'Ungrouped';
                if (! isset($groupsMap[$gid])) {
                    $groupsMap[$gid] = [
                        'id' => $gid,
                        'name' => $groupName,
                    ];
                }

                $cid = (int) $customer->id;
                $branchKey = $gid.'-'.$cid;
                if (! isset($branchesMap[$branchKey])) {
                    $branchesMap[$branchKey] = [
                        'id' => $cid,
                        'name' => $customer->company_name ?: $customer->name,
                        'company_name' => $customer->company_name,
                        'group_id' => $gid,
                    ];
                }

                $fid = $a->floor_id !== null ? (int) $a->floor_id : null;
                if ($fid === null) {
                    $floorKey = $gid.'-'.$cid.'-branch';
                    $floorsMap[$floorKey] = [
                        'id' => null,
                        'name' => 'Branch Inventory',
                        'location_id' => $cid,
                        'group_id' => $gid,
                        'is_branch_pool' => true,
                    ];
                } else {
                    $floorName = $a->floor?->name ?? ('Floor '.$fid);
                    $floorKey = $gid.'-'.$cid.'-f'.$fid;
                    $floorsMap[$floorKey] = [
                        'id' => $fid,
                        'name' => $floorName,
                        'location_id' => $cid,
                        'group_id' => $gid,
                        'is_branch_pool' => false,
                    ];
                }

                $uniqKey = $gid.'-'.$cid.'-'.($fid === null ? 'null' : (string) $fid).'-m'.$machine->id;
                if (! isset($seenMachineScope[$uniqKey])) {
                    $seenMachineScope[$uniqKey] = true;
                    $machinesByScope[] = [
                        'group_id' => $gid,
                        'customer_id' => $cid,
                        'floor_id' => $fid,
                        'machine' => [
                            'id' => (int) $machine->id,
                            'machine_alias' => $machine->machine_alias ?? ('Machine #'.$machine->id),
                            'serial_number' => $machine->serial_number ?? '',
                            'machine_type' => $machine->machine_type,
                        ],
                    ];
                }
            }

            $floors = array_values($floorsMap);
            usort($floors, function ($a, $b) {
                $cmp = $a['group_id'] <=> $b['group_id'];
                if ($cmp !== 0) {
                    return $cmp;
                }
                $cmp = $a['location_id'] <=> $b['location_id'];
                if ($cmp !== 0) {
                    return $cmp;
                }
                $ia = ! empty($a['is_branch_pool']) ? 0 : 1;
                $ib = ! empty($b['is_branch_pool']) ? 0 : 1;
                if ($ia !== $ib) {
                    return $ia <=> $ib;
                }

                return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
            });

            $groups = array_values($groupsMap);
            usort($groups, function ($a, $b) {
                if ($a['id'] === 0) {
                    return 1;
                }
                if ($b['id'] === 0) {
                    return -1;
                }

                return strcasecmp($a['name'], $b['name']);
            });

            return response()->json([
                'success' => true,
                'message' => 'Machine readings scope retrieved successfully',
                'data' => [
                    'groups' => $groups,
                    'branches' => array_values($branchesMap),
                    'floors' => $floors,
                    'machines_by_scope' => $machinesByScope,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching machine readings scope: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch machine readings scope',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get assigned machines for current logged-in employee
     */
    public function getMyAssignedMachines(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user || !$user->employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee not found. Please login again.'
                ], 401);
            }

            $employeeId = $user->employee->id;

            // Get active assignments for this employee with machine relationship
            $assignments = EmployeeCustomerMachineAssignment::with('machine')
                ->where('employee_id', $employeeId)
                ->where('status', 'active')
                ->whereNotNull('assigned_machine_id')
                ->whereNull('deleted_at')
                ->get();

            // Extract unique machines from assignments
            $machines = $assignments
                ->pluck('machine')
                ->filter() // Remove null values
                ->unique('id') // Get unique machines by ID
                ->values() // Reset array keys
                ->map(function ($machine) {
                    return [
                        'id' => $machine->id,
                        'name' => $machine->machine_alias ?? $machine->serial_number ?? 'Machine #' . $machine->id,
                        'machine_code' => $machine->serial_number ?? '',
                    ];
                })
                ->sortBy('name')
                ->values();

            return response()->json([
                'success' => true,
                'message' => 'Assigned machines retrieved successfully',
                'data' => $machines,
                'count' => $machines->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching assigned machines: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch assigned machines',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Punch In - Create attendance record for current logged-in employee
     */
    public function punchIn(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user || !$user->employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee not found. Please login again.'
                ], 401);
            }

            $employeeId = $user->employee->id;
            $today = now()->format('Y-m-d');
            $currentTime = now()->format('H:i:s');

            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,id',
                'selfie_image' => 'nullable|string', // Base64 encoded image
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check if already punched in today
            $existingAttendance = Attendance::where('emp_id', $employeeId)
                ->where('date', $today)
                ->whereNull('out_time')
                ->first();

            if ($existingAttendance) {
                return response()->json([
                    'success' => false,
                    'message' => 'You have already punched in today. Please punch out first.'
                ], 400);
            }

            // Handle selfie image upload if provided
            $selfieImagePath = null;
            if ($request->has('selfie_image') && $request->selfie_image) {
                try {
                    $imageData = $request->selfie_image;
                    
                    // Check if it's base64 encoded
                    if (preg_match('/^data:image\/(\w+);base64,/', $imageData, $matches)) {
                        $imageData = preg_replace('/^data:image\/\w+;base64,/', '', $imageData);
                        $imageData = base64_decode($imageData);
                        $extension = $matches[1] ?? 'jpg';
                    } else {
                        // Assume it's already base64 without prefix
                        $imageData = base64_decode($imageData);
                        $extension = 'jpg';
                    }

                    if ($imageData) {
                        $fileName = 'attendance/selfie_' . $employeeId . '_' . $today . '_' . time() . '.' . $extension;
                        Storage::disk('public')->put($fileName, $imageData);
                        $selfieImagePath = $fileName;
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to save selfie image: ' . $e->getMessage());
                    // Continue without image if upload fails
                }
            }

            $attendance = Attendance::create([
                'emp_id' => $employeeId,
                'date' => $today,
                'in_time' => $currentTime,
                'out_time' => null,
                'customer_id' => $request->customer_id,
                'selfie_image' => $selfieImagePath,
                'type' => 'regular', // Regular attendance (not regularized)
                'notes' => null,
                'created_by' => $user->id,
                'updated_by' => $user->id
            ]);

            $attendance->load(['employee', 'customer', 'creator', 'updater']);

            return response()->json([
                'success' => true,
                'message' => 'Punched in successfully',
                'data' => $attendance
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error in punch in: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to punch in',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Punch Out - Update attendance record with out time for current logged-in employee
     */
    public function punchOut(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user || !$user->employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee not found. Please login again.'
                ], 401);
            }

            $employeeId = $user->employee->id;
            $today = now()->format('Y-m-d');
            $currentTime = now()->format('H:i:s');

            $validator = Validator::make($request->all(), [
                'selfie_image' => 'nullable|string', // Base64 encoded image
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Find today's attendance record
            $attendance = Attendance::where('emp_id', $employeeId)
                ->where('date', $today)
                ->whereNull('out_time')
                ->first();

            if (!$attendance) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active punch in found for today. Please punch in first.'
                ], 400);
            }

            // Handle selfie image upload if provided
            $selfieImagePath = $attendance->selfie_image; // Keep existing image
            if ($request->has('selfie_image') && $request->selfie_image) {
                try {
                    $imageData = $request->selfie_image;
                    
                    // Check if it's base64 encoded
                    if (preg_match('/^data:image\/(\w+);base64,/', $imageData, $matches)) {
                        $imageData = preg_replace('/^data:image\/\w+;base64,/', '', $imageData);
                        $imageData = base64_decode($imageData);
                        $extension = $matches[1] ?? 'jpg';
                    } else {
                        // Assume it's already base64 without prefix
                        $imageData = base64_decode($imageData);
                        $extension = 'jpg';
                    }

                    if ($imageData) {
                        $fileName = 'attendance/selfie_out_' . $employeeId . '_' . $today . '_' . time() . '.' . $extension;
                        Storage::disk('public')->put($fileName, $imageData);
                        $selfieImagePath = $fileName; // Update with punch out image
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to save punch out selfie image: ' . $e->getMessage());
                    // Continue without updating image if upload fails
                }
            }

            $attendance->update([
                'out_time' => $currentTime,
                'selfie_image' => $selfieImagePath,
                'updated_by' => $user->id
            ]);

            $attendance->load(['employee', 'customer', 'creator', 'updater']);

            return response()->json([
                'success' => true,
                'message' => 'Punched out successfully',
                'data' => $attendance
            ]);

        } catch (\Exception $e) {
            Log::error('Error in punch out: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to punch out',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get today's attendance for current logged-in employee
     * Returns all attendance records for today (supports multiple punches)
     */
    public function getTodayAttendance(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user || !$user->employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee not found. Please login again.'
                ], 401);
            }

            $employeeId = $user->employee->id;
            $today = now()->format('Y-m-d');

            // Get all attendance records for today (supports multiple punches)
            $attendanceRecords = Attendance::with(['customer'])
                ->where('emp_id', $employeeId)
                ->whereDate('date', $today)
                ->orderBy('in_time', 'desc')
                ->get();
            
            // Format dates as Y-m-d to avoid timezone conversion issues
            $formattedRecords = $attendanceRecords->map(function ($record) use ($today) {
                $recordArray = $record->toArray();
                $recordArray['date'] = $today; // Use today's date string instead of converted datetime
                return $recordArray;
            });

            // For backward compatibility, also return single record if only one exists
            $singleRecord = $attendanceRecords->count() === 1 ? $formattedRecords->first() : null;

            return response()->json([
                'success' => true,
                'message' => 'Today\'s attendance retrieved successfully',
                'data' => $singleRecord, // Single record for backward compatibility
                'records' => $formattedRecords, // All records for today
                'count' => $attendanceRecords->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching today\'s attendance: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch today\'s attendance',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get attendance history for current logged-in employee
     * Defaults to current month if no date range is provided
     * Includes absent dates (past dates without attendance)
     */
    public function getMyAttendance(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user || !$user->employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee not found. Please login again.'
                ], 401);
            }

            $employeeId = $user->employee->id;
            $today = now();
            
            // Default to current month start (1st) to current date if no date range provided
            if (!$request->has('date_from') || !$request->date_from) {
                $dateFrom = $today->copy()->startOfMonth()->format('Y-m-d');
            } else {
                $dateFrom = $request->date_from;
            }
            
            if (!$request->has('date_to') || !$request->date_to) {
                // Use current date instead of end of month
                $dateTo = $today->format('Y-m-d');
            } else {
                $dateTo = $request->date_to;
            }

            // Get all attendance records for the date range (no need to load employee or customer since it's current user)
            // Use whereDate to avoid timezone issues when comparing dates
            $attendanceRecords = Attendance::where('emp_id', $employeeId)
                ->whereDate('date', '>=', $dateFrom)
                ->whereDate('date', '<=', $dateTo)
                ->orderBy('date', 'desc')
                ->get()
                ->keyBy(function ($item) {
                    // Key by formatted date string (Y-m-d) for consistent lookup
                    // Use the raw date value from database to avoid timezone conversion
                    $dateValue = $item->getRawOriginal('date') ?? $item->date;
                    return $dateValue instanceof \Carbon\Carbon 
                        ? $dateValue->format('Y-m-d') 
                        : \Carbon\Carbon::parse($dateValue)->format('Y-m-d');
                });

            // Create date range array
            $startDate = Carbon::parse($dateFrom);
            $endDate = Carbon::parse($dateTo);
            $todayDateOnly = $today->format('Y-m-d');
            $todayCarbon = $today->copy()->startOfDay();
            
            $attendanceData = [];
            
            // Loop through each date in the range
            $currentDate = $startDate->copy();
            
            while ($currentDate->lte($endDate)) {
                $dateStr = $currentDate->format('Y-m-d');
                $currentDateOnly = $currentDate->copy()->startOfDay();
                $isPast = $currentDateOnly->lt($todayCarbon);
                $isToday = $currentDateOnly->eq($todayCarbon);
                $isFuture = $currentDateOnly->gt($todayCarbon);
                
                if (isset($attendanceRecords[$dateStr])) {
                    // Date has attendance record - mark as present
                    $attendanceRecord = $attendanceRecords[$dateStr];
                    // Convert to array and add status field
                    $recordArray = $attendanceRecord->toArray();
                    // Remove customer property (not needed for my-attendance API)
                    unset($recordArray['customer']);
                    // Format date as Y-m-d to avoid timezone conversion issues
                    // Use the dateStr from the loop (which is already formatted correctly) instead of the model's date
                    $recordArray['date'] = $dateStr;
                    // Set status to 'present' if in_time exists (person punched in)
                    // Check both the attribute and the array value
                    $hasInTime = !empty($attendanceRecord->in_time) || !empty($recordArray['in_time']);
                    if ($hasInTime) {
                        $recordArray['status'] = 'present';
                    } else {
                        $recordArray['status'] = 'absent';
                    }
                    // Ensure type is set
                    $recordArray['type'] = $attendanceRecord->type ?? 'regular';
                    $attendanceData[] = $recordArray;
                } elseif ($isPast || $isToday) {
                    // Past date or today without attendance = absent
                    $attendanceData[] = [
                        'id' => null,
                        'emp_id' => $employeeId,
                        'date' => $dateStr,
                        'in_time' => null,
                        'out_time' => null,
                        'customer_id' => null,
                        'customer' => null,
                        'selfie_image' => null,
                        'type' => 'absent',
                        'notes' => null,
                        'status' => 'absent', // Mark as absent
                        'created_at' => null,
                        'updated_at' => null,
                    ];
                }
                // Future dates without attendance are skipped (not included)
                
                $currentDate->addDay();
            }

            // Pagination
            $perPage = $request->get('per_page', 30);
            $currentPage = $request->get('page', 1);
            $total = count($attendanceData);
            $offset = ($currentPage - 1) * $perPage;
            $paginatedData = array_slice($attendanceData, $offset, $perPage);

            // Create pagination response
            $lastPage = ceil($total / $perPage);

            return response()->json([
                'success' => true,
                'message' => 'Attendance history retrieved successfully',
                'data' => [
                    'data' => $paginatedData,
                    'current_page' => (int)$currentPage,
                    'per_page' => (int)$perPage,
                    'total' => $total,
                    'last_page' => $lastPage,
                    'from' => $offset + 1,
                    'to' => min($offset + $perPage, $total),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching attendance history: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch attendance history',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Location-based Punch In/Out - Supports multiple punches per user
     * Same endpoint, different payload based on action (punch_in or punch_out)
     */
    public function locationPunch(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user || !$user->employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee not found. Please login again.'
                ], 401);
            }

            $employeeId = $user->employee->id;
            $today = now()->format('Y-m-d');
            $currentTime = now()->format('H:i:s');

            // Validate request
            $validator = Validator::make($request->all(), [
                'action' => 'required|in:punch_in,punch_out',
                'customer_id' => 'nullable|exists:customers,id', // Optional for both punch_in and punch_out
                'latitude' => 'required|numeric|between:-90,90',
                'longitude' => 'required|numeric|between:-180,180',
                'type' => 'required|in:selfie,location,manual_regularization',
                'selfie_image' => 'nullable|string', // Optional - Base64 encoded image
                'notes' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            $action = $request->action;
            $latitude = $request->latitude;
            $longitude = $request->longitude;
            $type = $request->type;

            // Reverse geocode: get location name from lat/long (Nominatim)
            $locationName = $this->getLocationName($latitude, $longitude);

            // Handle selfie image upload if provided
            $selfieImagePath = null;
            if ($request->has('selfie_image') && $request->selfie_image) {
                try {
                    $imageData = $request->selfie_image;
                    
                    // Check if it's base64 encoded
                    if (preg_match('/^data:image\/(\w+);base64,/', $imageData, $matches)) {
                        $imageData = preg_replace('/^data:image\/\w+;base64,/', '', $imageData);
                        $imageData = base64_decode($imageData);
                        $extension = $matches[1] ?? 'jpg';
                    } else {
                        // Assume it's already base64 without prefix
                        $imageData = base64_decode($imageData);
                        $extension = 'jpg';
                    }

                    if ($imageData) {
                        $prefix = $action === 'punch_in' ? 'selfie_in' : 'selfie_out';
                        $fileName = 'attendance/' . $prefix . '_' . $employeeId . '_' . $today . '_' . time() . '.' . $extension;
                        Storage::disk('public')->put($fileName, $imageData);
                        $selfieImagePath = $fileName;
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to save selfie image: ' . $e->getMessage());
                    // Continue without image if upload fails
                }
            }

            if ($action === 'punch_in') {
                // Check if there's an open punch in (not punched out yet)
                // Only allow new punch in if the last record is punched out or no record exists
                $openPunchIn = Attendance::where('emp_id', $employeeId)
                    ->where('date', $today)
                    ->whereNull('out_time')
                    ->orderBy('in_time', 'desc')
                    ->first();

                if ($openPunchIn) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You already have an active punch in. Please punch out first before punching in again.',
                        'data' => [
                            'open_punch_in' => [
                                'id' => $openPunchIn->id,
                                'in_time' => $openPunchIn->in_time,
                                'date' => $openPunchIn->date
                            ]
                        ]
                    ], 400);
                }

                // Create new attendance record for punch in
                // Allow multiple punch ins per day, but only after previous punch out
                $attendance = Attendance::create([
                    'emp_id' => $employeeId,
                    'date' => $today,
                    'in_time' => $currentTime,
                    'out_time' => null,
                    'customer_id' => $request->customer_id,
                    'selfie_image' => $selfieImagePath,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'location' => $locationName, // Store location name in database
                    'type' => $type,
                    'notes' => $request->notes,
                    'created_by' => $user->id,
                    'updated_by' => $user->id
                ]);

                $attendance->load(['employee', 'customer', 'creator', 'updater']);

                return response()->json([
                    'success' => true,
                    'message' => 'Punched in successfully',
                    'data' => $attendance,
                    'location' => $locationName // Resolved from reverse geocode (null if getLocationName failed)
                ], 201);

            } else {
                // Punch out - find the most recent punch in without punch out
                // Allow multiple punch outs (each punch out closes the most recent open punch in)
                $attendance = Attendance::where('emp_id', $employeeId)
                    ->where('date', $today)
                    ->whereNull('out_time')
                    ->orderBy('in_time', 'desc')
                    ->first();

                if (!$attendance) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No active punch in found for today. Please punch in first.'
                    ], 400);
                }

                // Update the attendance record with punch out details
                $updateData = [
                    'out_time' => $currentTime,
                    'updated_by' => $user->id
                ];

                // Store punch out location separately (don't overwrite punch in location)
                if ($request->has('latitude') && $request->has('longitude')) {
                    // Store punch out lat/long separately
                    $updateData['punch_out_latitude'] = $latitude;
                    $updateData['punch_out_longitude'] = $longitude;
                    $punchOutLocation = $this->getLocationName($latitude, $longitude);
                    if ($punchOutLocation) {
                        $updateData['punch_out_location'] = $punchOutLocation;
                    }
                    // Note: We keep the original 'latitude', 'longitude', and 'location' fields (punch in location)
                }

                // Update selfie image if provided
                if ($selfieImagePath) {
                    $updateData['selfie_image'] = $selfieImagePath;
                }

                // Update notes if provided
                if ($request->has('notes') && $request->notes) {
                    $updateData['notes'] = $request->notes;
                }

                // Update type if different from punch in
                if ($request->has('type') && $request->type) {
                    $updateData['type'] = $type;
                }

                $attendance->update($updateData);
                $attendance->load(['employee', 'customer', 'creator', 'updater']);

                $punchOutLocationResolved = $updateData['punch_out_location'] ?? null;

                return response()->json([
                    'success' => true,
                    'message' => 'Punched out successfully',
                    'data' => $attendance,
                    'location' => $punchOutLocationResolved // Resolved punch-out location from reverse geocode (null if getLocationName failed)
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error in location punch: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to process punch',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get aggregated attendance report
     * Groups attendance by employee and date, calculates total hours and in-out count
     */
    public function getReport(Request $request): JsonResponse
    {
        try {
            $query = Attendance::with(['employee'])
                ->whereNotNull('in_time')
                ->selectRaw('
                    emp_id,
                    date,
                    COUNT(*) as in_out_count,
                    SUM(CASE WHEN out_time IS NOT NULL THEN 1 ELSE 0 END) as completed_sessions,
                    MIN(in_time) as first_in_time,
                    MAX(COALESCE(out_time, in_time)) as last_out_time
                ')
                ->groupBy('emp_id', 'date');

            // Filter by date range
            if ($request->has('date_from') && $request->date_from) {
                $query->where('date', '>=', $request->date_from);
            }

            if ($request->has('date_to') && $request->date_to) {
                $query->where('date', '<=', $request->date_to);
            }

            // Filter by employee
            if ($request->has('emp_id') && $request->emp_id) {
                $query->where('emp_id', $request->emp_id);
            }

            // Filter by date (single date)
            if ($request->has('date') && $request->date) {
                $query->where('date', $request->date);
            }

            $results = $query->orderBy('date', 'desc')
                ->orderBy('emp_id')
                ->get();

            // Calculate total hours for each employee-date combination
            $reportData = [];
            foreach ($results as $result) {
                $empId = $result->emp_id;
                $date = $result->date;

                // Get all attendance records for this employee and date
                $attendanceRecords = Attendance::where('emp_id', $empId)
                    ->where('date', $date)
                    ->orderBy('in_time', 'asc')
                    ->get();

                // Calculate total hours worked
                $totalMinutes = 0;
                foreach ($attendanceRecords as $record) {
                    if ($record->in_time && $record->out_time) {
                        try {
                            // Get date as string (Y-m-d format)
                            $dateStr = $record->date instanceof \DateTime 
                                ? $record->date->format('Y-m-d') 
                                : (is_string($record->date) ? $record->date : $record->date->format('Y-m-d'));
                            
                            // Get time portion only (H:i:s format)
                            // in_time and out_time are cast as datetime, so extract time part
                            $inTimeObj = $record->in_time instanceof \DateTime 
                                ? $record->in_time 
                                : Carbon::parse($record->in_time);
                            $outTimeObj = $record->out_time instanceof \DateTime 
                                ? $record->out_time 
                                : Carbon::parse($record->out_time);
                            
                            // Extract just the time part (H:i:s)
                            $inTimeStr = $inTimeObj->format('H:i:s');
                            $outTimeStr = $outTimeObj->format('H:i:s');
                            
                            // Combine date with time
                            $inTime = Carbon::parse($dateStr . ' ' . $inTimeStr);
                            $outTime = Carbon::parse($dateStr . ' ' . $outTimeStr);
                            
                            // If out time is before in time, it might be next day
                            if ($outTime->lt($inTime)) {
                                $outTime->addDay();
                            }
                            
                            $totalMinutes += $inTime->diffInMinutes($outTime);
                        } catch (\Exception $e) {
                            Log::warning('Error parsing attendance time for record ID ' . $record->id . ': ' . $e->getMessage());
                            continue;
                        }
                    }
                }

                $totalHours = round($totalMinutes / 60, 2);
                $hours = floor($totalMinutes / 60);
                $minutes = $totalMinutes % 60;

                // Determine status
                $status = 'Present';
                if ($totalHours < 4) {
                    $status = 'Half Day';
                } elseif ($totalHours == 0) {
                    $status = 'Absent';
                }

                // Check if there are any open punches (punch in without punch out)
                $hasOpenPunch = $attendanceRecords->contains(function ($record) {
                    return $record->in_time && !$record->out_time;
                });

                if ($hasOpenPunch) {
                    $status = 'Active';
                }

                $employee = $result->employee;
                
                // Get attendance types for this day (could be multiple types)
                $types = $attendanceRecords->pluck('type')->unique()->filter()->values()->toArray();
                $typeDisplay = !empty($types) ? implode(', ', $types) : 'Regular';
                
                $reportData[] = [
                    'emp_id' => $empId,
                    'employee_code' => $employee ? $employee->employee_code : 'N/A',
                    'employee_name' => $employee ? $employee->name : 'Unknown',
                    'date' => $date,
                    'total_hours' => $totalHours,
                    'total_hours_formatted' => sprintf('%dh %dm', $hours, $minutes),
                    'in_out_count' => $result->in_out_count,
                    'completed_sessions' => $result->completed_sessions,
                    'status' => $status,
                    'type' => $typeDisplay,
                    'types' => $types,
                    'first_in_time' => $result->first_in_time,
                    'last_out_time' => $result->last_out_time
                ];
            }

            // Return all data without pagination
            return response()->json([
                'success' => true,
                'message' => 'Attendance report retrieved successfully',
                'data' => $reportData
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching attendance report: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch attendance report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get detailed transactions for a specific employee and date
     */
    public function getEmployeeDayTransactions(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'emp_id' => 'required|exists:employees,id',
                'date' => 'required|date'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            $empId = $request->emp_id;
            $date = $request->date;

            $transactions = Attendance::where('emp_id', $empId)
                ->where('date', $date)
                ->with(['employee', 'customer'])
                ->orderBy('in_time', 'asc')
                ->get();

            $transactionData = [];
            foreach ($transactions as $transaction) {
                $duration = null;
                $durationFormatted = '-';
                
                // Format in_time and out_time as strings (extract time part only)
                $inTimeStr = '';
                if ($transaction->in_time) {
                    if ($transaction->in_time instanceof \DateTime) {
                        $inTimeStr = $transaction->in_time->format('H:i:s');
                    } else {
                        $inTimeStr = is_string($transaction->in_time) ? $transaction->in_time : '';
                        // If it contains date part, extract only time
                        if (strpos($inTimeStr, ' ') !== false) {
                            $parts = explode(' ', $inTimeStr);
                            $inTimeStr = end($parts); // Get last part (time)
                        }
                    }
                }
                
                $outTimeStr = null;
                if ($transaction->out_time) {
                    if ($transaction->out_time instanceof \DateTime) {
                        $outTimeStr = $transaction->out_time->format('H:i:s');
                    } else {
                        $outTimeStr = is_string($transaction->out_time) ? $transaction->out_time : null;
                        // If it contains date part, extract only time
                        if ($outTimeStr && strpos($outTimeStr, ' ') !== false) {
                            $parts = explode(' ', $outTimeStr);
                            $outTimeStr = end($parts); // Get last part (time)
                        }
                    }
                }
                
                // Calculate duration if both times exist
                if ($inTimeStr && $outTimeStr) {
                    try {
                        $inTime = Carbon::parse($date . ' ' . $inTimeStr);
                        $outTime = Carbon::parse($date . ' ' . $outTimeStr);
                        
                        // If out time is before in time, it might be next day
                        if ($outTime->lt($inTime)) {
                            $outTime->addDay();
                        }
                        
                        $duration = $inTime->diffInMinutes($outTime);
                        $hours = floor($duration / 60);
                        $minutes = $duration % 60;
                        $durationFormatted = sprintf('%dh %dm', $hours, $minutes);
                    } catch (\Exception $e) {
                        Log::warning('Error calculating duration for transaction ID ' . $transaction->id . ': ' . $e->getMessage());
                        $durationFormatted = '-';
                    }
                }

                // Get selfie image URL if available
                $selfieImageUrl = null;
                if ($transaction->selfie_image) {
                    $selfieImageUrl = asset('storage/' . $transaction->selfie_image);
                }

                $transactionData[] = [
                    'id' => $transaction->id,
                    'in_time' => $inTimeStr,
                    'out_time' => $outTimeStr,
                    'duration' => $duration,
                    'duration_formatted' => $durationFormatted,
                    'latitude' => $transaction->latitude, // Punch in latitude
                    'longitude' => $transaction->longitude, // Punch in longitude
                    'location' => $transaction->location, // Punch in location name
                    'punch_out_latitude' => $transaction->punch_out_latitude, // Punch out latitude
                    'punch_out_longitude' => $transaction->punch_out_longitude, // Punch out longitude
                    'punch_out_location' => $transaction->punch_out_location, // Punch out location name
                    'selfie_image' => $transaction->selfie_image,
                    'selfie_image_url' => $selfieImageUrl,
                    'customer_name' => $transaction->customer ? $transaction->customer->company_name : null,
                    'type' => $transaction->type,
                    'notes' => $transaction->notes,
                    'status' => $transaction->out_time ? 'Completed' : 'Active'
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Employee day transactions retrieved successfully',
                'data' => [
                    'employee' => $transactions->first() ? [
                        'id' => $transactions->first()->employee->id,
                        'employee_code' => $transactions->first()->employee->employee_code,
                        'name' => $transactions->first()->employee->name
                    ] : null,
                    'date' => $date,
                    'transactions' => $transactionData
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching employee day transactions: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch employee day transactions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export attendance report to Excel (CSV format)
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        try {
            // Use same query logic as getReport
            $query = Attendance::with(['employee'])
                ->whereNotNull('in_time')
                ->selectRaw('
                    emp_id,
                    date,
                    COUNT(*) as in_out_count,
                    SUM(CASE WHEN out_time IS NOT NULL THEN 1 ELSE 0 END) as completed_sessions,
                    MIN(in_time) as first_in_time,
                    MAX(COALESCE(out_time, in_time)) as last_out_time
                ')
                ->groupBy('emp_id', 'date');

            // Apply filters
            if ($request->has('date') && $request->date) {
                $query->where('date', $request->date);
            }
            if ($request->has('emp_id') && $request->emp_id) {
                $query->where('emp_id', $request->emp_id);
            }

            $results = $query->orderBy('date', 'desc')
                ->orderBy('emp_id')
                ->get();

            // Calculate report data (same logic as getReport)
            $reportData = [];
            foreach ($results as $result) {
                $empId = $result->emp_id;
                $date = $result->date;

                $attendanceRecords = Attendance::where('emp_id', $empId)
                    ->where('date', $date)
                    ->orderBy('in_time', 'asc')
                    ->get();

                $totalMinutes = 0;
                foreach ($attendanceRecords as $record) {
                    if ($record->in_time && $record->out_time) {
                        try {
                            $dateStr = $record->date instanceof \DateTime 
                                ? $record->date->format('Y-m-d') 
                                : (is_string($record->date) ? $record->date : $record->date->format('Y-m-d'));
                            
                            $inTimeObj = $record->in_time instanceof \DateTime 
                                ? $record->in_time 
                                : Carbon::parse($record->in_time);
                            $outTimeObj = $record->out_time instanceof \DateTime 
                                ? $record->out_time 
                                : Carbon::parse($record->out_time);
                            
                            $inTimeStr = $inTimeObj->format('H:i:s');
                            $outTimeStr = $outTimeObj->format('H:i:s');
                            
                            $inTime = Carbon::parse($dateStr . ' ' . $inTimeStr);
                            $outTime = Carbon::parse($dateStr . ' ' . $outTimeStr);
                            
                            if ($outTime->lt($inTime)) {
                                $outTime->addDay();
                            }
                            
                            $totalMinutes += $inTime->diffInMinutes($outTime);
                        } catch (\Exception $e) {
                            continue;
                        }
                    }
                }

                $totalHours = round($totalMinutes / 60, 2);
                $hours = floor($totalMinutes / 60);
                $minutes = $totalMinutes % 60;

                $status = 'Present';
                if ($totalHours < 4) {
                    $status = 'Half Day';
                } elseif ($totalHours == 0) {
                    $status = 'Absent';
                }

                $hasOpenPunch = $attendanceRecords->contains(function ($record) {
                    return $record->in_time && !$record->out_time;
                });

                if ($hasOpenPunch) {
                    $status = 'Active';
                }

                // Collect locations for the date (punch in + punch out), comma-separated, unique
                $locations = [];
                foreach ($attendanceRecords as $record) {
                    if (!empty(trim((string) ($record->location ?? '')))) {
                        $locations[] = trim($record->location);
                    }
                    if (!empty(trim((string) ($record->punch_out_location ?? '')))) {
                        $locations[] = trim($record->punch_out_location);
                    }
                }
                $locationColumn = implode(', ', array_unique(array_filter($locations)));

                $employee = $result->employee;
                
                $reportData[] = [
                    'emp_id' => $empId,
                    'employee_code' => $employee ? $employee->employee_code : 'N/A',
                    'employee_name' => $employee ? $employee->name : 'Unknown',
                    'date' => $date,
                    'total_hours_formatted' => sprintf('%dh %dm', $hours, $minutes),
                    'status' => $status,
                    'location' => $locationColumn
                ];
            }

            $filename = 'attendance_report_' . date('Y-m-d_His') . '.csv';

            $headers = [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'max-age=0',
            ];

            $callback = function () use ($reportData) {
                $output = fopen('php://output', 'w');
                
                // Add BOM for UTF-8
                fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

                $escapeCsv = function ($value) {
                    if (is_string($value) && (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n") || str_contains($value, "\r"))) {
                        return '"' . str_replace('"', '""', $value) . '"';
                    }
                    return $value;
                };

                // Header row
                $headers = ['SI No', 'Emp ID', 'Name', 'Date', 'Working Hours', 'Status', 'Location'];
                fputcsv($output, array_map($escapeCsv, $headers));

                // Data rows
                $sno = 1;
                foreach ($reportData as $row) {
                    fputcsv($output, array_map($escapeCsv, [
                        $sno++,
                        $row['employee_code'],
                        $row['employee_name'],
                        date('d/m/Y', strtotime($row['date'])),
                        $row['total_hours_formatted'],
                        $row['status'],
                        $row['location'] ?? ''
                    ]));
                }

                fclose($output);
            };

            return response()->stream($callback, 200, $headers);

        } catch (\Exception $e) {
            Log::error('Error exporting attendance report to Excel: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export attendance report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export attendance report to PDF
     */
    public function exportPdf(Request $request): Response
    {
        try {
            // Use same query logic as getReport
            $query = Attendance::with(['employee'])
                ->whereNotNull('in_time')
                ->selectRaw('
                    emp_id,
                    date,
                    COUNT(*) as in_out_count,
                    SUM(CASE WHEN out_time IS NOT NULL THEN 1 ELSE 0 END) as completed_sessions,
                    MIN(in_time) as first_in_time,
                    MAX(COALESCE(out_time, in_time)) as last_out_time
                ')
                ->groupBy('emp_id', 'date');

            // Apply filters
            if ($request->has('date') && $request->date) {
                $query->where('date', $request->date);
            }
            if ($request->has('emp_id') && $request->emp_id) {
                $query->where('emp_id', $request->emp_id);
            }

            $results = $query->orderBy('date', 'desc')
                ->orderBy('emp_id')
                ->get();

            // Calculate report data (same logic as getReport)
            $reportData = [];
            foreach ($results as $result) {
                $empId = $result->emp_id;
                $date = $result->date;

                $attendanceRecords = Attendance::where('emp_id', $empId)
                    ->where('date', $date)
                    ->orderBy('in_time', 'asc')
                    ->get();

                $totalMinutes = 0;
                foreach ($attendanceRecords as $record) {
                    if ($record->in_time && $record->out_time) {
                        try {
                            $dateStr = $record->date instanceof \DateTime 
                                ? $record->date->format('Y-m-d') 
                                : (is_string($record->date) ? $record->date : $record->date->format('Y-m-d'));
                            
                            $inTimeObj = $record->in_time instanceof \DateTime 
                                ? $record->in_time 
                                : Carbon::parse($record->in_time);
                            $outTimeObj = $record->out_time instanceof \DateTime 
                                ? $record->out_time 
                                : Carbon::parse($record->out_time);
                            
                            $inTimeStr = $inTimeObj->format('H:i:s');
                            $outTimeStr = $outTimeObj->format('H:i:s');
                            
                            $inTime = Carbon::parse($dateStr . ' ' . $inTimeStr);
                            $outTime = Carbon::parse($dateStr . ' ' . $outTimeStr);
                            
                            if ($outTime->lt($inTime)) {
                                $outTime->addDay();
                            }
                            
                            $totalMinutes += $inTime->diffInMinutes($outTime);
                        } catch (\Exception $e) {
                            continue;
                        }
                    }
                }

                $totalHours = round($totalMinutes / 60, 2);
                $hours = floor($totalMinutes / 60);
                $minutes = $totalMinutes % 60;

                $status = 'Present';
                if ($totalHours < 4) {
                    $status = 'Half Day';
                } elseif ($totalHours == 0) {
                    $status = 'Absent';
                }

                $hasOpenPunch = $attendanceRecords->contains(function ($record) {
                    return $record->in_time && !$record->out_time;
                });

                if ($hasOpenPunch) {
                    $status = 'Active';
                }

                $employee = $result->employee;
                
                $reportData[] = [
                    'emp_id' => $empId,
                    'employee_code' => $employee ? $employee->employee_code : 'N/A',
                    'employee_name' => $employee ? $employee->name : 'Unknown',
                    'date' => $date,
                    'total_hours_formatted' => sprintf('%dh %dm', $hours, $minutes),
                    'status' => $status
                ];
            }

            // Generate HTML content for PDF
            $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>EBMS - Attendance Report</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
        th { background-color: #dc3545; color: white; font-weight: bold; }
        .report-title { text-align: center; font-size: 18px; font-weight: bold; margin-bottom: 5px; }
        .report-subtitle { text-align: center; font-size: 14px; color: #6c757d; margin-bottom: 10px; }
        .report-meta { text-align: center; font-size: 9px; color: #6c757d; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="report-title">EBMS</div>
    <div class="report-subtitle">Attendance Report</div>
    <div class="report-meta">Generated on: ' . date('d/m/Y H:i:s') . '</div>
    <table>
        <thead>
            <tr>
                <th>SI No</th>
                <th>Emp ID</th>
                <th>Name</th>
                <th>Date</th>
                <th>Working Hours</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>';

            $sno = 1;
            foreach ($reportData as $row) {
                $html .= '<tr>
                    <td>' . $sno++ . '</td>
                    <td>' . htmlspecialchars($row['employee_code']) . '</td>
                    <td>' . htmlspecialchars($row['employee_name']) . '</td>
                    <td>' . date('d/m/Y', strtotime($row['date'])) . '</td>
                    <td>' . htmlspecialchars($row['total_hours_formatted']) . '</td>
                    <td>' . htmlspecialchars($row['status']) . '</td>
                </tr>';
            }

            $html .= '</tbody>
    </table>
</body>
</html>';

            return response($html, 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');

        } catch (\Exception $e) {
            Log::error('Error exporting attendance report to PDF: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export attendance report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get location name from latitude and longitude using OpenStreetMap Nominatim (free)
     */
    private function getLocationName($latitude, $longitude): ?string
    {
        try {
            if (!$latitude || !$longitude) {
                return null;
            }

            // Using OpenStreetMap Nominatim API (free, no API key required)
            // Nominatim policy: max 1 request per second - avoid burst traffic
            $response = Http::withHeaders([
                'User-Agent' => 'EBMS-Dashboard/1.0 (admin@veeyaainnovatives.com)'
            ])->get('https://nominatim.openstreetmap.org/reverse', [
                'lat' => $latitude,
                'lon' => $longitude,
                'format' => 'json',
                'addressdetails' => 1
            ]);

            if (!$response->successful()) {
                Log::warning('Nominatim reverse geocode failed', [
                    'status' => $response->status(),
                    'lat' => $latitude,
                    'lon' => $longitude
                ]);
                return null;
            }

            $data = $response->json();
            if (!$data) {
                return null;
            }

            if (isset($data['address']) && is_array($data['address'])) {
                $addr = $data['address'];
                $name = null;
                if (isset($addr['house_number']) && isset($addr['road'])) {
                    $name = trim($addr['house_number'] . ' ' . $addr['road'] . ', ' . ($addr['suburb'] ?? $addr['city'] ?? $addr['state'] ?? ''));
                } elseif (isset($addr['road'])) {
                    $name = trim($addr['road'] . ', ' . ($addr['suburb'] ?? $addr['city'] ?? $addr['state'] ?? ''));
                } elseif (isset($addr['suburb'])) {
                    $name = trim($addr['suburb'] . ', ' . ($addr['city'] ?? $addr['state'] ?? ''));
                } elseif (isset($addr['city'])) {
                    $name = trim($addr['city'] . ', ' . ($addr['state'] ?? ''));
                } elseif (isset($addr['state'])) {
                    $name = $addr['state'];
                }
                if ($name) {
                    return $name;
                }
            }

            // Fallback: use display_name if address parsing didn't yield a result
            if (!empty($data['display_name'])) {
                return trim($data['display_name']);
            }

            return null;
        } catch (\Exception $e) {
            Log::warning('Error fetching location name from Nominatim: ' . $e->getMessage());
            return null;
        }
    }
}