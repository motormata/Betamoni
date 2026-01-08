<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;


use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

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
        
        // Find user
        $user = User::where($loginField, $request->login)
                    ->where('is_active', true)
                    ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials or account is inactive'
            ], 401);
        }

        // Load relationships
        $user->load(['roles', 'market.region']);

        // Create token
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'agent_code' => $user->agent_code,
                    'is_active' => $user->is_active,
                    'role' => $user->roles->first()->slug ?? null,
                    'role_name' => $user->roles->first()->name ?? null,
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
                'token_type' => 'Bearer'
            ]
        ], 200);
    }

    /**
     * Get authenticated user details
     */
    public function me(Request $request)
    {
        $user = $request->user();
        $user->load(['roles', 'market.region']);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'agent_code' => $user->agent_code,
                'is_active' => $user->is_active,
                'role' => $user->roles->first()->slug ?? null,
                'role_name' => $user->roles->first()->name ?? null,
                'permissions' => $user->roles->flatMap->permissions->pluck('slug')->unique()->values(),
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
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ], 200);
    }

    /**
     * Logout from all devices (revoke all tokens)
     */
    public function logoutAll(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out from all devices successfully'
        ], 200);
    }

    /**
     * Refresh token
     */
    public function refresh(Request $request)
    {
        $user = $request->user();
        
        // Delete current token
        $request->user()->currentAccessToken()->delete();
        
        // Create new token
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Token refreshed successfully',
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer'
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

