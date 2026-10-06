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
        $setups = Setup::with(['client.product', 'client.membership.project', 'client.projectModules'])->latest()->get();
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

        if (!empty($validated['assignedModules'])) {
            $this->assignModulesToClient($validated['clientId'], $validated['assignedModules']);
        }

        $this->notifyAllUsers('New Setup Created', 'A new setup was added.');

        return response()->json([
            'success' => true,
            'message' => 'Setup created successfully.',
            'data' => $setup->load(['client.product', 'client.membership.project', 'client.projectModules'])
        ], 201);
    }

    public function show(Setup $setup)
    {
        $setup->load(['client.product', 'client.membership.project', 'client.projectModules']);
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

        if (!empty($validated['assignedModules'])) {
            $this->assignModulesToClient($validated['clientId'], $validated['assignedModules']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Setup updated successfully.',
            'data' => $setup->load(['client.product', 'client.membership.project', 'client.projectModules'])
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

    private function assignModulesToClient($clientId, $moduleItems)
    {
        if (empty($moduleItems)) return;

        $client = \App\Models\Client::find($clientId);
        if (!$client) return;

        $moduleIds = [];
        foreach ($moduleItems as $item) {
            // Check if it's an existing module ID
            if (is_numeric($item)) {
                $module = \App\Models\ProjectModule::find($item);
                
                if ($module) {
                    // Reusing existing module, do not modify its client_id column
                    $moduleIds[] = $module->id;
                }
            } else {
                // It's a string name, find or create custom module
                $module = \App\Models\ProjectModule::where('name', $item)
                    ->where('product_id', $client->product_id)
                    ->first();

                if ($module) {
                    // Module exists globally for this product. Just merge the project_id. 
                    // Do NOT merge client_id for already existing modules (reusable) as per user rules.
                    $existingProjects = $module->project_id ?? [];
                    if (!in_array($client->project_id, $existingProjects)) {
                        $existingProjects[] = $client->project_id;
                        $module->project_id = array_values(array_unique($existingProjects));
                        $module->save();
                    }
                } else {
                    // Newly created custom module specifically for this client -> add client_id
                    $module = \App\Models\ProjectModule::create([
                        'name' => $item,
                        'product_id' => $client->product_id,
                        'project_id' => [$client->project_id],
                        'status' => 'Active',
                        'client_id' => [$client->id],
                    ]);
                }
                // If it already existed, we just reuse it and don't touch the client_id array.
                
                $moduleIds[] = $module->id;
            }
        }

        // Attach the modules to the client in client_project_modules pivot table.
        // Using sync() ensures that the modules accurately reflect what was sent on update.
        $client->projectModules()->sync($moduleIds);
    }
}
