<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /**
     * Display a listing of all transactions with client and user data.
     * Supports filtering by payment_type, status, client_id, and payment_method.
     */
    public function __construct()
    {
        $this->middleware('permission:Transactions,VIEW')->only(['index', 'show']);
        $this->middleware('permission:Transactions,ADD')->only(['store']);
        $this->middleware('permission:Transactions,EDIT')->only(['update']);
        $this->middleware('permission:Transactions,DELETE')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $query = Transaction::with(['client', 'user'])->latest();

        if ($request->filled('payment_type')) {
            $query->where('payment_type', $request->payment_type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        $transactions = $query->get();

        return response()->json([
            'success' => true,
            'data' => $transactions
        ], 200);
    }

    /**
     * Store a new transaction record (supports payment_type).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id'           => 'nullable|integer|exists:clients,id',
            'amount'              => 'required|numeric',
            'currency'            => 'nullable|string',
            'status'              => 'nullable|string',
            'payment_method'      => 'nullable|string',
            'payment_type'        => 'nullable|string',
            'razorpay_order_id'   => 'nullable|string',
            'razorpay_payment_id' => 'nullable|string',
            'razorpay_signature'  => 'nullable|string',
            'payment_link_id'     => 'nullable|string',
            'short_url'           => 'nullable|string',
            'description'         => 'nullable|string',
        ]);

        $validated['user_id']  = auth()->id();
        $validated['currency'] = $validated['currency'] ?? 'INR';
        $validated['status']   = $validated['status'] ?? 'paid';

        $transaction = Transaction::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Transaction created successfully.',
            'data'    => $transaction->load(['client', 'user'])
        ], 201);
    }

    /**
     * Display the specified transaction with its relations.
     */
    public function show($id)
    {
        $transaction = Transaction::with(['client', 'user'])->find($id);

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $transaction
        ], 200);
    }

    /**
     * Update an existing transaction record (supports payment_type).
     */
    public function update(Request $request, $id)
    {
        $transaction = Transaction::find($id);

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found.'
            ], 404);
        }

        $validated = $request->validate([
            'client_id'           => 'nullable|integer|exists:clients,id',
            'amount'              => 'nullable|numeric',
            'currency'            => 'nullable|string',
            'status'              => 'nullable|string',
            'payment_method'      => 'nullable|string',
            'payment_type'        => 'nullable|string',
            'razorpay_order_id'   => 'nullable|string',
            'razorpay_payment_id' => 'nullable|string',
            'razorpay_signature'  => 'nullable|string',
            'payment_link_id'     => 'nullable|string',
            'short_url'           => 'nullable|string',
            'description'         => 'nullable|string',
        ]);

        $transaction->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Transaction updated successfully.',
            'data'    => $transaction->load(['client', 'user'])
        ], 200);
    }
}
