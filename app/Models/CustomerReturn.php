<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReturn extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'customer_returns';

    protected $fillable = [
        'customer_id',
        'product_id',
        'delivery_id',
        'stock_id',
        'return_qty',
        'return_reason',
        'return_reason_details',
        'return_date',
        'status',
        'approved_by',
        'approved_at',
        'action_taken',
        'internal_stock_id',
        'vendor_id',
        'admin_notes',
        'rejection_reason',
        'created_by',
        'modified_by'
    ];

    protected $casts = [
        'return_qty' => 'integer',
        'return_date' => 'date',
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    /**
     * Get the customer that owns the return
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the product being returned
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the delivery associated with this return
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /**
     * Get the stock record associated with this return
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * Get the admin who approved the return
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the internal stock if moved to internal stocks
     */
    public function internalStock(): BelongsTo
    {
        return $this->belongsTo(InternalStock::class, 'internal_stock_id');
    }

    /**
     * Get the vendor if returned to vendor
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Get the user who created the return
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last modified the return
     */
    public function modifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modified_by');
    }

    /**
     * Get return reason options
     */
    public static function getReturnReasonOptions()
    {
        return [
            'spoiled' => 'Spoiled',
            'damaged' => 'Damaged',
            'expired' => 'Expired',
            'wrong_product' => 'Wrong Product',
            'qty_mismatched' => 'Quantity Mismatched',
            'overstock' => 'Overstock',
            'customer_request' => 'Customer Request',
            'other' => 'Other'
        ];
    }

    /**
     * Get status options
     */
    public static function getStatusOptions()
    {
        return [
            'pending' => 'Pending Approval',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'moved_to_internal' => 'Moved to Internal Stocks',
            'returned_to_vendor' => 'Returned to Vendor',
            'disposed' => 'Disposed'
        ];
    }

    /**
     * Get action taken options
     */
    public static function getActionTakenOptions()
    {
        return [
            'move_to_internal' => 'Move to Internal Stocks',
            'return_to_vendor' => 'Return to Vendor',
            'dispose' => 'Dispose'
        ];
    }

    /**
     * Scope to filter by status
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by customer
     */
    public function scopeByCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * Scope to filter by product
     */
    public function scopeByProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    /**
     * Scope to get pending returns
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to get approved returns
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Check if return is pending
     */
    public function isPending()
    {
        return $this->status === 'pending';
    }

    /**
     * Check if return is approved
     */
    public function isApproved()
    {
        return $this->status === 'approved';
    }

    /**
     * Check if return can be moved to internal stocks
     */
    public function canMoveToInternal()
    {
        return $this->status === 'approved' && $this->action_taken === null;
    }

    /**
     * Check if return can be returned to vendor
     */
    public function canReturnToVendor()
    {
        return $this->status === 'approved' && $this->action_taken === null;
    }
}
