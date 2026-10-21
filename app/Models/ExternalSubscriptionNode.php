<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalSubscriptionNode extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['config'];
    protected $casts = [
        'config' => 'encrypted:array',
        'selected' => 'boolean',
        'present' => 'boolean',
        'supported' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(ExternalSubscription::class, 'source_id');
    }
}
