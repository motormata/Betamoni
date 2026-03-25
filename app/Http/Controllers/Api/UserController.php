<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    //
    public function index()
    {
        $users = User::with(['role', 'market.region'])->get();

        return response()->json([
            'success' => true,
            'data' => $users
        ], 200);
    }

    /**
     * Get all available roles
     */
    public function roles()
    {
        $roles = \App\Models\Role::all();

        return response()->json([
            'success' => true,
            'data' => $roles
        ], 200);
    }

    /**
     * Create a new role (Super Admin only)
     */
    public function storeRole(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:roles,name',
            'slug' => 'required|string|max:255|unique:roles,slug',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $role = \App\Models\Role::create([
            'name' => $request->name,
            'slug' => $request->slug,
            'description' => $request->description,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Role created successfully',
            'data' => $role
        ], 201);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'phone_number' => 'required|string|unique:users',
            'password' => 'required|string|min:8',
            'role_id' => 'required|exists:roles,id',
            'market_id' => 'nullable|exists:markets,id',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false,'message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone_number' => $request->phone_number,
            'password' => Hash::make($request->password),
            'market_id' => $request->market_id,
            'role_id' => $request->role_id,
            'is_active' => $request->is_active ?? true,
        ]);

        $user->load(['role', 'market.region']);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data' => $user
        ], 201);
    }

    public function show($id)
    {
        $user = User::with(['role', 'market.region'])->find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $user
        ], 200);
    }


    public function update(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $id,
            'phone_number' => 'required|string|unique:users,phone_number,' . $id,
            'password' => 'nullable|string|min:8',
            'role_id' => 'required|exists:roles,id',
            'market_id' => 'nullable|exists:markets,id',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->except('password', 'role_id');
        
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $data = $request->except('password');
        
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        $user->load(['role', 'market.region']);

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => $user
        ], 200);
    }

    public function destroy($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully'
        ], 200);
    }

    public function assignMarket(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'market_id' => 'required|exists:markets,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user->update(['market_id' => $request->market_id]);
        $user->load(['market.region']);

        return response()->json([
            'success' => true,
            'message' => 'Agent assigned to market successfully',
            'data' => $user
        ], 200);
    }

    public function agentsPerformance(Request $request)
    {
        $supervisor = auth()->user();
        
        // Find all agents in the same market as the supervisor
        $agents = User::where('market_id', $supervisor->market_id)
            ->whereHas('role', function($q) {
                $q->where('slug', 'agent');
            })
            ->get();

        $calculationService = new \App\Services\LoanCalculationService();
        $date = $request->has('date') ? \Carbon\Carbon::parse($request->date) : today();

        $performance = $agents->map(function($agent) use ($calculationService, $date) {
            // 1. Collections made by this agent today
            $collectionsQuery = \App\Models\Payment::where('collected_by', $agent->id)
                ->whereDate('payment_date', $date);
            
            $collectionsAmount = $collectionsQuery->sum('amount');
            $collectionsCount = $collectionsQuery->count();

            // 2. Expected due today for this agent's specific borrowers
            $expectedQuery = \App\Models\RepaymentSchedule::whereDate('due_date', $date)
                ->whereHas('loan', function($q) use ($agent) {
                    $q->where('agent_id', $agent->id);
                });
            
            $expectedAmount = $expectedQuery->sum('expected_amount');
            $expectedCount = $expectedQuery->count();

            // 3. Total Overdue amount for this agent's active loans
            $overdueQuery = \App\Models\RepaymentSchedule::whereDate('due_date', '<', $date)
                ->where('status', '!=', 'paid')
                ->whereHas('loan', function($q) use ($agent) {
                    $q->where('agent_id', $agent->id);
                });
            
            $overdueSchedules = $overdueQuery->get();
            $totalOverdueAmount = $overdueSchedules->sum(function($s) { return $s->getOutstandingAmount(); });
            $overdueCount = $overdueSchedules->count();

            return [
                'agent_id' => $agent->id,
                'agent_name' => $agent->name,
                'today' => [
                    'collected_amount' => $collectionsAmount,
                    'collected_count' => $collectionsCount,
                    'expected_amount' => $expectedAmount,
                    'expected_count' => $expectedCount,
                    'performance_rate' => $expectedAmount > 0 ? round(($collectionsAmount / $expectedAmount) * 100, 2) : 0,
                ],
                'portfolio' => [
                    'total_overdue_amount' => $totalOverdueAmount,
                    'total_overdue_count' => $overdueCount,
                    'active_loans_count' => \App\Models\Loan::where('agent_id', $agent->id)
                        ->whereIn('status', ['active', 'overdue'])
                        ->count(),
                ]
            ];
        });

        return response()->json([
            'success' => true,
            'market_name' => $supervisor->market->name ?? 'Unknown',
            'date' => $date->format('Y-m-d'),
            'data' => $performance
        ], 200);
    }
}
