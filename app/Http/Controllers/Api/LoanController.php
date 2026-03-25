<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\LoanActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LoanController extends Controller
{
    public function index(Request $request)
    {
        $query = Loan::with(['borrower', 'agent', 'market.region', 'approvedBy']);

        // --- Role-Based Scoping & Filtering ---
        $user = auth()->user();

        // 1. Supervisor: Only see loans within their assigned market
        if ($user->isSupervisor()) {
            $query->where('market_id', $user->market_id);
        }

        // 2. Agent: Only show their own loans
        if ($user->isAgent()) {
            $query->where('agent_id', $user->id);
        }

        // --- Custom Request Filters ---

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by agent (Admins/Supervisors can filter specific agents in their scope)
        if ($request->has('agent_id')) {
            $query->where('agent_id', $request->agent_id);
        }

        // Filter by market (Admins can filter any, Supervisors are already locked to theirs)
        if ($request->has('market_id')) {
            $requestedMarket = $request->market_id;
            
            if ($user->isSupervisor() && $requestedMarket !== $user->market_id) {
                // If supervisor tries to look at another market, force back to their own
                $query->where('market_id', $user->market_id);
            } else {
                $query->where('market_id', $requestedMarket);
            }
        }

        // Filter by date range
        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        // Search by loan number or borrower
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('loan_number', 'like', "%{$search}%")
                  ->orWhereHas('borrower', function($bq) use ($search) {
                      $bq->where('first_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%")
                         ->orWhere('phone', 'like', "%{$search}%");
                  });
            });
        }

        $loans = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $loans
        ], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'borrower_id' => 'required|exists:borrowers,id',
            'principal_amount' => 'required|numeric|min:1000',
            'interest_rate' => 'required|numeric|min:0|max:100',
            'duration_days' => 'required|integer|min:1',
            'repayment_frequency' => 'required|in:daily,weekly,bi-weekly,monthly',
            'collection_day' => 'nullable|string',
            'collection_time' => 'nullable|date_format:H:i',
            'collection_location' => 'nullable|string',
            'purpose' => 'nullable|string',
            'notes' => 'nullable|string',
            'guarantors' => 'nullable|array',
            'guarantors.*.name' => 'required_with:guarantors|string',
            'guarantors.*.phone' => 'required_with:guarantors|string',
            'guarantors.*.address' => 'required_with:guarantors|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Calculate interest and total
            $interestAmount = ($request->principal_amount * $request->interest_rate) / 100;
            $totalAmount = $request->principal_amount + $interestAmount;

            // Check if there is enough Cash in Hand to create/fund this loan
            $calculationService = new \App\Services\LoanCalculationService();
            $currentCash = $calculationService->calculateCashInHand();

            if ($currentCash['cash_in_hand'] < $request->principal_amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient cash in hand to create this loan application. Available: ₦' . number_format($currentCash['cash_in_hand'], 2)
                ], 400);
            }

            // Get agent and market
            $agent = auth()->user();
            $marketId = $agent->market_id ?? $request->market_id;

            $loan = Loan::create([
                'loan_number' => Loan::generateLoanNumber(),
                'borrower_id' => $request->borrower_id,
                'agent_id' => auth()->id(),
                'market_id' => $marketId,
                'principal_amount' => $request->principal_amount,
                'interest_rate' => $request->interest_rate,
                'interest_amount' => $interestAmount,
                'total_amount' => $totalAmount,
                'balance' => $totalAmount,
                'duration_days' => $request->duration_days,
                'repayment_frequency' => $request->repayment_frequency,
                'collection_day' => $request->collection_day,
                'collection_time' => $request->collection_time,
                'collection_location' => $request->collection_location,
                'purpose' => $request->purpose,
                'notes' => $request->notes,
                'status' => 'pending',
            ]);

            // Add guarantors if provided
            if ($request->has('guarantors')) {
                foreach ($request->guarantors as $guarantor) {
                    $loan->guarantors()->create($guarantor);
                }
            }

            // Log activity
            LoanActivity::create([
                'loan_id' => $loan->id,
                'user_id' => auth()->id(),
                'action' => 'created',
                'description' => 'Loan application created by ' . auth()->user()->name,
            ]);

            $loan->load(['borrower', 'agent', 'market', 'guarantors']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Loan application created successfully',
                'data' => $loan
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create loan: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        $loan = Loan::with([
            'borrower.market',
            'agent',
            'market.region',
            'approvedBy',
            'payments.collectedBy',
            'guarantors',
            'activities.user'
        ])->find($id);

        if (!$loan) {
            return response()->json([
                'success' => false,
                'message' => 'Loan not found'
            ], 404);
        }

        // Check if agent can view this loan
        if (auth()->user()->isAgent() && $loan->agent_id != auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to view this loan'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $loan
        ], 200);
    }

    public function approve(Request $request, $id)
    {
        $loan = Loan::find($id);

        if (!$loan) {
            return response()->json([
                'success' => false,
                'message' => 'Loan not found'
            ], 404);
        }

        if ($loan->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending loans can be approved'
            ], 400);
        }

        $loan->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        // Log activity
        LoanActivity::create([
            'loan_id' => $loan->id,
            'user_id' => auth()->id(),
            'action' => 'approved',
            'description' => 'Loan approved by ' . auth()->user()->name,
        ]);

        $loan->load(['borrower', 'agent', 'approvedBy']);

        return response()->json([
            'success' => true,
            'message' => 'Loan approved successfully',
            'data' => $loan
        ], 200);
    }

    public function reject(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'rejection_reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $loan = Loan::find($id);

        if (!$loan) {
            return response()->json([
                'success' => false,
                'message' => 'Loan not found'
            ], 404);
        }

        if ($loan->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending loans can be rejected'
            ], 400);
        }

        $loan->update([
            'status' => 'rejected',
            'approved_by' => auth()->id(),
            'rejected_at' => now(),
            'rejection_reason' => $request->rejection_reason,
        ]);

        // Log activity
        LoanActivity::create([
            'loan_id' => $loan->id,
            'user_id' => auth()->id(),
            'action' => 'rejected',
            'description' => 'Loan rejected by ' . auth()->user()->name . '. Reason: ' . $request->rejection_reason,
        ]);

        $loan->load(['borrower', 'agent', 'approvedBy']);

        return response()->json([
            'success' => true,
            'message' => 'Loan rejected',
            'data' => $loan
        ], 200);
    }

    public function disburse(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'disbursement_date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $loan = Loan::find($id);

        if (!$loan) {
            return response()->json([
                'success' => false,
                'message' => 'Loan not found'
            ], 404);
        }

        if ($loan->status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Only approved loans can be disbursed'
            ], 400);
        }

        // Start transaction - all steps must complete together
        DB::beginTransaction();
        try {
            $disbursementDate = \Carbon\Carbon::parse($request->disbursement_date);
            $dueDate = $disbursementDate->copy()->addDays($loan->duration_days);

            // Update loan status
            $loan->update([
                'status' => 'disbursed',
                'disbursement_date' => $disbursementDate,
                'due_date' => $dueDate,
                'disbursed_at' => now(),
            ]);

            // Generate repayment schedule
            $calculationService = new \App\Services\LoanCalculationService();
            $schedules = $calculationService->generateRepaymentSchedule($loan);

            // Record cash ledger entry (Money OUT)
            \App\Models\CashLedger::create([
                'transaction_type' => 'disbursement',
                'amount' => -$loan->principal_amount, // Negative = money OUT
                'loan_id' => $loan->id,
                'user_id' => auth()->id(),
                'transaction_date' => $disbursementDate,
                'transaction_time' => now()->format('H:i:s'),
                'description' => 'Loan disbursed to ' . $loan->borrower->full_name . ' - ' . $loan->loan_number,
                'reference_number' => $loan->loan_number,
            ]);

            // Log activity
            LoanActivity::create([
                'loan_id' => $loan->id,
                'user_id' => auth()->id(),
                'action' => 'disbursed',
                'description' => 'Loan disbursed by ' . auth()->user()->name . '. ' . count($schedules) . ' repayment schedules created.',
                'metadata' => [
                    'disbursement_date' => $disbursementDate->toDateString(),
                    'due_date' => $dueDate->toDateString(),
                    'schedule_count' => count($schedules),
                ]
            ]);

            DB::commit();

            $loan->load(['borrower', 'agent', 'approvedBy', 'repaymentSchedules']);

            return response()->json([
                'success' => true,
                'message' => 'Loan disbursed successfully',
                'data' => $loan
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to disburse loan: ' . $e->getMessage()
            ], 500);
        }
    }

    public function summary(Request $request)
    {
        $query = Loan::query();
        $user = auth()->user();

        // --- Role-Based Scoping ---
        if ($user->isSupervisor()) {
            $query->where('market_id', $user->market_id);
        }

        if ($user->isAgent()) {
            $query->where('agent_id', $user->id);
        }

        // --- Manual Filters ---
        if ($request->has('market_id')) {
            $requestedMarket = $request->market_id;
            if ($user->isSupervisor() && $requestedMarket !== $user->market_id) {
                $query->where('market_id', $user->market_id);
            } else {
                $query->where('market_id', $requestedMarket);
            }
        }

        $summary = [
            'total_loans' => (clone $query)->count(),
            'pending_loans' => (clone $query)->where('status', 'pending')->count(),
            'approved_loans' => (clone $query)->where('status', 'approved')->count(),
            'active_loans' => (clone $query)->where('status', 'active')->count(),
            'overdue_loans' => (clone $query)->where('status', 'overdue')->count(),
            'completed_loans' => (clone $query)->where('status', 'completed')->count(),
            'defaulted_loans' => (clone $query)->where('status', 'defaulted')->count(),
            'total_disbursed' => (clone $query)->whereIn('status', ['disbursed', 'active', 'overdue', 'completed'])
                ->sum('principal_amount'),
            'total_collected' => (clone $query)->sum('amount_paid'),
            'total_outstanding' => (clone $query)->whereIn('status', ['disbursed', 'active', 'overdue'])
                ->sum('balance'),
        ];

        return response()->json([
            'success' => true,
            'data' => $summary
        ], 200);
    }
}