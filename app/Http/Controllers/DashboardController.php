<?php

namespace App\Http\Controllers;

use App\Services\LoanCalculationService;
use Illuminate\Http\Request;

/**
 * Dashboard Controller
 * 
 * This provides all the metrics needed for dashboards and reports.
 * It uses the LoanCalculationService for all calculations.
 */
class DashboardController extends Controller
{
    protected $calculationService;

    public function __construct(LoanCalculationService $calculationService)
    {
        $this->calculationService = $calculationService;
    }

    /**
     * Get main dashboard metrics
     * 
     * Returns all key metrics for the home screen:
     * - Cash in hand
     * - Active loans
     * - Today's collections
     * - Today's expected repayments
     * - Total exposure
     */
    public function index(Request $request)
    {
        // Get market filter (for agents who only see their market)
        $marketId = $this->getMarketFilter($request);

        // Calculate all metrics
        $cashPosition = $this->calculationService->calculateCashInHand($marketId);
        $activeLoans = $this->calculationService->calculateActiveLoans($marketId);
        $todayCollections = $this->calculationService->calculateLoanRecoveredPerDay(today(), $marketId);
        $todayRepayments = $this->calculationService->calculateRepaymentsForToday(today(), $marketId);
        $exposure = $this->calculationService->calculatePendingPayments($marketId);

        return response()->json([
            'success' => true,
            'data' => [
                'cash_position' => $cashPosition,
                'active_loans' => $activeLoans,
                'today' => [
                    'collections' => $todayCollections,
                    'expected_repayments' => $todayRepayments,
                ],
                'portfolio' => $exposure,
            ]
        ], 200);
    }

