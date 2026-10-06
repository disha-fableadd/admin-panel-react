<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Membership;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Full Reports page data (summary counts + revenue overview + membership status + renewal analysis).
     * Optional filter: product_id
     */
    public function summary(Request $request)
    {
        $period    = $request->get('period', 'month'); // daily, weekly, monthly
        $productId = $request->get('product_id');

        // Base query for clients
        $clientQuery = Client::query();
        if ($productId) {
            $clientQuery->where('product_id', $productId);
        }

        // Base query for memberships
        $membershipQuery = Membership::query();
        if ($productId) {
            $membershipQuery->where('product_id', $productId);
        }

        // Base query for transactions
        $transactionQuery = Transaction::query();
        if ($productId) {
            $transactionQuery->whereHas('client', function ($q) use ($productId) {
                $q->where('product_id', $productId);
            });
        }

        // --- Summary Counts ---
        $totalClients = (clone $clientQuery)->count();

        $activeStaff = User::whereHas('roleModel', function ($q) {
            $q->where('name', '!=', 'Super Admin');
        })->where('status', 'Active')->count();

        $activeMemberships = (clone $membershipQuery)->where('status', 'Active')->count();

        $expiringMemberships = (clone $clientQuery)->where('status', 'Active')
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [Carbon::now(), Carbon::now()->addDays(30)])
            ->count();

        $renewals = (clone $clientQuery)->where('status', 'Renewed')->count();

        $totalTransactions     = (clone $transactionQuery)->count();
        $totalRevenue          = (clone $transactionQuery)->whereIn('status', ['captured', 'paid'])->sum('amount');
        $failedPendingPayments = (clone $transactionQuery)->whereIn('status', ['failed', 'created', 'authorized', 'pending'])->count();

        // --- Revenue Overview by period ---
        $revenueOverview = $this->getRevenueOverview($period, $productId);

        // Transaction status breakdown
        $successful = (clone $transactionQuery)->whereIn('status', ['captured', 'paid'])->count();
        $pending    = (clone $transactionQuery)->whereIn('status', ['created', 'authorized', 'pending'])->count();
        $failed     = (clone $transactionQuery)->where('status', 'failed')->count();
        $refunded   = (clone $transactionQuery)->where('status', 'refunded')->sum('amount');

        // --- Membership Status Distribution ---
        $membershipStatus = [
            'active'         => (clone $clientQuery)->where('status', 'Active')->count(),
            'expiring_soon'  => (clone $clientQuery)->where('status', 'Active')
                ->whereNotNull('expiry_date')
                ->whereBetween('expiry_date', [Carbon::now(), Carbon::now()->addDays(30)])
                ->count(),
            'expired'        => (clone $clientQuery)->where('status', 'Expired')->count(),
            'cancelled'      => (clone $clientQuery)->where('status', 'Cancelled')->count(),
        ];
        $membershipTotal = array_sum($membershipStatus);

        // --- Renewal Analysis ---
        $totalRenewals  = (clone $clientQuery)->where('status', 'Renewed')->count();
        $upcoming       = (clone $clientQuery)->where('status', 'Active')
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [Carbon::now(), Carbon::now()->addDays(30)])
            ->count();
        $renewed        = (clone $clientQuery)->where('status', 'Renewed')->count();
        $notRenewed     = (clone $clientQuery)->where('status', 'Expired')->count();
        $renewalRate    = ($totalRenewals + $notRenewed) > 0
            ? round(($renewed / ($renewed + $notRenewed)) * 100, 1)
            : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'total_clients'           => $totalClients,
                    'active_staff'            => $activeStaff,
                    'active_memberships'      => $activeMemberships,
                    'expiring_memberships'    => $expiringMemberships,
                    'renewals'                => $renewals,
                    'total_transactions'      => $totalTransactions,
                    'total_revenue'           => $totalRevenue,
                    'failed_pending_payments' => $failedPendingPayments,
                ],
                'revenue_overview' => [
                    'total_revenue' => $totalRevenue,
                    'successful'    => $successful,
                    'pending'       => $pending,
                    'failed'        => $failed,
                    'refunded'      => $refunded,
                    'chart_data'    => $revenueOverview,
                ],
                'membership_status' => [
                    'total'          => $membershipTotal,
                    'active'         => $membershipStatus['active'],
                    'expiring_soon'  => $membershipStatus['expiring_soon'],
                    'expired'        => $membershipStatus['expired'],
                    'cancelled'      => $membershipStatus['cancelled'],
                ],
                'renewal_analysis' => [
                    'total_renewals' => $totalRenewals,
                    'upcoming'       => $upcoming,
                    'renewed'        => $renewed,
                    'not_renewed'    => $notRenewed,
                    'renewal_rate'   => $renewalRate,
                ],
            ],
        ], 200);
    }

    /**
     * Membership Plan Performance — default 5 per page, with proper pagination.
     * Optional filter: product_id
     */
    public function __construct()
    {
        $this->middleware('permission:Reports,VIEW');
    }

    public function membershipPlanPerformance(Request $request)
    {
        $perPage   = (int) $request->get('per_page', 5);
        $page      = (int) $request->get('page', 1);
        $productId = $request->get('product_id', '');

        $query = Membership::withCount([
            'clients as total_clients'    => fn($q) => $productId ? $q->where('product_id', $productId) : $q,
            'clients as active_clients'   => fn($q) => $productId
                ? $q->where('product_id', $productId)->where('status', 'Active')
                : $q->where('status', 'Active'),
            'clients as expired_clients'  => fn($q) => $productId
                ? $q->where('product_id', $productId)->where('status', 'Expired')
                : $q->where('status', 'Expired'),
            'clients as renewed_clients'  => fn($q) => $productId
                ? $q->where('product_id', $productId)->where('status', 'Renewed')
                : $q->where('status', 'Renewed'),
        ])
        ->orderByDesc('total_clients');

        // If product_id filter is set, only show memberships that have clients with that product
        if ($productId) {
            $query->whereHas('clients', fn($q) => $q->where('product_id', $productId));
        }

        $total       = $query->count();
        $memberships = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

        $data = $memberships->map(function ($membership) use ($productId) {
            // Revenue = sum of captured transactions for clients on this plan (filtered by product_id if set)
            $revenue = Transaction::whereHas('client', function ($q) use ($membership, $productId) {
                $q->where('membership_id', $membership->id);
                if ($productId) {
                    $q->where('product_id', $productId);
                }
            })->where('status', 'captured')->sum('amount');

            $renewedCount = $membership->renewed_clients ?? 0;
            $expiredCount = $membership->expired_clients ?? 0;
            $renewalRate  = ($renewedCount + $expiredCount) > 0
                ? round(($renewedCount / ($renewedCount + $expiredCount)) * 100)
                : 0;

            return [
                'id'           => $membership->id,
                'plan_name'    => $membership->plan_name ?? $membership->billing_title ?? 'Unknown Plan',
                'total'        => $membership->total_clients ?? 0,
                'active'       => $membership->active_clients ?? 0,
                'expired'      => $membership->expired_clients ?? 0,
                'renewals'     => $membership->renewed_clients ?? 0,
                'revenue'      => (float) $revenue,
                'renewal_rate' => $renewalRate,
            ];
        });

        return response()->json([
            'success'    => true,
            'data'       => $data,
            'pagination' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
            ],
        ], 200);
    }


    /**
     * Transaction Report — latest 5 by default, with search/filter & pagination.
     */
    public function transactionReport(Request $request)
    {
        $limit  = (int) $request->get('limit', 5);
        $page   = (int) $request->get('page', 1);
        $search = $request->get('search', '');
        $status = $request->get('status', '');
        $method = $request->get('method', '');

        $query = Transaction::with(['client', 'client.membership'])
            ->latest();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('razorpay_payment_id', 'like', "%{$search}%")
                  ->orWhereHas('client', function ($cq) use ($search) {
                      $cq->where('client_name', 'like', "%{$search}%")
                         ->orWhere('brand_name', 'like', "%{$search}%");
                  });
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($method) {
            $query->where('payment_method', $method);
        }

        $productId = $request->get('product_id', '');
        if ($productId) {
            $query->whereHas('client', function ($q) use ($productId) {
                $q->where('product_id', $productId);
            });
        }

        $total       = $query->count();
        $transactions = $query->offset(($page - 1) * $limit)->limit($limit)->get();

        $data = $transactions->map(function ($txn) {
            $client      = $txn->client;
            $membership  = $client?->membership;

            return [
                'id'               => $txn->id,
                'transaction_id'   => $txn->razorpay_payment_id
                    ? 'TXN-' . strtoupper(substr($txn->razorpay_payment_id, -6))
                    : 'TXN-' . str_pad($txn->id, 5, '0', STR_PAD_LEFT),
                'client_name'      => $client?->client_name ?? $client?->brand_name ?? 'N/A',
                'plan_name'        => $membership?->plan_name ?? $membership?->billing_title ?? 'N/A',
                'date'             => $txn->created_at?->format('d M Y'),
                'amount'           => (float) $txn->amount,
                'payment_method'   => $txn->payment_method ?? 'N/A',
                'status'           => ucfirst($txn->status),
                'razorpay_order_id'   => $txn->razorpay_order_id,
                'razorpay_payment_id' => $txn->razorpay_payment_id,
                'description'      => $txn->description,
                'created_at'       => $txn->created_at,
            ];
        });

        return response()->json([
            'success'    => true,
            'data'       => $data,
            'pagination' => [
                'total'        => $total,
                'per_page'     => $limit,
                'current_page' => $page,
                'last_page'    => $limit > 0 ? (int) ceil($total / $limit) : 1,
            ],
        ], 200);
    }

    /**
     * Helper: build chart data for revenue overview (daily/weekly/monthly).
     */
    private function getRevenueOverview(string $period, $productId = null): array
    {
        switch ($period) {
            case 'daily':
                // Last 7 days
                $data = [];
                for ($i = 6; $i >= 0; $i--) {
                    $date    = Carbon::now()->subDays($i)->format('Y-m-d');
                    $query   = Transaction::whereIn('status', ['captured', 'paid'])
                        ->whereDate('created_at', $date);

                    if ($productId) {
                        $query->whereHas('client', function ($q) use ($productId) {
                            $q->where('product_id', $productId);
                        });
                    }

                    $revenue = $query->sum('amount');
                    $data[] = [
                        'label'   => Carbon::now()->subDays($i)->format('D'),
                        'revenue' => (float) $revenue,
                    ];
                }
                return $data;

            case 'weekly':
                // Last 8 weeks
                $data = [];
                for ($i = 7; $i >= 0; $i--) {
                    $start   = Carbon::now()->subWeeks($i)->startOfWeek();
                    $end     = Carbon::now()->subWeeks($i)->endOfWeek();
                    $query   = Transaction::whereIn('status', ['captured', 'paid'])
                        ->whereBetween('created_at', [$start, $end]);

                    if ($productId) {
                        $query->whereHas('client', function ($q) use ($productId) {
                            $q->where('product_id', $productId);
                        });
                    }

                    $revenue = $query->sum('amount');
                    $data[] = [
                        'label'   => 'W' . $start->weekOfYear,
                        'revenue' => (float) $revenue,
                    ];
                }
                return $data;

            case 'monthly':
            default:
                // Last 6 months
                $data = [];
                for ($i = 5; $i >= 0; $i--) {
                    $month   = Carbon::now()->subMonths($i);
                    $query   = Transaction::whereIn('status', ['captured', 'paid'])
                        ->whereYear('created_at', $month->year)
                        ->whereMonth('created_at', $month->month);

                    if ($productId) {
                        $query->whereHas('client', function ($q) use ($productId) {
                            $q->where('product_id', $productId);
                        });
                    }

                    $revenue = $query->sum('amount');
                    $data[] = [
                        'label'   => $month->format('M'),
                        'revenue' => (float) $revenue,
                    ];
                }
                return $data;
        }
    }
}
