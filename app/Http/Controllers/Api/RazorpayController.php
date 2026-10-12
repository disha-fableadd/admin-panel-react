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
            'amount' => 'required|numeric|min:1',
            'currency' => 'nullable|string',
            'receipt' => 'nullable|string',
            'client_id' => 'nullable|integer',
            'payment_type' => 'nullable|string',
            'description' => 'nullable|string',
            'name' => 'nullable|string',
            'email' => 'nullable|string',
            'contact' => 'nullable|string',
        ]);

        $credentials = $this->getRazorpayCredentials();

        if (empty($credentials['key_id']) || empty($credentials['key_secret'])) {
            return response()->json(['success' => false, 'message' => 'Razorpay credentials not configured.'], 400);
        }

        $amountInPaise = round($request->amount * 100);
        $client = $request->client_id ? \App\Models\Client::find($request->client_id) : null;
        $paymentType = $request->payment_type ?? 'purchase';

        $clientName = $request->name ?? ($client ? $client->client_name : '');
        $clientEmail = $request->email ?? ($client ? $client->work_email : '');
        $clientContact = $request->contact ?? ($client ? $client->mobile : '');

        $response = Http::withBasicAuth($credentials['key_id'], $credentials['key_secret'])
            ->post('https://api.razorpay.com/v1/orders', [
                'amount' => $amountInPaise,
                'currency' => $request->currency ?? 'INR',
                'receipt' => $request->receipt ?? ('rcpt_' . uniqid()),
                'notes' => [
                    'client_id' => (string) ($request->client_id ?? ''),
                    'payment_type' => (string) $paymentType,
                    'description' => (string) ($request->description ?? 'Checkout payment'),
                ]
            ]);

        if ($response->successful()) {
            $orderData = $response->json();
            
            // Create a pending transaction record
            $transaction = Transaction::create([
                'user_id' => auth()->id() ?? null,
                'client_id' => $request->client_id,
                'razorpay_order_id' => $orderData['id'],
                'amount' => $request->amount,
                'currency' => $orderData['currency'] ?? 'INR',
                'status' => 'pending',
                'payment_method' => 'Razorpay',
                'payment_type' => $paymentType,
                'description' => $request->description ?? 'Razorpay standard checkout order',
            ]);

            // If it's a renewal, make sure an Upcoming renewal record exists in the table
            if ($paymentType === 'renewal' && $client) {
                $existingRenewal = \App\Models\Renewal::where('client_id', $client->id)
                    ->whereIn('renewal_status', ['Upcoming', 'Overdue', 'Pending'])
                    ->first();
                    
                if (!$existingRenewal) {
                    $cycle = strtolower(trim($client->billing_title ?? ''));
                    $newEndDate = null;
                    if ($client->expiry_date) {
                        $newEndDate = (strpos($cycle, 'month') !== false) 
                            ? \Carbon\Carbon::parse($client->expiry_date)->addMonth()->toDateString() 
                            : \Carbon\Carbon::parse($client->expiry_date)->addYear()->toDateString();
                    }

                    \App\Models\Renewal::create([
                        'client_id' => $client->id,
                        'renewal_status' => 'Upcoming',
                        'amount' => $request->amount,
                        'previous_end_date' => $client->expiry_date,
                        'renewal_date' => null,
                        'new_end_date' => $newEndDate,
                    ]);
                }
            }

            $companyName = Setting::where('key', 'mail_from_name')->value('value') ?? config('app.name', 'Fablead Developers');

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully.',
                'data' => [
                    'order_id' => $orderData['id'],
                    'amount' => $orderData['amount'], // in paise for Razorpay Checkout
                    'amount_in_rupees' => (float) $request->amount,
                    'currency' => $orderData['currency'],
                    'key_id' => $credentials['key_id'],
                    'name' => $companyName,
                    'description' => $request->description ?? 'Payment for Package',
                    'prefill' => [
                        'name' => $clientName,
                        'email' => $clientEmail,
                        'contact' => $clientContact,
                    ],
                    'notes' => [
                        'client_id' => $request->client_id,
                        'payment_type' => $paymentType,
                    ],
                    'transaction_id' => $transaction->id,
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
            'client_id' => 'nullable|integer',
            'payment_type' => 'nullable|string',
        ]);

        $credentials = $this->getRazorpayCredentials();

        if (empty($credentials['key_secret'])) {
            return response()->json(['success' => false, 'message' => 'Razorpay secret key not configured.'], 400);
        }

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
                    'status' => 'paid',
                    'payment_method' => 'Razorpay',
                ]);
            } else {
                // If order was not saved during createOrder for some reason, create it now
                $transaction = Transaction::create([
                    'user_id' => auth()->id() ?? null,
                    'client_id' => $request->client_id ?? null,
                    'razorpay_order_id' => $request->razorpay_order_id,
                    'razorpay_payment_id' => $request->razorpay_payment_id,
                    'razorpay_signature' => $request->razorpay_signature,
                    'amount' => $request->amount ?? 0,
                    'currency' => $request->currency ?? 'INR',
                    'status' => 'paid',
                    'payment_method' => 'Razorpay',
                    'payment_type' => $request->payment_type ?? 'purchase',
                    'description' => 'Payment recorded on verification fallback'
                ]);
            }

            $clientId = $transaction->client_id ?? $request->client_id;
            $paymentType = $transaction->payment_type ?? $request->payment_type ?? 'purchase';

            // Auto-activate client upon payment
            if ($clientId) {
                \App\Models\Client::where('id', $clientId)->update(['status' => 'Active']);
            }

            // Automatically update client's expiry date if it's a renewal
            if ($clientId && $paymentType === 'renewal') {
                $client = \App\Models\Client::find($clientId);
                if ($client) {
                    $renewal = \App\Models\Renewal::where('client_id', $clientId)
                        ->whereIn('renewal_status', ['Upcoming', 'Overdue', 'Pending'])
                        ->first();
                    
                    $cycle = strtolower(trim($client->billing_title ?? ''));
                    $newEndDate = null;
                    if ($client->expiry_date) {
                        $newEndDate = (strpos($cycle, 'month') !== false) 
                            ? \Carbon\Carbon::parse($client->expiry_date)->addMonth()->toDateString() 
                            : \Carbon\Carbon::parse($client->expiry_date)->addYear()->toDateString();
                    }

                    if ($renewal) {
                        $renewal->update([
                            'renewal_status' => 'Completed',
                            'renewal_date' => \Carbon\Carbon::today()->toDateString(),
                            'amount' => $transaction->amount,
                            'new_end_date' => $renewal->new_end_date ?? $newEndDate,
                        ]);
                        $client->update([
                            'expiry_date' => $renewal->new_end_date ?? $newEndDate
                        ]);
                    } else {
                        \App\Models\Renewal::create([
                            'client_id' => $client->id,
                            'renewal_status' => 'Completed',
                            'amount' => $transaction->amount,
                            'previous_end_date' => $client->expiry_date,
                            'renewal_date' => \Carbon\Carbon::today()->toDateString(),
                            'new_end_date' => $newEndDate,
                        ]);
                        $client->update([
                            'expiry_date' => $newEndDate
                        ]);
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment verified successfully.',
                'data' => [
                    'transaction_id' => $transaction->id ?? null,
                    'order_id' => $request->razorpay_order_id,
                    'payment_id' => $request->razorpay_payment_id,
                    'status' => 'paid',
                ]
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

    public function getOrderStatus($orderId)
    {
        $transaction = Transaction::where('razorpay_order_id', $orderId)
            ->with(['client.membership', 'client.product'])
            ->first();

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Order transaction not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $transaction
        ]);
    }

    public function createPaymentLink(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric',
            'client_id' => 'required|integer',
            'description' => 'nullable|string',
            'currency' => 'nullable|string',
            'payment_type' => 'nullable|string|in:purchase,renewal',
        ]);

        $credentials = $this->getRazorpayCredentials();

        if (empty($credentials['key_id']) || empty($credentials['key_secret'])) {
            return response()->json(['success' => false, 'message' => 'Razorpay credentials not configured.'], 400);
        }

        $amountInPaise = $request->amount * 100;

        $client = \App\Models\Client::find($request->client_id);
        $customer = [
            'name' => $client ? $client->client_name : 'Customer',
            'email' => $client ? $client->work_email : '',
            'contact' => $client ? $client->mobile : '',
        ];

        $response = Http::withBasicAuth($credentials['key_id'], $credentials['key_secret'])
            ->post('https://api.razorpay.com/v1/payment_links', [
                'amount' => $amountInPaise,
                'currency' => $request->currency ?? 'INR',
                'accept_partial' => false,
                'description' => $request->description ?? 'Payment for invoice',
                'customer' => $customer,
                'notify' => [
                    'sms' => false,
                    'email' => false
                ],
                'reminder_enable' => true,
            ]);

        if ($response->successful()) {
            $linkData = $response->json();
            $paymentType = $request->payment_type ?? 'purchase';
            
            $existingTransaction = null;
            if ($client && $client->plan_status === 'Purchase' && $paymentType === 'purchase') {
                $existingTransaction = Transaction::where('client_id', $request->client_id)
                    ->where('payment_type', 'purchase')
                    ->where('status', 'pending')
                    ->first();
            }

            if ($existingTransaction) {
                $existingTransaction->update([
                    'user_id' => auth()->id(),
                    'payment_link_id' => $linkData['id'],
                    'short_url' => $linkData['short_url'],
                    'amount' => $request->amount,
                    'currency' => $linkData['currency'],
                    'description' => $request->description,
                    'payment_method' => 'Razorpay Link',
                ]);
            } else {
                // Create a pending transaction record
                Transaction::create([
                    'user_id' => auth()->id(),
                    'client_id' => $request->client_id,
                    'payment_link_id' => $linkData['id'],
                    'short_url' => $linkData['short_url'],
                    'amount' => $request->amount,
                    'currency' => $linkData['currency'],
                    'status' => 'pending', // Pending payment link
                    'description' => $request->description,
                    'payment_type' => $paymentType,
                    'payment_method' => 'Razorpay Link',
                ]);
            }

            // If it's a renewal, make sure an Upcoming renewal record exists in the table
            if (($request->payment_type ?? 'purchase') === 'renewal' && $client) {
                $existingRenewal = \App\Models\Renewal::where('client_id', $client->id)
                    ->whereIn('renewal_status', ['Upcoming', 'Overdue', 'Pending'])
                    ->first();
                    
                if (!$existingRenewal) {
                    $cycle = strtolower(trim($client->billing_title ?? ''));
                    $newEndDate = null;
                    if ($client->expiry_date) {
                        $newEndDate = (strpos($cycle, 'month') !== false) 
                            ? \Carbon\Carbon::parse($client->expiry_date)->addMonth()->toDateString() 
                            : \Carbon\Carbon::parse($client->expiry_date)->addYear()->toDateString();
                    }

                    \App\Models\Renewal::create([
                        'client_id' => $client->id,
                        'renewal_status' => 'Upcoming',
                        'amount' => $request->amount,
                        'previous_end_date' => $client->expiry_date,
                        'renewal_date' => null,
                        'new_end_date' => $newEndDate,
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'payment_link_id' => $linkData['id'],
                    'short_url' => $linkData['short_url'],
                    'amount' => $request->amount,
                ]
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Failed to create payment link',
            'error' => $response->json(),
        ], 500);
    }

    public function webhook(Request $request)
    {
        $webhookSecret = Setting::where('key', 'webhook_secret')->value('value') ?? env('RAZORPAY_WEBHOOK_SECRET');
        $signature = $request->header('X-Razorpay-Signature');

        if (!$signature || !$webhookSecret) {
            return response()->json(['success' => false, 'message' => 'Invalid signature or secret missing'], 400);
        }

        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);

        if (!hash_equals($expectedSignature, $signature)) {
            return response()->json(['success' => false, 'message' => 'Invalid signature'], 400);
        }

        $data = json_decode($payload, true);
        $event = $data['event'] ?? null;

        if ($event === 'payment_link.paid' || $event === 'payment_link.authenticated') {
            $paymentLinkId = $data['payload']['payment_link']['entity']['id'] ?? null;
            $paymentId = $data['payload']['payment_link']['entity']['payment_id'] ?? null;
            
            if ($paymentLinkId) {
                $transaction = Transaction::where('payment_link_id', $paymentLinkId)->first();
                if ($transaction && $transaction->status !== 'paid') {
                    $transaction->update([
                        'status' => 'paid',
                        'razorpay_payment_id' => $paymentId,
                    ]);

                    // Auto-activate client upon payment
                    if ($transaction->client_id) {
                        \App\Models\Client::where('id', $transaction->client_id)->update(['status' => 'Active']);
                    }

                    // Automatically update client's expiry date if it's a renewal
                    if ($transaction->client_id && $transaction->payment_type === 'renewal') {
                        $client = \App\Models\Client::find($transaction->client_id);
                        if ($client) {
                            $renewal = \App\Models\Renewal::where('client_id', $transaction->client_id)
                                ->whereIn('renewal_status', ['Upcoming', 'Overdue', 'Pending'])
                                ->first();
                            
                            $cycle = strtolower(trim($client->billing_title ?? ''));
                            $newEndDate = null;
                            if ($client->expiry_date) {
                                $newEndDate = (strpos($cycle, 'month') !== false) 
                                    ? \Carbon\Carbon::parse($client->expiry_date)->addMonth()->toDateString() 
                                    : \Carbon\Carbon::parse($client->expiry_date)->addYear()->toDateString();
                            }

                            if ($renewal) {
                                $renewal->update([
                                    'renewal_status' => 'Completed',
                                    'renewal_date' => \Carbon\Carbon::today()->toDateString(),
                                    'amount' => $transaction->amount,
                                    'new_end_date' => $renewal->new_end_date ?? $newEndDate,
                                ]);
                                $client->update([
                                    'expiry_date' => $renewal->new_end_date ?? $newEndDate
                                ]);
                            } else {
                                \App\Models\Renewal::create([
                                    'client_id' => $client->id,
                                    'renewal_status' => 'Completed',
                                    'amount' => $transaction->amount,
                                    'previous_end_date' => $client->expiry_date,
                                    'renewal_date' => \Carbon\Carbon::today()->toDateString(),
                                    'new_end_date' => $newEndDate,
                                ]);
                                $client->update([
                                    'expiry_date' => $newEndDate
                                ]);
                            }
                        }
                    }
                }
            }
        } elseif ($event === 'payment.captured') {
            $orderId = $data['payload']['payment']['entity']['order_id'] ?? null;
            $paymentId = $data['payload']['payment']['entity']['id'] ?? null;
            
            if ($orderId) {
                $transaction = Transaction::where('razorpay_order_id', $orderId)->first();
                if ($transaction && $transaction->status !== 'paid') {
                    $transaction->update([
                        'status' => 'paid',
                        'razorpay_payment_id' => $paymentId,
                    ]);

                    // Auto-activate client upon payment
                    if ($transaction->client_id) {
                        \App\Models\Client::where('id', $transaction->client_id)->update(['status' => 'Active']);
                    }

                    // Automatically update client's expiry date if it's a renewal
                    if ($transaction->client_id && $transaction->payment_type === 'renewal') {
                        $client = \App\Models\Client::find($transaction->client_id);
                        if ($client) {
                            $renewal = \App\Models\Renewal::where('client_id', $transaction->client_id)
                                ->whereIn('renewal_status', ['Upcoming', 'Overdue', 'Pending'])
                                ->first();
                            
                            $cycle = strtolower(trim($client->billing_title ?? ''));
                            $newEndDate = null;
                            if ($client->expiry_date) {
                                $newEndDate = (strpos($cycle, 'month') !== false) 
                                    ? \Carbon\Carbon::parse($client->expiry_date)->addMonth()->toDateString() 
                                    : \Carbon\Carbon::parse($client->expiry_date)->addYear()->toDateString();
                            }

                            if ($renewal) {
                                $renewal->update([
                                    'renewal_status' => 'Completed',
                                    'renewal_date' => \Carbon\Carbon::today()->toDateString(),
                                    'amount' => $transaction->amount,
                                    'new_end_date' => $renewal->new_end_date ?? $newEndDate,
                                ]);
                                $client->update([
                                    'expiry_date' => $renewal->new_end_date ?? $newEndDate
                                ]);
                            } else {
                                \App\Models\Renewal::create([
                                    'client_id' => $client->id,
                                    'renewal_status' => 'Completed',
                                    'amount' => $transaction->amount,
                                    'previous_end_date' => $client->expiry_date,
                                    'renewal_date' => \Carbon\Carbon::today()->toDateString(),
                                    'new_end_date' => $newEndDate,
                                ]);
                                $client->update([
                                    'expiry_date' => $newEndDate
                                ]);
                            }
                        }
                    }
                }
            }
        }

        return response()->json(['success' => true]);
    }
}