    /**
     * Get cash position details
     */
    public function cashPosition(Request $request)
    {
        $marketId = $this->getMarketFilter($request);
        $asOfDate = $request->has('date') ? \Carbon\Carbon::parse($request->date) : today();

        $data = $this->calculationService->calculateCashInHand($marketId, $asOfDate);

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    /**
     * Get daily collections report
     */
    public function dailyCollections(Request $request)
    {
        $marketId = $this->getMarketFilter($request);
        $date = $request->has('date') ? \Carbon\Carbon::parse($request->date) : today();

        $data = $this->calculationService->calculateLoanRecoveredPerDay($date, $marketId);

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    /**
     * Get active loans summary
     */
    public function activeLoans(Request $request)
    {
        $marketId = $this->getMarketFilter($request);

        $data = $this->calculationService->calculateActiveLoans($marketId);

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    /**
     * Get today's repayment schedule
     */
    public function todayRepayments(Request $request)
    {
        $marketId = $this->getMarketFilter($request);
        $agentId = auth()->user()->isAgent() ? auth()->id() : null;
        $date = $request->has('date') ? \Carbon\Carbon::parse($request->date) : today();

        $data = $this->calculationService->calculateRepaymentsForToday($date, $marketId, $agentId);

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    /**
     * Get portfolio exposure
     */
    public function portfolioExposure(Request $request)
    {
        $marketId = $this->getMarketFilter($request);

        $data = $this->calculationService->calculatePendingPayments($marketId);

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    /**
     * Get detailed loan balance
     */
    public function loanBalance($loanId)
    {
        $data = $this->calculationService->calculateLoanBalance($loanId);

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    /**
     * Get historical performance (date range)
     *
     * PERFORMANCE: Uses 5 batch SQL queries with GROUP BY instead of
     * per-day, per-market service calls. Query count is constant
     * regardless of date range or number of markets.
     */
    public function historicalPerformance(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'from_date' => 'required|date',
            'to_date'   => 'required|date|after_or_equal:from_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors()
            ], 422);
        }

        $marketId = $this->getMarketFilter($request);
        $fromDate = \Carbon\Carbon::parse($request->from_date);
        $toDate   = \Carbon\Carbon::parse($request->to_date);

        // Fetch markets we are interested in
        $markets = \App\Models\Market::query()
            ->when($marketId, fn($q) => $q->where('id', $marketId))
            ->get(['id', 'name'])
            ->keyBy('id');

        $marketIds = $markets->pluck('id');

        // ────────────────────────────────────────────────────────────
        // QUERY 1: Collections (verified payments) grouped by date + market
        // ────────────────────────────────────────────────────────────
        $collections = \App\Models\Payment::query()
            ->join('loans', 'payments.loan_id', '=', 'loans.id')
            ->whereBetween('payments.payment_date', [$fromDate, $toDate])
            ->where('payments.is_verified', true)
            ->when($marketId, fn($q) => $q->where('loans.market_id', $marketId))
            ->groupBy('date', 'loans.market_id')
            ->select(
                \DB::raw('DATE(payments.payment_date) as date'),
                'loans.market_id',
                \DB::raw('SUM(payments.amount) as total_recovered'),
                \DB::raw('COUNT(payments.id) as payment_count')
            )
            ->get()
            ->groupBy('date')
            ->map(fn($rows) => $rows->keyBy('market_id'));

        // ────────────────────────────────────────────────────────────
        // QUERY 2: Expected repayments grouped by date + market
        // ────────────────────────────────────────────────────────────
        $expected = \App\Models\RepaymentSchedule::query()
            ->join('loans', 'repayment_schedules.loan_id', '=', 'loans.id')
            ->whereBetween('repayment_schedules.due_date', [$fromDate, $toDate])
            ->when($marketId, fn($q) => $q->where('loans.market_id', $marketId))
            ->groupBy('date', 'loans.market_id')
            ->select(
                \DB::raw('DATE(repayment_schedules.due_date) as date'),
                'loans.market_id',
                \DB::raw('SUM(repayment_schedules.expected_amount) as total_expected')
            )
            ->get()
            ->groupBy('date')
            ->map(fn($rows) => $rows->keyBy('market_id'));

        // ────────────────────────────────────────────────────────────
        // QUERIES 3-5: Loan activity (approved / disbursed / rejected)
        // ────────────────────────────────────────────────────────────
        $loanActivityFetch = function ($timestampCol) use ($fromDate, $toDate, $marketId) {
            return \App\Models\Loan::query()
                ->whereNotNull($timestampCol)
                ->whereBetween($timestampCol, [$fromDate, $toDate->copy()->endOfDay()])
                ->when($marketId, fn($q) => $q->where('market_id', $marketId))
                ->groupBy('date', 'market_id')
                ->select(
                    \DB::raw("DATE($timestampCol) as date"),
                    'market_id',
                    \DB::raw('COUNT(*) as loan_count'),
                    \DB::raw('SUM(principal_amount) as total_principal')
                )
                ->get()
                ->groupBy('date')
                ->map(fn($rows) => $rows->keyBy('market_id'));
        };

        $approvedData  = $loanActivityFetch('approved_at');
        $disbursedData = $loanActivityFetch('disbursed_at');
        $rejectedData  = $loanActivityFetch('rejected_at');

        // ────────────────────────────────────────────────────────────
        // Assemble the response from pre-fetched data
        // ────────────────────────────────────────────────────────────
        $dailyData   = [];
        $currentDate = $fromDate->copy();

        while ($currentDate <= $toDate) {
            $dateKey = $currentDate->format('Y-m-d');

            $dayCollections     = 0;
            $dayExpected        = 0;
            $dayApprovedCount   = 0;
            $dayApprovedVolume  = 0;
            $dayDisbursedCount  = 0;
            $dayDisbursedVolume = 0;
            $dayRejectedCount   = 0;
            $dayRejectedVolume  = 0;

            $marketBreakdown = [];

            foreach ($markets as $mId => $market) {
                // Look up pre-fetched rows (default to zero)
                $cRow = $collections->get($dateKey)?->get($mId);
                $eRow = $expected->get($dateKey)?->get($mId);
                $aRow = $approvedData->get($dateKey)?->get($mId);
                $dRow = $disbursedData->get($dateKey)?->get($mId);
                $rRow = $rejectedData->get($dateKey)?->get($mId);

                $mCollected  = (float) ($cRow->total_recovered ?? 0);
                $mExpected   = (float) ($eRow->total_expected ?? 0);
                $mApprCount  = (int)   ($aRow->loan_count ?? 0);
                $mApprVol    = (float) ($aRow->total_principal ?? 0);
                $mDisbCount  = (int)   ($dRow->loan_count ?? 0);
                $mDisbVol    = (float) ($dRow->total_principal ?? 0);
                $mRejCount   = (int)   ($rRow->loan_count ?? 0);
                $mRejVol     = (float) ($rRow->total_principal ?? 0);

                $dayCollections     += $mCollected;
                $dayExpected        += $mExpected;
                $dayApprovedCount   += $mApprCount;
                $dayApprovedVolume  += $mApprVol;
                $dayDisbursedCount  += $mDisbCount;
                $dayDisbursedVolume += $mDisbVol;
                $dayRejectedCount   += $mRejCount;
                $dayRejectedVolume  += $mRejVol;

                $marketBreakdown[] = [
                    'market_id'       => $mId,
                    'market_name'     => $market->name,
                    'collections'     => $mCollected,
                    'expected'        => $mExpected,
                    'collection_rate' => $mExpected > 0
                        ? round(($mCollected / $mExpected) * 100, 2)
                        : 0,
                    'activity' => [
                        'approved'  => ['count' => $mApprCount, 'total_principal' => $mApprVol],
                        'disbursed' => ['count' => $mDisbCount, 'total_principal' => $mDisbVol],
                        'rejected'  => ['count' => $mRejCount,  'total_principal' => $mRejVol],
                    ],
                ];
            }

            $dailyData[] = [
                'date'                   => $dateKey,
                'total_collections'      => $dayCollections,
                'total_expected'         => $dayExpected,
                'total_approved_count'   => $dayApprovedCount,
                'total_approved_volume'  => $dayApprovedVolume,
                'total_disbursed_count'  => $dayDisbursedCount,
                'total_disbursed_volume' => $dayDisbursedVolume,
                'total_rejected_count'   => $dayRejectedCount,
                'total_rejected_volume'  => $dayRejectedVolume,
                'markets'                => $marketBreakdown,
            ];

            $currentDate->addDay();
        }

        // ────────────────────────────────────────────────────────────
        // Period-wide summary
        // ────────────────────────────────────────────────────────────
        $totalCollected      = array_sum(array_column($dailyData, 'total_collections'));
        $totalExpected       = array_sum(array_column($dailyData, 'total_expected'));
        $totalApprovedCount  = array_sum(array_column($dailyData, 'total_approved_count'));
        $totalApprovedVolume = array_sum(array_column($dailyData, 'total_approved_volume'));
        $totalDisbursedCount = array_sum(array_column($dailyData, 'total_disbursed_count'));
        $totalDisbursedVolume= array_sum(array_column($dailyData, 'total_disbursed_volume'));
        $totalRejectedCount  = array_sum(array_column($dailyData, 'total_rejected_count'));
        $totalRejectedVolume = array_sum(array_column($dailyData, 'total_rejected_volume'));

        return response()->json([
            'success' => true,
            'data'    => [
                'period' => [
                    'from' => $fromDate->format('Y-m-d'),
                    'to'   => $toDate->format('Y-m-d'),
                    'days' => $fromDate->diffInDays($toDate) + 1,
                ],
                'summary' => [
                    'total_collected'  => $totalCollected,
                    'total_expected'   => $totalExpected,
                    'collection_rate'  => $totalExpected > 0 ? round(($totalCollected / $totalExpected) * 100, 2) : 0,
                    'loans' => [
                        'approved_count'   => $totalApprovedCount,
                        'approved_volume'  => $totalApprovedVolume,
                        'disbursed_count'  => $totalDisbursedCount,
                        'disbursed_volume' => $totalDisbursedVolume,
                        'rejected_count'   => $totalRejectedCount,
                        'rejected_volume'  => $totalRejectedVolume,
                    ],
                ],
                'daily_breakdown' => $dailyData,
            ]
        ], 200);
    }

    /**
     * Helper: Get market ID filter based on user role
     */
    private function getMarketFilter(Request $request)
    {
        // Super admin and supervisor can filter by market or see all
        if (auth()->user()->isSuperAdmin() || auth()->user()->isSupervisor()) {
            return $request->get('market_id');
        }

        // Agents only see their own market
        if (auth()->user()->isAgent()) {
            return auth()->user()->market_id;
        }

        return null;
    }
}
