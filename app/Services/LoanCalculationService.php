<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\Payment;
use App\Models\CashLedger;
use App\Models\RepaymentSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Loan Calculation Service
 * 
 * This service contains ALL calculation logic for the micro-lending system.
 * 
 * CORE PRINCIPLES:
 * 1. Cash only changes when payment or disbursement happens
 * 2. NO balances are stored - everything is calculated on demand
 * 3. Repayment schedules = expected obligations (NOT money)
 * 4. A loan is ACTIVE if it has unpaid obligations
 * 
 * All methods are written to be understandable by non-engineers.
 * Each step is explicit - no clever shortcuts.
 */
class LoanCalculationService
{
    /**
     * CALCULATION A: Cash in Hand
     * 
     * This calculates how much physical cash we currently have.
     * 
     * LOGIC:
     * 1. Start with zero
     * 2. Add all money that came IN (payments from borrowers, capital injections)
     * 3. Subtract all money that went OUT (loan disbursements, expenses)
     * 4. The result is current cash position
     * 
     * WHY: Cash ledger is the single source of truth for money movements
     */
    public function calculateCashInHand($marketId = null, $asOfDate = null)
    {
        // If no date specified, use today
        $asOfDate = $asOfDate ?? today();

        // Build the query to get all cash movements up to the specified date
        // Note: Cash in hand is a global system calculation (Capital - Global Disbursements + Global Recoveries).
        // Since capital injections don't have a market_id, filtering this by market_id would incorrectly drop 
        // the initial capital and result in a deeply negative number.
        $query = CashLedger::whereDate('transaction_date', '<=', $asOfDate);

        // Sum all transactions
        // Positive amounts = money IN (Capital, Payments)
        // Negative amounts = money OUT (Disbursements, Expenses)
        $totalCash = $query->sum('amount');

        return [
            'cash_in_hand' => $totalCash,
            'as_of_date' => $asOfDate->format('Y-m-d'),
            'currency' => 'NGN', // Nigerian Naira
        ];
    }

    /**
     * CALCULATION B: Loan Recovered Per Day
     * 
     * This shows how much money was collected from borrowers on a specific day.
     * 
     * LOGIC:
     * 1. Find all payments received on the specified date
     * 2. Sum up the amounts
     * 3. Group by loan type (daily/weekly/monthly) if needed
     * 
     * WHY: Payments table contains actual money received
     */
    public function calculateLoanRecoveredPerDay($date = null, $marketId = null)
    {
        // If no date specified, use today
        $date = $date ?? today();

        // Build query for payments on this date
        $query = Payment::whereDate('payment_date', $date)
                       ->where('is_verified', true); // Only count verified payments

        // Filter by market if specified
        if ($marketId) {
            $query->whereHas('loan', function($q) use ($marketId) {
                $q->where('market_id', $marketId);
            });
        }

        // Total amount collected
        $totalRecovered = $query->sum('amount');

        // Break down by loan type (daily/weekly/monthly)
        $byType = $query->with('loan')
                       ->get()
                       ->groupBy('loan.repayment_frequency')
                       ->map(function($payments) {
                           return [
                               'count' => $payments->count(),
                               'total_amount' => $payments->sum('amount')
                           ];
                       });

        return [
            'date' => $date->format('Y-m-d'),
            'total_recovered' => $totalRecovered,
            'by_loan_type' => [
                'daily' => $byType->get('daily', ['count' => 0, 'total_amount' => 0]),
                'weekly' => $byType->get('weekly', ['count' => 0, 'total_amount' => 0]),
                'monthly' => $byType->get('monthly', ['count' => 0, 'total_amount' => 0]),
            ],
            'payment_count' => $query->count(),
        ];
    }

