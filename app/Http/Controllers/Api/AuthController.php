<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    /**
     * Create a new AuthController instance.
     */
    public function __construct()
    {
        // Middleware is now applied in routes/api.php
    }

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

        // Try to find user by username
        $user = User::where('username', $request->username)->first();
        
        // If not found by username, try email (for backward compatibility)
        if (!$user) {
            $user = User::where('email', $request->username)->first();
        }

        // Check if user exists and password is correct
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Invalid username or password.'
            ], 401);
        }

        // Check if user account is active (status = 1)
        // status = 0 means inactive/banned
        if ($user->status === 0 || $user->status === false) {
            return response()->json([
                'success' => false,
                'message' => 'Your account was temporarily banned. Please check with admin.'
            ], 403);
        }

        // Generate access token for the user (4 hours TTL)
        $token = auth('api')->login($user);

        return $this->respondWithToken($token);
    }

    /**
     * Get the authenticated User.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function me()
    {
        return response()->json([
            'success' => true,
            'data' => auth('api')->user()
        ]);
    }

    /**
     * Log the user out (Invalidate the token).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout()
    {
        auth('api')->logout();

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
            
            // Set the refresh token and get user
            $user = JWTAuth::setToken($refreshToken)->authenticate();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid refresh token'
                ], 401);
            }
            
            // Generate new access token with default TTL (4 hours)
            $newToken = auth('api')->login($user);
            
            // Generate new refresh token with refresh TTL
            // Preserve user's custom claims from User model
            $newRefreshToken = JWTAuth::factory()
                ->setTTL(config('jwt.refresh_ttl'))
                ->customClaims($user->getJWTCustomClaims())
                ->fromUser($user);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'access_token' => $newToken,
                    'refresh_token' => $newRefreshToken,
                    'token_type' => 'bearer',
                    'expires_in' => auth('api')->factory()->getTTL() * 60
                ]
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

        // Check if email exists in users table
        $user = User::where('email', $request->email)->first();
        
        if (!$user) {
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
        $resetUrl = $frontendUrl . '/reset-password/' . $token . '/' . urlencode($request->email);

        // Send email using SMTP (settings from .env file)
        try {
            $emailContent = "Hello,\n\n";
            $emailContent .= "You have requested to reset your password. Please click the following link to reset your password:\n\n";
            $emailContent .= $resetUrl . "\n\n";
            $emailContent .= "This link will expire in 60 minutes.\n\n";
            $emailContent .= "If you did not request this password reset, please ignore this email.\n\n";
            $emailContent .= "Best regards,\nSDS Management System";

            Mail::raw($emailContent, function ($message) use ($request) {
                $message->to($request->email)
                        ->subject('Password Reset Request - SDS Management System');
            });

            return response()->json([
                'success' => true,
                'message' => 'Password reset link sent to your email.'
            ]);
        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::error('Password reset email error: ' . $e->getMessage());
            
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
            'email' => 'required|email|exists:users,email',
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

        // Update user password
        $user = User::where('email', $request->email)->first();
        $user->password = Hash::make($request->password);
        $user->save();

        // Delete used token
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password has been reset successfully. You can now login with your new password.'
        ]);
    }

    /**
     * Change password for authenticated user.
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

        $user = auth('api')->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Please login again.'
            ], 401);
        }

        // Verify old password
        if (!Hash::check($request->oldPassword, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect.'
            ], 422);
        }

        // Check if new password is same as old password
        if (Hash::check($request->newPassword, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'New password must be different from current password.'
            ], 422);
        }

        // Update password
        $user->password = Hash::make($request->newPassword);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.'
        ]);
    }

    /**
     * Get the token array structure.
     *
     * @param  string $token
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithToken($token)
    {
        $user = auth('api')->user();
        
        // Generate refresh token with longer TTL (refresh_ttl from config)
        // Preserve user's custom claims from User model
        $refreshToken = JWTAuth::factory()
            ->setTTL(config('jwt.refresh_ttl'))
            ->customClaims($user->getJWTCustomClaims())
            ->fromUser($user);
        
        return response()->json([
            'success' => true,
            'data' => [
                'access_token' => $token,
                'refresh_token' => $refreshToken,
                'token_type' => 'bearer',
                'expires_in' => auth('api')->factory()->getTTL() * 60
                // User data is now included in JWT token claims for security
            ]
        ]);
    }
}
