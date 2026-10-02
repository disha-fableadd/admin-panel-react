<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Membership;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index()
    {
        $clients = Client::with(['product', 'membership.billing', 'membership.project', 'projectModules', 'setup'])->get();
        return response()->json([
            'success' => true,
            'data' => $clients
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_name' => 'required|string|max:255',
            'brand_name' => 'nullable|string|max:255',
            'work_email' => 'nullable|email|max:255',
            'mobile' => 'nullable|string|max:20',
            'product_id' => 'required|integer|exists:products,id',
            'membership_id' => 'required|integer|exists:memberships,id',
            'status' => 'required|string|in:Active,Inactive',
            'assign_module' => 'nullable|array',
            'assign_module.*' => 'integer|exists:project_modules,id',
            
            'is_custom_billing' => 'boolean',
            'billing_title' => 'nullable|string|max:255',
            'amount' => 'nullable|array',
            'renewal_amount' => 'nullable|array',
            
            'start_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
            'location' => 'nullable|string|max:255',
        ]);

        // Billing info is now taken directly from the request manually

        // Create the client
        $client = Client::create($validated);

        // Assign modules to the pivot table
        if (!empty($validated['assign_module'])) {
            $client->projectModules()->sync($validated['assign_module']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Client created successfully.',
            'data' => $client->load('projectModules')
        ], 201);
    }

    public function show(Client $client)
    {
        $client->load(['product', 'membership.billing', 'membership.project', 'projectModules', 'setup']);
        return response()->json([
            'success' => true,
            'data' => $client
        ]);
    }

    public function update(Request $request, Client $client)
    {
        $validated = $request->validate([
            'client_name' => 'required|string|max:255',
            'brand_name' => 'nullable|string|max:255',
            'work_email' => 'nullable|email|max:255',
            'mobile' => 'nullable|string|max:20',
            'product_id' => 'required|integer|exists:products,id',
            'membership_id' => 'required|integer|exists:memberships,id',
            'status' => 'required|string|in:Active,Inactive',
            'assign_module' => 'nullable|array',
            'assign_module.*' => 'integer|exists:project_modules,id',
            
            'is_custom_billing' => 'boolean',
            'billing_title' => 'nullable|string|max:255',
            'amount' => 'nullable|array',
            'renewal_amount' => 'nullable|array',
            
            'start_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
            'location' => 'nullable|string|max:255',
        ]);

        // Billing info is now taken directly from the request manually

        $client->update($validated);

        if (isset($validated['assign_module'])) {
            $client->projectModules()->sync($validated['assign_module']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Client updated successfully.',
            'data' => $client->load('projectModules')
        ]);
    }

    public function destroy(Client $client)
    {
        $client->delete();
        return response()->json([
            'success' => true,
            'message' => 'Client deleted successfully.'
        ]);
    }
}
