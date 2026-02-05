<?php

namespace App\Http\Controllers;

use App\Models\CashLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Cash Ledger Controller
 * 
 * Manages cash flow operations including:
 * - Capital injections (adding cash)
 * - Expense withdrawals  
 * - Cash ledger history
 */
class CashLedgerController extends Controller
{
    /**
     * Get all cash ledger entries
     */
    public function index(Request $request)
    {
        $query = CashLedger::with(['loan', 'payment', 'user']);

        // Filter by transaction type
        if ($request->has('type')) {
            $query->where('transaction_type', $request->type);
        }

        // Filter by date range
        if ($request->has('from_date')) {
            $query->whereDate('transaction_date', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->whereDate('transaction_date', '<=', $request->to_date);
        }

        // Filter by inflow/outflow
        if ($request->has('flow')) {
            if ($request->flow === 'in') {
                $query->where('amount', '>', 0);
            } elseif ($request->flow === 'out') {
                $query->where('amount', '<', 0);
            }
        }

        $entries = $query->latest('transaction_date')->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => $entries
        ], 200);
    }

    /**
     * Add capital / cash injection
     * 
     * This is how you "add cash at hand" - by recording a capital injection
     * Examples: Initial investment, top-up, loan from bank, etc.
     */
    public function addCapital(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'description' => 'required|string|max:500',
            'transaction_date' => 'nullable|date',
            'reference_number' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $entry = CashLedger::create([
            'transaction_type' => 'capital_injection',
            'amount' => abs($request->amount), // Always positive (money IN)
            'user_id' => auth()->id(),
            'transaction_date' => $request->transaction_date ?? today(),
            'transaction_time' => now()->format('H:i:s'),
            'description' => $request->description,
            'reference_number' => $request->reference_number ?? $this->generateReferenceNumber('CAP'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Capital added successfully',
            'data' => $entry
        ], 201);
    }

    /**
     * Record an expense / withdrawal
     * 
     * For recording money going out that's not a loan disbursement
     * Examples: Office rent, transport, supplies, etc.
     */
    public function recordExpense(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'description' => 'required|string|max:500',
            'transaction_date' => 'nullable|date',
            'reference_number' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $entry = CashLedger::create([
            'transaction_type' => 'expense',
            'amount' => -abs($request->amount), // Always negative (money OUT)
            'user_id' => auth()->id(),
            'transaction_date' => $request->transaction_date ?? today(),
            'transaction_time' => now()->format('H:i:s'),
            'description' => $request->description,
            'reference_number' => $request->reference_number ?? $this->generateReferenceNumber('EXP'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Expense recorded successfully',
            'data' => $entry
        ], 201);
    }

    /**
     * Get cash summary
     * 
     * Quick overview of cash position with breakdown
     */
    public function summary(Request $request)
    {
        $fromDate = $request->has('from_date') ? \Carbon\Carbon::parse($request->from_date) : null;
        $toDate = $request->has('to_date') ? \Carbon\Carbon::parse($request->to_date) : today();

        $query = CashLedger::query();
        
        if ($fromDate) {
            $query->whereDate('transaction_date', '>=', $fromDate);
        }
        $query->whereDate('transaction_date', '<=', $toDate);

        // Get breakdown by transaction type
        $breakdown = $query->get()->groupBy('transaction_type')->map(function($entries, $type) {
            return [
                'count' => $entries->count(),
                'total' => $entries->sum('amount'),
            ];
        });

        // Calculate totals
        $totalInflow = CashLedger::where('amount', '>', 0)
            ->when($fromDate, fn($q) => $q->whereDate('transaction_date', '>=', $fromDate))
            ->whereDate('transaction_date', '<=', $toDate)
            ->sum('amount');

        $totalOutflow = CashLedger::where('amount', '<', 0)
            ->when($fromDate, fn($q) => $q->whereDate('transaction_date', '>=', $fromDate))
            ->whereDate('transaction_date', '<=', $toDate)
            ->sum('amount');

        $currentBalance = CashLedger::whereDate('transaction_date', '<=', $toDate)->sum('amount');

        return response()->json([
            'success' => true,
            'data' => [
                'current_balance' => $currentBalance,
                'period' => [
                    'from' => $fromDate?->format('Y-m-d'),
                    'to' => $toDate->format('Y-m-d'),
                ],
                'totals' => [
                    'inflow' => $totalInflow,
                    'outflow' => abs($totalOutflow),
                    'net' => $totalInflow + $totalOutflow,
                ],
                'breakdown' => $breakdown,
            ]
        ], 200);
    }

    /**
     * Generate a reference number for transactions
     */
    private function generateReferenceNumber($prefix)
    {
        $date = now()->format('Ymd');
        $lastEntry = CashLedger::whereDate('created_at', today())
            ->where('reference_number', 'like', "{$prefix}-{$date}-%")
            ->latest()
            ->first();
        
        $sequence = 1;
        if ($lastEntry && preg_match('/-(\d+)$/', $lastEntry->reference_number, $matches)) {
            $sequence = intval($matches[1]) + 1;
        }

        return "{$prefix}-{$date}-" . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }
}
