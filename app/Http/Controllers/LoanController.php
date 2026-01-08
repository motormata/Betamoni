<?php

namespace App\Http\Controllers;

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

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by agent
        if ($request->has('agent_id')) {
            $query->where('agent_id', $request->agent_id);
        }

        // Filter by market
        if ($request->has('market_id')) {
            $query->where('market_id', $request->market_id);
        }

        // Filter by date range
        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        // For agents - only show their loans
        if (auth()->user()->isAgent()) {
            $query->where('agent_id', auth()->id());
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
            'repayments.collectedBy',
            'guarantors',
            'documents',
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

        $disbursementDate = \Carbon\Carbon::parse($request->disbursement_date);
        $dueDate = $disbursementDate->copy()->addDays($loan->duration_days);

        $loan->update([
            'status' => 'disbursed',
            'disbursement_date' => $disbursementDate,
            'due_date' => $dueDate,
            'disbursed_at' => now(),
        ]);

        // Log activity
        LoanActivity::create([
            'loan_id' => $loan->id,
            'user_id' => auth()->id(),
            'action' => 'disbursed',
            'description' => 'Loan disbursed by ' . auth()->user()->name,
            'metadata' => [
                'disbursement_date' => $disbursementDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
            ]
        ]);

        $loan->load(['borrower', 'agent', 'approvedBy']);

        return response()->json([
            'success' => true,
            'message' => 'Loan disbursed successfully',
            'data' => $loan
        ], 200);
    }

    public function summary(Request $request)
    {
        $query = Loan::query();

        // Filter by agent for agents
        if (auth()->user()->isAgent()) {
            $query->where('agent_id', auth()->id());
        }

        // Filter by market if provided
        if ($request->has('market_id')) {
            $query->where('market_id', $request->market_id);
        }

        $summary = [
            'total_loans' => $query->count(),
            'pending_loans' => (clone $query)->where('status', 'pending')->count(),
            'approved_loans' => (clone $query)->where('status', 'approved')->count(),
            'active_loans' => (clone $query)->where('status', 'active')->count(),
            'completed_loans' => (clone $query)->where('status', 'completed')->count(),
            'defaulted_loans' => (clone $query)->where('status', 'defaulted')->count(),
            'total_disbursed' => (clone $query)->whereIn('status', ['disbursed', 'active', 'completed'])
                ->sum('principal_amount'),
            'total_collected' => (clone $query)->sum('amount_paid'),
            'total_outstanding' => (clone $query)->whereIn('status', ['disbursed', 'active'])
                ->sum('balance'),
        ];

        return response()->json([
            'success' => true,
            'data' => $summary
        ], 200);
    }
}