    /**
     * CALCULATION C: Active Loans by Type
     * 
     * A loan is ACTIVE if:
     * - It has been disbursed
     * - It still has unpaid repayment schedules
     * 
     * LOGIC:
     * 1. Find all loans with status 'active' or 'disbursed'
     * 2. For each loan, check if it has unpaid schedules
     * 3. If yes, it's active. If all schedules are paid, it should be marked complete
     * 4. Group by repayment frequency (daily/weekly/monthly)
     * 
     * WHY: We don't store "active" as a balance - we derive it from obligations
     */
    public function calculateActiveLoans($marketId = null)
    {
        // Base query for loans that are potentially active
        $query = Loan::whereIn('status', ['disbursed', 'active']);

        // Filter by market if specified
        if ($marketId) {
            $query->where('market_id', $marketId);
        }

        // Get all potentially active loans with their schedules
        $loans = $query->with(['repaymentSchedules', 'payments'])->get();

        // Now check which ones actually have unpaid obligations
        $activeLoans = $loans->filter(function($loan) {
            return $this->loanHasUnpaidObligations($loan);
        });

        // Group by loan type
        $byType = $activeLoans->groupBy('repayment_frequency');

        $daily = $byType->get('daily', collect());
        $weekly = $byType->get('weekly', collect());
        $monthly = $byType->get('monthly', collect());

        return [
            'total_active_loans' => $activeLoans->count(),
            'by_type' => [
                'daily' => [
                    'count' => $daily->count(),
                    'total_principal' => $daily->sum('principal_amount'),
                ],
                'weekly' => [
                    'count' => $weekly->count(),
                    'total_principal' => $weekly->sum('principal_amount'),
                ],
                'monthly' => [
                    'count' => $monthly->count(),
                    'total_principal' => $monthly->sum('principal_amount'),
                ],
            ],
        ];
    }

    /**
     * CALCULATION D: Repayment Count for Today
     * 
     * How many borrowers are supposed to pay today?
     * 
     * LOGIC:
     * 1. Find all repayment schedules with due date = today
     * 2. Filter out the ones that are already fully paid
     * 3. Count the remaining ones
     * 
     * WHY: This helps agents plan their collection route
     */
    public function calculateRepaymentsForToday($date = null, $marketId = null, $agentId = null)
    {
        // If no date specified, use today
        $date = $date ?? today();

        // Find all schedules due on this date
        $query = RepaymentSchedule::whereDate('due_date', $date)
                                  ->with(['loan.borrower', 'loan.agent']);

        // Filter by market if specified
        if ($marketId) {
            $query->whereHas('loan', function($q) use ($marketId) {
                $q->where('market_id', $marketId);
            });
        }

        // Filter by agent if specified
        if ($agentId) {
            $query->whereHas('loan', function($q) use ($agentId) {
                $q->where('agent_id', $agentId);
            });
        }

        $schedules = $query->get();

        // Separate into pending and paid
        $pending = $schedules->filter(function($schedule) {
            return !$schedule->isPaid();
        });

        $paid = $schedules->filter(function($schedule) {
            return $schedule->isPaid();
        });

        // Calculate expected vs collected
        $totalExpected = $schedules->sum('expected_amount');
        $totalCollected = $schedules->sum(function($schedule) {
            return $schedule->getAmountPaid();
        });

        return [
            'date' => $date->format('Y-m-d'),
            'total_schedules' => $schedules->count(),
            'pending_count' => $pending->count(),
            'paid_count' => $paid->count(),
            'total_expected' => $totalExpected,
            'total_collected' => $totalCollected,
            'outstanding' => $totalExpected - $totalCollected,
            'collection_rate' => $totalExpected > 0 
                ? round(($totalCollected / $totalExpected) * 100, 2) 
                : 0,
            'pending_list' => $pending->values()->map(function($schedule) {
                return [
                    'schedule_id' => $schedule->id,
                    'loan_number' => $schedule->loan->loan_number,
                    'borrower_name' => $schedule->loan->borrower->full_name,
                    'borrower_phone' => $schedule->loan->borrower->phone,
                    'expected_amount' => $schedule->expected_amount,
                    'amount_paid' => $schedule->getAmountPaid(),
                    'remaining' => $schedule->getOutstandingAmount(),
                    'location' => $schedule->loan->collection_location,
                ];
            }),
        ];
    }

