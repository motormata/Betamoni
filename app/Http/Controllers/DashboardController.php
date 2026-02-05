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
        $date = $request->has('date') ? \Carbon\Carbon::parse($request->date) : today();

        $data = $this->calculationService->calculateRepaymentsForToday($date, $marketId);

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
     */
    public function historicalPerformance(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $marketId = $this->getMarketFilter($request);
        $fromDate = \Carbon\Carbon::parse($request->from_date);
        $toDate = \Carbon\Carbon::parse($request->to_date);

        // Calculate metrics for each day in the range
        $dailyData = [];
        $currentDate = $fromDate->copy();

        while ($currentDate <= $toDate) {
            $dailyData[] = [
                'date' => $currentDate->format('Y-m-d'),
                'collections' => $this->calculationService->calculateLoanRecoveredPerDay($currentDate, $marketId),
                'repayments' => $this->calculationService->calculateRepaymentsForToday($currentDate, $marketId),
            ];
            $currentDate->addDay();
        }

        // Summary totals
        $totalCollected = array_sum(array_column(array_column($dailyData, 'collections'), 'total_recovered'));
        $totalExpected = array_sum(array_column(array_column($dailyData, 'repayments'), 'total_expected'));

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'from' => $fromDate->format('Y-m-d'),
                    'to' => $toDate->format('Y-m-d'),
                    'days' => $fromDate->diffInDays($toDate) + 1,
                ],
                'summary' => [
                    'total_collected' => $totalCollected,
                    'total_expected' => $totalExpected,
                    'collection_rate' => $totalExpected > 0 ? round(($totalCollected / $totalExpected) * 100, 2) : 0,
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
