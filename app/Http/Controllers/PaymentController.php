<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\CashLedger;
use App\Models\Loan;
use App\Models\RepaymentSchedule;
use App\Models\LoanActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    /**
     * Record a new payment from a borrower
     * 
     * This is the MOST IMPORTANT operation in the system.
     * It must:
     * 1. Create a payment record
     * 2. Create a cash ledger entry
     * 3. Update repayment schedule status
     * 4. Log the activity
     * 5. Check if loan is now fully paid
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'loan_id' => 'required|exists:loans,id',
            'amount' => 'required|numeric|min:1',
            'payment_date' => 'required|date',
            'payment_method' => 'required|in:cash,bank_transfer,mobile_money,other',
            'repayment_schedule_id' => 'nullable|exists:repayment_schedules,id',
            'collection_location' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Start transaction - all steps must complete together
        DB::beginTransaction();
        try {
            $loan = Loan::findOrFail($request->loan_id);

            // STEP 1: Create payment record
            $payment = Payment::create([
                'loan_id' => $request->loan_id,
                'collected_by' => auth()->id(),
                'payment_date' => $request->payment_date,
                'payment_time' => now()->format('H:i:s'),
                'amount' => $request->amount,
                'payment_method' => $request->payment_method,
                'receipt_number' => Payment::generateReceiptNumber(),
                'repayment_schedule_id' => $request->repayment_schedule_id,
                'collection_location' => $request->collection_location,
                'notes' => $request->notes,
                'is_verified' => true, // Auto-verify
                'verified_by' => auth()->id(),
                'verified_at' => now(),
            ]);

            // STEP 2: Create cash ledger entry (Money IN)
            CashLedger::create([
                'transaction_type' => 'payment',
                'amount' => $request->amount, // Positive = money IN
                'loan_id' => $request->loan_id,
                'payment_id' => $payment->id,
                'user_id' => auth()->id(),
                'transaction_date' => $request->payment_date,
                'transaction_time' => now()->format('H:i:s'),
                'description' => 'Payment received from ' . $loan->borrower->full_name . ' - Receipt: ' . $payment->receipt_number,
                'reference_number' => $payment->receipt_number,
            ]);

            // STEP 3: Update repayment schedule status if specified
            if ($request->repayment_schedule_id) {
                $schedule = RepaymentSchedule::find($request->repayment_schedule_id);
                if ($schedule) {
                    $schedule->updateStatus();
                }
            }

            // STEP 4: Log activity
            LoanActivity::create([
                'loan_id' => $request->loan_id,
                'user_id' => auth()->id(),
                'action' => 'payment_received',
                'description' => 'Payment of ₦' . number_format($request->amount, 2) . ' received by ' . auth()->user()->name,
                'metadata' => [
                    'amount' => $request->amount,
                    'receipt_number' => $payment->receipt_number,
                    'payment_method' => $request->payment_method,
                ],
            ]);

            // STEP 5: Check if loan is now fully paid
            $this->checkAndMarkLoanComplete($loan);

            DB::commit();

            // Load relationships for response
            $payment->load(['loan.borrower', 'collectedBy', 'repaymentSchedule']);

            return response()->json([
                'success' => true,
                'message' => 'Payment recorded successfully',
                'data' => $payment
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to record payment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all payments for a loan
     */
    public function index(Request $request)
    {
        $query = Payment::with(['loan.borrower', 'collectedBy', 'verifiedBy', 'repaymentSchedule']);

        // Filter by loan
        if ($request->has('loan_id')) {
            $query->where('loan_id', $request->loan_id);
        }

        // Filter by date range
        if ($request->has('from_date')) {
            $query->whereDate('payment_date', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->whereDate('payment_date', '<=', $request->to_date);
        }

        // Filter by collector (for agents)
        if ($request->has('collected_by')) {
            $query->where('collected_by', $request->collected_by);
        }

        // For agents - only show their payments
        if (auth()->user()->isAgent()) {
            $query->where('collected_by', auth()->id());
        }

        $payments = $query->latest('payment_date')->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $payments
        ], 200);
    }

    /**
     * Get a single payment
     */
    public function show($id)
    {
        $payment = Payment::with([
            'loan.borrower',
            'loan.agent',
            'collectedBy',
            'verifiedBy',
            'repaymentSchedule',
            'cashLedgerEntry'
        ])->find($id);

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $payment
        ], 200);
    }

    /**
     * Check if a loan is now fully paid and mark it complete
     */
    private function checkAndMarkLoanComplete($loan)
    {
        // Load schedules and payments
        $loan->load(['repaymentSchedules', 'payments']);

        // Check if all schedules are paid
        foreach ($loan->repaymentSchedules as $schedule) {
            if (!$schedule->isPaid()) {
                return; // Still has unpaid schedules
            }
        }

        // All schedules are paid - mark loan as completed
        $loan->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        // Log completion
        LoanActivity::create([
            'loan_id' => $loan->id,
            'user_id' => auth()->id(),
            'action' => 'completed',
            'description' => 'Loan fully repaid and marked as completed',
        ]);
    }
}
