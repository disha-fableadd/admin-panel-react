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
    public function clientsCounts()
    {
        $total = Client::count();
        $active = Client::where('status', 'Active')->count();
        $inactive = Client::where('status', 'Inactive')->count();
        $newThisMonth = Client::where('created_at', '>=', Carbon::now()->startOfMonth())->count();

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

    public function staffCounts()
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

    public function membershipCounts()
    {
        $total = Membership::count();
        $active = Membership::where('status', 'Active')->count();
        $inactive = Membership::where('status', 'Inactive')->count();
        $newThisMonth = Membership::where('created_at', '>=', Carbon::now()->startOfMonth())->count();

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

    public function dashboardCounts()
    {
        // Reports and Dashboard general counts
        $totalClients = Client::count();
        
        $activeStaff = User::whereHas('roleModel', function($q) {
            $q->where('name', '!=', 'Super Admin');
        })->where('status', 'Active')->count();

        $activeMemberships = Membership::where('status', 'Active')->count();

        // Expiring Memberships (e.g., expiring in the next 30 days)
        $expiringMemberships = Client::where('status', 'Active')
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [Carbon::now(), Carbon::now()->addDays(30)])
            ->count();

        // Renewals (e.g., clients with 'Renewed' status or recent renewal)
        $renewals = Client::where('status', 'Renewed')->count();

        // Real transaction data
        $totalTransactions      = Transaction::count();
        $totalRevenue           = Transaction::where('status', 'captured')->sum('amount');
        $failedPendingPayments  = Transaction::whereIn('status', ['failed', 'created', 'authorized'])->count();

        // --- NEW FEATURES ---

        // 1. Revenue Overview (Last 7 Days)
        $revenueOverview = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $daySum = Transaction::where('status', 'captured')
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
            'active' => Client::where('status', 'Active')->count(),
            'expiring_soon' => $expiringMemberships,
            'expired' => Client::where('status', 'Expired')->count(),
            'cancelled' => Client::where('status', 'Inactive')->count(), // Or 'Cancelled' if it exists
        ];

        // 3. Upcoming Renewals (Latest 5 Expiring)
        $upcomingRenewals = Client::with(['product', 'membership'])
            ->where('status', 'Active')
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '>=', Carbon::today())
            ->orderBy('expiry_date', 'asc')
            ->take(5)
            ->get()
            ->map(function ($client) {
                return [
                    'id' => $client->id,
                    'client_name' => $client->client_name,
                    'brand_name' => $client->brand_name,
                    'product' => $client->product ? $client->product->name : null,
                    'membership' => $client->membership ? $client->membership->plan_name : null,
                    'end_date' => Carbon::parse($client->expiry_date)->format('d M Y'),
                    'days_left' => Carbon::parse($client->expiry_date)->diffInDays(Carbon::today()),
                    'status' => 'Expiring'
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
