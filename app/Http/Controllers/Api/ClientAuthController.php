<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;

class ClientAuthController extends Controller
{
    /**
     * Client Login API (Without Auth)
     *
     * Authenticates a client by work_email, mobile, identifier, or ID.
     * Returns full client data including membership, product, project, setup, renewals, and transactions.
     * Only returns data for the logged-in client.
     */
    public function login(Request $request)
    {
        $email = $request->input('email') ?? $request->input('work_email');
        $mobile = $request->input('mobile') ?? $request->input('phone');
        $clientId = $request->input('client_id') ?? $request->input('id');
        $identifier = $request->input('identifier') ?? $request->input('login') ?? $request->input('username');

        // Check if any identifier was provided
        if (empty($email) && empty($mobile) && empty($clientId) && empty($identifier)) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide an email or mobile number to log in.'
            ], 422);
        }

        $query = Client::query();

        if (!empty($clientId)) {
            $query->where('id', $clientId);
        } elseif (!empty($email)) {
            $cleanEmail = trim(strtolower($email));
            $query->whereRaw('LOWER(TRIM(work_email)) = ?', [$cleanEmail]);
        } elseif (!empty($mobile)) {
            $cleanMobile = trim($mobile);
            $digitsOnly = preg_replace('/[^0-9]/', '', $cleanMobile);
            $query->where(function ($q) use ($cleanMobile, $digitsOnly) {
                $q->where('mobile', $cleanMobile);
                if (!empty($digitsOnly)) {
                    $q->orWhere('mobile', 'LIKE', '%' . $digitsOnly . '%');
                }
            });
        } elseif (!empty($identifier)) {
            $cleanIdentifier = trim($identifier);
            $cleanLower = strtolower($cleanIdentifier);
            $digitsOnly = preg_replace('/[^0-9]/', '', $cleanIdentifier);

            $query->where(function ($q) use ($cleanLower, $cleanIdentifier, $digitsOnly) {
                $q->whereRaw('LOWER(TRIM(work_email)) = ?', [$cleanLower])
                  ->orWhere('mobile', $cleanIdentifier)
                  ->orWhere('client_name', 'LIKE', '%' . $cleanIdentifier . '%');

                if (!empty($digitsOnly)) {
                    $q->orWhere('mobile', 'LIKE', '%' . $digitsOnly . '%');
                }
            });
        }

        $client = $query->first();

        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'Client not found. Please verify your email or mobile number.'
            ], 404);
        }

        // Check if client is active
        if ($client->status === 'Inactive') {
            return response()->json([
                'success' => false,
                'message' => 'Your account is currently inactive. Please contact the administrator.'
            ], 403);
        }

        // Eager load only this client's relations
        $client->load([
            'product',
            'project',
            'membership.project',
            'projectModules',
            'setup',
            'renewals' => function ($q) {
                $q->orderBy('created_at', 'desc');
            },
            'transactions' => function ($q) {
                $q->orderBy('created_at', 'desc');
            }
        ]);

        $purchaseTransaction = $client->transactions->firstWhere('payment_type', 'purchase');

        return response()->json([
            'success' => true,
            'message' => 'Client login successful.',
            'data' => [
                'client'               => $client,
                'product'              => $client->product,
                'project'              => $client->project,
                'membership'           => $client->membership,
                'setup'                => $client->setup,
                'project_modules'      => $client->projectModules,
                'renewals'             => $client->renewals,
                'transactions'         => $client->transactions,
                'purchase_transaction' => $purchaseTransaction,
            ]
        ], 200);
    }

    /**
     * Get Client Data API (Without Auth)
     *
     * Fetch complete data for a specific client by ID or identifier.
     */
    public function getClientData(Request $request, $id = null)
    {
        $clientId = $id ?? $request->input('client_id') ?? $request->input('id');
        $email = $request->input('email') ?? $request->input('work_email');
        $mobile = $request->input('mobile') ?? $request->input('phone');

        $query = Client::query();

        if (!empty($clientId)) {
            $query->where('id', $clientId);
        } elseif (!empty($email)) {
            $cleanEmail = trim(strtolower($email));
            $query->whereRaw('LOWER(TRIM(work_email)) = ?', [$cleanEmail]);
        } elseif (!empty($mobile)) {
            $cleanMobile = trim($mobile);
            $query->where('mobile', $cleanMobile);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Client ID, email, or mobile is required.'
            ], 422);
        }

        $client = $query->first();

        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'Client not found.'
            ], 404);
        }

        $client->load([
            'product',
            'project',
            'membership.project',
            'projectModules',
            'setup',
            'renewals' => function ($q) {
                $q->orderBy('created_at', 'desc');
            },
            'transactions' => function ($q) {
                $q->orderBy('created_at', 'desc');
            }
        ]);

        $purchaseTransaction = $client->transactions->firstWhere('payment_type', 'purchase');

        return response()->json([
            'success' => true,
            'data' => [
                'client'               => $client,
                'product'              => $client->product,
                'project'              => $client->project,
                'membership'           => $client->membership,
                'setup'                => $client->setup,
                'project_modules'      => $client->projectModules,
                'renewals'             => $client->renewals,
                'transactions'         => $client->transactions,
                'purchase_transaction' => $purchaseTransaction,
            ]
        ], 200);
    }
}
