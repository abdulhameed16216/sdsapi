<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tymon\JWTAuth\Facades\JWTAuth;

class CustomerAuthController extends Controller
{
    /**
     * Get a JWT via given credentials.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Try to find customer by username
        $customer = Customer::where('username', $request->username)->first();
        
        // If not found by username, try email (for backward compatibility)
        if (!$customer) {
            $customer = Customer::where('email', $request->username)->first();
        }

        // Check if customer exists and password is correct
        if (!$customer || !Hash::check($request->password, $customer->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Invalid username or password.'
            ], 401);
        }

        // Generate access token for the customer (4 hours TTL)
        // Use customer-api guard for customers
        $token = auth('customer-api')->setTTL(240)->login($customer);

        return $this->respondWithToken($token, $customer);
    }

    /**
     * Get the authenticated Customer.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function me()
    {
        return response()->json([
            'success' => true,
            'data' => auth('customer-api')->user()
        ]);
    }

    /**
     * Log the customer out (Invalidate the token).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout()
    {
        auth('customer-api')->logout();

        return response()->json([
            'success' => true,
            'message' => 'Successfully logged out'
        ]);
    }

    /**
     * Refresh a token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh(Request $request)
    {
        try {
            // Get refresh token from request body or Authorization header
            $refreshToken = $request->input('refresh_token');
            
            if (!$refreshToken) {
                // Try to get from Authorization header
                $authHeader = $request->header('Authorization');
                if ($authHeader && strpos($authHeader, 'Bearer ') === 0) {
                    $refreshToken = substr($authHeader, 7);
                }
            }
            
            if (!$refreshToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Refresh token is required'
                ], 401);
            }
            
            // Set the refresh token and get customer using customer-api guard
            JWTAuth::setDefaultDriver('customer-api');
            $customer = JWTAuth::setToken($refreshToken)->authenticate();
            
            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid refresh token'
                ], 401);
            }
            
            // Generate new access token with default TTL (4 hours)
            $newToken = auth('customer-api')->setTTL(240)->login($customer);
            
            // Generate new refresh token with 1 day TTL (1440 minutes = 24 hours)
            // Preserve customer's custom claims from Customer model
            $newRefreshToken = JWTAuth::factory()
                ->setTTL(1440) // 1 day = 24 hours = 1440 minutes
                ->customClaims($customer->getJWTCustomClaims())
                ->fromUser($customer);
            
            // Return only access_token and refresh_token directly (no roles, no extra data)
            return response()->json([
                'access_token' => $newToken,
                'refresh_token' => $newRefreshToken
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to refresh token: ' . $e->getMessage()
            ], 401);
        }
    }

    /**
     * Request password reset (send reset link to email).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if email exists in customers table
        $customer = Customer::where('email', $request->email)->first();
        
        if (!$customer) {
            // Don't reveal if email exists or not for security
            return response()->json([
                'success' => true,
                'message' => 'If that email address exists in our system, we will send a password reset link.'
            ]);
        }

        // Generate password reset token (10 characters: alphanumeric only - letters and numbers)
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $token = '';
        for ($i = 0; $i < 10; $i++) {
            $token .= $characters[random_int(0, strlen($characters) - 1)];
        }
        
        // Store token in password_reset_tokens table (plain token, not hashed)
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            [
                'token' => $token, // Store plain 10-character token
                'created_at' => now()
            ]
        );

        // Generate reset URL - read FRONTEND_URL from .env file
        $frontendUrl = config('app.frontend_url');
        $resetUrl = $frontendUrl . '/customer/reset-password/' . $token . '/' . urlencode($request->email);

        // Send email using SMTP (settings from .env file)
        try {
            $emailContent = "Hello " . $customer->name . ",\n\n";
            $emailContent .= "You have requested to reset your password. Please click the following link to reset your password:\n\n";
            $emailContent .= $resetUrl . "\n\n";
            $emailContent .= "This link will expire in 60 minutes.\n\n";
            $emailContent .= "If you did not request this password reset, please ignore this email.\n\n";
            $emailContent .= "Best regards,\nSDS Management System";

            Mail::raw($emailContent, function ($message) use ($request, $customer) {
                $message->to($request->email, $customer->name)
                        ->subject('Password Reset Request - SDS Customer Portal');
            });

            return response()->json([
                'success' => true,
                'message' => 'Password reset link sent to your email.'
            ]);
        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::error('Customer password reset email error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Unable to send password reset link. Please try again later.'
            ], 500);
        }
    }

    /**
     * Verify reset token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyResetToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if token exists and is valid
        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$resetRecord) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired reset token.'
            ], 400);
        }

        // Check if token is expired (60 minutes)
        if (now()->diffInMinutes($resetRecord->created_at) > 60) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return response()->json([
                'success' => false,
                'message' => 'Reset token has expired. Please request a new one.'
            ], 400);
        }

        // Verify token (compare plain tokens)
        if ($request->token !== $resetRecord->token) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid reset token.'
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Token is valid.'
        ]);
    }

    /**
     * Reset password with token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetPasswordWithToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'email' => 'required|email|exists:customers,email',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if token exists and is valid
        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$resetRecord) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired reset token.'
            ], 400);
        }

        // Check if token is expired (60 minutes)
        if (now()->diffInMinutes($resetRecord->created_at) > 60) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return response()->json([
                'success' => false,
                'message' => 'Reset token has expired. Please request a new one.'
            ], 400);
        }

        // Verify token (compare plain tokens)
        if ($request->token !== $resetRecord->token) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid reset token.'
            ], 400);
        }

        // Update customer password
        $customer = Customer::where('email', $request->email)->first();
        $customer->password = Hash::make($request->password);
        $customer->save();

        // Delete used token
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password has been reset successfully. You can now login with your new password.'
        ]);
    }

    /**
     * Change password for authenticated customer.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'oldPassword' => 'required|string',
            'newPassword' => 'required|string|min:6',
            'confirmPassword' => 'required|string|same:newPassword',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $customer = auth('customer-api')->user();

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Please login again.'
            ], 401);
        }

        // Verify old password
        if (!Hash::check($request->oldPassword, $customer->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect.'
            ], 422);
        }

        // Check if new password is same as old password
        if (Hash::check($request->newPassword, $customer->password)) {
            return response()->json([
                'success' => false,
                'message' => 'New password must be different from current password.'
            ], 422);
        }

        // Update password
        $customer->password = Hash::make($request->newPassword);
        $customer->save();

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.'
        ]);
    }

    /**
     * Get the token array structure.
     *
     * @param  string $token
     * @param  \App\Models\Customer|null $customer
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithToken($token, $customer = null)
    {
        // Get customer from parameter or auth context (same pattern as admin AuthController)
        if (!$customer) {
            $customer = auth('customer-api')->user();
        }
        
        // Generate refresh token with 1 day TTL (1440 minutes = 24 hours)
        // Preserve customer's custom claims from Customer model
        $refreshToken = JWTAuth::factory()
            ->setTTL(1440) // 1 day = 24 hours = 1440 minutes
            ->customClaims($customer->getJWTCustomClaims())
            ->fromUser($customer);
        
        // Return only access_token and refresh_token directly (no wrapper, no roles, no extra data)
        return response()->json([
            'access_token' => $token,
            'refresh_token' => $refreshToken
        ]);
    }
}

