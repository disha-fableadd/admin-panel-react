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
        $this->middleware('permission:Clients,EDIT')->only(['update']);
        $this->middleware('permission:Clients,DELETE')->only(['destroy']);
    }

    public function index()
    {
        $clients = Client::with(['product', 'project', 'membership.project', 'projectModules', 'setup', 'renewals'])->latest()->get();
        return response()->json([
            'success' => true,
            'data' => $clients
        ]);
    }

    public function upcomingRenewals()
    {
        $clients = Client::with(['product', 'project', 'membership.project', 'projectModules', 'setup', 'renewals'])->latest()->get();
        
        $upcoming = $clients->filter(function ($client) {
            return in_array($client->membership_status, ['Expiring Soon', 'Expired']) || $client->plan_status === 'Renewed';
        })->values();

        return response()->json([
            'success' => true,
            'data' => $upcoming
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_name' => 'required|string|max:255',
            'brand_name' => 'nullable|string|max:255',
            'work_email' => 'nullable|email|max:255',
            'mobile' => 'nullable|string|max:20',
            'password' => 'nullable|string|max:255',
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

        // Validate that the same email cannot purchase the exact same project twice
        if (!empty($validated['work_email'])) {
            $email = trim(strtolower($validated['work_email']));
            $productId = $validated['product_id'];
            $projectId = $validated['project_id'] ?? null;

            $duplicateQuery = Client::whereRaw('LOWER(TRIM(work_email)) = ?', [$email])
                ->where('product_id', $productId);

            if (!empty($projectId)) {
                $duplicateQuery->where('project_id', $projectId);
            } else {
                $duplicateQuery->whereNull('project_id');
            }

            if ($duplicateQuery->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This project has already been purchased with this email address.',
                    'errors' => [
                        'work_email' => ['This email address has already purchased this project.']
                    ]
                ], 422);
            }

            // If client has an existing password from a previous purchase and none was provided, reuse it
            if (empty($validated['password'])) {
                $existingClientWithPassword = Client::whereRaw('LOWER(TRIM(work_email)) = ?', [$email])
                    ->whereNotNull('password')
                    ->where('password', '!=', '')
                    ->latest()
                    ->first();
                if ($existingClientWithPassword) {
                    $validated['password'] = $existingClientWithPassword->password;
                }
            }
        }

        // Status determination based on payment_type
        if (!empty($validated['payment_type'])) {
            $paymentType = strtolower($validated['payment_type']);
            if ($paymentType === 'cash') {
                $validated['status'] = 'Active'; // Cash payment is directly Active
            } else {
                $validated['status'] = $validated['status'] ?? 'Pending';
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
                'user_id'        => auth()->id() ?? null,
                'client_id'      => $client->id,
                'amount'         => (float) $paymentAmount,
                'currency'       => 'INR',
                'status'         => ($paymentType === 'cash') ? 'paid' : 'pending',
                'payment_type'   => 'purchase',
                'payment_method' => ($paymentType === 'cash') ? 'Cash' : 'Online',
                'description'    => 'Initial purchase for ' . $client->client_name . ' (' . ucfirst($paymentType) . ')',
            ]);
        }

        $this->notifyAllUsers('New Client Created', 'A new client was added.');

        // Note: No emails are sent upon client registration/add; emails are sent to both client and admin only after payment success

        return response()->json([
            'success' => true,
            'message' => 'Client created successfully.',
            'data' => $client->load(['product', 'project', 'membership.project', 'projectModules', 'setup', 'renewals'])
        ], 201);
    }

    public function show(Client $client)
    {
        $client->load(['product', 'project', 'membership.project', 'projectModules', 'setup', 'renewals']);
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
            'password' => 'nullable|string|max:255',
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

        // Validate that the same email cannot have duplicate purchase of the same project
        if (!empty($validated['work_email'])) {
            $email = trim(strtolower($validated['work_email']));
            $productId = $validated['product_id'];
            $projectId = $validated['project_id'] ?? null;

            $duplicateQuery = Client::where('id', '!=', $client->id)
                ->whereRaw('LOWER(TRIM(work_email)) = ?', [$email])
                ->where('product_id', $productId);

            if (!empty($projectId)) {
                $duplicateQuery->where('project_id', $projectId);
            } else {
                $duplicateQuery->whereNull('project_id');
            }

            if ($duplicateQuery->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This project has already been purchased with this email address.',
                    'errors' => [
                        'work_email' => ['This email address has already purchased this project.']
                    ]
                ], 422);
            }
        }

        if (array_key_exists('password', $validated) && ($validated['password'] === null || $validated['password'] === '')) {
            unset($validated['password']);
        }

        // Billing info is now taken directly from the request manually

        $client->update($validated);

        if (isset($validated['assign_module'])) {
            $client->projectModules()->sync($validated['assign_module']);
        }

        // Synchronize changes to the Setup
        if ($client->setup) {
            $setupUpdateData = [];
            
            if (array_key_exists('product_id', $validated)) {
                $product = \App\Models\Product::find($validated['product_id']);
                if ($product) $setupUpdateData['product'] = $product->title;
            }
            
            if (array_key_exists('membership_id', $validated)) {
                $membership = \App\Models\Membership::find($validated['membership_id']);
                if ($membership) $setupUpdateData['plan_name'] = $membership->plan_name;
            }
            
            if (array_key_exists('amount', $validated)) {
                $cycle = $client->setup->billing_cycle;
                $val = null;
                if ($cycle && is_array($validated['amount'])) {
                    foreach ($validated['amount'] as $k => $v) {
                        if (strcasecmp($k, $cycle) === 0) {
                            $val = $v;
                            break;
                        }
                    }
                }
                if ($val === null && !empty($validated['amount']) && is_array($validated['amount'])) {
                    $val = reset($validated['amount']);
                }
                if ($val !== null) {
                    $setupUpdateData['amount'] = $val;
                }
            }
            
            if (array_key_exists('renewal_amount', $validated)) {
                $cycle = $client->setup->billing_cycle;
                $val = null;
                if ($cycle && is_array($validated['renewal_amount'])) {
                    foreach ($validated['renewal_amount'] as $k => $v) {
                        if (strcasecmp($k, $cycle) === 0) {
                            $val = $v;
                            break;
                        }
                    }
                }
                if ($val === null && !empty($validated['renewal_amount']) && is_array($validated['renewal_amount'])) {
                    $val = reset($validated['renewal_amount']);
                }
                if ($val !== null) {
                    $setupUpdateData['renewal_amount'] = $val;
                }
            }

            if (isset($validated['assign_module'])) {
                $moduleNames = \App\Models\ProjectModule::whereIn('id', $validated['assign_module'])->pluck('name')->toArray();
                $setupUpdateData['assigned_modules'] = $moduleNames;
            }
            
            if (!empty($setupUpdateData)) {
                $client->setup->update($setupUpdateData);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Client updated successfully.',
            'data' => $client->load(['product', 'project', 'membership.project', 'projectModules', 'setup'])
        ]);
    }

    /**
     * Explicitly send or resend the account active email to a client.
     */
    public function resendActiveEmail($id)
    {
        $client = Client::with(['product', 'project', 'membership'])->find($id);
        if (!$client) {
            return response()->json(['success' => false, 'message' => 'Client not found.'], 404);
        }

        if (empty($client->work_email)) {
            return response()->json(['success' => false, 'message' => 'Client does not have a work email address.'], 400);
        }

        $mailService = app(\App\Services\MailService::class);
        $sent = $mailService->sendClientAccountActiveEmail($client, true);

        if ($sent) {
            return response()->json([
                'success' => true,
                'message' => 'Account activation email sent successfully to ' . $client->work_email
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Failed to send email. Please check SMTP settings.'
        ], 500);
    }

    public function destroy(Client $client)
    {
        $id = $client->id;

        $hasSetup = \App\Models\Setup::where('client_id', $id)->exists();
        $hasRenewals = \App\Models\Renewal::where('client_id', $id)->exists();

        if ($hasSetup || $hasRenewals) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete this client because they have existing setups or renewals. Please delete those records first.'
            ], 400);
        }

        // Automatically delete associated transactions
        \App\Models\Transaction::where('client_id', $id)->delete();

        $client->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Client and associated transactions deleted successfully.'
        ]);
    }
}
