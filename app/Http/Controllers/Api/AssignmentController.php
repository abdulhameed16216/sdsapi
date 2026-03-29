<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AssignmentController extends Controller
{
    /**
     * Get all assignments
     */
    public function index()
    {
        try {
            $vendorMachineAssignments = $this->getVendorMachineAssignments();
            $employeeVendorAssignments = $this->getEmployeeVendorAssignments();

            return response()->json([
                'success' => true,
                'data' => [
                    'vendor_machine_assignments' => $vendorMachineAssignments,
                    'employee_vendor_assignments' => $employeeVendorAssignments
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve assignments',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign vendor to machine
     */
    public function assignVendorToMachine(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'vendor_id' => 'required|exists:customers,id',
                'machine_id' => 'required|exists:machines,id',
                'assigned_date' => 'nullable|date',
                'notes' => 'nullable|string|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check if assignment already exists
            $existingAssignment = DB::table('vendor_machine_assignments')
                ->where('vendor_id', $request->vendor_id)
                ->where('machine_id', $request->machine_id)
                ->whereNull('deleted_at')
                ->first();

            if ($existingAssignment) {
                return response()->json([
                    'success' => false,
                    'message' => 'This vendor is already assigned to this machine'
                ], 400);
            }

            // Create assignment
            $assignmentId = DB::table('vendor_machine_assignments')->insertGetId([
                'vendor_id' => $request->vendor_id,
                'machine_id' => $request->machine_id,
                'assigned_date' => $request->assigned_date ?? now(),
                'notes' => $request->notes,
                'status' => 'active',
                'created_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Vendor assigned to machine successfully',
                'data' => ['assignment_id' => $assignmentId]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign vendor to machine',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign employee to vendor
     */
    public function assignEmployeeToVendor(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'employee_id' => 'required|exists:employees,id',
                'vendor_id' => 'required|exists:customers,id',
                'assigned_date' => 'nullable|date',
                'notes' => 'nullable|string|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check if assignment already exists
            $existingAssignment = DB::table('employee_vendor_assignments')
                ->where('employee_id', $request->employee_id)
                ->where('vendor_id', $request->vendor_id)
                ->whereNull('deleted_at')
                ->first();

            if ($existingAssignment) {
                return response()->json([
                    'success' => false,
                    'message' => 'This employee is already assigned to this vendor'
                ], 400);
            }

            // Create assignment
            $assignmentId = DB::table('employee_vendor_assignments')->insertGetId([
                'employee_id' => $request->employee_id,
                'vendor_id' => $request->vendor_id,
                'assigned_date' => $request->assigned_date ?? now(),
                'notes' => $request->notes,
                'status' => 'active',
                'created_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Employee assigned to vendor successfully',
                'data' => ['assignment_id' => $assignmentId]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign employee to vendor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove vendor from machine
     */
    public function removeVendorFromMachine(Request $request, $id)
    {
        try {
            $updated = DB::table('vendor_machine_assignments')
                ->where('id', $id)
                ->update([
                    'deleted_at' => now(),
                    'deleted_by' => auth()->id(),
                    'updated_at' => now()
                ]);

            if ($updated) {
                return response()->json([
                    'success' => true,
                    'message' => 'Vendor removed from machine successfully'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Assignment not found'
                ], 404);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove vendor from machine',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove employee from vendor
     */
    public function removeEmployeeFromVendor(Request $request, $id)
    {
        try {
            $updated = DB::table('employee_vendor_assignments')
                ->where('id', $id)
                ->update([
                    'deleted_at' => now(),
                    'deleted_by' => auth()->id(),
                    'updated_at' => now()
                ]);

            if ($updated) {
                return response()->json([
                    'success' => true,
                    'message' => 'Employee removed from vendor successfully'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Assignment not found'
                ], 404);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove employee from vendor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get vendor machine assignments
     */
    private function getVendorMachineAssignments()
    {
        return DB::table('vendor_machine_assignments as vma')
            ->join('customers as v', 'vma.vendor_id', '=', 'v.id')
            ->join('machines as m', 'vma.machine_id', '=', 'm.id')
            ->select(
                'vma.id',
                'vma.vendor_id',
                'vma.machine_id',
                'vma.assigned_date',
                'vma.notes',
                'vma.status',
                'v.name as vendor_name',
                'v.company_name as vendor_company',
                'm.name as machine_name',
                'm.machine_code',
                'vma.created_at'
            )
            ->whereNull('vma.deleted_at')
            ->orderBy('vma.created_at', 'desc')
            ->get();
    }

    /**
     * Get employee vendor assignments
     */
    private function getEmployeeVendorAssignments()
    {
        return DB::table('employee_vendor_assignments as eva')
            ->join('employees as e', 'eva.employee_id', '=', 'e.id')
            ->join('customers as v', 'eva.vendor_id', '=', 'v.id')
            ->select(
                'eva.id',
                'eva.employee_id',
                'eva.vendor_id',
                'eva.assigned_date',
                'eva.notes',
                'eva.status',
                'e.name as employee_name',
                'e.employee_code',
                'v.name as vendor_name',
                'v.company_name as vendor_company',
                'eva.created_at'
            )
            ->whereNull('eva.deleted_at')
            ->orderBy('eva.created_at', 'desc')
            ->get();
    }

    /**
     * Get available vendors for assignment
     */
    public function getAvailableVendors()
    {
        try {
            $vendors = DB::table('customers')
                ->select('id', 'name', 'company_name', 'email', 'phone')
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $vendors
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve vendors',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available machines for assignment
     */
    public function getAvailableMachines()
    {
        try {
            $machines = DB::table('machines')
                ->select('id', 'name', 'machine_code', 'status', 'location')
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $machines
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve machines',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available employees for assignment
     */
    public function getAvailableEmployees()
    {
        try {
            $employees = DB::table('employees')
                ->select('id', 'name', 'employee_code', 'email', 'mobile_number')
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $employees
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve employees',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
