<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'loan_id',
        'collected_by',
        'payment_date',
        'payment_time',
        'amount',
        'payment_method',
        'receipt_number',
        'repayment_schedule_id',
        'collection_location',
        'notes',
        'is_verified',
        'verified_by',
        'verified_at'
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
    ];

    // Relationships
    
    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function collectedBy()
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function repaymentSchedule()
    {
        return $this->belongsTo(RepaymentSchedule::class);
    }

    public function cashLedgerEntry()
    {
        return $this->hasOne(CashLedger::class);
    }

    /**
     * Generate a unique receipt number for this payment
     * Format: PAY-YYYYMMDD-0001
     */
    public static function generateReceiptNumber()
    {
        $date = now()->format('Ymd');
        $lastPayment = self::whereDate('created_at', today())->latest()->first();
        $sequence = $lastPayment ? intval(substr($lastPayment->receipt_number, -4)) + 1 : 1;
        
        return 'PAY-' . $date . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Verify this payment
     */
    public function verify($verifiedBy)
    {
        $this->update([
            'is_verified' => true,
            'verified_by' => $verifiedBy,
            'verified_at' => now(),
        ]);
    }
}
