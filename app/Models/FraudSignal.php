<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FraudSignal extends Model
{
    protected $fillable = [
        'user_id',
        'voucher_id',
        'signal_type',
        'severity',
        'score_delta',
        'status',
        'reason',
        'context',
        'triggered_at',
        'reviewed_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'score_delta' => 'integer',
            'context' => 'array',
            'triggered_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }
}
