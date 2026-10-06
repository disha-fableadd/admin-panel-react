<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Membership;
use App\Models\Transaction;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:Clients,VIEW')->only(['index', 'show']);
        $this->middleware('permission:Clients,ADD')->only(['store']);
        $this->middleware('permission:Clients,EDIT')->only(['update']);
        $this->middleware('permission:Clients,DELETE')->only(['destroy']);
    }

    public function index()
    {
        $clients = Client::with(['product', 'project', 'membership.project', 'projectModules', 'setup'])->latest()->get();
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
            'project_id' => 'nullable|integer|exists:projects,id',
            'membership_id' => 'required|integer|exists:memberships,id',
            'status' => 'nullable|string|in:Active,Inactive,Pending',
            'assign_module' => 'nullable|array',
            'assign_module.*' => 'integer|exists:project_modules,id',
            
            'is_custom_billing' => 'boolean',
            'billing_title' => 'nullable|string|max:255',
            'amount' => 'nullable|array',
            'renewal_amount' => 'nullable|array',
            
            'start_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
            'location' => 'nullable|string|max:255',

            // Optional Payment details
            'payment_type' => 'nullable|string',
            'payment_amount' => 'nullable|numeric',
        ]);

        // Default status based on payment_type if status wasn't explicitly provided
        if (!empty($validated['payment_type'])) {
            $paymentType = strtolower($validated['payment_type']);
            if (!isset($validated['status'])) {
                $validated['status'] = ($paymentType === 'cash') ? 'Active' : 'Inactive';
            }
        } else {
            $validated['status'] = $validated['status'] ?? 'Active';
        }

        // Extract payment fields before creating client record
        $paymentType = isset($validated['payment_type']) ? strtolower($validated['payment_type']) : null;
        $paymentAmount = $validated['payment_amount'] ?? null;
        unset($validated['payment_type'], $validated['payment_amount']);

        // Create the client
        $client = Client::create($validated);

        // Assign modules to the pivot table
        if (!empty($validated['assign_module'])) {
            $client->projectModules()->sync($validated['assign_module']);
        }

        // If payment_type is provided, automatically record the transaction
        if ($paymentType) {
            // Determine transaction amount if not explicitly given
            if ($paymentAmount === null) {
                $clientAmount = $client->amount;
                if (!empty($clientAmount) && is_array($clientAmount)) {
                    $paymentAmount = (float) (reset($clientAmount) ?: 0);
                } else {
                    $paymentAmount = 0;
                }
            }

            Transaction::create([
                'user_id'        => auth()->id(),
                'client_id'      => $client->id,
                'amount'         => (float) $paymentAmount,
                'currency'       => 'INR',
                'status'         => ($paymentType === 'cash') ? 'paid' : 'pending',
                'payment_type'   => $paymentType,
                'payment_method' => ($paymentType === 'cash') ? 'Cash' : 'Online',
                'description'    => 'Initial purchase for ' . $client->client_name . ' (' . ucfirst($paymentType) . ')',
            ]);
        }

        $this->notifyAllUsers('New Client Created', 'A new client was added.');

        return response()->json([
            'success' => true,
            'message' => 'Client created successfully.',
            'data' => $client->load(['product', 'project', 'membership.project', 'projectModules', 'setup'])
        ], 201);
    }

    public function show(Client $client)
    {
        $client->load(['product', 'project', 'membership.project', 'projectModules', 'setup']);
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
            'project_id' => 'nullable|integer|exists:projects,id',
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
            'data' => $client->load(['product', 'project', 'membership.project', 'projectModules', 'setup'])
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
