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
     * Resolve date range (from filter_range or start_date & end_date)
     */
    private function resolveDateRange(Request $request): array
    {
        $filterRange = $request->get('filter_range'); // 'today', 'this_week', 'this_month', 'custom'
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        $dateFrom = null;
        $dateTo = null;

        if ($filterRange) {
            $range = strtolower(str_replace(' ', '_', trim($filterRange)));
            if ($range === 'today') {
                $dateFrom = Carbon::today()->startOfDay();
                $dateTo   = Carbon::today()->endOfDay();
            } elseif ($range === 'this_week') {
                $dateFrom = Carbon::now()->startOfWeek();
                $dateTo   = Carbon::now()->endOfWeek();
            } elseif ($range === 'this_month') {
                $dateFrom = Carbon::now()->startOfMonth();
                $dateTo   = Carbon::now()->endOfMonth();
            } elseif ($range === 'custom' || $range === 'custom_date') {
                if ($startDate) {
                    $dateFrom = Carbon::parse($startDate)->startOfDay();
                }
                if ($endDate) {
                    $dateTo = Carbon::parse($endDate)->endOfDay();
                }
            }
        } elseif ($startDate || $endDate) {
            if ($startDate) {
                $dateFrom = Carbon::parse($startDate)->startOfDay();
            }
            if ($endDate) {
                $dateTo = Carbon::parse($endDate)->endOfDay();
            }
        }

        return [$dateFrom, $dateTo];
    }

    /**
     * Apply date range to any query builder on a given column
     */
    private function applyDateRange($query, ?Carbon $dateFrom, ?Carbon $dateTo, string $column = 'created_at')
    {
        if ($dateFrom && $dateTo) {
            $query->whereBetween($column, [$dateFrom, $dateTo]);
        } elseif ($dateFrom) {
            $query->where($column, '>=', $dateFrom);
        } elseif ($dateTo) {
            $query->where($column, '<=', $dateTo);
        }
    }

    /**
     * Full Reports page data (summary counts + revenue overview + membership status + renewal analysis).
     * Optional filter: product_id, filter_range, start_date, end_date, period
     */
    public function summary(Request $request)
    {
        $period      = $request->get('period', 'month'); // daily, weekly, monthly
        $productId   = $request->get('product_id');
        $filterRange = $request->get('filter_range');
        [$dateFrom, $dateTo] = $this->resolveDateRange($request);

        // Base query for clients
        $clientQuery = Client::query();
        if ($productId) {
            $clientQuery->where('product_id', $productId);
        }
        $this->applyDateRange($clientQuery, $dateFrom, $dateTo, 'created_at');

        // Base query for memberships
        $membershipQuery = Membership::query();
        if ($productId) {
            $membershipQuery->where('product_id', $productId);
        }
        $this->applyDateRange($membershipQuery, $dateFrom, $dateTo, 'created_at');

        // Base query for transactions
        $transactionQuery = Transaction::query();
        if ($productId) {
            $transactionQuery->whereHas('client', function ($q) use ($productId) {
                $q->where('product_id', $productId);
            });
        }
        $this->applyDateRange($transactionQuery, $dateFrom, $dateTo, 'created_at');

        // --- Summary Counts ---
        $totalClients = (clone $clientQuery)->count();

        $staffQuery = User::whereHas('roleModel', function ($q) {
            $q->where('name', '!=', 'Super Admin');
        })->where('status', 'Active');
        $this->applyDateRange($staffQuery, $dateFrom, $dateTo, 'created_at');
        $activeStaff = $staffQuery->count();

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
        $revenueOverview = $this->getRevenueOverview($period, $productId, $dateFrom, $dateTo);

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
            'filter' => [
                'product_id'   => $productId,
                'filter_range' => $filterRange,
                'start_date'   => $dateFrom ? $dateFrom->format('Y-m-d') : null,
                'end_date'     => $dateTo ? $dateTo->format('Y-m-d') : null,
                'period'       => $period,
            ],
            'data' => [
                'summary' => [
                    'total_clients'           => $totalClients,
                    'active_staff'            => $activeStaff,
                    'active_memberships'      => $activeMemberships,
                    'expiring_memberships'    => $expiringMemberships,
                    'renewals'                => $renewals,
                    'total_transactions'      => $totalTransactions,
                    'total_revenue'           => (float) $totalRevenue,
                    'failed_pending_payments' => $failedPendingPayments,
                ],
                'revenue_overview' => [
                    'total_revenue' => (float) $totalRevenue,
                    'successful'    => $successful,
                    'pending'       => $pending,
                    'failed'        => $failed,
                    'refunded'      => (float) $refunded,
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
        $filterRange = $request->get('filter_range');
        [$dateFrom, $dateTo] = $this->resolveDateRange($request);

        $applyDate = function ($q) use ($dateFrom, $dateTo) {
            $this->applyDateRange($q, $dateFrom, $dateTo, 'created_at');
            return $q;
        };

        $query = Membership::withCount([
            'clients as total_clients'    => function ($q) use ($productId, $applyDate) {
                if ($productId) $q->where('product_id', $productId);
                $applyDate($q);
            },
            'clients as active_clients'   => function ($q) use ($productId, $applyDate) {
                if ($productId) $q->where('product_id', $productId);
                $q->where('status', 'Active');
                $applyDate($q);
            },
            'clients as expired_clients'  => function ($q) use ($productId, $applyDate) {
                if ($productId) $q->where('product_id', $productId);
                $q->where('status', 'Expired');
                $applyDate($q);
            },
            'clients as renewed_clients'  => function ($q) use ($productId, $applyDate) {
                if ($productId) $q->where('product_id', $productId);
                $q->where('status', 'Renewed');
                $applyDate($q);
            },
        ])
        ->orderByDesc('total_clients');

        // If product_id filter is set, only show memberships that have clients with that product
        if ($productId) {
            $query->whereHas('clients', fn($q) => $q->where('product_id', $productId));
        }

        $total       = $query->count();
        $memberships = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

        $data = $memberships->map(function ($membership) use ($productId, $dateFrom, $dateTo) {
            // Revenue = sum of captured transactions for clients on this plan (filtered by product_id & date if set)
            $txnQuery = Transaction::whereHas('client', function ($q) use ($membership, $productId) {
                $q->where('membership_id', $membership->id);
                if ($productId) {
                    $q->where('product_id', $productId);
                }
            })->whereIn('status', ['captured', 'paid']);

            $this->applyDateRange($txnQuery, $dateFrom, $dateTo, 'created_at');
            $revenue = $txnQuery->sum('amount');

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
            'filter'     => [
                'product_id'   => $productId,
                'filter_range' => $filterRange,
                'start_date'   => $dateFrom ? $dateFrom->format('Y-m-d') : null,
                'end_date'     => $dateTo ? $dateTo->format('Y-m-d') : null,
            ],
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
        $limit       = (int) $request->get('limit', 5);
        $page        = (int) $request->get('page', 1);
        $search      = $request->get('search', '');
        $status      = $request->get('status', '');
        $method      = $request->get('method', '');
        $productId   = $request->get('product_id', '');
        $filterRange = $request->get('filter_range');
        [$dateFrom, $dateTo] = $this->resolveDateRange($request);

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

        if ($productId) {
            $query->whereHas('client', function ($q) use ($productId) {
                $q->where('product_id', $productId);
            });
        }

        $this->applyDateRange($query, $dateFrom, $dateTo, 'created_at');

        $total        = $query->count();
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
            'filter'     => [
                'product_id'   => $productId,
                'filter_range' => $filterRange,
                'start_date'   => $dateFrom ? $dateFrom->format('Y-m-d') : null,
                'end_date'     => $dateTo ? $dateTo->format('Y-m-d') : null,
            ],
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
     * Helper: build chart data for revenue overview (daily/weekly/monthly/custom).
     */
    private function getRevenueOverview(string $period, $productId = null, ?Carbon $dateFrom = null, ?Carbon $dateTo = null): array
    {
        // If a narrow date range is given (e.g., today, this week, custom within 31 days)
        if ($dateFrom && $dateTo && $dateFrom->diffInDays($dateTo) <= 31) {
            $data = [];
            $daysCount = (int) $dateFrom->diffInDays($dateTo);
            for ($i = 0; $i <= $daysCount; $i++) {
                $currentDay = (clone $dateFrom)->addDays($i);
                $query = Transaction::whereIn('status', ['captured', 'paid'])
                    ->whereDate('created_at', $currentDay->format('Y-m-d'));

                if ($productId) {
                    $query->whereHas('client', function ($q) use ($productId) {
                        $q->where('product_id', $productId);
                    });
                }

                $revenue = $query->sum('amount');
                $data[] = [
                    'label'   => $currentDay->format('d M'),
                    'revenue' => (float) $revenue,
                ];
            }
            return $data;
        }

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
