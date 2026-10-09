<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Renewal;
use Carbon\Carbon;
use Illuminate\Http\Request;

class RenewalController extends Controller
{
    /**
     * List all renewals with search, filter by status/product, date range, and pagination.
     *
     * Query params:
     *   search         - search by client_name or brand_name
     *   renewal_status - Upcoming | Completed | Overdue | Cancelled
     *   product_id     - filter by product
     *   date_from      - filter renewals from date (YYYY-MM-DD)
     *   date_to        - filter renewals to date (YYYY-MM-DD)
     *   per_page       - rows per page (default 10)
     *   page           - page number (default 1)
     *   sort_by        - column to sort (default: renewal_date)
     *   sort_dir       - asc | desc (default: desc)
     */
    public function __construct()
    {
        $this->middleware('permission:Renewals,VIEW')->only(['index', 'show']);
        $this->middleware('permission:Renewals,ADD')->only(['store', 'createRenewalForClient']);
        $this->middleware('permission:Renewals,EDIT')->only(['update']);
        $this->middleware('permission:Renewals,DELETE')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $perPage       = (int) $request->get('per_page', 10);
        $page          = (int) $request->get('page', 1);
        $search        = $request->get('search', '');
        $renewalStatus = $request->get('renewal_status', '');
        $productId     = $request->get('product_id', '');
        $dateFrom      = $request->get('date_from', '');
        $dateTo        = $request->get('date_to', '');
        $sortBy        = $request->get('sort_by', 'renewal_date');
        $sortDir       = in_array(strtolower($request->get('sort_dir', 'desc')), ['asc', 'desc'])
                            ? strtolower($request->get('sort_dir', 'desc'))
                            : 'desc';

        $query = Renewal::with(['client', 'client.product', 'client.project', 'client.membership', 'client.setup'])
            ->orderBy($sortBy, $sortDir);

        // Search by client name or brand name
        if ($search) {
            $query->whereHas('client', function ($q) use ($search) {
                $q->where('client_name', 'like', "%{$search}%")
                  ->orWhere('brand_name', 'like', "%{$search}%");
            });
        }

        // Filter by renewal status
        if ($renewalStatus) {
            $query->where('renewal_status', $renewalStatus);
        }

        // Filter by product
        if ($productId) {
            $query->whereHas('client', function ($q) use ($productId) {
                $q->where('product_id', $productId);
            });
        }

        // Date range filter on renewal_date
        if ($dateFrom) {
            $query->where('renewal_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('renewal_date', '<=', $dateTo);
        }

        $total    = $query->count();
        $renewals = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

        // Summary counts for dashboard cards
        $upcomingCount  = Renewal::where('renewal_status', 'Upcoming')->count();
        $overdueCount   = Renewal::where('renewal_status', 'Overdue')->count();
        $completedCount = Renewal::where('renewal_status', 'Completed')->count();

        return response()->json([
            'success' => true,
            'data'    => $renewals,
            'total'   => $total,
            'page'    => $page,
            'summary' => [
                'upcoming'  => $upcomingCount,
                'overdue'   => $overdueCount,
                'completed' => $completedCount,
                'total'     => Renewal::count(),
            ],
            'pagination' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
            ],
        ], 200);
    }

    /**
     * Get a single renewal record.
     */
    public function show($id)
    {
        // $id is the client_id from clients/upcoming-renewals
        $client = \App\Models\Client::with(['product', 'project', 'membership', 'setup'])->find($id);

        if (!$client) {
            return response()->json(['success' => false, 'message' => 'Client not found.'], 404);
        }

        // Try to find an existing pending renewal
        $renewal = Renewal::where('client_id', $client->id)
            ->whereIn('renewal_status', ['Upcoming', 'Overdue'])
            ->first();

        // If no renewal record exists yet, mock one in memory for the frontend
        if (!$renewal) {
            $renewalAmount = 0;
            if (is_array($client->renewal_amount) && !empty($client->renewal_amount)) {
                $renewalAmount = (float) array_sum($client->renewal_amount);
            } elseif (is_numeric($client->renewal_amount)) {
                $renewalAmount = (float) $client->renewal_amount;
            }

            $renewal = new Renewal([
                'id'                => null,
                'client_id'         => $client->id,
                'renewal_status'    => 'Upcoming',
                'amount'            => $renewalAmount,
                'previous_end_date' => $client->expiry_date,
                'renewal_date'      => null,
                'new_end_date'      => null,
                'notes'             => null,
            ]);
        }
        $renewal->setRelation('client', $client);

        return response()->json([
            'success' => true,
            'data'    => $renewal,
        ], 200);
    }

