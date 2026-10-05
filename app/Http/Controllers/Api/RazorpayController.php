<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Setting;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;

class RazorpayController extends Controller
{
    private function getRazorpayCredentials()
    {
        $settings = Setting::pluck('value', 'key')->toArray();
        return [
            'key_id' => $settings['key_id'] ?? env('RAZORPAY_KEY'),
            'key_secret' => $settings['key_secret'] ?? env('RAZORPAY_SECRET'),
        ];
    }

    public function createOrder(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric',
            'currency' => 'nullable|string',
            'receipt' => 'nullable|string',
            'client_id' => 'nullable|integer',
            'description' => 'nullable|string',
        ]);

        $credentials = $this->getRazorpayCredentials();

        if (empty($credentials['key_id']) || empty($credentials['key_secret'])) {
            return response()->json(['success' => false, 'message' => 'Razorpay credentials not configured.'], 400);
        }

        $amountInPaise = $request->amount * 100;

        $response = Http::withBasicAuth($credentials['key_id'], $credentials['key_secret'])
            ->post('https://api.razorpay.com/v1/orders', [
                'amount' => $amountInPaise,
                'currency' => $request->currency ?? 'INR',
                'receipt' => $request->receipt ?? uniqid('rcpt_'),
            ]);

        if ($response->successful()) {
            $orderData = $response->json();
            
            // Create a pending transaction record
            Transaction::create([
                'user_id' => auth()->id(),
                'client_id' => $request->client_id,
                'razorpay_order_id' => $orderData['id'],
                'amount' => $request->amount,
                'currency' => $orderData['currency'],
                'status' => 'created',
                'description' => $request->description,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'order_id' => $orderData['id'],
                    'amount' => $orderData['amount'],
                    'currency' => $orderData['currency'],
                    'key_id' => $credentials['key_id']
                ]
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Failed to create order',
            'error' => $response->json()
        ], 500);
    }

    public function verifyPayment(Request $request)
    {
        $request->validate([
            'razorpay_order_id' => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature' => 'required|string',
        ]);

        $credentials = $this->getRazorpayCredentials();

        $generatedSignature = hash_hmac(
            'sha256',
            $request->razorpay_order_id . '|' . $request->razorpay_payment_id,
            $credentials['key_secret']
        );

        if (hash_equals($generatedSignature, $request->razorpay_signature)) {
            // Update transaction status
            $transaction = Transaction::where('razorpay_order_id', $request->razorpay_order_id)->first();
            
            if ($transaction) {
                $transaction->update([
                    'razorpay_payment_id' => $request->razorpay_payment_id,
                    'razorpay_signature' => $request->razorpay_signature,
                    'status' => 'captured',
                ]);
            } else {
                // If order was not saved during createOrder for some reason, create it now
                Transaction::create([
                    'user_id' => auth()->id(),
                    'razorpay_order_id' => $request->razorpay_order_id,
                    'razorpay_payment_id' => $request->razorpay_payment_id,
                    'razorpay_signature' => $request->razorpay_signature,
                    'amount' => 0, // Would need fetching from razorpay API to get accurate amount
                    'currency' => 'INR',
                    'status' => 'captured',
                    'description' => 'Payment recorded on verification fallback'
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment verified successfully.'
            ]);
        }

        // Mark transaction as failed if signature doesn't match
        $transaction = Transaction::where('razorpay_order_id', $request->razorpay_order_id)->first();
        if ($transaction) {
            $transaction->update([
                'status' => 'failed',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Payment verification failed.'
        ], 400);
    }
}
