<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Machine;
use App\Models\Employee;
use App\Models\EmployeeCustomerMachineAssignment;
use App\Models\CustomerMachine;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackupController extends Controller
{
    /** Module key => [label, model, nameAttribute] */
    private const MODULES = [
        'customers' => [
            'label' => 'Customers',
            'model' => Customer::class,
            'name_column' => 'company_name', // fallback name
            'name_callback' => null, // use model
        ],
        'machines' => [
            'label' => 'Machines',
            'model' => Machine::class,
            'name_column' => 'machine_alias',
            'name_callback' => null,
        ],
        'employees' => [
            'label' => 'Employees',
            'model' => Employee::class,
            'name_column' => 'name',
            'name_callback' => null,
        ],
        'assign_employees' => [
            'label' => 'Assign Employees',
            'model' => EmployeeCustomerMachineAssignment::class,
            'name_column' => null,
            'name_callback' => true, // build from relations
        ],
        'assign_machine_to_customer' => [
            'label' => 'Assign Machine to Customer',
            'model' => CustomerMachine::class,
            'name_column' => null,
            'name_callback' => true,
        ],
    ];

    /**
     * List available backup modules
     */
    public function modules(): JsonResponse
    {
        $list = [];
        foreach (self::MODULES as $key => $config) {
            $list[] = ['id' => $key, 'name' => $config['label']];
        }
        return response()->json(['success' => true, 'data' => $list]);
    }

    /**
     * Get soft-deleted records for a module
     */
    public function index(Request $request, string $module): JsonResponse
    {
        if (!isset(self::MODULES[$module])) {
            return response()->json(['success' => false, 'message' => 'Invalid module'], 422);
        }

        $config = self::MODULES[$module];
        $modelClass = $config['model'];

        try {
            $query = $modelClass::onlyTrashed()->orderBy('deleted_at', 'desc');

            // Eager load relations for name display (include trashed so we can show names)
            if ($module === 'assign_employees') {
                $query->with(['customer' => fn ($q) => $q->withTrashed(), 'employee' => fn ($q) => $q->withTrashed(), 'machine' => fn ($q) => $q->withTrashed()]);
            }
            if ($module === 'assign_machine_to_customer') {
                $query->with(['customer' => fn ($q) => $q->withTrashed(), 'machine' => fn ($q) => $q->withTrashed()]);
            }

            $rows = $query->get();
            $data = $rows->map(function ($row, $index) use ($module, $config) {
                $name = $this->getDisplayName($row, $module, $config);
                return [
                    'si' => $index + 1,
                    'id' => $row->id,
                    'name' => $name,
                    'deleted_at' => $row->deleted_at?->format('Y-m-d H:i:s'),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'count' => $data->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Backup index error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch deleted records',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function getDisplayName($row, string $module, array $config): string
    {
        if ($module === 'assign_employees') {
            $c = $row->customer ? ($row->customer->company_name ?? $row->customer->name) : 'N/A';
            $e = $row->employee ? $row->employee->name : 'N/A';
            $m = $row->machine ? ($row->machine->serial_number . ' - ' . $row->machine->machine_alias) : 'N/A';
            return $c . ' | ' . $e . ' | ' . $m;
        }
        if ($module === 'assign_machine_to_customer') {
            $c = $row->customer ? ($row->customer->company_name ?? $row->customer->name) : 'N/A';
            $m = $row->machine ? ($row->machine->serial_number . ' - ' . $row->machine->machine_alias) : 'N/A';
            return $c . ' | ' . $m;
        }
        $col = $config['name_column'];
        if ($col && isset($row->{$col})) {
            $v = $row->{$col};
            if ($module === 'machines') {
                return ($row->serial_number ?? '') . ' - ' . ($v ?? '');
            }
            return (string) $v;
        }
        return '#' . $row->id;
    }

    /**
     * Revert (restore): set deleted_at = null on this record only.
     * For machines: only the machine is restored; customer/employee assignment links are not restored.
     */
    public function revert(string $module, int $id): JsonResponse
    {
        if (!isset(self::MODULES[$module])) {
            return response()->json(['success' => false, 'message' => 'Invalid module'], 422);
        }

        $modelClass = self::MODULES[$module]['model'];

        try {
            $row = $modelClass::onlyTrashed()->find($id);
            if (!$row) {
                return response()->json(['success' => false, 'message' => 'Record not found in trash'], 404);
            }
            $row->restore();
            return response()->json([
                'success' => true,
                'message' => 'Record restored successfully',
                'data' => ['id' => $row->id],
            ]);
        } catch (\Exception $e) {
            Log::error('Backup revert error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to restore record',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Force delete: permanently delete and sync/clean dependencies
     */
    public function forceDelete(string $module, int $id): JsonResponse
    {
        if (!isset(self::MODULES[$module])) {
            return response()->json(['success' => false, 'message' => 'Invalid module'], 422);
        }

        $modelClass = self::MODULES[$module]['model'];

        try {
            DB::beginTransaction();

            $row = $modelClass::withTrashed()->find($id);
            if (!$row) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Record not found'], 404);
            }

            $this->forceDeleteDependencies($module, $id, $modelClass);

            $row->forceDelete();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Record permanently deleted',
                'data' => ['id' => $id],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Backup forceDelete error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to permanently delete record',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function forceDeleteDependencies(string $module, int $id, string $modelClass): void
    {
        if ($module === 'customers') {
            EmployeeCustomerMachineAssignment::withTrashed()->where('customer_id', $id)->forceDelete();
            CustomerMachine::withTrashed()->where('cust_id', $id)->forceDelete();
        }
        if ($module === 'machines') {
            EmployeeCustomerMachineAssignment::withTrashed()->where('assigned_machine_id', $id)->forceDelete();
            CustomerMachine::withTrashed()->where('machine_id', $id)->forceDelete();
        }
        if ($module === 'employees') {
            EmployeeCustomerMachineAssignment::withTrashed()->where('employee_id', $id)->forceDelete();
        }
        // assign_employees and assign_machine_to_customer have no child rows to clean
    }
}
