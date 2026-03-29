<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $search = $request->get('search');

        $query = Customer::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('mobile_number', 'like', "%{$search}%")
                  ->orWhere('address', 'like', "%{$search}%");
            });
        }

        $customers = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $customers
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:customers,email',
            'mobile_number' => 'required|string|max:20',
            'state' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:1000',
            'username' => [
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    if ($value && Customer::where('username', $value)->exists()) {
                        $fail('The username has already been taken.');
                    }
                },
            ],
            'password' => 'nullable|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = [
            'name' => $request->name,
            'email' => $request->email,
            'mobile_number' => $request->mobile_number,
            'state' => $request->state,
        ];

        // Only add username if provided
        if ($request->filled('username')) {
            $data['username'] = $request->username;
        }

        // Only add password if provided
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $customer = Customer::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Customer created successfully',
            'data' => $customer
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $customer
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:customers,email,' . $id,
            'mobile_number' => 'required|string|max:20',
            'state' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:1000',
            'username' => [
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($id) {
                    if ($value && Customer::where('username', $value)->where('id', '!=', $id)->exists()) {
                        $fail('The username has already been taken.');
                    }
                },
            ],
            'password' => 'nullable|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = [
            'name' => $request->name,
            'email' => $request->email,
            'mobile_number' => $request->mobile_number,
            'state' => $request->state,
            'address' => $request->address,
        ];

        // Only update username if provided
        if ($request->filled('username')) {
            $data['username'] = $request->username;
        }

        // Only update password if provided
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $customer->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Customer updated successfully',
            'data' => $customer->fresh()
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found'
            ], 404);
        }

        $customer->delete();

        return response()->json([
            'success' => true,
            'message' => 'Customer deleted successfully'
        ]);
    }
}