    /**
     * CALCULATION E: Pending Payments (Total Exposure)
     * 
     * This is the total amount of money that borrowers still owe us.
     * Also called "Portfolio at Risk" or "Total Exposure"
     * 
     * LOGIC:
     * 1. Find ALL active loans
     * 2. For each loan:
     *    a. Get total amount expected (from repayment schedules)
     *    b. Get total amount received (from payments)
     *    c. Outstanding = Expected - Received
     * 3. Sum all outstanding amounts
     * 
     * WHY: This shows our total money on the street
     */
    public function calculatePendingPayments($marketId = null, $includeOverdue = true)
    {
        // Get all active loans
        $query = Loan::whereIn('status', ['disbursed', 'active']);

        if ($marketId) {
            $query->where('market_id', $marketId);
        }

        $loans = $query->with(['repaymentSchedules', 'payments'])->get();

        $totalExpected = 0;
        $totalReceived = 0;
        $overdueAmount = 0;
        $currentAmount = 0;

        foreach ($loans as $loan) {
            // Step 1: Calculate total expected from schedules
            $loanExpected = $loan->repaymentSchedules->sum('expected_amount');
            $totalExpected += $loanExpected;

            // Step 2: Calculate total received from payments
            $loanReceived = $loan->payments->sum('amount');
            $totalReceived += $loanReceived;

            // Step 3: Separate overdue from current
            if ($includeOverdue) {
                $overdue = $loan->repaymentSchedules
                    ->filter(function($schedule) {
                        return $schedule->isOverdue();
                    })
                    ->sum(function($schedule) {
                        return $schedule->getOutstandingAmount();
                    });
                
                $overdueAmount += $overdue;
            }
        }

        $totalOutstanding = $totalExpected - $totalReceived;
        $currentAmount = $totalOutstanding - $overdueAmount;

        return [
            'total_exposure' => $totalOutstanding,
            'breakdown' => [
                'total_expected' => $totalExpected,
                'total_received' => $totalReceived,
                'total_outstanding' => $totalOutstanding,
                'current_outstanding' => $currentAmount,
                'overdue_outstanding' => $overdueAmount,
            ],
            'loan_count' => $loans->count(),
            'recovery_rate' => $totalExpected > 0 
                ? round(($totalReceived / $totalExpected) * 100, 2)
                : 0,
        ];
    }

    /**
     * CALCULATION F: Loan Approvals, Disbursements, and Rejections per Day
     * 
     * This calculates the daily volume and value of loan originations.
     */
    public function calculateLoanActivityByDate($date = null, $marketId = null)
    {
        $date = $date ?? today();
        
        $approvedQuery = Loan::whereDate('approved_at', $date);
        $disbursedQuery = Loan::whereDate('disbursed_at', $date);
        $rejectedQuery = Loan::whereDate('rejected_at', $date);

        if ($marketId) {
            $approvedQuery->where('market_id', $marketId);
            $disbursedQuery->where('market_id', $marketId);
            $rejectedQuery->where('market_id', $marketId);
        }

        $approved = $approvedQuery->get(['id', 'principal_amount']);
        $disbursed = $disbursedQuery->get(['id', 'principal_amount']);
        $rejected = $rejectedQuery->get(['id', 'principal_amount']);

        return [
            'approved' => [
                'count' => $approved->count(),
                'total_principal' => $approved->sum('principal_amount')
            ],
            'disbursed' => [
                'count' => $disbursed->count(),
                'total_principal' => $disbursed->sum('principal_amount')
            ],
            'rejected' => [
                'count' => $rejected->count(),
                'total_principal' => $rejected->sum('principal_amount')
            ]
        ];
    }

