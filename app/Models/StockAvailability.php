<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAvailability extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'stock_availability';

    protected $fillable = [
        'stock_group_id',
        'customer_id',
        'product_id',
        'closing_qty',
        'open_qty',
        'stock_in_qty',
        'stock_out_qty',
        'calculated_available_qty',
        'used_qty', // Stock used/consumed - this is what users enter
        'date',
        'time',
        'notes',
        'created_by'
    ];

    protected $casts = [
        'date' => 'date',
        'time' => 'datetime:H:i:s',
        'closing_qty' => 'integer',
        'open_qty' => 'integer',
        'stock_in_qty' => 'integer',
        'stock_out_qty' => 'integer',
        'calculated_available_qty' => 'integer',
        'used_qty' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    /**
     * Get the stock availability group that owns the stock availability.
     */
    public function stockGroup(): BelongsTo
    {
        return $this->belongsTo(StockAvailabilityGroup::class, 'stock_group_id');
    }

    /**
     * Get the customer that owns the stock availability.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the product that owns the stock availability.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the user who created the stock availability.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }


    /**
     * Scope to filter by customer.
     */
    public function scopeByCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * Scope to filter by product.
     */
    public function scopeByProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    /**
     * Scope to filter by date range.
     */
    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    /**
     * Scope to filter by specific date.
     */
    public function scopeByDate($query, $date)
    {
        return $query->where('date', $date);
    }

    /**
     * Scope to get today's entries.
     */
    public function scopeToday($query)
    {
        return $query->where('date', today());
    }

    /**
     * Get opening stock for a customer, product, and date
     */
    public static function getOpeningStock($customerId, $productId, $date)
    {
        // Get the previous day's closing stock
        $previousDate = \Carbon\Carbon::parse($date)->subDay()->format('Y-m-d');
        
        $previousStock = static::where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->where('date', $previousDate)
            ->first();
            
        return $previousStock ? $previousStock->closing_qty : 0;
    }

    /**
     * Calculate stock movements for a customer, product, and date
     */
    public static function calculateStockMovements($customerId, $productId, $date)
    {
        // Convert date to proper format for comparison
        $dateObj = \Carbon\Carbon::parse($date)->format('Y-m-d');
        
        // Get stock in qty (customer deliveries)
        // This includes deliveries where transfer_status is NOT 'customer_transfer' (e.g., 'delivery_received', null, etc.)
        $stockInQty = StockProduct::whereHas('stock', function($query) use ($customerId, $dateObj) {
            $query->where('customer_id', $customerId)
                  ->whereDate('t_date', $dateObj) // Use whereDate for proper date comparison
                  ->where(function($q) {
                      $q->where('transfer_status', '!=', 'customer_transfer')
                        ->orWhereNull('transfer_status'); // Include null transfer_status (deliveries)
                  });
        })
        ->where('product_id', $productId)
        ->where('stock_type', 'in')
        ->sum('stock_qty');

        // Add transfers received (customer_transfer with stock_type = 'in')
        $transferInQty = StockProduct::whereHas('stock', function($query) use ($customerId, $dateObj) {
            $query->where('customer_id', $customerId)
                  ->whereDate('t_date', $dateObj) // Use whereDate for proper date comparison
                  ->where('transfer_status', 'customer_transfer');
        })
        ->where('product_id', $productId)
        ->where('stock_type', 'in')
        ->sum('stock_qty');

        // Get stock out qty (transfers sent out + returns from deliveries)
        // Transfers sent: where this customer is the sending customer
        $transferOutQty = StockProduct::whereHas('stock', function($query) use ($customerId, $dateObj) {
            $query->where('from_cust_id', $customerId)
                  ->whereDate('t_date', $dateObj) // Use whereDate for proper date comparison
                  ->where('transfer_status', 'customer_transfer');
        })
        ->where('product_id', $productId)
        ->where('stock_type', 'out')
        ->sum('stock_qty');
        
        // Returns from deliveries: where this customer received delivery and returned items
        $returnOutQty = StockProduct::whereHas('stock', function($query) use ($customerId, $dateObj) {
            $query->where('customer_id', $customerId)
                  ->whereDate('t_date', $dateObj) // Use whereDate for proper date comparison
                  ->where(function($q) {
                      $q->where('transfer_status', 'delivery_received')
                        ->orWhere(function($subQ) {
                            $subQ->where('transfer_status', '!=', 'customer_transfer')
                                 ->whereNotNull('delivery_id');
                        });
                  });
        })
        ->where('product_id', $productId)
        ->where('stock_type', 'out')
        ->sum('stock_qty');
        
        $stockOutQty = $transferOutQty + $returnOutQty;

        return [
            'stock_in_qty' => $stockInQty + $transferInQty,
            'stock_out_qty' => $stockOutQty
        ];
    }

    /**
     * Calculate available quantity
     */
    public static function calculateAvailableQty($customerId, $productId, $date)
    {
        $openingStock = static::getOpeningStock($customerId, $productId, $date);
        $movements = static::calculateStockMovements($customerId, $productId, $date);
        
        return $openingStock + $movements['stock_in_qty'] - $movements['stock_out_qty'];
    }

    /**
     * Create or update stock availability with auto-calculation
     */
    public static function createOrUpdateAvailability($customerId, $productId, $date, $closingQty, $notes = null, $createdBy = null)
    {
        $openingStock = static::getOpeningStock($customerId, $productId, $date);
        $movements = static::calculateStockMovements($customerId, $productId, $date);
        $calculatedAvailable = $openingStock + $movements['stock_in_qty'] - $movements['stock_out_qty'];

        return static::updateOrCreate(
            [
                'customer_id' => $customerId,
                'product_id' => $productId,
                'date' => $date
            ],
            [
                'open_qty' => $openingStock,
                'stock_in_qty' => $movements['stock_in_qty'],
                'stock_out_qty' => $movements['stock_out_qty'],
                'calculated_available_qty' => $calculatedAvailable,
                'closing_qty' => $closingQty,
                'notes' => $notes,
                'created_by' => $createdBy,
                'time' => now()->format('H:i:s')
            ]
        );
    }

    /**
     * Validate closing quantity against calculated available quantity
     */
    public function validateClosingQty()
    {
        return $this->closing_qty <= $this->calculated_available_qty;
    }

    /**
     * Get validation message
     */
    public function getValidationMessage()
    {
        if (!$this->validateClosingQty()) {
            return "Closing quantity ({$this->closing_qty}) cannot exceed calculated available quantity ({$this->calculated_available_qty})";
        }
        return null;
    }

}