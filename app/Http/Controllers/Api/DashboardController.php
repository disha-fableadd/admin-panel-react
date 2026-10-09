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

        // Base queries with product filtering
        $clientQuery = Client::when($productId, function ($q) use ($productId) {
            $q->where('product_id', $productId);
        });

        $membershipQuery = Membership::when($productId, function ($q) use ($productId) {
            $q->where('product_id', $productId);
        });

        $transactionQuery = Transaction::when($productId, function ($q) use ($productId) {
            $q->whereHas('client', function ($q2) use ($productId) {
                $q2->where('product_id', $productId);
            });
        });

        // Reports and Dashboard general counts
        $totalClients = (clone $clientQuery)->count();
        
        $activeStaff = User::whereHas('roleModel', function($q) {
            $q->where('name', '!=', 'Super Admin');
        })->where('status', 'Active')->count();

        $activeMemberships = (clone $membershipQuery)->where('status', 'Active')->count();

        // Expiring Memberships (e.g., expiring in the next 30 days)
        $expiringMemberships = (clone $clientQuery)->where('status', 'Active')
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [Carbon::now(), Carbon::now()->addDays(30)])
            ->count();

        // Renewals from renewals table
        $renewalQuery = \App\Models\Renewal::when($productId, function ($q) use ($productId) {
            $q->whereHas('client', function ($q2) use ($productId) {
                $q2->where('product_id', $productId);
            });
        });
        $renewals = (clone $renewalQuery)->count();

        // Real transaction data
        $totalTransactions      = (clone $transactionQuery)->count();
        $totalRevenue           = (clone $transactionQuery)->where('status', 'paid')->sum('amount');
        $failedPendingPayments  = (clone $transactionQuery)->where('status', 'pending')->count();

        // --- NEW FEATURES ---

        // 1. Revenue Overview (Last 7 Days)
        $revenueOverview = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $daySum = (clone $transactionQuery)
                        ->where('status', 'paid')
                        ->whereDate('created_at', $date)
                        ->sum('amount');
            $revenueOverview[] = [
                'day' => $date->format('D'), // Mon, Tue, etc.
                'amount' => $daySum
            ];
        }

        // 2. Membership Status (Based on Client statuses, assuming total matches)
        $membershipStatus = [
            'total' => $totalClients,
            'active' => (clone $clientQuery)->where('status', 'Active')->count(),
            'expiring_soon' => $expiringMemberships,
            'expired' => (clone $clientQuery)->where('status', 'Expired')->count(),
            'cancelled' => (clone $clientQuery)->where('status', 'Inactive')->count(), // Or 'Cancelled' if it exists
        ];

        // 3. Recent/Upcoming Renewals (Latest 5 from renewals table)
        $upcomingRenewals = (clone $renewalQuery)->with(['client.product', 'client.project', 'client.membership'])
            ->orderBy('renewal_date', 'desc')
            ->take(5)
            ->get()
            ->map(function ($renewal) {
                $client = $renewal->client;
                $endDate = $renewal->new_end_date ?? $renewal->previous_end_date;
                return [
                    'id' => $renewal->id,
                    'client_name' => $client ? $client->client_name : null,
                    'brand_name' => $client ? $client->brand_name : null,
                    'product' => $client ? $client->product : null,
                    'project' => $client ? $client->project : null,
                    'membership' => $client ? $client->membership : null,
                    'end_date' => $endDate ? Carbon::parse($endDate)->format('d M Y') : null,
                    'days_left' => $endDate ? Carbon::parse($endDate)->diffInDays(Carbon::today()) : 0,
                    'status' => $renewal->renewal_status ?? 'Renewed'
                ];
            });

        // 4. Products (Latest 4)
        $products = Product::latest()->take(4)->get();

        return response()->json([
            'success' => true,
            'data' => [
                'total_clients' => $totalClients,
                'active_staff' => $activeStaff,
                'active_memberships' => $activeMemberships,
                'expiring_memberships' => $expiringMemberships,
                'renewals' => $renewals,
                'total_transactions' => $totalTransactions,
                'total_revenue' => $totalRevenue,
                'failed_pending_payments' => $failedPendingPayments,
                
                // Added arrays
                'revenue_overview' => [
                    'total' => $totalRevenue,
                    'chart' => $revenueOverview
                ],
                'membership_status' => $membershipStatus,
                'upcoming_renewals' => $upcomingRenewals,
                'products' => $products
            ]
        ], 200);
    }
}
