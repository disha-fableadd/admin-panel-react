<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setup;
use Illuminate\Http\Request;

class SetupController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:Setup,VIEW')->only(['index', 'show']);
        $this->middleware('permission:Setup,ADD')->only(['store']);
        $this->middleware('permission:Setup,EDIT')->only(['update']);
        $this->middleware('permission:Setup,DELETE')->only(['destroy']);
    }

    public function index()
    {
        $setups = Setup::with(['client.product', 'client.membership.project'])->latest()->get();
        return response()->json([
            'success' => true,
            'data' => $setups
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'clientId' => 'required|integer',
            'clientName' => 'nullable|string|max:255',
            'product' => 'nullable|string',
            'domain' => 'required|string|max:255',
            'databaseName' => 'nullable|string',
            'dbHost' => 'nullable|string',
            'dbUsername' => 'nullable|string',
            'dbPassword' => 'nullable|string',
            'dbPort' => 'nullable|integer',
            'sslEnabled' => 'nullable|boolean',
            'status' => 'nullable|string',
            'version' => 'nullable|string',
            'planName' => 'nullable|string',
            'billingCycle' => 'nullable|string',
            'amount' => 'nullable|numeric',
            'renewalAmount' => 'nullable|numeric',
            'assignedModules' => 'nullable|array',
            'assignedModules.*' => 'string'
        ]);

        $setupData = [
            'client_id' => $validated['clientId'],
            'client_name' => $validated['clientName'] ?? null,
            'product' => $validated['product'] ?? null,
            'domain' => $validated['domain'],
            'database_name' => $validated['databaseName'] ?? null,
            'db_host' => $validated['dbHost'] ?? null,
            'db_username' => $validated['dbUsername'] ?? null,
            'db_password' => $validated['dbPassword'] ?? null,
            'db_port' => $validated['dbPort'] ?? 5432,
            'ssl_enabled' => $validated['sslEnabled'] ?? true,
            'status' => $validated['status'] ?? 'Active',
            'version' => $validated['version'] ?? 'v1.0.0',
            'plan_name' => $validated['planName'] ?? null,
            'billing_cycle' => $validated['billingCycle'] ?? null,
            'amount' => $validated['amount'] ?? null,
            'renewal_amount' => $validated['renewalAmount'] ?? null,
            'assigned_modules' => $validated['assignedModules'] ?? [],
        ];

        $setup = Setup::create($setupData);

        $this->notifyAllUsers('New Setup Created', 'A new setup was added.');

        return response()->json([
            'success' => true,
            'message' => 'Setup created successfully.',
            'data' => $setup
        ], 201);
    }

    public function show(Setup $setup)
    {
        $setup->load(['client.product', 'client.membership.project']);
        return response()->json([
            'success' => true,
            'data' => $setup
        ]);
    }

    public function update(Request $request, Setup $setup)
    {
        $validated = $request->validate([
            'clientId' => 'required|integer',
            'clientName' => 'nullable|string|max:255',
            'product' => 'nullable|string',
            'domain' => 'required|string|max:255',
            'databaseName' => 'nullable|string',
            'dbHost' => 'nullable|string',
            'dbUsername' => 'nullable|string',
            'dbPassword' => 'nullable|string',
            'dbPort' => 'nullable|integer',
            'sslEnabled' => 'nullable|boolean',
            'status' => 'nullable|string',
            'version' => 'nullable|string',
            'planName' => 'nullable|string',
            'billingCycle' => 'nullable|string',
            'amount' => 'nullable|numeric',
            'renewalAmount' => 'nullable|numeric',
            'assignedModules' => 'nullable|array',
            'assignedModules.*' => 'string'
        ]);

        $setupData = [
            'client_id' => $validated['clientId'],
            'client_name' => $validated['clientName'] ?? null,
            'product' => $validated['product'] ?? null,
            'domain' => $validated['domain'],
            'database_name' => $validated['databaseName'] ?? null,
            'db_host' => $validated['dbHost'] ?? null,
            'db_username' => $validated['dbUsername'] ?? null,
            'db_password' => $validated['dbPassword'] ?? null,
            'db_port' => $validated['dbPort'] ?? 5432,
            'ssl_enabled' => $validated['sslEnabled'] ?? true,
            'status' => $validated['status'] ?? 'Active',
            'version' => $validated['version'] ?? 'v1.0.0',
            'plan_name' => $validated['planName'] ?? null,
            'billing_cycle' => $validated['billingCycle'] ?? null,
            'amount' => $validated['amount'] ?? null,
            'renewal_amount' => $validated['renewalAmount'] ?? null,
            'assigned_modules' => $validated['assignedModules'] ?? [],
        ];

        $setup->update($setupData);

        return response()->json([
            'success' => true,
            'message' => 'Setup updated successfully.',
            'data' => $setup
        ]);
    }

    public function destroy(Setup $setup)
    {
        $setup->delete();
        return response()->json([
            'success' => true,
            'message' => 'Setup deleted successfully.'
        ]);
    }
}
