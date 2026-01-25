<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Quote extends Model
{
    protected $fillable = [
        'name',
        'mobile_number',
        'email',
        'request_type',
        'ref_id', // subscription_id or partner_id
        'service',
        'state',
        'project_type',
        'budget',
        'project_details',
        'project_date',
        'files',
        'status',
    ];

    protected $casts = [
        'project_date' => 'date',
        'files' => 'array',
    ];

    /**
     * Get the subscription if request_type is 'subscription'
     */
    public function subscription()
    {
        return $this->belongsTo(Subscription::class, 'ref_id');
    }

    /**
     * Get the partner program if request_type is 'partner'
     */
    public function partnerProgram()
    {
        return $this->belongsTo(PartnerProgram::class, 'ref_id');
    }
}
