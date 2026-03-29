<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ProfileController extends Controller
{
    /**
     * Get current user profile
     */
    public function show(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            $user->load('employee.role');

            // Format the response similar to login API
            $formattedData = [
                'id' => $user->id,
                'username' => $user->username,
                'status' => $user->status,
                'employee' => $user->employee ? [
                    'id' => $user->employee->id,
                    'name' => $user->employee->name,
                    'employee_code' => $user->employee->employee_code,
                    'email' => $user->employee->email,
                    'mobile_number' => $user->employee->mobile_number,
                    'profile_photo' => $user->employee->employee_image ? url('storage/' . $user->employee->employee_image) : null,
                    'role' => $user->employee->role ? [
                        'id' => $user->employee->role->id,
                        'name' => $user->employee->role->name,
                        'slug' => $user->employee->role->slug,
                    ] : null,
                ] : null,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Profile retrieved successfully',
                'data' => $formattedData
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving profile: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user profile
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Log profile update attempt
            Log::info('Profile update request for user ID: ' . $user->id);

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255|unique:employees,email,' . $user->employee->id,
                'mobile_number' => 'required|string|max:20',
                'profile_photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120' // 5MB max
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $employee = $user->employee;
            
            // Update employee information
            $employee->name = $request->name;
            $employee->email = $request->email;
            $employee->mobile_number = $request->mobile_number;
            $employee->updated_by = $user->id;

            // Handle profile photo upload
            if ($request->hasFile('profile_photo')) {
                // Delete old profile photo if exists
                if ($employee->profile_photo) {
                    $oldPhotoPath = str_replace(url('/storage/'), '', $employee->profile_photo);
                    if (Storage::disk('public')->exists($oldPhotoPath)) {
                        Storage::disk('public')->delete($oldPhotoPath);
                    }
                }

                // Upload new profile photo
                $file = $request->file('profile_photo');
                $filename = 'profile_' . $user->id . '_' . time() . '.' . $file->getClientOriginalExtension();
                // Store file in public/files/employees/images/
                $folderPath = public_path('files/employees/images');
                if (!file_exists($folderPath)) {
                    mkdir($folderPath, 0755, true);
                }
                $file->move($folderPath, $filename);
                $path = "files/employees/images/{$filename}";
                $employee->profile_photo = url($path);
            }

            $employee->save();

            // Refresh user data
            $user->load('employee.role');

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => $user
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating profile: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reset user password
     */
    public function resetPassword(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:6|confirmed',
                'new_password_confirmation' => 'required|string|min:6'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verify current password
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect'
                ], 400);
            }

            // Update password
            $user->password = Hash::make($request->new_password);
            $user->save();

            Log::info("Password updated for user ID: {$user->id}");

            return response()->json([
                'success' => true,
                'message' => 'Password updated successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating password: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update password',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove profile photo
     */
    public function removeProfilePhoto(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            $employee = $user->employee;
            
            if ($employee->profile_photo) {
                // Delete profile photo file
                $photoPath = str_replace(url('/storage/'), '', $employee->profile_photo);
                if (Storage::disk('public')->exists($photoPath)) {
                    Storage::disk('public')->delete($photoPath);
                }

                // Remove photo reference from database
                $employee->profile_photo = null;
                $employee->updated_by = $user->id;
                $employee->save();
            }

            // Refresh user data
            $user->load('employee.role');

            return response()->json([
                'success' => true,
                'message' => 'Profile photo removed successfully',
                'data' => $user
            ]);

        } catch (\Exception $e) {
            Log::error('Error removing profile photo: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove profile photo',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
