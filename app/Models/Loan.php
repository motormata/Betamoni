<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Loan extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'loan_number', 'borrower_id', 'agent_id', 'market_id', 'approved_by',
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
     * Synchronize the loan status based on its current schedules and balance
     */
    public function syncStatus()
    {
        // 1. Check if fully paid
        $totalExpected = $this->repaymentSchedules->sum('expected_amount');
        $totalPaid = $this->payments->sum('amount');

        if ($totalPaid >= $totalExpected && $totalExpected > 0) {
            $this->update(['status' => 'completed', 'completed_at' => now()]);
            return;
        }

        // 2. Check if overdue
        if ($this->isOverdue()) {
            if ($this->status !== 'overdue') {
                $this->update(['status' => 'overdue']);
            }
            return;
        }

        // 3. Otherwise, if it was disbursed, it's active
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

    // Generate loan number
    public static function generateLoanNumber()
    {
        $date = now()->format('Ymd');
        $lastLoan = self::whereDate('created_at', today())->latest()->first();
        $sequence = $lastLoan ? intval(substr($lastLoan->loan_number, -4)) + 1 : 1;
        
        return 'BM-' . $date . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }
}