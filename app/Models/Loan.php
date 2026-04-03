<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Loan extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'loan_number', 'borrower_id', 'loan_product_id', 'quantity', 'agent_id', 'market_id', 'approved_by',
        'principal_amount', 'interest_rate', 'interest_amount', 'total_amount',
        'amount_paid', 'balance', 'duration_days', 'disbursement_date', 'due_date',
        'repayment_frequency', 'installment_amount', 'status', 'collection_day',
        'collection_time', 'collection_location', 'purpose', 'notes', 'rejection_reason',
        'approved_at', 'rejected_at', 'disbursed_at', 'completed_at'
    ];

    protected $casts = [
        'principal_amount' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'interest_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'balance' => 'decimal:2',
        'installment_amount' => 'decimal:2',
        'disbursement_date' => 'date',
        'due_date' => 'date',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'disbursed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // Relationships
    public function borrower()
    {
        return $this->belongsTo(Borrower::class);
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function market()
    {
        return $this->belongsTo(Market::class);
    }

    public function product()
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }


    public function activities()
    {
        return $this->hasMany(LoanActivity::class);
    }

    public function repaymentSchedules()
    {
        return $this->hasMany(RepaymentSchedule::class);
    }

    public function guarantors()
    {
        return $this->hasMany(Guarantor::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function cashLedgerEntries()
    {
        return $this->hasMany(CashLedger::class);
    }

    // Helper methods
    public function isPending()
    {
        return $this->status === 'pending';
    }

    public function isApproved()
    {
        return $this->status === 'approved';
    }

    public function isActive()
    {
        return $this->status === 'active';
    }

    public function isCompleted()
    {
        return $this->status === 'completed';
    }

    public function isOverdue()
    {
        // A loan is overdue if it's currently disbursed/active and HAS at least one overdue schedule
        if (!in_array($this->status, ['disbursed', 'active', 'overdue'])) {
            return false;
        }

        return $this->repaymentSchedules()->whereDate('due_date', '<', today())->where('status', '!=', 'paid')->exists();
    }

    /**
     * Synchronize the loan status based on its current schedules and balance.
     *
     * IMPORTANT: always use the query-builder form of the relationships
     * (i.e. repaymentSchedules() / payments() with parentheses) rather than
     * the collection property (repaymentSchedules / payments without parentheses).
     * The property returns whatever was eager-loaded at model-load time, which
     * will be stale if a payment was just written inside the same request.
     * The query-builder form always issues a fresh DB query.
     */
    public function syncStatus()
    {
        // 1. Check if fully paid — query fresh from DB
        $totalExpected = $this->repaymentSchedules()->sum('expected_amount');
        $totalPaid     = $this->payments()->sum('amount');

        if ($totalPaid >= $totalExpected && $totalExpected > 0) {
            $this->update(['status' => 'completed', 'completed_at' => now()]);
            return;
        }

        // 2. Check if overdue (isOverdue already uses the query-builder internally)
        if ($this->isOverdue()) {
            if ($this->status !== 'overdue') {
                $this->update(['status' => 'overdue']);
            }
            return;
        }

        // 3. Otherwise, if it was disbursed/overdue and now has a payment, mark active
        if (in_array($this->status, ['disbursed', 'overdue'])) {
            $this->update(['status' => 'active']);
        }
    }

    public function calculateInterest()
    {
        return ($this->principal_amount * $this->interest_rate) / 100;
    }

    public function calculateTotal()
    {
        return $this->principal_amount + $this->interest_amount;
    }

    public function calculateBalance()
    {
        return $this->total_amount - $this->amount_paid;
    }

    /**
     * Generate a unique loan number.
     *
     * Format: BM-YYYYMMDD-XXXXXX  (6-digit random suffix)
     *
     * WHY NOT a simple sequence counter?
     * A "read last number, add 1, insert" pattern has a race condition:
     * two simultaneous requests read the same last number and generate
     * identical loan numbers. Using a random suffix + uniqueness check
     * eliminates that window entirely without DB-level locks.
     *
     * The loan_number column must have a UNIQUE index (already present
     * in the migration) — that is the final safety net.
     */
    public static function generateLoanNumber(): string
    {
        $date = now()->format('Ymd');

        do {
            $suffix = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $candidate = 'BM-' . $date . '-' . $suffix;
        } while (self::where('loan_number', $candidate)->exists());

        return $candidate;
    }
}