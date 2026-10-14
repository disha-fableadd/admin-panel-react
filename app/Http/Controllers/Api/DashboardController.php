<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Client;
use App\Models\Membership;
use App\Models\Transaction;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function clientsCounts(Request $request)
    {
        $productId = $request->product_id;
        
        $query = Client::when($productId, function ($q) use ($productId) {
            $q->where('product_id', $productId);
        });

        $total = (clone $query)->count();
        $active = (clone $query)->where('status', 'Active')->count();
        $inactive = (clone $query)->where('status', 'Inactive')->count();
        $newThisMonth = (clone $query)->where('created_at', '>=', Carbon::now()->startOfMonth())->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
                'new_this_month' => $newThisMonth,
            ]
        ], 200);
    }

    public function staffCounts(Request $request)
    {
        // Exclude Super Admin if needed as done in index
        $query = User::whereHas('roleModel', function($q) {
            $q->where('name', '!=', 'Super Admin');
        });

        $total = (clone $query)->count();
        $active = (clone $query)->where('status', 'Active')->count();
        $inactive = (clone $query)->where('status', 'Inactive')->count();
        $newThisMonth = (clone $query)->where('created_at', '>=', Carbon::now()->startOfMonth())->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
                'new_this_month' => $newThisMonth,
            ]
        ], 200);
    }

    public function membershipCounts(Request $request)
    {
        $productId = $request->product_id;

        $query = Membership::when($productId, function ($q) use ($productId) {
            $q->where('product_id', $productId);
        });

        $total = (clone $query)->count();
        $active = (clone $query)->where('status', 'Active')->count();
        $inactive = (clone $query)->where('status', 'Inactive')->count();
        $newThisMonth = (clone $query)->where('created_at', '>=', Carbon::now()->startOfMonth())->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
                'new_this_month' => $newThisMonth,
            ]
        ], 200);
    }

    public function dashboardCounts(Request $request)
    {
        $productId = $request->product_id;
        $filterRange = $request->get('filter_range'); // 'today', 'this_week', 'this_month', 'custom'
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        // Resolve date range if filter_range or custom dates provided
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

        // Helper closures to apply date filtering
        $applyDateFilter = function ($q, $column = 'created_at') use ($dateFrom, $dateTo) {
            if ($dateFrom && $dateTo) {
                $q->whereBetween($column, [$dateFrom, $dateTo]);
            } elseif ($dateFrom) {
                $q->where($column, '>=', $dateFrom);
            } elseif ($dateTo) {
                $q->where($column, '<=', $dateTo);
            }
        };

        // Base queries with product filtering & date filtering
        $clientQuery = Client::when($productId, function ($q) use ($productId) {
            $q->where('product_id', $productId);
        });
        if ($dateFrom || $dateTo) {
            $applyDateFilter($clientQuery, 'created_at');
        }

        $membershipQuery = Membership::when($productId, function ($q) use ($productId) {
            $q->where('product_id', $productId);
        });
        if ($dateFrom || $dateTo) {
            $applyDateFilter($membershipQuery, 'created_at');
        }

        $transactionQuery = Transaction::where(function ($query) {
            $query->where('payment_method', '!=', 'Manual')
                  ->orWhereNull('payment_method');
        })->when($productId, function ($q) use ($productId) {
            $q->whereHas('client', function ($q2) use ($productId) {
                $q2->where('product_id', $productId);
            });
        });
        if ($dateFrom || $dateTo) {
            $applyDateFilter($transactionQuery, 'created_at');
        }

        // Reports and Dashboard general counts
        $totalClients = (clone $clientQuery)->count();
        
        $staffQuery = User::whereHas('roleModel', function($q) {
            $q->where('name', '!=', 'Super Admin');
        })->where('status', 'Active');
        if ($dateFrom || $dateTo) {
            $applyDateFilter($staffQuery, 'created_at');
        }
        $activeStaff = $staffQuery->count();

        $activeMemberships = (clone $membershipQuery)->where('status', 'Active')->count();

        // Expiring Memberships (e.g., expiring in the next 30 days)
        $expiringMemberships = (clone $clientQuery)->where('status', 'Active')
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [Carbon::now(), Carbon::now()->addDays(30)])
            ->count();

        // Renewals: count clients whose membership status is Expired or Expiring Soon
        $clientsForRenewals = (clone $clientQuery)->get();
        $renewals = $clientsForRenewals->filter(function ($client) {
            return in_array($client->membership_status, ['Expired', 'Expiring Soon']);
        })->count();

        // Real transaction data
        $totalTransactions      = (clone $transactionQuery)->count();
        $totalRevenue           = (clone $transactionQuery)->where('status', 'paid')->sum('amount');
        $failedPendingPayments  = (clone $transactionQuery)->where('status', 'pending')->count();

        // --- CHART / BREAKDOWN FEATURES ---

        // 1. Revenue Overview
        // If a specific date range is selected, show daily revenue within that range or the last 7 days / last 6 months
        $revenueOverview = [];
        if ($dateFrom && $dateTo && $dateFrom->diffInDays($dateTo) <= 31) {
            // Day-by-day for short custom ranges
            $daysCount = (int) $dateFrom->diffInDays($dateTo);
            for ($i = 0; $i <= $daysCount; $i++) {
                $currentDay = (clone $dateFrom)->addDays($i);
                $daySum = Transaction::where(function ($query) {
                            $query->where('payment_method', '!=', 'Manual')
                                  ->orWhereNull('payment_method');
                        })->when($productId, function ($q) use ($productId) {
                            $q->whereHas('client', function ($q2) use ($productId) {
                                $q2->where('product_id', $productId);
                            });
                        })
                        ->where('status', 'paid')
                        ->whereDate('created_at', $currentDay->format('Y-m-d'))
                        ->sum('amount');

                $revenueOverview[] = [
                    'day' => $currentDay->format('d M'),
                    'amount' => (float) $daySum
                ];
            }
        } else {
            // Default: Last 7 Days or last 6 months
            for ($i = 6; $i >= 0; $i--) {
                $date = Carbon::today()->subDays($i);
                $daySum = Transaction::where(function ($query) {
                            $query->where('payment_method', '!=', 'Manual')
                                  ->orWhereNull('payment_method');
                        })->when($productId, function ($q) use ($productId) {
                            $q->whereHas('client', function ($q2) use ($productId) {
                                $q2->where('product_id', $productId);
                            });
                        })
                        ->where('status', 'paid')
                        ->whereDate('created_at', $date)
                        ->sum('amount');
                $revenueOverview[] = [
                    'day' => $date->format('D'), // Mon, Tue, etc.
                    'amount' => (float) $daySum
                ];
            }
        }

        // 2. Membership Status (Based on filtered Client query)
        $membershipStatus = [
            'total' => $totalClients,
            'active' => (clone $clientQuery)->where('status', 'Active')->count(),
            'expiring_soon' => $expiringMemberships,
            'expired' => (clone $clientQuery)->where('status', 'Expired')->count(),
            'cancelled' => (clone $clientQuery)->where('status', 'Inactive')->count(),
        ];

        // 3. Recent/Upcoming Renewals (Latest 5 from clients with Expired/Expiring Soon status)
        $upcomingRenewals = $clientsForRenewals->filter(function ($client) {
            return in_array($client->membership_status, ['Expired', 'Expiring Soon']);
        })->sortBy('calculated_end_date')->take(5)->map(function ($client) {
            $endDate = $client->calculated_end_date;
            return [
                'id' => $client->id,
                'client_name' => $client->client_name,
                'brand_name' => $client->brand_name,
                'product' => $client->product,
                'project' => $client->project,
                'membership' => $client->membership,
                'end_date' => $endDate ? Carbon::parse($endDate)->format('d M Y') : null,
                'days_left' => $client->days_left ?? 0,
                'status' => $client->membership_status
            ];
        })->values();

        // 4. Products (Latest 4)
        $products = Product::latest()->take(4)->get();

        return response()->json([
            'success' => true,
            'filter' => [
                'product_id' => $productId,
                'filter_range' => $filterRange,
                'start_date' => $dateFrom ? $dateFrom->format('Y-m-d') : null,
                'end_date' => $dateTo ? $dateTo->format('Y-m-d') : null,
            ],
            'data' => [
                'total_clients' => $totalClients,
                'active_staff' => $activeStaff,
                'active_memberships' => $activeMemberships,
                'expiring_memberships' => $expiringMemberships,
                'renewals' => $renewals,
                'total_transactions' => $totalTransactions,
                'total_revenue' => (float) $totalRevenue,
                'failed_pending_payments' => $failedPendingPayments,
                
                // Added arrays
                'revenue_overview' => [
                    'total' => (float) $totalRevenue,
                    'chart' => $revenueOverview
                ],
                'membership_status' => $membershipStatus,
                'upcoming_renewals' => $upcomingRenewals,
                'products' => $products
            ]
        ], 200);
    }
}
