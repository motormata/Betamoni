<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Borrower extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'first_name', 'last_name', 'phone', 'alternate_phone', 'email', 'bvn',
        'gender', 'date_of_birth', 'home_address', 'business_address', 'lga', 'state',
        'business_type', 'business_description', 'id_type', 'id_number',
        'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relationship', 'next_of_kin_address',
        'market_id', 'shop_number', 'registered_by', 'photo_path', 'id_card_path',
        'business_photo_path', 'is_active'
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'is_active' => 'boolean',
    ];

    protected $appends = ['full_name'];

    public function getFullNameAttribute()
    {
        return $this->first_name . ' ' . $this->last_name;
    }

    public function market()
    {
        return $this->belongsTo(Market::class);
    }

    public function registeredBy()
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function loans()
    {
        return $this->hasMany(Loan::class);
    }

    public function activeLoans()
    {
        return $this->hasMany(Loan::class)->whereIn('status', ['active', 'disbursed']);
    }
}
