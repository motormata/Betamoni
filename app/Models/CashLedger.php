<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CashLedger extends Model
{
    use HasUuids;
    protected $table = 'cash_ledger';

    protected $fillable = [
        'transaction_type',
        'amount',
        'loan_id',
        'payment_id',
        'user_id',
        'transaction_date',
        'transaction_time',
        'description',
        'reference_number'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
    ];

    // Relationships
    
    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if this is money coming IN
     */
    public function isInflow()
    {
        return $this->amount > 0;
    }

    /**
     * Check if this is money going OUT
     */
    public function isOutflow()
    {
        return $this->amount < 0;
    }

    /**
     * Get absolute value of amount (for display purposes)
     */
    public function getAbsoluteAmount()
    {
        return abs($this->amount);
    }
}
