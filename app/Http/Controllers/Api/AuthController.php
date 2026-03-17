<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;


use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * Login user and create token
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'login' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Determine login field type
        $loginField = $this->getLoginField($request->login);
        
        $credentials = [
            $loginField => $request->login,
            'password' => $request->password,
        ];

        // Attempt to login using JWT ('api' guard)
        if (!$token = auth('api')->attempt($credentials)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }

        // Get the authenticated user
        $user = auth('api')->user();

        // Check if user is active
        if (!$user->is_active) {
            auth('api')->logout();
            return response()->json([
                'success' => false,
                'message' => 'Account is inactive'
            ], 401);
        }

        // Load relationships for response
        $user->load(['role', 'market.region']);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone_number,
                    'agent_code' => $user->agent_code,
                    'is_active' => $user->is_active,
                    'role' => $user->role->slug ?? null,
                    'role_name' => $user->role->name ?? null,
                    'market' => $user->market ? [
                        'id' => $user->market->id,
                        'name' => $user->market->name,
                        'code' => $user->market->code,
                        'region' => [
                            'id' => $user->market->region->id,
                            'name' => $user->market->region->name,
                        ]
                    ] : null,
                ],
                'token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => config('jwt.ttl') * 60
            ]
        ], 200);
    }

    /**
     * Get authenticated user details
     */
    public function me()
    {
        $user = auth('api')->user();
        $user->load(['role', 'market.region']);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone_number,
                'agent_code' => $user->agent_code,
                'is_active' => $user->is_active,
                'role' => $user->role->slug ?? null,
                'role_name' => $user->role->name ?? null,
                'market' => $user->market ? [
                    'id' => $user->market->id,
                    'name' => $user->market->name,
                    'code' => $user->market->code,
                    'address' => $user->market->address,
                    'region' => [
                        'id' => $user->market->region->id,
                        'name' => $user->market->region->name,
                        'code' => $user->market->region->code,
                    ]
                ] : null,
            ]
        ], 200);
    }

    /**
     * Logout user (revoke token)
     */
    public function logout()
    {
        auth('api')->logout();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ], 200);
    }

    /**
     * Refresh token
     */
    public function refresh()
    {
        return response()->json([
            'success' => true,
            'message' => 'Token refreshed successfully',
            'data' => [
                'token' => auth('api')->refresh(),
                'token_type' => 'Bearer',
                'expires_in' => config('jwt.ttl') * 60
            ]
        ], 200);
    }

    /**
     * Determine login field based on input
     */
    private function getLoginField($value)
    {
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return 'email';
        }
        
        if (strpos(strtoupper($value), 'AGT') === 0) {
            return 'agent_code';
        }
        
        return 'phone';
    }
}

