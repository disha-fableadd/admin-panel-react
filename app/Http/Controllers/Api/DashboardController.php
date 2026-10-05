<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Client;
use App\Models\Membership;
use App\Models\Transaction;
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
            ]
        ], 200);
    }
}
