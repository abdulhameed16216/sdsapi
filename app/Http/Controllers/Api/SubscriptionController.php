<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SubscriptionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $search = $request->get('search');
        $status = $request->get('status');

        $query = Subscription::with('user');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('short_description', 'like', "%{$search}%")
                  ->orWhere('main_description', 'like', "%{$search}%");
            });
        }

        if ($status !== null) {
            $query->where('status', (int)$status);
        }

        $subscriptions = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // Convert status integers to strings for frontend
        // Get the paginator as array first
        $responseArray = $subscriptions->toArray();
        
        // Convert all status values from integer to string in the data array
        if (isset($responseArray['data']) && is_array($responseArray['data'])) {
            foreach ($responseArray['data'] as $key => &$item) {
                if (isset($item['status'])) {
                    // Always convert status to string, regardless of current type
                    $statusInt = is_int($item['status']) ? $item['status'] : (int)$item['status'];
                    $item['status'] = $this->getStatusString($statusInt);
                }
            }
            unset($item); // Break reference
        }

        return response()->json([
            'success' => true,
            'data' => $responseArray
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'short_description' => 'nullable|string',
            'main_description' => 'nullable|string',
            'start_price' => 'nullable|numeric|min:0',
            'end_price' => 'nullable|numeric|min:0|gte:start_price',
            'price_unit' => 'nullable|string|max:50',
            'sections' => 'nullable|array',
            'sections.*.title' => 'nullable|string|max:255',
            'sections.*.items' => 'nullable|array',
            'sections.*.items.*' => 'nullable|string',
            'status' => 'integer|in:0,1,2', // 0 = Draft, 1 = Published, 2 = Archived
            'is_visible_on_website' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Convert status string to integer if needed
        $status = $request->status;
        if (is_string($status)) {
            $statusMap = ['draft' => 0, 'published' => 1, 'archived' => 2];
            $status = $statusMap[$status] ?? 0;
        }

        $subscription = Subscription::create([
            'title' => $request->title,
            'short_description' => $request->short_description,
            'main_description' => $request->main_description,
            'start_price' => $request->start_price,
            'end_price' => $request->end_price,
            'price_unit' => $request->price_unit ?? 'month',
            'sections' => $request->sections ?? [],
            'status' => $status ?? 0, // Default to draft (0)
            'is_visible_on_website' => $request->has('is_visible_on_website') ? (bool)$request->is_visible_on_website : true, // Default to true
            'user_id' => auth('api')->id(),
        ]);

        // Convert status to string for response
        $subscriptionData = $subscription->load('user')->toArray();
        $rawStatus = $subscription->getRawOriginal('status');
        $subscriptionData['status'] = $this->getStatusString($rawStatus);

        return response()->json([
            'success' => true,
            'message' => 'Subscription created successfully',
            'data' => $subscriptionData
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $subscription = Subscription::with('user')->find($id);

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'Subscription not found'
            ], 404);
        }

        // Convert status integer to string for frontend
        // Get raw status value to avoid casting issues
        $rawStatus = $subscription->getRawOriginal('status');
        $statusString = $this->getStatusString($rawStatus);
        
        // Convert to array and manually set status as string
        $subscriptionData = $subscription->toArray();
        $subscriptionData['status'] = $statusString;

        return response()->json([
            'success' => true,
            'data' => $subscriptionData
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $subscription = Subscription::find($id);

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'Subscription not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'short_description' => 'nullable|string',
            'main_description' => 'nullable|string',
            'start_price' => 'nullable|numeric|min:0',
            'end_price' => 'nullable|numeric|min:0|gte:start_price',
            'price_unit' => 'nullable|string|max:50',
            'sections' => 'nullable|array',
            'sections.*.title' => 'nullable|string|max:255',
            'sections.*.items' => 'nullable|array',
            'sections.*.items.*' => 'nullable|string',
            'status' => 'integer|in:0,1,2',
            'is_visible_on_website' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Convert status string to integer if needed
        $status = $request->status;
        if (is_string($status)) {
            $statusMap = ['draft' => 0, 'published' => 1, 'archived' => 2];
            $status = $statusMap[$status] ?? $subscription->status;
        }

        $subscription->update([
            'title' => $request->title,
            'short_description' => $request->short_description,
            'main_description' => $request->main_description,
            'start_price' => $request->start_price,
            'end_price' => $request->end_price,
            'price_unit' => $request->price_unit ?? $subscription->price_unit,
            'sections' => $request->sections ?? $subscription->sections,
            'status' => $status ?? $subscription->status,
            'is_visible_on_website' => $request->has('is_visible_on_website') ? (bool)$request->is_visible_on_website : $subscription->is_visible_on_website,
        ]);

        // Reload to get fresh data
        $subscription->refresh();
        
        // Convert status to string for response
        $subscriptionData = $subscription->load('user')->toArray();
        $rawStatus = $subscription->getRawOriginal('status');
        $subscriptionData['status'] = $this->getStatusString($rawStatus);

        return response()->json([
            'success' => true,
            'message' => 'Subscription updated successfully',
            'data' => $subscriptionData
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $subscription = Subscription::find($id);

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'Subscription not found'
            ], 404);
        }

        $subscription->delete();

        return response()->json([
            'success' => true,
            'message' => 'Subscription deleted successfully'
        ]);
    }

    /**
     * Update subscription status.
     */
    public function updateStatus(Request $request, $id)
    {
        $subscription = Subscription::find($id);

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'Subscription not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|integer|in:0,1,2',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $subscription->update([
            'status' => $request->status
        ]);

        // Reload to get fresh data
        $subscription->refresh();
        
        // Convert status to string for response
        $subscriptionData = $subscription->load('user')->toArray();
        $rawStatus = $subscription->getRawOriginal('status');
        $subscriptionData['status'] = $this->getStatusString($rawStatus);

        return response()->json([
            'success' => true,
            'message' => 'Subscription status updated successfully',
            'data' => $subscriptionData
        ]);
    }

    /**
     * Public index - Display a listing of published subscriptions (for website).
     */
    public function publicIndex(Request $request)
    {
        $limit = $request->get('limit');
        $search = $request->get('search');

        $query = Subscription::where('status', 1) // Only published subscriptions
            ->where('is_visible_on_website', true); // Only visible on website

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('short_description', 'like', "%{$search}%")
                  ->orWhere('main_description', 'like', "%{$search}%");
            });
        }

        $query->orderBy('created_at', 'desc');

        if ($limit) {
            $subscriptions = $query->limit((int)$limit)->get();
        } else {
            $subscriptions = $query->get();
        }

        return response()->json([
            'success' => true,
            'data' => $subscriptions
        ]);
    }

    /**
     * Public show - Display the specified published subscription (for website).
     */
    public function publicShow($id)
    {
        $subscription = Subscription::where('id', $id)
            ->where('status', 1) // Only published subscriptions
            ->where('is_visible_on_website', true) // Only visible on website
            ->first();

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'Subscription not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $subscription
        ]);
    }

    /**
     * Convert status integer to string.
     */
    private function getStatusString($status)
    {
        $statusMap = [0 => 'draft', 1 => 'published', 2 => 'archived'];
        return $statusMap[$status] ?? 'draft';
    }
}

