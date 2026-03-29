<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

class AuthController extends Controller
{
    /**
     * Register a new user (deprecated - use EmployeeController instead)
     */
    public function register(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Registration is handled through the Employee API. Please use POST /api/employees endpoint.'
        ], 400);
    }

    /**
     * Login user
     * Supports both web (username) and mobile (mobile number) login
     * Use ismobile: true for mobile login, username field will contain mobile number
     */
    public function login(Request $request): JsonResponse
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required',
            'ismobile' => 'nullable|boolean', // Optional: true for mobile login
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $isMobileLogin = $request->input('ismobile', false) === true || $request->input('ismobile') === 'true' || $request->input('ismobile') === 1;
        
        // Both web and mobile use same login logic: username and password
        $credentials = $request->only('username', 'password');
        
        try {
            // Set JWT TTL: 2 days for mobile app, 1 day for web
            if ($isMobileLogin) {
                // Set TTL to 2 days (2880 minutes = 48 hours) for mobile app
                $token = JWTAuth::customClaims(['exp' => now()->addDays(2)->timestamp])->attempt($credentials);
            } else {
                // Set TTL to 1 day (1440 minutes = 24 hours) for web
                $token = JWTAuth::customClaims(['exp' => now()->addDay()->timestamp])->attempt($credentials);
            }
            
            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials'
                ], 401);
            }
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Could not create token'
            ], 500);
        }

        // Get user with employee and role data (same for both web and mobile)
        $user = User::with('employee.role.privileges')->where('username', $request->username)->first();
        
        if (!$user->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Account is inactive'
            ], 403);
        }

        // Update last login
        $user->update(['last_login_at' => now()]);

        // Clean up old refresh tokens for this user
        $user->tokens()->where('name', 'refresh_token')->delete();

        // Create a simple refresh token (random string)
        $refreshToken = \Illuminate\Support\Str::random(40);
        
        // Store refresh token in personal_access_tokens table
        $user->tokens()->create([
            'name' => 'refresh_token',
            'token' => hash('sha256', $refreshToken),
            'refresh_token' => $refreshToken,
            'abilities' => ['refresh'],
            'expires_at' => now()->addDays(30) // Refresh token valid for 30 days
        ]);

        // Get privileges from user's role
        $privileges = [];
        if ($user->employee && $user->employee->role) {
            $role = $user->employee->role;
            // Fetch privileges and format as "category.action"
            $rolePrivileges = $role->privileges;
            foreach ($rolePrivileges as $privilege) {
                $privileges[] = $privilege->category . '.' . $privilege->action;
            }
        }
        
        // Calculate expires_in: 2 days (172800 seconds) for mobile, 1 day (86400 seconds) for web
        $expiresIn = $isMobileLogin ? (2880 * 60) : (1440 * 60);
        
        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'access_token' => $token,
                'refresh_token' => $refreshToken,
                'token_type' => 'bearer',
                'expires_in' => $expiresIn,
                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'status' => $user->status,
                    'employee' => $user->employee ? [
                        'id' => $user->employee->id,
                        'name' => $user->employee->name,
                        'employee_code' => $user->employee->employee_code,
                        'email' => $user->employee->email,
                        'mobile_number' => $user->employee->mobile_number,
                        'profile_photo' => $user->employee->employee_image ? (str_starts_with($user->employee->employee_image, 'files/') ? url($user->employee->employee_image) : url('files/' . $user->employee->employee_image)) : null,
                        'role' => $user->employee->role ? [
                            'id' => $user->employee->role->id,
                            'name' => $user->employee->role->name,
                            'slug' => $user->employee->role->slug,
                        ] : null,
                    ] : null,
                ],
                'privileges' => $privileges, // Add privileges array
            ]
        ]);
    }

    /**
     * Refresh access token using refresh token
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            $refreshToken = $request->input('refresh_token');
            
            if (!$refreshToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Refresh token is required'
                ], 400);
            }
            
            // Find the refresh token in the database
            $tokenRecord = \Laravel\Sanctum\PersonalAccessToken::where('refresh_token', $refreshToken)
                ->where('name', 'refresh_token')
                ->where('expires_at', '>', now())
                ->first();
            
            if (!$tokenRecord) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired refresh token'
                ], 401);
            }
            
            // Get the user from the token
            $user = $tokenRecord->tokenable;
            
            if (!$user || !$user->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or inactive user'
                ], 401);
            }
            
            // Load role and privileges
            $user->load('employee.role.privileges');
            
            // Create new access token
            $newToken = JWTAuth::fromUser($user);

            // Get privileges from user's role
            $privileges = [];
            if ($user->employee && $user->employee->role) {
                $role = $user->employee->role;
                // Fetch privileges and format as "category.action"
                $rolePrivileges = $role->privileges;
                foreach ($rolePrivileges as $privilege) {
                    $privileges[] = $privilege->category . '.' . $privilege->action;
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Token refreshed successfully',
                'data' => [
                    'access_token' => $newToken,
                    'token_type' => 'bearer',
                    'expires_in' => JWTAuth::factory()->getTTL() * 60,
                    'user' => [
                        'id' => $user->id,
                        'username' => $user->username,
                        'status' => $user->status,
                        'employee' => $user->employee ? [
                            'id' => $user->employee->id,
                            'name' => $user->employee->name,
                            'employee_code' => $user->employee->employee_code,
                            'email' => $user->employee->email,
                            'mobile_number' => $user->employee->mobile_number,
                            'profile_photo' => $user->employee->employee_image ? (str_starts_with($user->employee->employee_image, 'files/') ? url($user->employee->employee_image) : url('files/' . $user->employee->employee_image)) : null,
                            'role' => $user->employee->role ? [
                                'id' => $user->employee->role->id,
                                'name' => $user->employee->role->name,
                                'slug' => $user->employee->role->slug,
                            ] : null,
                        ] : null,
                    ],
                    'privileges' => $privileges, // Add privileges array
                ]
            ]);
            
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid refresh token'
            ], 401);
        }
    }

    /**
     * Logout user
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            // Invalidate current JWT token
            JWTAuth::invalidate(JWTAuth::getToken());
            
            // Delete all refresh tokens for this user
            if ($user) {
                $user->tokens()->where('name', 'refresh_token')->delete();
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Logout successful'
            ]);
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Could not logout'
            ], 500);
        }
    }

    /**
     * Clean up expired refresh tokens (can be called periodically)
     */
    public function cleanupExpiredTokens(): JsonResponse
    {
        try {
            $deletedCount = \Laravel\Sanctum\PersonalAccessToken::where('name', 'refresh_token')
                ->where('expires_at', '<', now())
                ->delete();
            
            return response()->json([
                'success' => true,
                'message' => "Cleaned up {$deletedCount} expired refresh tokens"
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cleanup expired tokens'
            ], 500);
        }
    }

    /**
     * Get authenticated user
     */
    public function user(Request $request): JsonResponse
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'username' => $user->username,
                        'status' => $user->status,
                        'employee' => $user->employee ? [
                            'id' => $user->employee->id,
                            'name' => $user->employee->name,
                            'employee_code' => $user->employee->employee_code,
                            'email' => $user->employee->email,
                            'mobile_number' => $user->employee->mobile_number,
                            'profile_photo' => $user->employee->employee_image ? (str_starts_with($user->employee->employee_image, 'files/') ? url($user->employee->employee_image) : url('files/' . $user->employee->employee_image)) : null,
                            'role' => $user->employee->role ? [
                                'id' => $user->employee->role->id,
                                'name' => $user->employee->role->name,
                                'slug' => $user->employee->role->slug,
                            ] : null,
                        ] : null,
                    ]
                ]
            ]);
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token is invalid'
            ], 401);
        }
    }

    /**
     * Update user profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'avatar' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $user->update($request->only(['name', 'phone', 'avatar']));

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => [
                'user' => $user->fresh()
            ]
        ]);
    }

    /**
     * Change password
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect'
            ], 400);
        }

        $user->update([
            'password' => Hash::make($request->password)
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully'
        ]);
    }

    /**
     * Forgot password
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        // Here you would typically send a password reset email
        // For now, we'll just return a success message

        return response()->json([
            'success' => true,
            'message' => 'Password reset link sent to your email'
        ]);
    }

    /**
     * Reset password
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        // Here you would typically validate the reset token
        // For now, we'll just update the password

        $user = User::where('email', $request->email)->first();
        $user->update([
            'password' => Hash::make($request->password)
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully'
        ]);
    }
}
