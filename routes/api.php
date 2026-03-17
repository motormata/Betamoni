<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RegionController;
use App\Http\Controllers\Api\MarketController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\BorrowerController;
use App\Http\Controllers\Api\LoanController;

// Public routes
Route::get('/force-uuid-reset', function() {
    try {
        \Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--force' => true]);
        return response()->json(['success' => true, 'message' => 'Database reset to UUID schema successfully.']);
    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
});

Route::post('/setup-admin', function (\Illuminate\Http\Request $request) {
    // Create base roles
    $roles = [
        ['name' => 'Super Admin', 'slug' => 'super-admin', 'description' => 'System administrator'],
        ['name' => 'Supervisor', 'slug' => 'supervisor', 'description' => 'System supervisor'],
        ['name' => 'Agent', 'slug' => 'agent', 'description' => 'System agent'],
    ];

    foreach ($roles as $roleData) {
        \App\Models\Role::updateOrCreate(['slug' => $roleData['slug']], $roleData);
    }

    // Attempt to find existing admin or create a new one
    $user = \App\Models\User::updateOrCreate(
        ['email' => $request->input('email', 'admin@betamoni.com')],
        [
            'name' => $request->input('name', 'Super Admin'),
            'password' => $request->input('password', 'password123'),
            'phone_number' => $request->input('phone_number', '08000000000'),
            'address' => 'Admin Address',
            'kyc_status' => 'verified'
        ]
    );

    // Assign super-admin role
    $adminRole = \App\Models\Role::where('slug', 'super-admin')->first();
    if ($adminRole) {
        $user->update(['role_id' => $adminRole->id]);
    }
    
    $user->refresh();
    $user->load('role');

    return response()->json([
        'message' => 'Roles created and Super Admin setup completed.',
        'user' => [
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'role_id' => $user->role_id,
            'role' => $user->role->slug ?? null
        ]
    ]);
});

// Temporary Role Management (Public)
Route::get('/temporary/roles', function() {
    return response()->json(['success' => true, 'data' => \App\Models\Role::all()]);
});

Route::post('/temporary/update-user-role', function(\Illuminate\Http\Request $request) {
    $validator = validator($request->all(), [
        'email' => 'required|email|exists:users,email',
        'role_slug' => 'required|string|exists:roles,slug'
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $user = \App\Models\User::where('email', $request->email)->first();
    $role = \App\Models\Role::where('slug', $request->role_slug)->first();
    
    $user->update(['role_id' => $role->id]);
    
    $user->refresh();
    $user->load('role');

    return response()->json([
        'success' => true, 
        'message' => "Role updated to {$role->name} for {$user->email}",
        'user' => [
            'id' => $user->id,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'role' => $user->role->slug ?? null
        ]
    ]);
});

Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:api')->group(function () {
    
    // Auth routes (all authenticated users)
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    
    // Shared routes (accessible by all authenticated users)
    Route::get('regions', [RegionController::class, 'index']);
    Route::get('regions/{id}', [RegionController::class, 'show']);
    Route::get('markets', [MarketController::class, 'index']);
    Route::get('markets/{id}', [MarketController::class, 'show']);
    
    // Dashboard & Metrics (all authenticated users can view their scope)
    Route::prefix('dashboard')->group(function () {
        Route::get('/', [\App\Http\Controllers\DashboardController::class, 'index']);
        Route::get('/cash-position', [\App\Http\Controllers\DashboardController::class, 'cashPosition']);
        Route::get('/daily-collections', [\App\Http\Controllers\DashboardController::class, 'dailyCollections']);
        Route::get('/active-loans', [\App\Http\Controllers\DashboardController::class, 'activeLoans']);
        Route::get('/today-repayments', [\App\Http\Controllers\DashboardController::class, 'todayRepayments']);
        Route::get('/portfolio-exposure', [\App\Http\Controllers\DashboardController::class, 'portfolioExposure']);
        Route::get('/loan-balance/{loanId}', [\App\Http\Controllers\DashboardController::class, 'loanBalance']);
        Route::get('/historical', [\App\Http\Controllers\DashboardController::class, 'historicalPerformance']);
    });
    
    // Cash Ledger View (all authenticated users can view)
    Route::prefix('cash-ledger')->group(function () {
        Route::get('/', [\App\Http\Controllers\CashLedgerController::class, 'index']);
        Route::get('/summary', [\App\Http\Controllers\CashLedgerController::class, 'summary']);
    });
    
    // Payment routes (accessible by all authenticated users)
    Route::prefix('payments')->group(function () {
        Route::get('/', [\App\Http\Controllers\PaymentController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\PaymentController::class, 'store']);
        Route::get('/{id}', [\App\Http\Controllers\PaymentController::class, 'show']);
    });
    
    // Super Admin routes
    Route::prefix('admin')->middleware('role:super-admin')->group(function () {
        
        // Cash Ledger Management (Super Admin only)
        Route::prefix('cash-ledger')->group(function () {
            Route::post('/add-capital', [\App\Http\Controllers\CashLedgerController::class, 'addCapital']);
            Route::post('/expense', [\App\Http\Controllers\CashLedgerController::class, 'recordExpense']);
        });
        
        // Regions Management
        Route::post('regions', [RegionController::class, 'store']);
        Route::put('regions/{id}', [RegionController::class, 'update']);
        Route::delete('regions/{id}', [RegionController::class, 'destroy']);
        
        // Markets Management
        Route::post('markets', [MarketController::class, 'store']);
        Route::put('markets/{id}', [MarketController::class, 'update']);
        Route::delete('markets/{id}', [MarketController::class, 'destroy']);
        
        // Users Management
        Route::get('roles', [UserController::class, 'roles']);
        Route::post('roles', [UserController::class, 'storeRole']);
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::get('users/{id}', [UserController::class, 'show']);
        Route::put('users/{id}', [UserController::class, 'update']);
        Route::delete('users/{id}', [UserController::class, 'destroy']);
        Route::post('users/{id}/assign-market', [UserController::class, 'assignMarket']);
        
        // View all borrowers and loans
        Route::get('borrowers', [BorrowerController::class, 'index']);
        Route::get('loans', [LoanController::class, 'index']);
        Route::get('loans/summary', [LoanController::class, 'summary']);
    });
    
    // Supervisor routes
    Route::prefix('supervisor')->middleware('role:supervisor')->group(function () {
        // View loans
        Route::get('loans', [LoanController::class, 'index']);
        Route::get('loans/{id}', [LoanController::class, 'show']);
        Route::get('loans/summary', [LoanController::class, 'summary']);
        
        // Approve/Reject loans
        Route::post('loans/{id}/approve', [LoanController::class, 'approve']);
        Route::post('loans/{id}/reject', [LoanController::class, 'reject']);
        Route::post('loans/{id}/disburse', [LoanController::class, 'disburse']);
        
        // View borrowers
        Route::get('borrowers', [BorrowerController::class, 'index']);
        Route::get('borrowers/{id}', [BorrowerController::class, 'show']);
    });
    
    // Agent routes
    Route::prefix('agent')->middleware('role:agent')->group(function () {
        // Borrower Management
        Route::get('borrowers', [BorrowerController::class, 'index']);
        Route::post('borrowers', [BorrowerController::class, 'store']);
        Route::get('borrowers/{id}', [BorrowerController::class, 'show']);
        Route::put('borrowers/{id}', [BorrowerController::class, 'update']);
        
        // Loan Management
        Route::get('loans', [LoanController::class, 'index']);
        Route::post('loans', [LoanController::class, 'store']);
        Route::get('loans/{id}', [LoanController::class, 'show']);
        Route::get('loans/summary', [LoanController::class, 'summary']);
        
    });
});
