<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InformationRecord extends Model
{
    protected $fillable = [
        'merchant_id',
        'venue_id',
        'created_by_user_id',
        'approved_by_user_id',
        'source',
        'category',
        'title',
        'content',
        'status',
        'submitted_at',
        'approved_at',
        'published_at',
        'rejected_at',
        'rejection_reason',
        'admin_notes',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
            'rejected_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
