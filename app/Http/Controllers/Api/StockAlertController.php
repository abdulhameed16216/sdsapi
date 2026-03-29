<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Notification;
use App\Services\StockAvailabilityService;
use Carbon\Carbon;

class StockAlertController extends Controller
{
    /**
     * Get all stock alerts (customer and internal)
     */
    public function index(Request $request)
    {
        try {
            $customerAlerts = $this->getCustomerStockAlerts();
            $internalAlerts = $this->getInternalStockAlerts();
            
            $totalAlerts = count($customerAlerts) + count($internalAlerts);

            // Create notifications for new alerts
            $this->createNotificationsFromAlerts($customerAlerts, $internalAlerts);

            return response()->json([
                'success' => true,
                'message' => 'Stock alerts retrieved successfully',
                'data' => [
                    'customer_alerts' => $customerAlerts,
                    'internal_alerts' => $internalAlerts,
                    'total_alerts' => $totalAlerts
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock alerts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customer stock alerts
     */
    public function getCustomerAlerts(Request $request)
    {
        try {
            $customerId = $request->input('customer_id');
            $alerts = $this->getCustomerStockAlerts($customerId);

            return response()->json([
                'success' => true,
                'message' => 'Customer stock alerts retrieved successfully',
                'data' => [
                    'customer_alerts' => $alerts,
                    'total_alerts' => count($alerts)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve customer stock alerts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get internal stock alerts
     */
    public function getInternalAlerts(Request $request)
    {
        try {
            $alerts = $this->getInternalStockAlerts();

            return response()->json([
                'success' => true,
                'message' => 'Internal stock alerts retrieved successfully',
                'data' => [
                    'internal_alerts' => $alerts,
                    'total_alerts' => count($alerts)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve internal stock alerts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check stock alerts for a specific customer (when entering stock availability)
     */
    public function checkCustomerAlerts($customerId)
    {
        try {
            $alerts = $this->getCustomerStockAlerts($customerId);

            return response()->json([
                'success' => true,
                'message' => 'Customer stock alerts checked successfully',
                'data' => [
                    'customer_alerts' => $alerts,
                    'total_alerts' => count($alerts),
                    'has_alerts' => count($alerts) > 0
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check customer stock alerts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send email notification for stock alerts
     */
    public function sendEmail(Request $request)
    {
        try {
            $alerts = $request->input('alerts', []);
            
            if (empty($alerts)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No alerts to send'
                ], 400);
            }

            // Get stock alert email from .env (lines 42-43)
            // Priority: MAIL_STOCK_ALERT_EMAIL > MAIL_FROM_ADDRESS > default
            // Add MAIL_STOCK_ALERT_EMAIL=your-email@example.com to .env file (line 42-43)
            $stockAlertEmail = env('MAIL_STOCK_ALERT_EMAIL', env('MAIL_FROM_ADDRESS', config('mail.from.address', 'admin@example.com')));
            
            // Group alerts by type
            $customerAlerts = array_values(array_filter($alerts, function($alert) {
                return isset($alert['stock_type']) && $alert['stock_type'] === 'customer';
            }));
            
            $internalAlerts = array_values(array_filter($alerts, function($alert) {
                return isset($alert['stock_type']) && $alert['stock_type'] === 'internal';
            }));

            // Send email using mail configuration from .env
            try {
                Mail::send('emails.stock-alerts', [
                    'customerAlerts' => $customerAlerts,
                    'internalAlerts' => $internalAlerts,
                    'totalAlerts' => count($alerts)
                ], function ($message) use ($stockAlertEmail) {
                    $message->to($stockAlertEmail)
                            ->from(config('mail.from.address'), config('mail.from.name'))
                            ->subject('Stock Alert Notification - Low Stock Products');
                });
            } catch (\Exception $mailException) {
                \Log::error('Failed to send stock alert email', [
                    'error' => $mailException->getMessage(),
                    'stock_alert_email' => $stockAlertEmail,
                    'mail_config' => [
                        'host' => config('mail.mailers.smtp.host'),
                        'port' => config('mail.mailers.smtp.port'),
                        'from_address' => config('mail.from.address')
                    ]
                ]);
                
                // Don't fail the request if email fails, just log it
                return response()->json([
                    'success' => false,
                    'message' => 'Stock alerts retrieved but email sending failed. Please check mail configuration.',
                    'error' => $mailException->getMessage()
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Stock alert email sent successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send stock alert email',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customer stock alerts based on thresholds
     */
    private function getCustomerStockAlerts($customerId = null)
    {
        $alerts = [];

        // Get all products with their thresholds
        $products = Product::whereNull('deleted_at')
            ->where('status', 'active')
            ->get();

        foreach ($products as $product) {
            $threshold = $product->minimum_threshold ?? 0;
            
            if ($threshold <= 0) {
                continue; // Skip products without threshold
            }

            // Get current stock for customer(s)
            if ($customerId) {
                // Get stock for specific customer
                $stock = $this->getCustomerCurrentStock($customerId, $product->id);
                
                if ($stock !== null && $stock < $threshold) {
                    $customer = Customer::find($customerId);
                    
                    $alerts[] = [
                        'id' => $product->id,
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'product_code' => $product->code,
                        'current_stock' => $stock,
                        'threshold' => $threshold,
                        'stock_type' => 'customer',
                        'customer_id' => $customerId,
                        'customer_name' => $customer ? ($customer->company_name ?? $customer->name) : 'Unknown',
                        'alert_level' => $this->getAlertLevel($stock, $threshold),
                        'percentage' => $threshold > 0 ? round(($stock / $threshold) * 100, 2) : 0
                    ];
                }
            } else {
                // Get stock for all customers
                $customers = Customer::whereNull('deleted_at')
                    ->where('status', 'active')
                    ->get();

                foreach ($customers as $customer) {
                    $stock = $this->getCustomerCurrentStock($customer->id, $product->id);
                    
                    if ($stock !== null && $stock < $threshold) {
                        $alerts[] = [
                            'id' => $product->id . '_' . $customer->id,
                            'product_id' => $product->id,
                            'product_name' => $product->name,
                            'product_code' => $product->code,
                            'current_stock' => $stock,
                            'threshold' => $threshold,
                            'stock_type' => 'customer',
                            'customer_id' => $customer->id,
                            'customer_name' => $customer->company_name ?? $customer->name,
                            'alert_level' => $this->getAlertLevel($stock, $threshold),
                            'percentage' => $threshold > 0 ? round(($stock / $threshold) * 100, 2) : 0
                        ];
                    }
                }
            }
        }

        // Sort by alert level (critical first)
        usort($alerts, function($a, $b) {
            $levelOrder = ['critical' => 0, 'low' => 1];
            return $levelOrder[$a['alert_level']] <=> $levelOrder[$b['alert_level']];
        });

        return $alerts;
    }

    /**
     * Get internal stock alerts based on thresholds
     */
    private function getInternalStockAlerts()
    {
        $alerts = [];

        // Get all products with their thresholds
        $products = Product::whereNull('deleted_at')
            ->where('status', 'active')
            ->get();

        foreach ($products as $product) {
            $threshold = $product->minimum_threshold ?? 0;
            
            if ($threshold <= 0) {
                continue; // Skip products without threshold
            }

            // Get current internal stock
            $stock = $this->getInternalCurrentStock($product->id);
            
            if ($stock !== null && $stock < $threshold) {
                $alerts[] = [
                    'id' => $product->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'current_stock' => $stock,
                    'threshold' => $threshold,
                    'stock_type' => 'internal',
                    'alert_level' => $this->getAlertLevel($stock, $threshold),
                    'percentage' => $threshold > 0 ? round(($stock / $threshold) * 100, 2) : 0
                ];
            }
        }

        // Sort by alert level (critical first)
        usort($alerts, function($a, $b) {
            $levelOrder = ['critical' => 0, 'low' => 1];
            return $levelOrder[$a['alert_level']] <=> $levelOrder[$b['alert_level']];
        });

        return $alerts;
    }

    /**
     * Get current stock for a customer and product
     * Uses new calculation method from StockAvailabilityService (stocks_product table)
     */
    private function getCustomerCurrentStock($customerId, $productId)
    {
        try {
            // Use StockAvailabilityService to calculate current available stock
            // This calculates from stocks_product table: SUM(in) - (SUM(out) + SUM(sold-out))
            $currentStock = StockAvailabilityService::calculateAvailableStock($customerId, $productId);
            return $currentStock !== null ? (float)$currentStock : 0;
        } catch (\Exception $e) {
            \Log::error('Error getting customer current stock', [
                'customer_id' => $customerId,
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Get current internal stock for a product
     * Calculates from internal_stock_products and delivery_products tables
     * Formula: Stock In (from internal_stock_products) - Stock Out (from delivery_products)
     */
    private function getInternalCurrentStock($productId)
    {
        try {
            // Stock In: sum of stock_qty from internal_stock_products for this product
            // Note: Negative values represent stock out (returns to vendor)
            $stockIn = DB::table('internal_stock_products as isp')
                ->join('internal_stocks as is', 'isp.internal_stock_id', '=', 'is.id')
                ->where('isp.product_id', $productId)
                ->whereNull('is.deleted_at')
                ->whereNull('isp.deleted_at')
                ->sum('isp.stock_qty'); // Can be negative for returns to vendor
            
            // Stock Out: sum of (delivery_qty - return_qty) from delivery_products for this product
            // This represents stock delivered to customers
            $stockOut = DB::table('delivery_products')
                ->where('product_id', $productId)
                ->whereNull('deleted_at')
                ->selectRaw('SUM(COALESCE(delivery_qty, 0) - COALESCE(return_qty, 0)) as net_delivery')
                ->value('net_delivery') ?? 0;

            // Available Stock: stock_in - stock_out
            // Since stockIn can include negative values (returns), this calculation handles both
            $availableStock = max(0, $stockIn - $stockOut);

            return (float)$availableStock;
        } catch (\Exception $e) {
            \Log::error('Error getting internal current stock', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Create notifications from stock alerts
     */
    private function createNotificationsFromAlerts($customerAlerts, $internalAlerts)
    {
        try {
            // Process customer alerts
            foreach ($customerAlerts as $alert) {
                $customerName = $alert['customer_name'] ?? 'Unknown Customer';
                $productName = $alert['product_name'] ?? 'Unknown Product';
                $priority = $alert['alert_level'] === 'critical' ? 'critical' : ($alert['alert_level'] === 'low' ? 'high' : 'medium');

                // Check if notification already exists for this alert (within last hour)
                $existingNotification = Notification::where('type', 'stock_alert')
                    ->where('customer_id', $alert['customer_id'] ?? null)
                    ->where('product_id', $alert['product_id'] ?? null)
                    ->where('status', 'unread')
                    ->where('created_at', '>=', now()->subHour())
                    ->first();

                if (!$existingNotification) {
                    Notification::create([
                        'type' => 'stock_alert',
                        'title' => "Low Stock Alert - {$customerName}",
                        'message' => "{$productName} ({$alert['product_code']}) has only {$alert['current_stock']} units remaining. Threshold: {$alert['threshold']} units.",
                        'priority' => $priority,
                        'status' => 'unread',
                        'user_id' => null, // For all users
                        'customer_id' => $alert['customer_id'] ?? null,
                        'product_id' => $alert['product_id'] ?? null,
                        'data' => [
                            'alert_level' => $alert['alert_level'],
                            'current_stock' => $alert['current_stock'],
                            'threshold' => $alert['threshold'],
                            'percentage' => $alert['percentage'],
                            'stock_type' => 'customer'
                        ]
                    ]);
                }
            }

            // Process internal alerts
            foreach ($internalAlerts as $alert) {
                $productName = $alert['product_name'] ?? 'Unknown Product';
                $priority = $alert['alert_level'] === 'critical' ? 'critical' : ($alert['alert_level'] === 'low' ? 'high' : 'medium');

                // Check if notification already exists for this alert (within last hour)
                $existingNotification = Notification::where('type', 'stock_alert')
                    ->whereNull('customer_id')
                    ->where('product_id', $alert['product_id'] ?? null)
                    ->where('status', 'unread')
                    ->where('created_at', '>=', now()->subHour())
                    ->first();

                if (!$existingNotification) {
                    Notification::create([
                        'type' => 'stock_alert',
                        'title' => "Low Stock Alert - Internal Stock",
                        'message' => "{$productName} ({$alert['product_code']}) has only {$alert['current_stock']} units remaining. Threshold: {$alert['threshold']} units.",
                        'priority' => $priority,
                        'status' => 'unread',
                        'user_id' => null, // For all users
                        'customer_id' => null,
                        'product_id' => $alert['product_id'] ?? null,
                        'data' => [
                            'alert_level' => $alert['alert_level'],
                            'current_stock' => $alert['current_stock'],
                            'threshold' => $alert['threshold'],
                            'percentage' => $alert['percentage'],
                            'stock_type' => 'internal'
                        ]
                    ]);
                }
            }
        } catch (\Exception $e) {
            \Log::error('Failed to create notifications from alerts', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Determine alert level based on stock and threshold
     */
    private function getAlertLevel($stock, $threshold)
    {
        if ($threshold <= 0) {
            return 'low';
        }

        $percentage = ($stock / $threshold) * 100;

        // Critical: below 50% of threshold
        if ($percentage < 50) {
            return 'critical';
        }

        // Low: below threshold but above 50%
        return 'low';
    }

    /**
     * Check if threshold status changed (above → below threshold)
     * Returns true if status changed from above to below threshold
     */
    private function hasThresholdStatusChanged($customerId, $productId, $currentStock, $threshold)
    {
        if ($threshold <= 0) {
            return false; // No threshold set
        }

        $cacheKey = "threshold_status_{$customerId}_{$productId}";
        $previousStatus = Cache::get($cacheKey); // 'above' or 'below' or null

        $currentStatus = $currentStock >= $threshold ? 'above' : 'below';

        // If previous status was 'above' and current is 'below', status changed
        if ($previousStatus === 'above' && $currentStatus === 'below') {
            // Update cache with new status
            Cache::put($cacheKey, $currentStatus, now()->addDays(30));
            return true;
        }

        // Update cache with current status
        if ($previousStatus !== $currentStatus) {
            Cache::put($cacheKey, $currentStatus, now()->addDays(30));
        }

        return false;
    }

    /**
     * Automatically check and trigger notifications/emails for threshold alerts
     * This is called after stock operations (save, transfer, return)
     * 
     * @param int|null $customerId - If provided, only check this customer. If null, check all customers
     * @param int|null $productId - If provided, only check this product. If null, check all products
     */
    public function checkAndTriggerThresholdAlerts($customerId = null, $productId = null)
    {
        try {
            $alertsToNotify = [];
            
            // Get products to check
            $products = Product::whereNull('deleted_at')
                ->where('status', 'active');
            
            if ($productId) {
                $products->where('id', $productId);
            }
            
            $products = $products->get();

            foreach ($products as $product) {
                $threshold = $product->minimum_threshold ?? 0;
                
                if ($threshold <= 0) {
                    continue; // Skip products without threshold
                }

                // Check customer stock if customerId is provided or check all customers
                if ($customerId) {
                    $customers = [Customer::find($customerId)];
                } else {
                    $customers = Customer::whereNull('deleted_at')
                        ->where('status', 'active')
                        ->get();
                }

                foreach ($customers as $customer) {
                    if (!$customer) continue;

                    $currentStock = $this->getCustomerCurrentStock($customer->id, $product->id);
                    
                    // Check if threshold is crossed (stock < threshold)
                    if ($currentStock < $threshold) {
                        // Check if status changed (above → below)
                        if ($this->hasThresholdStatusChanged($customer->id, $product->id, $currentStock, $threshold)) {
                            $alertsToNotify[] = [
                                'product_id' => $product->id,
                                'product_name' => $product->name,
                                'product_code' => $product->code,
                                'current_stock' => $currentStock,
                                'threshold' => $threshold,
                                'stock_type' => 'customer',
                                'customer_id' => $customer->id,
                                'customer_name' => $customer->company_name ?? $customer->name,
                                'alert_level' => $this->getAlertLevel($currentStock, $threshold),
                                'percentage' => $threshold > 0 ? round(($currentStock / $threshold) * 100, 2) : 0
                            ];
                        }
                    } else {
                        // Stock is above threshold, update cache status
                        $cacheKey = "threshold_status_{$customer->id}_{$product->id}";
                        Cache::put($cacheKey, 'above', now()->addDays(30));
                    }
                }

                // Check internal stock
                $internalStock = $this->getInternalCurrentStock($product->id);
                
                if ($internalStock !== null && $internalStock < $threshold) {
                    $cacheKey = "threshold_status_internal_{$product->id}";
                    $previousStatus = Cache::get($cacheKey);
                    $currentStatus = $internalStock >= $threshold ? 'above' : 'below';
                    
                    if ($previousStatus === 'above' && $currentStatus === 'below') {
                        $alertsToNotify[] = [
                            'product_id' => $product->id,
                            'product_name' => $product->name,
                            'product_code' => $product->code,
                            'current_stock' => $internalStock,
                            'threshold' => $threshold,
                            'stock_type' => 'internal',
                            'customer_id' => null,
                            'customer_name' => null,
                            'alert_level' => $this->getAlertLevel($internalStock, $threshold),
                            'percentage' => $threshold > 0 ? round(($internalStock / $threshold) * 100, 2) : 0
                        ];
                        Cache::put($cacheKey, $currentStatus, now()->addDays(30));
                    } else if ($previousStatus !== $currentStatus) {
                        Cache::put($cacheKey, $currentStatus, now()->addDays(30));
                    }
                }
            }

            // If there are alerts, create notifications and send email
            if (!empty($alertsToNotify)) {
                // Group alerts by type
                $customerAlerts = array_filter($alertsToNotify, function($alert) {
                    return $alert['stock_type'] === 'customer';
                });
                $internalAlerts = array_filter($alertsToNotify, function($alert) {
                    return $alert['stock_type'] === 'internal';
                });

                // Create notifications
                $this->createNotificationsFromAlerts($customerAlerts, $internalAlerts);

                // Send email
                try {
                    $stockAlertEmail = env('MAIL_STOCK_ALERT_EMAIL', env('MAIL_FROM_ADDRESS', config('mail.from.address', 'admin@example.com')));
                    
                    Mail::send('emails.stock-alerts', [
                        'customerAlerts' => array_values($customerAlerts),
                        'internalAlerts' => array_values($internalAlerts),
                        'totalAlerts' => count($alertsToNotify)
                    ], function ($message) use ($stockAlertEmail) {
                        $message->to($stockAlertEmail)
                                ->from(config('mail.from.address'), config('mail.from.name'))
                                ->subject('Stock Alert Notification - Low Stock Products');
                    });
                } catch (\Exception $mailException) {
                    \Log::error('Failed to send automatic stock alert email', [
                        'error' => $mailException->getMessage(),
                        'alerts' => $alertsToNotify
                    ]);
                }
            }

            return $alertsToNotify;
        } catch (\Exception $e) {
            \Log::error('Error checking threshold alerts', [
                'customer_id' => $customerId,
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}

