<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /**
     * Display a listing of all transactions with client and user data.
     */
    public function index()
    {
        $transactions = Transaction::with(['client', 'user'])->latest()->get();

        return response()->json([
            'success' => true,
            'data' => $transactions
        ], 200);
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
}
