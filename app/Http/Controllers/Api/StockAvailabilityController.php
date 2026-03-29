<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockAvailability;
use App\Models\StockAvailabilityGroup;
use App\Models\Stock;
use App\Models\StockProduct;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Models\MachineReading;
use App\Services\StockAvailabilityService;
use App\Http\Controllers\Api\StockAlertController;
use App\Models\CustomerFloor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Response;

class StockAvailabilityController extends Controller
{
    /**
     * Floor-based "stock used" report (from stocks_product stock_type = sold-out).
     * Includes branch-level usage when customer_floor_id is NULL (shown as "Branch Inventory" in UI).
     *
     * Filters:
     * - date (Y-m-d) OR date_from/date_to (Y-m-d)
     * - customer_id
     * - customer_floor_id
     */
    public function floorUsedToday(Request $request): JsonResponse
    {
        try {
            $hasDate = (bool) $request->get('date');
            $hasRange = (bool) ($request->get('date_from') || $request->get('date_to'));

            $dateStr = $hasDate ? Carbon::parse($request->get('date'))->format('Y-m-d') : null;
            $dateFrom = $request->get('date_from') ? Carbon::parse($request->get('date_from'))->format('Y-m-d') : null;
            $dateTo = $request->get('date_to') ? Carbon::parse($request->get('date_to'))->format('Y-m-d') : null;

            // Use a single join-based query to avoid ambiguous "deleted_at" columns.
            $query = StockProduct::query()
                ->join('stocks', 'stocks.id', '=', 'stocks_product.stock_id')
                ->where('stocks_product.stock_type', 'sold-out')
                ->whereNull('stocks_product.deleted_at')
                ->whereNull('stocks.deleted_at')
                ->where('stocks.transfer_status', 'sold-out');

            if ($request->has('customer_id') && $request->customer_id) {
                $cid = (int) $request->customer_id;
                $query->where('stocks.customer_id', $cid);
            }
            if ($request->has('customer_floor_id') && $request->customer_floor_id) {
                $query->where('stocks.customer_floor_id', (int) $request->customer_floor_id);
            }

            if ($hasDate && $dateStr) {
                $query->whereDate('stocks.t_date', $dateStr);
            } elseif ($hasRange) {
                if ($dateFrom) $query->whereDate('stocks.t_date', '>=', $dateFrom);
                if ($dateTo) $query->whereDate('stocks.t_date', '<=', $dateTo);
            }

            $rows = $query
                ->leftJoin('products', 'products.id', '=', 'stocks_product.product_id')
                ->select([
                    DB::raw('stocks_product.id as id'),
                    DB::raw('stocks.customer_id as customer_id'),
                    DB::raw('DATE(stocks.t_date) as t_date'),
                    DB::raw('stocks.customer_floor_id as customer_floor_id'),
                    DB::raw('stocks_product.product_id as product_id'),
                    DB::raw('products.name as product_name'),
                    DB::raw('stocks_product.stock_qty as used_qty'),
                ])
                ->orderBy('stocks.t_date', 'desc')
                ->orderBy('stocks_product.id', 'desc')
                ->get();

            $customerIds = $rows->pluck('customer_id')->unique()->values()->all();
            $floorIds = $rows->pluck('customer_floor_id')->filter()->unique()->values()->all();

            $customers = Customer::with('customerGroup:id,name')->whereIn('id', $customerIds)->get()->keyBy('id');
            $floors = CustomerFloor::whereIn('id', $floorIds)->get()->keyBy('id');

            $data = $rows->map(function ($r) use ($customers, $floors) {
                $customer = $customers->get($r->customer_id);
                $floor = $r->customer_floor_id ? $floors->get($r->customer_floor_id) : null;

                return [
                    'id' => (int) $r->id,
                    'date' => $r->t_date,
                    'customer_group_name' => $customer && $customer->customerGroup ? $customer->customerGroup->name : null,
                    'branch_name' => $customer ? ($customer->company_name ?? $customer->name) : null,
                    'customer_id' => $r->customer_id,
                    'customer_floor_id' => $r->customer_floor_id,
                    'product_id' => $r->product_id,
                    'product_name' => $r->product_name,
                    'floor_name' => $floor ? $floor->name : null,
                    'used_qty' => (int) $r->used_qty,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'message' => 'Floor used today report',
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('StockAvailabilityController@floorUsedToday: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load floor used report',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a single sold-out row quantity.
     */
    public function updateFloorUsedEntry(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'used_qty' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $row = StockProduct::where('id', $id)
                ->where('stock_type', 'sold-out')
                ->whereNull('deleted_at')
                ->firstOrFail();

            $row->stock_qty = (int) $request->used_qty;
            $row->save();

            return response()->json([
                'success' => true,
                'message' => 'Stock used row updated',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update stock used row',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete (soft-delete) a single sold-out row.
     */
    public function deleteFloorUsedEntry($id): JsonResponse
    {
        try {
            $row = StockProduct::where('id', $id)
                ->where('stock_type', 'sold-out')
                ->whereNull('deleted_at')
                ->firstOrFail();
            $row->delete();

            return response()->json([
                'success' => true,
                'message' => 'Stock used row deleted',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete stock used row',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    /**
     * Display a listing of stock availability (calculated from stocks_product)
     * One row per sold-out stock header: customer + date + customer_floor_id (pool = null).
     * Group id: {customer_id}_{Ymd} for pool, {customer_id}_{Ymd}_f{floor_id} for a floor.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $stocksQuery = \App\Models\Stock::whereNull('deleted_at')
                ->where('transfer_status', 'sold-out');

            if ($request->has('customer_id') && $request->customer_id) {
                $stocksQuery->where('customer_id', $request->customer_id);
            }
            if ($request->has('date_from') && $request->date_from) {
                $stocksQuery->whereDate('t_date', '>=', $request->date_from);
            }
            if ($request->has('date_to') && $request->date_to) {
                $stocksQuery->whereDate('t_date', '<=', $request->date_to);
            }

            $stockHeaders = $stocksQuery->get(['customer_id', 't_date', 'customer_floor_id']);

            $seenKeys = [];
            $combinations = [];
            foreach ($stockHeaders as $row) {
                $date = Carbon::parse($row->t_date)->format('Y-m-d');
                $floorRaw = $row->customer_floor_id;
                $floorId = ($floorRaw === null || $floorRaw === '') ? null : (int) $floorRaw;
                $key = $row->customer_id . '|' . $date . '|' . ($floorId === null ? 'pool' : (string) $floorId);
                if (isset($seenKeys[$key])) {
                    continue;
                }
                $seenKeys[$key] = true;
                $combinations[] = [
                    'customer_id' => (int) $row->customer_id,
                    'date' => $date,
                    'customer_floor_id' => $floorId,
                ];
            }

            $floorIds = collect($combinations)->pluck('customer_floor_id')->filter()->unique()->values()->all();
            $floors = CustomerFloor::whereIn('id', $floorIds)->get()->keyBy('id');

            $reportData = [];
            foreach ($combinations as $combo) {
                $customer = \App\Models\Customer::find($combo['customer_id']);
                if (! $customer) {
                    continue;
                }

                $floorId = $combo['customer_floor_id'];
                $ymd = str_replace('-', '', $combo['date']);
                $publicId = $combo['customer_id'] . '_' . $ymd . ($floorId !== null ? '_f' . $floorId : '');

                $stock = \App\Models\Stock::where('customer_id', $combo['customer_id'])
                    ->whereDate('t_date', $combo['date'])
                    ->where('transfer_status', 'sold-out')
                    ->whereNull('deleted_at')
                    ->when(
                        $floorId === null,
                        fn ($q) => $q->whereNull('customer_floor_id'),
                        fn ($q) => $q->where('customer_floor_id', $floorId)
                    )
                    ->first();

                $productsCount = 0;
                $time = '00:00:00';
                if ($stock) {
                    $productsCount = (int) StockProduct::where('stock_id', $stock->id)
                        ->where('stock_type', 'sold-out')
                        ->whereNull('deleted_at')
                        ->count();
                    if ($stock->created_at) {
                        $time = Carbon::parse($stock->created_at)->format('H:i:s');
                    }
                }

                $floorName = null;
                if ($floorId !== null) {
                    $f = $floors->get($floorId);
                    $floorName = $f ? $f->name : null;
                }

                $reportData[] = [
                    'id' => $publicId,
                    'customer_id' => $combo['customer_id'],
                    'customer_floor_id' => $floorId,
                    'floor_name' => $floorName,
                    'date' => $combo['date'],
                    'time' => $time,
                    'customer' => [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'company_name' => $customer->company_name,
                    ],
                    'products_count' => $productsCount,
                    'stock_availability_records' => [],
                ];
            }
            
            // Search functionality
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $reportData = array_filter($reportData, function($item) use ($search) {
                    return stripos($item['customer']['name'], $search) !== false ||
                           stripos($item['customer']['company_name'], $search) !== false;
                });
                $reportData = array_values($reportData);
            }
            
            usort($reportData, function ($a, $b) {
                $c = strcmp($b['date'], $a['date']);
                if ($c !== 0) {
                    return $c;
                }
                $fa = $a['customer_floor_id'] ?? null;
                $fb = $b['customer_floor_id'] ?? null;
                if ($fa === $fb) {
                    return 0;
                }
                if ($fa === null) {
                    return -1;
                }
                if ($fb === null) {
                    return 1;
                }

                return $fa <=> $fb;
            });

            // Check if we should return all records (no pagination)
            if ($request->has('all') && $request->get('all') == 'true') {
                return response()->json([
                    'success' => true,
                    'message' => 'Stock availability report retrieved successfully',
                    'data' => [
                        'data' => $reportData,
                        'total' => count($reportData),
                        'current_page' => 1,
                        'last_page' => 1
                    ]
                ]);
            }
            
            // Pagination
            $perPage = $request->get('per_page', 15);
            $currentPage = $request->get('page', 1);
            $total = count($reportData);
            $offset = ($currentPage - 1) * $perPage;
            $paginatedData = array_slice($reportData, $offset, $perPage);

            return response()->json([
                'success' => true,
                'message' => 'Stock availability report retrieved successfully',
                'data' => [
                    'data' => $paginatedData,
                    'total' => $total,
                    'current_page' => (int)$currentPage,
                    'last_page' => ceil($total / $perPage),
                    'per_page' => $perPage
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving stock availability report: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock availability report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of stock availability records (legacy method)
     */
    public function indexRecords(Request $request): JsonResponse
    {
        try {
            $query = StockAvailability::with(['customer', 'product', 'creator']);

            // Search functionality
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('customer', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('company_name', 'like', "%{$search}%");
                })->orWhereHas('product', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%");
                    });
                });
            }

            // Filters
            if ($request->has('customer_id') && $request->customer_id) {
                $query->where('customer_id', $request->customer_id);
            }
            if ($request->has('product_id') && $request->product_id) {
                $query->where('product_id', $request->product_id);
            }
            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('date', '>=', $request->date_from);
            }
            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('date', '<=', $request->date_to);
            }

            $perPage = $request->get('per_page', 15);
            $stockAvailability = $query->orderBy('date', 'desc')->orderBy('time', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Stock availability records retrieved successfully',
                'data' => $stockAvailability
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving stock availability records: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock availability records',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get stock availability data for popup (customer, date, products with calculations)
     */
    public function getAvailabilityData(Request $request): JsonResponse
    {
        try {
                $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,id',
                'date' => 'required|date'
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Validation failed',
                        'errors' => $validator->errors()
                    ], 422);
                }

            $customerId = $request->customer_id;
            $date = $request->date;

            // Get all products that have stock movements for this customer on this date
            $productIds = StockProduct::whereHas('stock', function($query) use ($customerId, $date) {
                $query->where(function($q) use ($customerId, $date) {
                    $q->where('customer_id', $customerId)
                      ->orWhere('from_cust_id', $customerId);
                })->where('t_date', $date);
            })
            ->pluck('product_id')
            ->unique();

            Log::info('Product IDs with stock movements: ' . $productIds->toJson());

            $products = Product::whereIn('id', $productIds)->get();

            Log::info('Products found: ' . $products->count());

            // If no products found with stock movements, get all active products
            if ($products->isEmpty()) {
                $products = Product::where('status', 'active')->get();
                Log::info('No stock movements found, returning all active products: ' . $products->count());
            }

            $availabilityData = [];

            foreach ($products as $product) {
                // Calculate available stock up to this date (cumulative)
                $availableQty = StockAvailabilityService::calculateAvailableStock($customerId, $product->id, $date);
                
                // Calculate day-wise movements for this specific date
                $dayMovements = StockAvailabilityService::calculateDayWiseMovements($customerId, $product->id, $date);
                
                // Calculate opening stock (available stock before this date)
                $openingStock = StockAvailabilityService::calculateAvailableStock($customerId, $product->id, \Carbon\Carbon::parse($date)->subDay()->format('Y-m-d'));

                $availabilityData[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'product_size' => $product->size,
                    'product_unit' => $product->unit,
                    'product_image' => $product->product_image,
                    'opening_qty' => $openingStock,
                    'stock_in_qty' => $dayMovements['stock_in_qty'],
                    'stock_out_qty' => $dayMovements['stock_out_qty'],
                    'sale_qty' => $dayMovements['sale_qty'] ?? 0,
                    'calculated_available_qty' => $availableQty,
                    'current_available_qty' => $availableQty,
                    'stock_used_qty' => $dayMovements['sale_qty'] ?? 0, // Sale qty is the daily usage
                    'closing_qty' => $availableQty, // Default closing = available
                    'notes' => ''
                ];
            }

            return response()->json([
                    'success' => true,
                'message' => 'Stock availability data retrieved successfully',
                'data' => [
                    'customer_id' => $customerId,
                    'date' => $date,
                    'products' => $availabilityData
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving stock availability data: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock availability data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get stock availability data for popup (simplified version)
     *
     * When customer_floor_id is omitted (e.g. returns screen), behaviour is unchanged: all active products, branch-total stock.
     * When customer_floor_id is present (empty = Branch Inventory): only products with pool availability greater than zero (plus
     * always_include_product_ids). When a floor id is selected: all active products are returned with floor-scoped
     * quantities (zero where there is no stock on that floor).
     */
    public function getAvailabilityDataSimple(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,id',
                'date' => 'required|date',
                'exclude_stock_product_id' => 'nullable|exists:stock_products,id',
                'customer_floor_id' => 'nullable',
                'always_include_product_ids' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $customerId = (int) $request->customer_id;
            $date = $request->date;
            $excludeStockProductId = $request->exclude_stock_product_id;
            $dateYmd = Carbon::parse($date)->format('Y-m-d');
            $prevDay = Carbon::parse($date)->subDay()->format('Y-m-d');

            $alwaysIncludeIds = [];
            if ($request->filled('always_include_product_ids')) {
                $alwaysIncludeIds = array_values(array_filter(array_map('intval', explode(',', $request->always_include_product_ids))));
            }

            // Use query bag so empty customer_floor_id (Branch Inventory) still enables floor scope; Request::has() treats empty as absent.
            $useFloorStockScope = $request->query->has('customer_floor_id');
            $scopeFloorId = null;
            if ($useFloorStockScope && $request->filled('customer_floor_id')) {
                $scopeFloorId = (int) $request->customer_floor_id;
                if (! CustomerFloor::whereKey($scopeFloorId)->exists()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Validation failed',
                        'errors' => ['customer_floor_id' => ['Invalid floor']],
                    ], 422);
                }
            }

            $products = Product::where('status', 'active')->get();
            $availabilityData = [];

            foreach ($products as $product) {
                if ($useFloorStockScope) {
                    $openingStock = StockAvailabilityService::calculateAvailableStockForFloorScope($customerId, $product->id, $scopeFloorId, $prevDay);
                    $dayMovements = StockAvailabilityService::calculateDayWiseMovementsForFloorScope($customerId, $product->id, $scopeFloorId, $date);
                    $existingSaleQty = $dayMovements['sale_qty'] ?? 0;
                    if ($excludeStockProductId) {
                        $excludedRecord = StockProduct::with('stock')->find($excludeStockProductId);
                        if ($excludedRecord && $excludedRecord->product_id == $product->id && $excludedRecord->stock_type === 'sold-out' && $excludedRecord->stock) {
                            $s = $excludedRecord->stock;
                            $matchesScope = ((int) $s->customer_id === $customerId)
                                && (Carbon::parse($s->t_date)->format('Y-m-d') === $dateYmd)
                                && (
                                    $scopeFloorId === null
                                        ? $s->customer_floor_id === null
                                        : (int) $s->customer_floor_id === $scopeFloorId
                                );
                            if ($matchesScope) {
                                $existingSaleQty = max(0, $existingSaleQty - $excludedRecord->stock_qty);
                            }
                        }
                    }
                    $availableQtyBeforeSale = $openingStock + $dayMovements['stock_in_qty'] - $dayMovements['stock_out_qty'];
                } else {
                    $openingStock = StockAvailabilityService::calculateAvailableStock($customerId, $product->id, $prevDay);
                    $dayMovements = StockAvailabilityService::calculateDayWiseMovements($customerId, $product->id, $date);
                    $existingSaleQty = $dayMovements['sale_qty'] ?? 0;
                    if ($excludeStockProductId) {
                        $excludedRecord = StockProduct::find($excludeStockProductId);
                        if ($excludedRecord && $excludedRecord->product_id == $product->id && $excludedRecord->stock_type == 'sold-out') {
                            $existingSaleQty = max(0, $existingSaleQty - $excludedRecord->stock_qty);
                        }
                    }
                    $availableQtyBeforeSale = $openingStock + $dayMovements['stock_in_qty'] - $dayMovements['stock_out_qty'];
                }

                // Max stock_used_qty = stock in − out (gross); must match store() after per-product delete + validate.
                $displayAvailableQty = $useFloorStockScope
                    ? StockAvailabilityService::calculateMaxSoldOutQtyForFloorScope(
                        (int) $customerId,
                        (int) $product->id,
                        $scopeFloorId,
                        $dateYmd
                    )
                    : $availableQtyBeforeSale;

                $isFloorSelected = $useFloorStockScope && $scopeFloorId !== null;
                $isBranchPoolScope = $useFloorStockScope && $scopeFloorId === null;

                if ($isFloorSelected) {
                    $shouldInclude = true;
                } elseif ($isBranchPoolScope) {
                    $shouldInclude = $displayAvailableQty > 0
                        || in_array((int) $product->id, $alwaysIncludeIds, true);
                } else {
                    $shouldInclude = true;
                }

                if (! $shouldInclude) {
                    continue;
                }

                $availabilityData[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'product_size' => $product->size,
                    'product_unit' => $product->unit,
                    'product_image' => $product->product_image,
                    'opening_qty' => $openingStock,
                    'stock_in_qty' => $dayMovements['stock_in_qty'],
                    'stock_out_qty' => $dayMovements['stock_out_qty'],
                    'sale_qty' => $dayMovements['sale_qty'] ?? 0,
                    'calculated_available_qty' => $displayAvailableQty,
                    'current_available_qty' => $displayAvailableQty,
                    'stock_used_qty' => $existingSaleQty,
                    'closing_qty' => $useFloorStockScope
                        ? max(0, $displayAvailableQty - $existingSaleQty)
                        : max(0, $availableQtyBeforeSale - $existingSaleQty),
                    'notes' => '',
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Stock availability data retrieved successfully',
                'data' => [
                    'customer_id' => $customerId,
                    'date' => $date,
                    'products' => $availabilityData,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving stock availability data (simple): ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock availability data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get stock availability data for mobile app (current user's assigned customer)
     * Uses current date by default, or accepts date parameter
     */
    public function getMobileAvailabilityData(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user || !$user->employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee not found. Please login again.'
                ], 401);
            }

            $employeeId = $user->employee->id;
            
            // Get assigned customer for current employee
            $assignment = \App\Models\EmployeeCustomerMachineAssignment::with('customer')
                ->where('emp_id', $employeeId)
                ->where('status', 'active')
                ->first();
            
            if (!$assignment) {
                return response()->json([
                    'success' => false,
                    'message' => 'No customer assigned to your account. Please contact admin.'
                ], 404);
            }

            $customerId = $assignment->customer_id;
            
            // Use provided date or default to today
            $date = $request->has('date') && $request->date 
                ? $request->date 
                : now()->format('Y-m-d');

            // Get all active products
            $products = Product::where('status', 'active')->get();

            $availabilityData = [];

            foreach ($products as $product) {
                // Calculate opening stock (previous day's closing)
                $openingStock = StockAvailabilityService::getOpeningStock($customerId, $product->id, $date);
                
                // Calculate stock movements
                $movements = StockAvailabilityService::calculateStockMovements($customerId, $product->id, $date);
                
                // Calculate available quantity
                $availableQty = $openingStock + $movements['stock_in_qty'] - $movements['stock_out_qty'];

                // Get existing stock used qty if record exists
                $existingRecord = StockAvailability::where('customer_id', $customerId)
                    ->where('product_id', $product->id)
                    ->whereDate('date', $date)
                    ->first();

                $stockUsedQty = $existingRecord ? $existingRecord->used_qty : 0;

                $availabilityData[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'product_size' => $product->size,
                    'product_unit' => $product->unit,
                    'opening_qty' => $openingStock,
                    'stock_in_qty' => $movements['stock_in_qty'],
                    'stock_out_qty' => $movements['stock_out_qty'],
                    'calculated_available_qty' => $availableQty,
                    'current_available_qty' => $availableQty,
                    'stock_used_qty' => $stockUsedQty, // Stock used/consumed
                    'closing_qty' => $existingRecord ? $existingRecord->closing_qty : $availableQty, // Calculated closing
                    'notes' => $existingRecord ? $existingRecord->notes : ''
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Stock availability data retrieved successfully',
                'data' => [
                    'customer_id' => $customerId,
                    'customer_name' => $assignment->customer->name ?? $assignment->customer->company_name,
                    'date' => $date,
                    'products' => $availabilityData
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving mobile stock availability data: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock availability data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store stock availability records from mobile app
     */
    public function storeMobileAvailability(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user || !$user->employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee not found. Please login again.'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'date' => 'required|date',
                'products' => 'required|array',
                'products.*.product_id' => 'required|exists:products,id',
                'products.*.stock_used_qty' => 'required|numeric|min:0',
                'products.*.notes' => 'nullable|string|max:1000'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $employeeId = $user->employee->id;
            
            // Get assigned customer for current employee
            $assignment = \App\Models\EmployeeCustomerMachineAssignment::with('customer')
                ->where('emp_id', $employeeId)
                ->where('status', 'active')
                ->first();
            
            if (!$assignment) {
                return response()->json([
                    'success' => false,
                    'message' => 'No customer assigned to your account. Please contact admin.'
                ], 404);
            }

            $customerId = $assignment->customer_id;
            $date = $request->date;
            $time = $request->has('time') ? $request->time : now()->format('H:i:s');

            DB::beginTransaction();

            $createdRecords = [];

            foreach ($request->products as $productData) {
                $productId = $productData['product_id'];
                $stockUsedQty = $productData['stock_used_qty'];
                $notes = $productData['notes'] ?? null;

                // Calculate opening stock and movements
                $openingStock = StockAvailabilityService::getOpeningStock($customerId, $productId, $date);
                $movements = StockAvailabilityService::calculateStockMovements($customerId, $productId, $date);
                $calculatedAvailable = $openingStock + $movements['stock_in_qty'] - $movements['stock_out_qty'];

                // Validate stock used quantity
                if ($stockUsedQty > $calculatedAvailable) {
                    $errorDetails = [
                        "Stock used quantity ({$stockUsedQty}) cannot exceed calculated available quantity ({$calculatedAvailable})",
                        "Opening stock: {$openingStock}",
                        "Stock in quantity: {$movements['stock_in_qty']}",
                        "Stock out quantity: {$movements['stock_out_qty']}",
                        "Date: {$date}",
                        "Customer ID: {$customerId}",
                        "Product ID: {$productId}",
                        "Please ensure stock-in records exist for this product and date before recording stock usage."
                    ];
                    throw new \Exception(implode(". ", $errorDetails));
                }

                // Calculate closing qty: closing = calculated_available - stock_used
                $closingQty = $calculatedAvailable - $stockUsedQty;

                // Calculate closing qty: closing = calculated_available - stock_used
                $closingQty = $calculatedAvailable - $stockUsedQty;

                // Create or get a Stock record for this customer and date (for sales/sold-out)
                // Check if a stock record already exists for this date with transfer_status = 'sold-out'
                $stock = \App\Models\Stock::where('customer_id', $customerId)
                    ->whereDate('t_date', $date)
                    ->where('transfer_status', 'sold-out')
                    ->whereNull('deleted_at')
                    ->first();
                
                // If no stock record exists, create one for sales with transfer_status = 'sold-out'
                if (!$stock) {
                    $stock = \App\Models\Stock::create([
                        'customer_id' => $customerId,
                        't_date' => $date,
                        'transfer_status' => 'sold-out', // Mark as sold-out
                        'status' => 'active',
                        'created_by' => $user->id
                    ]);
                }

                // Delete existing sold-out record for this product on this date (if updating)
                \App\Models\StockProduct::where('stock_id', $stock->id)
                    ->where('product_id', $productId)
                    ->where('stock_type', 'sold-out')
                    ->whereNull('deleted_at')
                    ->delete();

                // Create new sold-out record (stock_type = 'sold-out') in stocks_product table
                if ($stockUsedQty > 0) {
                    $stockProduct = \App\Models\StockProduct::create([
                        'stock_id' => $stock->id,
                        'product_id' => $productId,
                        'stock_qty' => $stockUsedQty,
                        'stock_type' => 'sold-out' // Store daily usage/sales as sold-out
                    ]);

                    $createdRecords[] = [
                        'id' => $stockProduct->id,
                        'stock_id' => $stock->id,
                        'product_id' => $productId,
                        'product' => \App\Models\Product::find($productId),
                        'stock_qty' => $stockUsedQty,
                        'stock_type' => 'sold-out',
                    'date' => $date
                    ];
                }
            }

            DB::commit();

            // Trigger automatic threshold check after stock is saved
            try {
                $stockAlertController = new StockAlertController();
                foreach ($request->products as $productData) {
                    $stockAlertController->checkAndTriggerThresholdAlerts($customerId, $productData['product_id']);
                }
            } catch (\Exception $e) {
                // Log error but don't fail the request
                Log::error('Error triggering threshold alerts after stock save', [
                    'error' => $e->getMessage(),
                    'customer_id' => $customerId
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Stock sales records saved successfully',
                'data' => [
                    'stock_id' => isset($stock) ? $stock->id : null,
                    'customer_id' => $customerId,
                    'date' => $dateStr,
                    'records' => $createdRecords
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error storing mobile stock availability: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to save stock availability records',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store multiple stock availability records
     */
    public function store(Request $request): JsonResponse
    {
        try {
                $validator = Validator::make($request->all(), [
                    'customer_id' => 'required|exists:customers,id',
                    'customer_floor_id' => 'nullable|exists:customers_floor,id',
                    'date' => 'required|date',
                'time' => 'required|date_format:H:i',
                'products' => 'nullable|array',
                'products.*.product_id' => 'required|exists:products,id',
                'products.*.stock_used_qty' => 'required|numeric|min:0',
                'products.*.notes' => 'nullable|string|max:1000',
                'machines' => 'nullable|array',
                'machines.*.machine_id' => 'required|exists:machines,id',
                'machines.*.reading' => 'required|string|max:255'
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Validation failed',
                        'errors' => $validator->errors()
                    ], 422);
                }

            $data = $request->all();
            $createdRecords = [];

            // Parse date from ISO string to Y-m-d format for database
            $dateStr = $data['date'];
            if (strpos($dateStr, 'T') !== false) {
                // Extract date part from ISO string (e.g., "2025-11-08T18:30:00.000000Z" -> "2025-11-08")
                $dateStr = \Carbon\Carbon::parse($dateStr)->format('Y-m-d');
            } else {
                // Already in Y-m-d format, ensure it's valid
                $dateStr = \Carbon\Carbon::parse($dateStr)->format('Y-m-d');
            }

            DB::transaction(function () use ($data, $dateStr, &$createdRecords) {
                $customerId = $data['customer_id'];
                $customerFloorId = isset($data['customer_floor_id']) && $data['customer_floor_id'] ? (int) $data['customer_floor_id'] : null;
                $userId = Auth::id();
                
                // Create or get a Stock record for this customer and date (for sales/sold-out)
                // Check if a stock record already exists for this date with transfer_status = 'sold-out'
                $stock = \App\Models\Stock::where('customer_id', $customerId)
                    ->where('customer_floor_id', $customerFloorId)
                    ->whereDate('t_date', $dateStr)
                    ->where('transfer_status', 'sold-out')
                    ->whereNull('deleted_at')
                    ->first();
                
                // If no stock record exists, create one for sales with transfer_status = 'sold-out'
                if (!$stock) {
                    $stock = \App\Models\Stock::create([
                        'customer_id' => $customerId,
                        'customer_floor_id' => $customerFloorId,
                        't_date' => $dateStr,
                        'transfer_status' => 'sold-out', // Mark as sold-out
                        'status' => 'active',
                        'created_by' => $userId
                    ]);
                }

                // Process products only if provided
                if (isset($data['products']) && is_array($data['products']) && count($data['products']) > 0) {
                    foreach ($data['products'] as $productData) {
                    $productId = $productData['product_id'];
                    $stockUsedQty = $productData['stock_used_qty'];
                    $notes = $productData['notes'] ?? null;

                        // Remove this product's sold-out line first so validation matches post-save state (same as update flow).
                        \App\Models\StockProduct::where('stock_id', $stock->id)
                            ->where('product_id', $productId)
                            ->where('stock_type', 'sold-out')
                            ->whereNull('deleted_at')
                            ->delete();

                        // Branch pool (customer_floor_id null) or selected floor — net remaining after delete = max allowed total sold-out
                        $calculatedAvailable = StockAvailabilityService::calculateAvailableStockForFloorScope(
                            (int) $customerId,
                            (int) $productId,
                            $customerFloorId,
                            $dateStr
                        );

                        // Validate stock used quantity (sale qty) against selected scope
                        if ($stockUsedQty > $calculatedAvailable) {
                            $branchPoolAvailable = StockAvailabilityService::calculateAvailableStockForFloorScope(
                                (int) $customerId,
                                (int) $productId,
                                null,
                                $dateStr
                            );
                            $scopeLabel = $customerFloorId ? "Floor ID {$customerFloorId}" : 'Branch Inventory';
                            $errorDetails = [
                                "Stock used quantity ({$stockUsedQty}) cannot exceed available quantity in selected scope ({$scopeLabel}: {$calculatedAvailable})",
                                "Branch Inventory available: {$branchPoolAvailable}",
                                "If floor scope is selected, transfer stock to that floor first or select Branch Inventory",
                                "Date: {$dateStr}",
                                "Customer ID: {$customerId}",
                                "Product ID: {$productId}"
                            ];
                            throw new \Exception(implode(". ", $errorDetails));
                        }

                        // Create new sold-out record (stock_type = 'sold-out') in stocks_product table
                        if ($stockUsedQty > 0) {
                            $stockProduct = \App\Models\StockProduct::create([
                                'stock_id' => $stock->id,
                                'product_id' => $productId,
                                'stock_qty' => $stockUsedQty,
                                'stock_type' => 'sold-out', // Store daily usage/sales as sold-out
                            ]);

                            $createdRecords[] = [
                                'id' => $stockProduct->id,
                                'stock_id' => $stock->id,
                                'product_id' => $productId,
                                'product' => \App\Models\Product::find($productId),
                                'stock_qty' => $stockUsedQty,
                                'stock_type' => 'sold-out',
                                'date' => $dateStr
                            ];
                        }
                    }
                }

                // Save machine readings if provided
                if (isset($data['machines']) && is_array($data['machines'])) {
                    foreach ($data['machines'] as $machineData) {
                        // Use the parsed date
                        MachineReading::updateOrCreate(
                            [
                                'machine_id' => $machineData['machine_id'],
                                'reading_date' => $dateStr
                            ],
                            [
                                'user_id' => Auth::id(),
                                'reading_value' => is_numeric($machineData['reading']) ? (float)$machineData['reading'] : 0,
                                'reading_type' => 'stock_availability',
                                'unit' => '',
                                'notes' => $machineData['reading'] // Store the reading string in notes
                            ]
                        );
                    }
                }
            });

            // Trigger automatic threshold check after stock is saved
            try {
                $stockAlertController = new StockAlertController();
                if (isset($data['products']) && is_array($data['products'])) {
                    foreach ($data['products'] as $productData) {
                        $stockAlertController->checkAndTriggerThresholdAlerts($data['customer_id'], $productData['product_id']);
                    }
                }
            } catch (\Exception $e) {
                // Log error but don't fail the request
                Log::error('Error triggering threshold alerts after stock save', [
                    'error' => $e->getMessage(),
                    'customer_id' => $data['customer_id'] ?? null
                ]);
            }

                return response()->json([
                    'success' => true,
                'message' => 'Stock sales records created successfully',
                'data' => $createdRecords
                ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating stock availability records: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create stock availability records',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get saved stock availability data for mobile app (customer, date with products and machine readings)
     */
    public function getSavedStockAvailability(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,id',
                'date' => 'required|date'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $customerId = $request->customer_id;
            $dateStr = $request->date;
            
            // Parse date to Y-m-d format
            if (strpos($dateStr, 'T') !== false) {
                $dateStr = \Carbon\Carbon::parse($dateStr)->format('Y-m-d');
            } else {
                $dateStr = \Carbon\Carbon::parse($dateStr)->format('Y-m-d');
            }

            // Get stock availability group for this customer and date
            $stockGroup = StockAvailabilityGroup::with([
                'customer',
                'stockAvailabilityRecords.product'
            ])
            ->where('customer_id', $customerId)
            ->where('date', $dateStr)
            ->first();

            // Get machine readings for this date
            // Get machines assigned to this customer through employee assignments
            $assignedMachineIds = \App\Models\EmployeeCustomerMachineAssignment::where('customer_id', $customerId)
                ->where('status', 'active')
                ->whereNotNull('assigned_machine_id')
                ->whereNull('deleted_at')
                ->pluck('assigned_machine_id')
                ->toArray();

            $machineReadings = collect([]);
            if (!empty($assignedMachineIds)) {
                $machineReadings = MachineReading::with('machine')
                    ->where('reading_date', $dateStr)
                    ->where('reading_type', 'stock_availability')
                    ->whereIn('machine_id', $assignedMachineIds)
                    ->get();
            }

            // Format products data
            $products = [];
            if ($stockGroup && $stockGroup->stockAvailabilityRecords) {
                foreach ($stockGroup->stockAvailabilityRecords as $record) {
                    $products[] = [
                        'id' => $record->id,
                        'product_id' => $record->product_id,
                        'product_name' => $record->product->name ?? '',
                        'product_code' => $record->product->code ?? '',
                        'stock_used_qty' => $record->used_qty ?? 0,
                        'closing_qty' => $record->closing_qty,
                        'notes' => $record->notes,
                        'open_qty' => $record->open_qty,
                        'stock_in_qty' => $record->stock_in_qty,
                        'stock_out_qty' => $record->stock_out_qty,
                        'calculated_available_qty' => $record->calculated_available_qty
                    ];
                }
            }

            // Format machine readings data
            $machines = [];
            foreach ($machineReadings as $reading) {
                $machines[] = [
                    'id' => $reading->id,
                    'machine_id' => $reading->machine_id,
                    'machine_name' => $reading->machine->machine_alias ?? $reading->machine->serial_number ?? 'Machine #' . $reading->machine_id,
                    'machine_code' => $reading->machine->serial_number ?? '',
                    'reading' => $reading->notes ?? $reading->reading_value, // Use notes field which stores the reading string
                    'reading_value' => $reading->reading_value,
                    'reading_type' => $reading->reading_type
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Saved stock availability data retrieved successfully',
                'data' => [
                    'customer_id' => $customerId,
                    'customer_name' => $stockGroup->customer->name ?? '',
                    'date' => $dateStr,
                    'time' => $stockGroup->time ?? null,
                    'products' => $products,
                    'machines' => $machines,
                    'has_data' => ($stockGroup !== null || count($machines) > 0)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving saved stock availability: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve saved stock availability',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Parse stock-used group id: "5_20260104" (Branch Inventory) or "5_20260104_f12" (floor 12).
     *
     * @return array{customer_id:int, date:string, customer_floor_id:?int}|null
     */
    private function parseStockUsedGroupId(string $id): ?array
    {
        if (! preg_match('/^(\d+)_(\d{8})(?:_f(\d+))?$/', $id, $m)) {
            return null;
        }

        return [
            'customer_id' => (int) $m[1],
            'date' => Carbon::createFromFormat('Ymd', $m[2])->format('Y-m-d'),
            'customer_floor_id' => isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null,
        ];
    }

    /**
     * Display the specified stock availability group with its records
     * ID format: customer_id_date (e.g., "5_20260104") or customer_id_date_f{floor_id} for a floor.
     * Now calculates on-the-fly from stocks_product table
     */
    public function show($id): JsonResponse
    {
        try {
            $parsed = $this->parseStockUsedGroupId((string) $id);
            if ($parsed === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid ID format. Expected e.g. "5_20260104" or "5_20260104_f3"',
                ], 422);
            }

            $customerId = $parsed['customer_id'];
            $date = $parsed['date'];
            $scopeFloorId = $parsed['customer_floor_id'];

            // Get customer
            $customer = \App\Models\Customer::find($customerId);
            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer not found'
                ], 404);
            }

            // Get stock record for this customer-date-floor scope with transfer_status = 'sold-out'
            $stock = \App\Models\Stock::where('customer_id', $customerId)
                ->whereDate('t_date', $date)
                ->where('transfer_status', 'sold-out')
                ->whereNull('deleted_at')
                ->when(
                    $scopeFloorId === null,
                    fn ($q) => $q->whereNull('customer_floor_id'),
                    fn ($q) => $q->where('customer_floor_id', $scopeFloorId)
                )
                ->first();

            // Get all products with sold-out records for this customer-date
            $soldOutProducts = [];
            $selectedFloorId = null;
            $selectedFloorName = null;
            if ($stock) {
                $soldOutProducts = \App\Models\StockProduct::where('stock_id', $stock->id)
                    ->where('stock_type', 'sold-out')
                    ->whereNull('deleted_at')
                    ->with(['product'])
                    ->get();
                $selectedFloorId = $stock->customer_floor_id;
                if ($selectedFloorId) {
                    $floor = CustomerFloor::find($selectedFloorId);
                    $selectedFloorName = $floor ? $floor->name : null;
                }
            }

            // Get all products assigned to this customer for the popup
            $allProductIds = \App\Models\StockProduct::whereHas('stock', function($query) use ($customerId, $date) {
                $query->where('customer_id', $customerId)
                      ->whereDate('t_date', '<=', $date)
                      ->whereNull('deleted_at');
            })
            ->whereNull('deleted_at')
            ->pluck('product_id')
            ->unique();

            // Also get products assigned to customer
            $customerProducts = \App\Models\CustomerProduct::where('customer_id', $customerId)
                ->where('status', 'active')
                ->pluck('product_id')
                ->unique();

            $allProductIds = $allProductIds->merge($customerProducts)->unique();

            // Build stock availability records for each product
            $stockAvailabilityRecords = [];
            foreach ($allProductIds as $productId) {
                $product = \App\Models\Product::find($productId);
                if (!$product) continue;

                // Calculate stock movements
                $openingStock = StockAvailabilityService::getOpeningStock($customerId, $productId, $date);
                $movements = StockAvailabilityService::calculateStockMovements($customerId, $productId, $date);
                $availableQty = $openingStock + $movements['stock_in_qty'] - $movements['stock_out_qty'] - $movements['sale_qty'];

                // Get sold-out quantity for this product (if exists)
                $soldOutRecord = $soldOutProducts->firstWhere('product_id', $productId);
                $usedQty = $soldOutRecord ? $soldOutRecord->stock_qty : 0;

                $stockAvailabilityRecords[] = [
                    'id' => $soldOutRecord ? $soldOutRecord->id : null,
                    'product_id' => $productId,
                    'product' => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'code' => $product->code,
                        'size' => $product->size,
                        'unit' => $product->unit
                    ],
                    'opening_qty' => $openingStock,
                    'stock_in_qty' => $movements['stock_in_qty'],
                    'stock_out_qty' => $movements['stock_out_qty'],
                    'stock_sale_qty' => $movements['sale_qty'],
                    'calculated_available_qty' => $availableQty,
                    'used_qty' => $usedQty, // Get from stocks_product table
                    'closing_qty' => $availableQty - $usedQty,
                    'customer_floor_id' => $selectedFloorId,
                    'floor_name' => $selectedFloorName,
                ];
            }

            // Get time from stock record if exists
            $time = $stock && $stock->created_at ? \Carbon\Carbon::parse($stock->created_at)->format('H:i:s') : '00:00:00';

            // Build response in the format expected by frontend
            $stockGroup = [
                'id' => $id,
                'customer_id' => $customerId,
                'date' => $date,
                'time' => $time,
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'company_name' => $customer->company_name
                ],
                'customer_floor_id' => $selectedFloorId,
                'products_count' => count($stockAvailabilityRecords),
                'stock_availability_records' => $stockAvailabilityRecords,
                'created_by' => $stock ? $stock->created_by : null,
                'creator' => $stock && $stock->creator ? [
                    'id' => $stock->creator->id,
                    'username' => $stock->creator->username
                ] : null,
                'created_at' => $stock ? $stock->created_at : null,
                'updated_at' => $stock ? $stock->updated_at : null
            ];

            return response()->json([
                'success' => true,
                'message' => 'Stock availability group retrieved successfully',
                'data' => $stockGroup
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving stock availability group: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock availability group',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified stock availability record (legacy method)
     */
    public function showRecord($id): JsonResponse
    {
        try {
            $stockAvailability = StockAvailability::with(['customer', 'product', 'creator'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Stock availability record retrieved successfully',
                'data' => $stockAvailability
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving stock availability record: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock availability record',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified stock sales (sold-out) records
     * ID format: customer_id_date (e.g., "5_20260104")
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,id',
                'customer_floor_id' => 'nullable|exists:customers_floor,id',
                'date' => 'required|date',
                'time' => 'required|date_format:H:i',
                'products' => 'required|array|min:1',
                'products.*.product_id' => 'required|exists:products,id',
                'products.*.stock_used_qty' => 'required|numeric|min:0',
                'products.*.notes' => 'nullable|string|max:1000'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $parsed = $this->parseStockUsedGroupId((string) $id);
            if ($parsed === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid ID format. Expected e.g. "5_20260104" or "5_20260104_f3"',
                ], 422);
            }

            $customerId = $parsed['customer_id'];

            // Verify customer_id matches
            if ($customerId != $request->customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer ID mismatch'
                ], 422);
            }

            $requestFloorId = $request->filled('customer_floor_id') ? (int) $request->customer_floor_id : null;
            if ($requestFloorId !== $parsed['customer_floor_id']) {
                return response()->json([
                    'success' => false,
                    'message' => 'URL group id does not match customer_floor_id (floor scope must match the group being edited).',
                ], 422);
            }

            $data = $request->all();
            $updatedRecords = [];
            $stock = null; // Initialize stock variable
            
            // Parse date to ensure proper format
            $editDate = \Carbon\Carbon::parse($data['date'])->format('Y-m-d');

            DB::transaction(function () use ($data, $editDate, $customerId, &$updatedRecords, &$stock) {
                $userId = Auth::id();
                $customerFloorId = isset($data['customer_floor_id']) && $data['customer_floor_id'] ? (int) $data['customer_floor_id'] : null;
                
                // Find or create stock record with transfer_status = 'sold-out'
                $stock = \App\Models\Stock::where('customer_id', $customerId)
                    ->where('customer_floor_id', $customerFloorId)
                    ->whereDate('t_date', $editDate)
                    ->where('transfer_status', 'sold-out')
                    ->whereNull('deleted_at')
                    ->first();
                
                if (!$stock) {
                    $stock = \App\Models\Stock::create([
                        'customer_id' => $customerId,
                        'customer_floor_id' => $customerFloorId,
                        't_date' => $editDate,
                        'transfer_status' => 'sold-out',
                        'status' => 'active',
                        'created_by' => $userId
                    ]);
                } else {
                    // Update stock record
                    $stock->update([
                        'modified_by' => $userId
                    ]);
                }

                // Delete existing sold-out records for the selected scope only
                \App\Models\StockProduct::where('stock_id', $stock->id)
                    ->where('stock_type', 'sold-out')
                    ->whereNull('deleted_at')
                    ->delete();

                // Create new sold-out records
                foreach ($data['products'] as $productData) {
                    $productId = $productData['product_id'];
                    $stockUsedQty = $productData['stock_used_qty'];
                    $notes = $productData['notes'] ?? null;

                    // Sold-out lines for this header were deleted above; scoped availability matches what can be saved
                    $calculatedAvailable = StockAvailabilityService::calculateAvailableStockForFloorScope(
                        (int) $customerId,
                        (int) $productId,
                        $customerFloorId,
                        $editDate
                    );

                    // Validate stock used quantity (sale qty) against selected scope
                    if ($stockUsedQty > $calculatedAvailable) {
                        $branchPoolAvailable = StockAvailabilityService::calculateAvailableStockForFloorScope(
                            (int) $customerId,
                            (int) $productId,
                            null,
                            $editDate
                        );
                        $scopeLabel = $customerFloorId ? "Floor ID {$customerFloorId}" : 'Branch Inventory';
                        $errorDetails = [
                            "Stock used quantity ({$stockUsedQty}) cannot exceed available quantity in selected scope ({$scopeLabel}: {$calculatedAvailable})",
                            "Branch Inventory available: {$branchPoolAvailable}",
                            "If floor scope is selected, transfer stock to that floor first or select Branch Inventory",
                            "Date: {$editDate}",
                            "Customer ID: {$customerId}",
                            "Product ID: {$productId}"
                        ];
                        throw new \Exception(implode(". ", $errorDetails));
                    }

                    // Create new sold-out record (stock_type = 'sold-out') in stocks_product table
                    if ($stockUsedQty > 0) {
                        $stockProduct = \App\Models\StockProduct::create([
                            'stock_id' => $stock->id,
                            'product_id' => $productId,
                            'stock_qty' => $stockUsedQty,
                            'stock_type' => 'sold-out', // Store daily usage/sales as sold-out
                        ]);

                        $updatedRecords[] = [
                            'id' => $stockProduct->id,
                            'stock_id' => $stock->id,
                            'product_id' => $productId,
                            'product' => \App\Models\Product::find($productId),
                            'stock_qty' => $stockUsedQty,
                            'stock_type' => 'sold-out',
                            'date' => $editDate
                        ];
                    }
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Stock sales records updated successfully',
                'data' => [
                    'stock_id' => $stock->id,
                    'customer_id' => $customerId,
                    'date' => $editDate,
                    'records' => $updatedRecords
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating stock sales records: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update stock sales records',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified stock availability record (legacy method)
     */
    public function updateRecord(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'stock_used_qty' => 'required|numeric|min:0',
                'notes' => 'nullable|string|max:1000'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $stockAvailability = StockAvailability::findOrFail($id);

            // Validate stock used quantity against calculated available
            if ($request->stock_used_qty > $stockAvailability->calculated_available_qty) {
                return response()->json([
                    'success' => false,
                    'message' => "Stock used quantity ({$request->stock_used_qty}) cannot exceed calculated available quantity ({$stockAvailability->calculated_available_qty})",
                ], 422);
            }

            // Calculate closing qty: closing = calculated_available - stock_used
            $closingQty = $stockAvailability->calculated_available_qty - $request->stock_used_qty;

            $stockAvailability->update([
                'closing_qty' => $closingQty,
                'notes' => $request->notes,
                'time' => now()->format('H:i:s')
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Stock availability record updated successfully',
                'data' => $stockAvailability->load(['customer', 'product', 'creator'])
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating stock availability record: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update stock availability record',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified stock availability group and its records
     * ID format: customer_id_date (e.g., "5_20260104")
     * Deletes all stock records for that customer-date combination
     */
    public function destroy($id): JsonResponse
    {
        try {
            $parsed = $this->parseStockUsedGroupId((string) $id);
            if ($parsed === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid ID format. Expected e.g. "5_20260104" or "5_20260104_f3"',
                ], 422);
            }

            $customerId = $parsed['customer_id'];
            $date = $parsed['date'];
            $scopeFloorId = $parsed['customer_floor_id'];

            $stocks = \App\Models\Stock::where('customer_id', $customerId)
                ->whereDate('t_date', $date)
                ->where('transfer_status', 'sold-out')
                ->whereNull('deleted_at')
                ->when(
                    $scopeFloorId === null,
                    fn ($q) => $q->whereNull('customer_floor_id'),
                    fn ($q) => $q->where('customer_floor_id', $scopeFloorId)
                )
                ->get();

            if ($stocks->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No sold-out stock group found for this customer, date, and floor scope'
                ], 404);
            }

            DB::transaction(function () use ($stocks) {
                foreach ($stocks as $stock) {
                    \App\Models\StockProduct::where('stock_id', $stock->id)
                        ->whereNull('deleted_at')
                        ->delete();

                    $stock->delete();
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Stock availability records deleted successfully',
                'data' => [
                    'deleted_stocks_count' => $stocks->count(),
                    'customer_id' => $customerId,
                    'date' => $date,
                    'customer_floor_id' => $scopeFloorId,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting stock availability records: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete stock availability records',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified stock availability record (legacy method)
     */
    public function destroyRecord($id): JsonResponse
    {
        try {
            $stockAvailability = StockAvailability::findOrFail($id);
            $stockAvailability->delete();

            return response()->json([
                'success' => true,
                'message' => 'Stock availability record deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting stock availability record: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete stock availability record',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get current available stock for a customer and product
     */
    public function getCurrentStock(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,id',
                'product_id' => 'required|exists:products,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $customerId = $request->customer_id;
            $productId = $request->product_id;

            $currentStock = StockAvailabilityService::getCurrentAvailableStock($customerId, $productId);

            return response()->json([
                'success' => true,
                'message' => 'Current stock retrieved successfully',
                'data' => [
                    'customer_id' => $customerId,
                    'product_id' => $productId,
                    'current_available_qty' => $currentStock
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving current stock: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve current stock',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Recalculate stock availability for a specific date
     */
    public function recalculate(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'date' => 'required|date',
                'customer_id' => 'nullable|exists:customers,id',
                'product_id' => 'nullable|exists:products,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $date = $request->date;
            $customerId = $request->customer_id;
            $productId = $request->product_id;

            if ($customerId && $productId) {
                // Recalculate for specific customer and product
                StockAvailabilityService::recalculateForCustomerProduct($customerId, $productId, $date, $date);
            } else {
                // Recalculate for entire date
                StockAvailabilityService::recalculateForDate($date);
            }

            return response()->json([
                'success' => true,
                'message' => 'Stock availability recalculated successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error recalculating stock availability: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to recalculate stock availability',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customers list for dropdown
     */
    public function getCustomers(): JsonResponse
    {
        try {
            // Branch dropdown needs group mapping for filtering in UI
            $customers = Customer::select('id', 'name', 'company_name', 'customer_group_id')
                ->where('status', 'active')
                ->orderBy('company_name')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Customers retrieved successfully',
                'data' => $customers
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving customers: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve customers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get product availability data for popup when product is selected
     */
    public function getProductsForAvailabilityPopup(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,id',
                'date' => 'required|date',
                'product_id' => 'nullable|exists:products,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $customerId = $request->customer_id;
            $date = $request->date;
            $productId = $request->product_id;

            // If specific product is selected, show only that product
            if ($productId) {
                $product = Product::where('id', $productId)->where('status', 'active')->first();
                
                if (!$product) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Product not found or inactive'
                    ], 404);
                }

                // Calculate stock data for the specific product
                $openingStock = StockAvailabilityService::getOpeningStock($customerId, $productId, $date);
                $movements = StockAvailabilityService::calculateStockMovements($customerId, $productId, $date);
                $availableQty = $openingStock + $movements['stock_in_qty'] - $movements['stock_out_qty'];

                // Get existing stock used qty if record exists
                $existingRecord = StockAvailability::where('customer_id', $customerId)
                    ->where('product_id', $productId)
                    ->whereDate('date', $date)
                    ->first();
                
                $stockUsedQty = $existingRecord ? $existingRecord->used_qty : 0;

                $productData = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'product_size' => $product->size,
                    'product_unit' => $product->unit,
                    'product_image' => $product->product_image, // Include product image
                    'opening_qty' => $openingStock,
                    'stock_in_qty' => $movements['stock_in_qty'],
                    'stock_out_qty' => $movements['stock_out_qty'],
                    'calculated_available_qty' => $availableQty,
                    'current_available_qty' => $availableQty,
                    'stock_used_qty' => $stockUsedQty,
                    'closing_qty' => $existingRecord ? $existingRecord->closing_qty : $availableQty,
                    'notes' => $existingRecord ? $existingRecord->notes : ''
                ];

                return response()->json([
                    'success' => true,
                    'message' => 'Product availability data retrieved successfully',
                    'data' => [
                        'customer_id' => $customerId,
                        'date' => $date,
                        'product_id' => $productId,
                        'product' => $productData
                    ]
                ]);
            }

            // If no specific product selected, return all products with their availability
            $products = Product::where('status', 'active')->get();
            $availabilityData = [];

            foreach ($products as $product) {
                $openingStock = StockAvailabilityService::getOpeningStock($customerId, $product->id, $date);
                $movements = StockAvailabilityService::calculateStockMovements($customerId, $product->id, $date);
                $availableQty = $openingStock + $movements['stock_in_qty'] - $movements['stock_out_qty'];

                // Get existing stock used qty if record exists
                $existingRecord = StockAvailability::where('customer_id', $customerId)
                    ->where('product_id', $product->id)
                    ->whereDate('date', $date)
                    ->first();
                
                $stockUsedQty = 0;
                if ($existingRecord) {
                    // Calculate stock used from closing qty: stock_used = calculated_available - closing_qty
                    $stockUsedQty = max(0, $existingRecord->calculated_available_qty - $existingRecord->closing_qty);
                }

                $availabilityData[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'product_size' => $product->size,
                    'product_unit' => $product->unit,
                    'product_image' => $product->product_image, // Include product image
                    'opening_qty' => $openingStock,
                    'stock_in_qty' => $movements['stock_in_qty'],
                    'stock_out_qty' => $movements['stock_out_qty'],
                    'calculated_available_qty' => $availableQty,
                    'current_available_qty' => $availableQty,
                    'stock_used_qty' => $stockUsedQty,
                    'closing_qty' => $existingRecord ? $existingRecord->closing_qty : $availableQty,
                    'notes' => $existingRecord ? $existingRecord->notes : ''
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'All products availability data retrieved successfully',
                'data' => [
                    'customer_id' => $customerId,
                    'date' => $date,
                    'products' => $availabilityData
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving popup products data: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve popup products data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Simple test endpoint that always works
     */
    public function testSimple(Request $request): JsonResponse
    {
        try {
            $customerId = $request->get('customer_id', 1);
            $date = $request->get('date', '2025-10-13');

            // Get all active products
            $products = Product::where('status', 'active')->get();

            $availabilityData = [];

            foreach ($products as $product) {
                $availabilityData[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'product_size' => $product->size,
                    'product_unit' => $product->unit,
                    'opening_qty' => 0,
                    'stock_in_qty' => 0,
                    'stock_out_qty' => 0,
                    'calculated_available_qty' => 0,
                    'current_available_qty' => 0,
                    'stock_used_qty' => 0,
                    'closing_qty' => 0,
                    'notes' => ''
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Test data retrieved successfully',
                'data' => [
                            'customer_id' => $customerId,
                            'date' => $date,
                    'products' => $availabilityData
                ]
                        ]);

                } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * Test endpoint to debug stock data
     */
    public function testStockData(Request $request): JsonResponse
    {
        try {
            $customerId = $request->get('customer_id', 1);
            $date = $request->get('date', '2025-10-13');

            // Test StockProduct query
            $stockProducts = StockProduct::with('stock')->get();
            
            // Test Stock query
            $stocks = Stock::where('customer_id', $customerId)
                ->orWhere('from_cust_id', $customerId)
                ->where('t_date', $date)
                ->get();

            // Test Product query
            $products = Product::where('status', 'active')->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'stock_products_count' => $stockProducts->count(),
                    'stocks_count' => $stocks->count(),
                    'products_count' => $products->count(),
                    'stock_products' => $stockProducts->take(5),
                    'stocks' => $stocks->take(5),
                    'products' => $products->take(5)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * Get products list for dropdown
     */
    public function getProducts(): JsonResponse
    {
        try {
            $products = Product::select('id', 'name', 'code', 'size', 'unit')
                ->where('status', 'active')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Products retrieved successfully',
                'data' => $products
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving products: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test endpoint to check if models are working
     */
    public function testModels(): JsonResponse
    {
        try {
            // Test if models can be instantiated
            $stockGroup = new StockAvailabilityGroup();
            $stockAvailability = new StockAvailability();
            
            return response()->json([
                'success' => true,
                'message' => 'All models are working correctly!',
                'models' => [
                    'StockAvailabilityGroup' => class_exists(StockAvailabilityGroup::class),
                    'StockAvailability' => class_exists(StockAvailability::class),
                    'User' => class_exists(User::class)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Model test failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get previous day's closing value for opening stock calculation
     */
    public function getPreviousDayClosing(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,id',
                'product_id' => 'required|exists:products,id',
                'date' => 'required|date'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $customerId = $request->customer_id;
            $productId = $request->product_id;
            $date = $request->date;

            // Get previous day's closing value using the service
            $previousDayClosing = StockAvailabilityService::getOpeningStock($customerId, $productId, $date);

            return response()->json([
                'success' => true,
                'message' => 'Previous day closing value retrieved successfully',
                'data' => [
                    'customer_id' => $customerId,
                    'product_id' => $productId,
                    'date' => $date,
                    'previous_day_closing' => $previousDayClosing
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving previous day closing: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve previous day closing value',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export stock availability to Excel (CSV format)
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        try {
            // Use same logic as index method - get unique customer-date combinations from stocks table
            $stocksQuery = \App\Models\Stock::whereNull('deleted_at')
                ->where('transfer_status', 'sold-out')
                ->select('customer_id', 't_date')
                ->distinct();

            // Apply filters
            if ($request->has('customer_id') && $request->customer_id) {
                $stocksQuery->where('customer_id', $request->customer_id);
            }
            if ($request->has('date_from') && $request->date_from) {
                $stocksQuery->whereDate('t_date', '>=', $request->date_from);
            }
            if ($request->has('date_to') && $request->date_to) {
                $stocksQuery->whereDate('t_date', '<=', $request->date_to);
            }
            
            // Get unique customer-date combinations
            $customerDates = $stocksQuery->get()
                ->map(function($stock) {
                    return [
                        'customer_id' => $stock->customer_id,
                        'date' => \Carbon\Carbon::parse($stock->t_date)->format('Y-m-d')
                    ];
                })
                ->unique(function($item) {
                    return $item['customer_id'] . '_' . $item['date'];
                })
                ->values();
            
            // Build report data (same as index method)
            $stockGroups = [];
            foreach ($customerDates as $customerDate) {
                $customer = \App\Models\Customer::find($customerDate['customer_id']);
                if (!$customer) continue;
                
                // Get stock record for this customer-date
                $stock = \App\Models\Stock::where('customer_id', $customerDate['customer_id'])
                    ->whereDate('t_date', $customerDate['date'])
                    ->where('transfer_status', 'sold-out')
                    ->whereNull('deleted_at')
                    ->first();
                
                // Get sold-out products for this date
                $soldOutProducts = [];
                if ($stock) {
                    $soldOutProducts = \App\Models\StockProduct::where('stock_id', $stock->id)
                        ->where('stock_type', 'sold-out')
                        ->whereNull('deleted_at')
                        ->with('product')
                        ->get();
                }
                
                // Get all products with stock movements for this customer-date
                $productIds = \App\Models\StockProduct::whereHas('stock', function($query) use ($customerDate) {
                    $query->where('customer_id', $customerDate['customer_id'])
                          ->whereDate('t_date', '<=', $customerDate['date'])
                          ->whereNull('deleted_at');
                })
                ->whereNull('deleted_at')
                ->pluck('product_id')
                ->unique();
                
                // Build stock availability records
                $stockAvailabilityRecords = [];
                foreach ($productIds as $productId) {
                    $product = \App\Models\Product::find($productId);
                    if (!$product) continue;

                    $openingStock = StockAvailabilityService::getOpeningStock($customerDate['customer_id'], $productId, $customerDate['date']);
                    $movements = StockAvailabilityService::calculateStockMovements($customerDate['customer_id'], $productId, $customerDate['date']);
                    $availableQty = $openingStock + $movements['stock_in_qty'] - $movements['stock_out_qty'] - $movements['sale_qty'];

                    $soldOutRecord = $soldOutProducts->firstWhere('product_id', $productId);
                    $usedQty = $soldOutRecord ? $soldOutRecord->stock_qty : 0;

                    $stockAvailabilityRecords[] = (object)[
                        'product' => $product,
                        'opening_stock' => $openingStock,
                        'opening_qty' => $openingStock,
                        'stock_in_qty' => $movements['stock_in_qty'],
                        'stock_out_qty' => $movements['stock_out_qty'],
                        'available_qty' => $availableQty,
                        'calculated_available_qty' => $availableQty,
                        'used_qty' => $usedQty,
                        'closing_qty' => $availableQty - $usedQty
                    ];
                }
                
                $time = $stock && $stock->created_at ? \Carbon\Carbon::parse($stock->created_at)->format('H:i:s') : '00:00:00';
                
                $stockGroups[] = (object)[
                    'customer' => $customer,
                    'date' => $customerDate['date'],
                    'time' => $time,
                    'stockAvailabilityRecords' => $stockAvailabilityRecords
                ];
            }
            
            // Search functionality
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $stockGroups = array_filter($stockGroups, function($item) use ($search) {
                    return stripos($item->customer->name, $search) !== false ||
                           stripos($item->customer->company_name, $search) !== false;
                });
                $stockGroups = array_values($stockGroups);
            }
            
            // Sort by date desc
            usort($stockGroups, function($a, $b) {
                return strcmp($b->date, $a->date);
            });

            $filename = 'customer_stock_available_' . date('Y-m-d_His') . '.csv';

            $headers = [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'max-age=0',
            ];

            $callback = function () use ($stockGroups) {
                $output = fopen('php://output', 'w');
                
                // Add BOM for UTF-8
                fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

                // Helper function to escape CSV values
                $escapeCsv = function ($value) {
                    if (is_string($value) && (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n") || str_contains($value, "\r"))) {
                        return '"' . str_replace('"', '""', $value) . '"';
                    }
                    return $value;
                };

                // Header row
                $headers = [
                    'S.No',
                    'Customer',
                    'Date',
                    'Time',
                    'Product Name',
                    'Product Code',
                    'Opening Stock',
                    'Stock In',
                    'Stock Out',
                    'Available Stock',
                    'Closing Stock'
                ];
                fputcsv($output, array_map($escapeCsv, $headers));

                // Data rows
                $sno = 1;
                foreach ($stockGroups as $group) {
                    $customerName = $group->customer ? ($group->customer->company_name ?? $group->customer->name) : 'N/A';
                    $date = $group->date ? date('d/m/Y', strtotime($group->date)) : 'N/A';
                    $time = $group->time ?? 'N/A';

                    if ($group->stockAvailabilityRecords && count($group->stockAvailabilityRecords) > 0) {
                        // Each product gets its own row with incrementing serial number
                        foreach ($group->stockAvailabilityRecords as $record) {
                            $productName = $record->product ? $record->product->name : 'N/A';
                            $productCode = $record->product ? ($record->product->code ?? 'N/A') : 'N/A';
                            $openingStock = $record->opening_stock ?? 0;
                            $stockIn = $record->stock_in_qty ?? 0;
                            $stockOut = $record->stock_out_qty ?? 0;
                            $availableStock = $record->available_qty ?? 0;
                            $closingStock = $record->closing_qty ?? 0;

                            $row = [
                                $sno,
                                $customerName,
                                $date,
                                $time,
                                $productName,
                                $productCode,
                                $openingStock,
                                $stockIn,
                                $stockOut,
                                $availableStock,
                                $closingStock
                            ];
                            fputcsv($output, array_map($escapeCsv, $row));
                            $sno++; // Increment serial number for each product row
                        }
                    } else {
                        // No products, just one row
                        $row = [
                            $sno,
                            $customerName,
                            $date,
                            $time,
                            'No Products',
                            '',
                            '',
                            '',
                            '',
                            '',
                            ''
                        ];
                        fputcsv($output, array_map($escapeCsv, $row));
                        $sno++; // Increment serial number
                    }
                }
                fclose($output);
            };

            return new StreamedResponse($callback, 200, $headers);

        } catch (\Exception $e) {
            Log::error('Error exporting stock availability to Excel: ' . $e->getMessage());
            // Return empty CSV with error message
            $filename = 'error_' . date('Y-m-d_His') . '.csv';
            $headers = [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ];
            $callback = function () use ($e) {
                $output = fopen('php://output', 'w');
                fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
                fputcsv($output, ['Error', 'Failed to export stock availability: ' . $e->getMessage()]);
                fclose($output);
            };
            return new StreamedResponse($callback, 500, $headers);
        }
    }

    /**
     * Export stock availability to PDF
     */
    public function exportPdf(Request $request): Response
    {
        try {
            // Use same logic as index method - get unique customer-date combinations from stocks table
            $stocksQuery = \App\Models\Stock::whereNull('deleted_at')
                ->where('transfer_status', 'sold-out')
                ->select('customer_id', 't_date')
                ->distinct();

            // Apply filters
            if ($request->has('customer_id') && $request->customer_id) {
                $stocksQuery->where('customer_id', $request->customer_id);
            }
            if ($request->has('date_from') && $request->date_from) {
                $stocksQuery->whereDate('t_date', '>=', $request->date_from);
            }
            if ($request->has('date_to') && $request->date_to) {
                $stocksQuery->whereDate('t_date', '<=', $request->date_to);
            }
            
            // Get unique customer-date combinations
            $customerDates = $stocksQuery->get()
                ->map(function($stock) {
                    return [
                        'customer_id' => $stock->customer_id,
                        'date' => \Carbon\Carbon::parse($stock->t_date)->format('Y-m-d')
                    ];
                })
                ->unique(function($item) {
                    return $item['customer_id'] . '_' . $item['date'];
                })
                ->values();
            
            // Build report data (same as index method)
            $stockGroups = [];
            foreach ($customerDates as $customerDate) {
                $customer = \App\Models\Customer::find($customerDate['customer_id']);
                if (!$customer) continue;
                
                // Get stock record for this customer-date
                $stock = \App\Models\Stock::where('customer_id', $customerDate['customer_id'])
                    ->whereDate('t_date', $customerDate['date'])
                    ->where('transfer_status', 'sold-out')
                    ->whereNull('deleted_at')
                    ->first();
                
                // Get sold-out products for this date
                $soldOutProducts = [];
                if ($stock) {
                    $soldOutProducts = \App\Models\StockProduct::where('stock_id', $stock->id)
                        ->where('stock_type', 'sold-out')
                        ->whereNull('deleted_at')
                        ->with('product')
                        ->get();
                }
                
                // Get all products with stock movements for this customer-date
                $productIds = \App\Models\StockProduct::whereHas('stock', function($query) use ($customerDate) {
                    $query->where('customer_id', $customerDate['customer_id'])
                          ->whereDate('t_date', '<=', $customerDate['date'])
                          ->whereNull('deleted_at');
                })
                ->whereNull('deleted_at')
                ->pluck('product_id')
                ->unique();
                
                // Build stock availability records
                $stockAvailabilityRecords = [];
                foreach ($productIds as $productId) {
                    $product = \App\Models\Product::find($productId);
                    if (!$product) continue;

                    $openingStock = StockAvailabilityService::getOpeningStock($customerDate['customer_id'], $productId, $customerDate['date']);
                    $movements = StockAvailabilityService::calculateStockMovements($customerDate['customer_id'], $productId, $customerDate['date']);
                    $availableQty = $openingStock + $movements['stock_in_qty'] - $movements['stock_out_qty'] - $movements['sale_qty'];

                    $soldOutRecord = $soldOutProducts->firstWhere('product_id', $productId);
                    $usedQty = $soldOutRecord ? $soldOutRecord->stock_qty : 0;

                    $stockAvailabilityRecords[] = (object)[
                        'product' => $product,
                        'opening_stock' => $openingStock,
                        'opening_qty' => $openingStock,
                        'stock_in_qty' => $movements['stock_in_qty'],
                        'stock_out_qty' => $movements['stock_out_qty'],
                        'available_qty' => $availableQty,
                        'calculated_available_qty' => $availableQty,
                        'used_qty' => $usedQty,
                        'closing_qty' => $availableQty - $usedQty
                    ];
                }
                
                $time = $stock && $stock->created_at ? \Carbon\Carbon::parse($stock->created_at)->format('H:i:s') : '00:00:00';
                
                $stockGroups[] = (object)[
                    'customer' => $customer,
                    'date' => $customerDate['date'],
                    'time' => $time,
                    'stockAvailabilityRecords' => $stockAvailabilityRecords
                ];
            }
            
            // Search functionality
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $stockGroups = array_filter($stockGroups, function($item) use ($search) {
                    return stripos($item->customer->name, $search) !== false ||
                           stripos($item->customer->company_name, $search) !== false;
                });
                $stockGroups = array_values($stockGroups);
            }
            
            // Sort by date desc
            usort($stockGroups, function($a, $b) {
                return strcmp($b->date, $a->date);
            });

            // Generate HTML content for PDF
            $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>EBMS - Customer Stock Available Today Report</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
        th { background-color: #343a40; color: white; font-weight: bold; }
        .main-row { background-color: #f8f9fa; font-weight: bold; }
        .child-row { background-color: #ffffff; }
        .child-row td:first-child { border-left: 3px solid #007bff; }
        .report-title { text-align: center; font-size: 18px; font-weight: bold; margin-bottom: 5px; }
        .report-subtitle { text-align: center; font-size: 14px; color: #6c757d; margin-bottom: 10px; }
        .report-meta { text-align: center; font-size: 9px; color: #6c757d; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="report-title">EBMS</div>
    <div class="report-subtitle">Customer Stock Available Today Report</div>
    <div class="report-meta">Generated on: ' . date('d/m/Y H:i:s') . '</div>
    <table>
        <thead>
            <tr>
                <th>S.No</th>
                <th>Customer</th>
                <th>Date</th>
                <th>Time</th>
                <th>Product Name</th>
                <th>Product Code</th>
                <th>Opening Stock</th>
                <th>Stock In</th>
                <th>Stock Out</th>
                <th>Available Stock</th>
                <th>Closing Stock</th>
            </tr>
        </thead>
        <tbody>';

            $sno = 1;
            foreach ($stockGroups as $group) {
                $customerName = htmlspecialchars($group->customer ? ($group->customer->company_name ?? $group->customer->name) : 'N/A');
                $date = $group->date ? date('d/m/Y', strtotime($group->date)) : 'N/A';
                $time = htmlspecialchars($group->time ?? 'N/A');

                if ($group->stockAvailabilityRecords && count($group->stockAvailabilityRecords) > 0) {
                    // Each product gets its own row with incrementing serial number
                    foreach ($group->stockAvailabilityRecords as $record) {
                        $productName = htmlspecialchars($record->product ? $record->product->name : 'N/A');
                        $productCode = htmlspecialchars($record->product ? ($record->product->code ?? 'N/A') : 'N/A');
                        $openingStock = $record->opening_stock ?? 0;
                        $stockIn = $record->stock_in_qty ?? 0;
                        $stockOut = $record->stock_out_qty ?? 0;
                        $availableStock = $record->available_qty ?? 0;
                        $closingStock = $record->closing_qty ?? 0;

                        $html .= '<tr class="main-row">
                            <td>' . $sno . '</td>
                            <td>' . $customerName . '</td>
                            <td>' . $date . '</td>
                            <td>' . $time . '</td>
                            <td>' . $productName . '</td>
                            <td>' . $productCode . '</td>
                            <td>' . $openingStock . '</td>
                            <td>' . $stockIn . '</td>
                            <td>' . $stockOut . '</td>
                            <td>' . $availableStock . '</td>
                            <td>' . $closingStock . '</td>
                        </tr>';
                        $sno++; // Increment serial number for each product row
                    }
                } else {
                    $html .= '<tr class="main-row">
                        <td>' . $sno . '</td>
                        <td>' . $customerName . '</td>
                        <td>' . $date . '</td>
                        <td>' . $time . '</td>
                        <td>No Products</td>
                        <td></td><td></td><td></td><td></td><td></td><td></td>
                    </tr>';
                    $sno++; // Increment serial number
                }
            }

            $html .= '      </tbody>
        </table>
    </body>
    </html>';

            return response($html, 200)
                ->header('Content-Type', 'text/html')
                ->header('Content-Disposition', 'inline; filename="customer_stock_available_' . date('Y-m-d_His') . '.pdf"');

        } catch (\Exception $e) {
            Log::error('Error exporting stock availability to PDF: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export stock availability to PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}