    /**
     * Create / record a new renewal for a client.
     *
     * Body params:
     *   client_id         - required
     *   renewal_status    - Upcoming | Completed | Overdue | Cancelled
     *   amount            - renewal amount
     *   previous_end_date - old expiry date (YYYY-MM-DD)
     *   renewal_date      - when renewal was processed (YYYY-MM-DD)
     *   new_end_date      - new expiry date after renewal (YYYY-MM-DD)
     *   notes             - optional
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id'         => 'required|integer|exists:clients,id',
            'renewal_status'    => 'required|string|in:Upcoming,Completed,Overdue,Cancelled',
            'amount'            => 'nullable|numeric|min:0',
            'previous_end_date' => 'nullable|date',
            'renewal_date'      => 'nullable|date',
            'new_end_date'      => 'nullable|date',
            'notes'             => 'nullable|string|max:1000',
        ]);

        $renewal = Renewal::create($validated);

        // If completed, also update client expiry_date and status
        if ($validated['renewal_status'] === 'Completed' && !empty($validated['new_end_date'])) {
            $client = Client::find($validated['client_id']);
            if ($client) {
                $client->update([
                    'expiry_date' => $validated['new_end_date'],
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Renewal created successfully.',
            'data'    => $renewal->load('client.product'),
        ], 201);
    }

    /**
     * Update an existing renewal record.
     */
    public function update(Request $request, $id)
    {
        $renewal = Renewal::find($id);

        if (!$renewal) {
            return response()->json(['success' => false, 'message' => 'Renewal not found.'], 404);
        }

        $validated = $request->validate([
            'renewal_status'    => 'sometimes|string|in:Upcoming,Completed,Overdue,Cancelled',
            'amount'            => 'sometimes|numeric|min:0',
            'previous_end_date' => 'sometimes|nullable|date',
            'renewal_date'      => 'sometimes|nullable|date',
            'new_end_date'      => 'sometimes|nullable|date',
            'notes'             => 'sometimes|nullable|string|max:1000',
        ]);

        $renewal->update($validated);

        // If marked completed, update client expiry_date and status
        if (isset($validated['renewal_status']) && $validated['renewal_status'] === 'Completed') {
            $newEnd = $validated['new_end_date'] ?? $renewal->new_end_date;
            if ($newEnd) {
                $renewal->client->update([
                    'expiry_date' => $newEnd,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Renewal updated successfully.',
            'data'    => $renewal->fresh()->load('client.product'),
        ], 200);
    }

    /**
     * Process renewal — quickly mark as Completed and set new_end_date.
     * POST /api/renewals/{id}/process
     *
     * Body params:
     *   renewal_date - date renewal was processed (optional, defaults to today)
     *   new_end_date - new expiry date (required)
     *   notes        - optional
     */
    public function process(Request $request, $id)
    {
        // $id is the client_id
        $client = \App\Models\Client::find($id);

        if (!$client) {
            return response()->json(['success' => false, 'message' => 'Client not found.'], 404);
        }

        $validated = $request->validate([
            'renewal_date'   => 'nullable|date',
            'new_end_date'   => 'required|date',
            'amount'         => 'required|numeric',
            'payment_method' => 'nullable|string',
            'notes'          => 'nullable|string|max:1000',
        ]);

        $paymentMethod = $validated['payment_method'] ?? 'Manual';
        $isOnlinePayment = in_array(strtolower($paymentMethod), ['razorpay link', 'online']);

        // Find existing pending renewal or create a new one
        $renewal = Renewal::where('client_id', $client->id)
            ->whereIn('renewal_status', ['Upcoming', 'Overdue'])
            ->first();

        $statusToSet = $isOnlinePayment ? 'Pending' : 'Completed';

        if ($renewal) {
            $renewal->update([
                'renewal_status' => $statusToSet,
                'renewal_date'   => $validated['renewal_date'] ?? Carbon::today()->toDateString(),
                'new_end_date'   => $validated['new_end_date'],
                'amount'         => $validated['amount'],
                'notes'          => $validated['notes'] ?? $renewal->notes,
            ]);
        } else {
            $renewal = Renewal::create([
                'client_id'         => $client->id,
                'renewal_status'    => $statusToSet,
                'amount'            => $validated['amount'],
                'previous_end_date' => $client->expiry_date,
                'renewal_date'      => $validated['renewal_date'] ?? Carbon::today()->toDateString(),
                'new_end_date'      => $validated['new_end_date'],
                'notes'             => $validated['notes'] ?? null,
            ]);
        }

        // Only update client expiry and create a paid transaction if this is a manual/cash completion
        if (!$isOnlinePayment && $renewal->client) {
            $renewal->client->update([
                'expiry_date' => $validated['new_end_date'],
            ]);

            // Create Transaction for this renewal
            \App\Models\Transaction::create([
                'client_id'      => $renewal->client_id,
                'amount'         => $validated['amount'],
                'payment_method' => $paymentMethod,
                'payment_type'   => 'renewal',
                'status'         => 'paid',
                'currency'       => 'INR',
                'user_id'        => auth()->id(),
                'description'    => 'Renewal processed for client ' . $renewal->client->client_name,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Renewal processed successfully.',
            'data'    => $renewal->fresh()->load(['client.product', 'client.membership', 'client.setup']),
        ], 200);
    }

    /**
     * Delete a renewal record.
     */
    public function destroy($id)
    {
        $renewal = Renewal::find($id);

        if (!$renewal) {
            return response()->json(['success' => false, 'message' => 'Renewal not found.'], 404);
        }

        $renewal->delete();

        return response()->json([
            'success' => true,
            'message' => 'Renewal deleted successfully.',
        ], 200);
    }

    /**
     * Auto-detect upcoming/overdue renewals from clients table
     * and generate renewal records for clients without one.
     * GET /api/renewals/sync-from-clients
     */
    public function syncFromClients()
    {
        $clients = Client::whereNotNull('expiry_date')
            ->whereDoesntHave('renewals')
            ->get();

        $created = 0;

        foreach ($clients as $client) {
            $expiryDate = Carbon::parse($client->expiry_date);
            $today      = Carbon::today();

            // Determine status
            if ($expiryDate->isPast()) {
                $status = 'Overdue';
            } elseif ($expiryDate->diffInDays($today, false) >= -30) {
                $status = 'Upcoming';
            } else {
                $status = 'Upcoming';
            }

            // Get renewal amount (first value from array or flat value)
            $renewalAmount = 0;
            if (is_array($client->renewal_amount) && !empty($client->renewal_amount)) {
                $renewalAmount = (float) array_sum($client->renewal_amount);
            } elseif (is_numeric($client->renewal_amount)) {
                $renewalAmount = (float) $client->renewal_amount;
            }

            Renewal::create([
                'client_id'         => $client->id,
                'renewal_status'    => $status,
                'amount'            => $renewalAmount,
                'previous_end_date' => $client->start_date,
                'renewal_date'      => null,
                'new_end_date'      => $expiryDate->copy()->addYear()->toDateString(),
                'notes'             => null,
            ]);

            $created++;
        }

        return response()->json([
            'success' => true,
            'message' => "{$created} renewal record(s) synced from clients.",
            'created' => $created,
        ], 200);
    }
}