    /**
     * CALCULATION G: Historical Performance (batch-optimized)
     *
     * Fetches collections, expected repayments, and loan activity
     * (approved / disbursed / rejected) across a date range using
     * 5 batch SQL queries with GROUP BY, then assembles a per-day,
     * per-market breakdown in memory.
     *
     * @param  \Carbon\Carbon  $fromDate
     * @param  \Carbon\Carbon  $toDate
     * @param  string|null     $marketId  UUID filter (null = all markets)
     * @return array  ['daily_breakdown' => [...], 'summary' => [...]]
     */
    public function calculateHistoricalPerformance(Carbon $fromDate, Carbon $toDate, $marketId = null)
    {
        // Fetch markets we are interested in
        $markets = \App\Models\Market::query()
            ->when($marketId, fn($q) => $q->where('id', $marketId))
            ->get(['id', 'name'])
            ->keyBy('id');

        // ── QUERY 1: Collections (verified payments) ────────────────
        $collections = \App\Models\Payment::query()
            ->join('loans', 'payments.loan_id', '=', 'loans.id')
            ->whereBetween('payments.payment_date', [$fromDate, $toDate])
            ->where('payments.is_verified', true)
            ->when($marketId, fn($q) => $q->where('loans.market_id', $marketId))
            ->groupBy('date', 'loans.market_id')
            ->select(
                DB::raw('DATE(payments.payment_date) as date'),
                'loans.market_id',
                DB::raw('SUM(payments.amount) as total_recovered'),
                DB::raw('COUNT(payments.id) as payment_count')
            )
            ->get()
            ->groupBy('date')
            ->map(fn($rows) => $rows->keyBy('market_id'));

        // ── QUERY 2: Expected repayments ────────────────────────────
        $expected = RepaymentSchedule::query()
            ->join('loans', 'repayment_schedules.loan_id', '=', 'loans.id')
            ->whereBetween('repayment_schedules.due_date', [$fromDate, $toDate])
            ->when($marketId, fn($q) => $q->where('loans.market_id', $marketId))
            ->groupBy('date', 'loans.market_id')
            ->select(
                DB::raw('DATE(repayment_schedules.due_date) as date'),
                'loans.market_id',
                DB::raw('SUM(repayment_schedules.expected_amount) as total_expected')
            )
            ->get()
            ->groupBy('date')
            ->map(fn($rows) => $rows->keyBy('market_id'));

        // ── QUERIES 3-5: Loan activity (approved / disbursed / rejected)
        $fetchActivity = function (string $timestampCol) use ($fromDate, $toDate, $marketId) {
            return Loan::query()
                ->whereNotNull($timestampCol)
                ->whereBetween($timestampCol, [$fromDate, $toDate->copy()->endOfDay()])
                ->when($marketId, fn($q) => $q->where('market_id', $marketId))
                ->groupBy('date', 'market_id')
                ->select(
                    DB::raw("DATE($timestampCol) as date"),
                    'market_id',
                    DB::raw('COUNT(*) as loan_count'),
                    DB::raw('SUM(principal_amount) as total_principal')
                )
                ->get()
                ->groupBy('date')
                ->map(fn($rows) => $rows->keyBy('market_id'));
        };

        $approvedData  = $fetchActivity('approved_at');
        $disbursedData = $fetchActivity('disbursed_at');
        $rejectedData  = $fetchActivity('rejected_at');

        // ── Assemble daily breakdown from pre-fetched data ──────────
        $dailyData   = [];
        $currentDate = $fromDate->copy();

        while ($currentDate <= $toDate) {
            $dateKey = $currentDate->format('Y-m-d');
            $day     = $this->assembleDayMetrics(
                $dateKey, $markets, $collections, $expected,
                $approvedData, $disbursedData, $rejectedData
            );

            $dailyData[] = $day;
            $currentDate->addDay();
        }

        // ── Period-wide summary ─────────────────────────────────────
        $col = fn(string $key) => array_sum(array_column($dailyData, $key));

        return [
            'period' => [
                'from' => $fromDate->format('Y-m-d'),
                'to'   => $toDate->format('Y-m-d'),
                'days' => $fromDate->diffInDays($toDate) + 1,
            ],
            'summary' => [
                'total_collected'  => $col('total_collections'),
                'total_expected'   => $col('total_expected'),
                'collection_rate'  => $col('total_expected') > 0
                    ? round(($col('total_collections') / $col('total_expected')) * 100, 2)
                    : 0,
                'loans' => [
                    'approved_count'   => $col('total_approved_count'),
                    'approved_volume'  => $col('total_approved_volume'),
                    'disbursed_count'  => $col('total_disbursed_count'),
                    'disbursed_volume' => $col('total_disbursed_volume'),
                    'rejected_count'   => $col('total_rejected_count'),
                    'rejected_volume'  => $col('total_rejected_volume'),
                ],
            ],
            'daily_breakdown' => $dailyData,
        ];
    }

