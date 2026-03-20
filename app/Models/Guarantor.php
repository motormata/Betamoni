<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Guarantor extends Model
{
    use HasUuids;

    protected $fillable = [
        'loan_id',
        'name',
        'phone',
        'address',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }
}
