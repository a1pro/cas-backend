<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BnplVoucherOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'checkout_code',
        'plan_key',
        'plan_name',
        'amount_gbp',
        'payment_provider',
        'payment_status',
        'voucher_status',
        'voucher_code',
        'customer_name',
        'customer_email',
        'customer_phone',
        'checkout_completed_at',
        'payment_confirmed_at',
        'voucher_issued_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_gbp' => 'decimal:2',
            'checkout_completed_at' => 'datetime',
            'payment_confirmed_at' => 'datetime',
            'voucher_issued_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