    /**
     * HELPER: Assemble a single day's metrics from pre-fetched batch data
     */
    private function assembleDayMetrics(
        string $dateKey,
        $markets,
        $collections,
        $expected,
        $approvedData,
        $disbursedData,
        $rejectedData
    ): array {
        $dayCollections = $dayExpected = 0;
        $dayApprCount = $dayApprVol = 0;
        $dayDisbCount = $dayDisbVol = 0;
        $dayRejCount  = $dayRejVol  = 0;

        $marketBreakdown = [];

        foreach ($markets as $mId => $market) {
            $cRow = $collections->get($dateKey)?->get($mId);
            $eRow = $expected->get($dateKey)?->get($mId);
            $aRow = $approvedData->get($dateKey)?->get($mId);
            $dRow = $disbursedData->get($dateKey)?->get($mId);
            $rRow = $rejectedData->get($dateKey)?->get($mId);

            $mCollected = (float) ($cRow->total_recovered ?? 0);
            $mExpected  = (float) ($eRow->total_expected ?? 0);
            $mApprCount = (int)   ($aRow->loan_count ?? 0);
            $mApprVol   = (float) ($aRow->total_principal ?? 0);
            $mDisbCount = (int)   ($dRow->loan_count ?? 0);
            $mDisbVol   = (float) ($dRow->total_principal ?? 0);
            $mRejCount  = (int)   ($rRow->loan_count ?? 0);
            $mRejVol    = (float) ($rRow->total_principal ?? 0);

            $dayCollections += $mCollected;
            $dayExpected    += $mExpected;
            $dayApprCount   += $mApprCount;
            $dayApprVol     += $mApprVol;
            $dayDisbCount   += $mDisbCount;
            $dayDisbVol     += $mDisbVol;
            $dayRejCount    += $mRejCount;
            $dayRejVol      += $mRejVol;

            $marketBreakdown[] = [
                'market_id'       => $mId,
                'market_name'     => $market->name,
                'collections'     => $mCollected,
                'expected'        => $mExpected,
                'collection_rate' => $mExpected > 0
                    ? round(($mCollected / $mExpected) * 100, 2) : 0,
                'activity' => [
                    'approved'  => ['count' => $mApprCount, 'total_principal' => $mApprVol],
                    'disbursed' => ['count' => $mDisbCount, 'total_principal' => $mDisbVol],
                    'rejected'  => ['count' => $mRejCount,  'total_principal' => $mRejVol],
                ],
            ];
        }

        return [
            'date'                   => $dateKey,
            'total_collections'      => $dayCollections,
            'total_expected'         => $dayExpected,
            'total_approved_count'   => $dayApprCount,
            'total_approved_volume'  => $dayApprVol,
            'total_disbursed_count'  => $dayDisbCount,
            'total_disbursed_volume' => $dayDisbVol,
            'total_rejected_count'   => $dayRejCount,
            'total_rejected_volume'  => $dayRejVol,
            'markets'                => $marketBreakdown,
        ];
    }

    /**
     * HELPER: Check if a loan has unpaid obligations
     * 
     * A loan has unpaid obligations if ANY of its repayment schedules
     * are not fully paid yet.
     */
    private function loanHasUnpaidObligations($loan)
    {
        // If no schedules exist, loan is not active
        if ($loan->repaymentSchedules->isEmpty()) {
            return false;
        }

        // Check if any schedule is unpaid
        foreach ($loan->repaymentSchedules as $schedule) {
            if (!$schedule->isPaid()) {
                return true; // Found an unpaid schedule
            }
        }

        // All schedules are paid
        return false;
    }

