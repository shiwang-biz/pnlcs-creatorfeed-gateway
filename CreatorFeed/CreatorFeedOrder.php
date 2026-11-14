<?php

namespace Modules\Gateways\CreatorFeed;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Model;

/**
 * One CreatorFeed order raised for a PNLCS invoice.
 *
 * This table is the only thing that ties a CreatorFeed order_id back to an
 * invoice: a webhook or return URL naming an order that is not here is never
 * applied to anything.
 */
class CreatorFeedOrder extends Model
{
    protected $table = 'creatorfeed_orders';

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'gst' => 'decimal:2',
        'net' => 'decimal:2',
        'raw' => 'array',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'applied_at' => 'datetime',
        'last_checked_at' => 'datetime',
    ];

    /** Statuses a status check can still change. */
    public const OPEN_STATUSES = ['creating', 'pending', 'failed', 'error'];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true)
            || ($this->status === 'paid' && $this->applied_at === null);
    }
}