    /**
     * Calculate individual loan balance
     * 
     * For a specific loan, show:
     * - Original amount
     * - Total expected
     * - Total paid
     * - Balance remaining
     */
    public function calculateLoanBalance($loanId)
    {
        $loan = Loan::with(['repaymentSchedules', 'payments'])->findOrFail($loanId);

        // What was borrowed
        $principalAmount = $loan->principal_amount;
        $interestAmount = $loan->interest_amount;
        $totalAmount = $loan->total_amount;

        // What is expected (from schedules)
        $totalExpected = $loan->repaymentSchedules->sum('expected_amount');

        // What has been paid
        $totalPaid = $loan->payments->sum('amount');

        // What remains
        $balance = $totalExpected - $totalPaid;

        // Schedule breakdown
        $scheduleStatus = $loan->repaymentSchedules->map(function($schedule) {
            return [
                'installment_number' => $schedule->installment_number,
                'due_date' => $schedule->due_date->format('Y-m-d'),
                'expected' => $schedule->expected_amount,
                'paid' => $schedule->getAmountPaid(),
                'outstanding' => $schedule->getOutstandingAmount(),
                'status' => $schedule->isPaid() ? 'paid' : ($schedule->isOverdue() ? 'overdue' : 'pending'),
            ];
        });

        return [
            'loan_number' => $loan->loan_number,
            'amounts' => [
                'principal' => $principalAmount,
                'interest' => $interestAmount,
                'total' => $totalAmount,
            ],
            'payment_summary' => [
                'total_expected' => $totalExpected,
                'total_paid' => $totalPaid,
                'balance' => max(0, $balance),
            ],
            'schedules' => $scheduleStatus,
            'payment_history' => $loan->payments->map(function($payment) {
                return [
                    'date' => $payment->payment_date->format('Y-m-d'),
                    'amount' => $payment->amount,
                    'receipt_number' => $payment->receipt_number,
                ];
            }),
        ];
    }

    /**
     * Generate repayment schedule when loan is disbursed
     * 
     * This creates the expected payment schedule based on:
     * - Loan amount
     * - Duration
     * - Repayment frequency
     */
    public function generateRepaymentSchedule($loan)
    {
        $totalAmount = $loan->total_amount;
        $frequency = $loan->repayment_frequency;
        $duration = $loan->duration_days;
        $startDate = $loan->disbursement_date;

        // Calculate number of installments
        $installments = $this->calculateInstallmentCount($frequency, $duration);
        
        // Calculate amount per installment
        $amountPerInstallment = $totalAmount / $installments;

        // Generate schedule entries
        $schedules = [];
        for ($i = 1; $i <= $installments; $i++) {
            $dueDate = $this->calculateDueDate($startDate, $frequency, $i);
            
            $schedules[] = RepaymentSchedule::create([
                'loan_id' => $loan->id,
                'due_date' => $dueDate,
                'expected_amount' => round($amountPerInstallment, 2),
                'installment_number' => $i,
                'status' => 'pending',
            ]);
        }

        return $schedules;
    }

    /**
     * Calculate number of installments based on frequency and duration
     */
    private function calculateInstallmentCount($frequency, $durationDays)
    {
        switch ($frequency) {
            case 'daily':
                return $durationDays;
            case 'weekly':
                return ceil($durationDays / 7);
            case 'bi-weekly':
                return ceil($durationDays / 14);
            case 'monthly':
                return ceil($durationDays / 30);
            default:
                return 1;
        }
    }

    /**
     * Calculate due date for an installment
     */
    private function calculateDueDate($startDate, $frequency, $installmentNumber)
    {
        $date = Carbon::parse($startDate);

        switch ($frequency) {
            case 'daily':
                return $date->addDays($installmentNumber);
            case 'weekly':
                return $date->addWeeks($installmentNumber);
            case 'bi-weekly':
                return $date->addWeeks($installmentNumber * 2);
            case 'monthly':
                return $date->addMonths($installmentNumber);
            default:
                return $date;
        }
    }
}
