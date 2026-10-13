<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\Client;
use App\Models\Renewal;
use App\Services\MailService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    /**
     * Complete and mark a transaction as paid, auto-activate client, process renewals, and send notification emails.
     */
    private function completePaidTransaction(Transaction $transaction, ?string $paymentId = null, ?string $signature = null): Transaction
    {
        $wasAlreadyPaid = ($transaction->status === 'paid');

        $updateData = [
            'status' => 'paid',
        ];

        if ($paymentId) {
            $updateData['razorpay_payment_id'] = $paymentId;
        }

        if ($signature) {
            $updateData['razorpay_signature'] = $signature;
        }

        if (empty($transaction->payment_method)) {
            $updateData['payment_method'] = $transaction->payment_link_id ? 'Razorpay Link' : 'Razorpay';
        }

        $transaction->update($updateData);

        $clientId = $transaction->client_id;
        $paymentType = $transaction->payment_type ?? 'purchase';

        // Auto-activate client upon payment
        if ($clientId) {
            Client::where('id', $clientId)->update(['status' => 'Active']);
        }

        // Automatically update client's expiry date if it's a renewal
        if ($clientId && $paymentType === 'renewal') {
            $client = Client::find($clientId);
            if ($client) {
                $renewal = Renewal::where('client_id', $clientId)
                    ->whereIn('renewal_status', ['Upcoming', 'Overdue', 'Pending'])
                    ->first();
                
                $cycle = strtolower(trim($client->billing_title ?? ''));
                $newEndDate = null;
                if ($client->expiry_date) {
                    $newEndDate = (strpos($cycle, 'month') !== false) 
                        ? Carbon::parse($client->expiry_date)->addMonth()->toDateString() 
                        : Carbon::parse($client->expiry_date)->addYear()->toDateString();
                }

                if ($renewal) {
                    $renewal->update([
                        'renewal_status' => 'Completed',
                        'renewal_date' => Carbon::today()->toDateString(),
                        'amount' => $transaction->amount,
                        'new_end_date' => $renewal->new_end_date ?? $newEndDate,
                    ]);
                    $client->update([
                        'expiry_date' => $renewal->new_end_date ?? $newEndDate
                    ]);
                } else {
                    Renewal::create([
                        'client_id' => $client->id,
                        'renewal_status' => 'Completed',
                        'amount' => $transaction->amount,
                        'previous_end_date' => $client->expiry_date,
                        'renewal_date' => Carbon::today()->toDateString(),
                        'new_end_date' => $newEndDate,
                    ]);
                    $client->update([
                        'expiry_date' => $newEndDate
                    ]);
                }
            }
        }

        // Trigger email notification to both Client and Admin if not previously marked paid
        if (!$wasAlreadyPaid) {
            try {
                $mailService = app(MailService::class);
                $mailService->sendPaymentSuccessNotifications($transaction);
            } catch (\Throwable $e) {
                Log::error("Failed to send payment success notification for Transaction #{$transaction->id}: " . $e->getMessage());
            }
        }

        return $transaction->fresh();
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
            'razorpay_order_id' => 'nullable|string',
            'razorpay_payment_link_id' => 'nullable|string',
            'payment_link_id' => 'nullable|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature' => 'nullable|string',
            'client_id' => 'nullable|integer',
            'payment_type' => 'nullable|string',
        ]);

        $credentials = $this->getRazorpayCredentials();

        if (empty($credentials['key_secret'])) {
            return response()->json(['success' => false, 'message' => 'Razorpay secret key not configured.'], 400);
        }

        $orderId = $request->razorpay_order_id;
        $paymentLinkId = $request->razorpay_payment_link_id ?? $request->payment_link_id;

        if (empty($orderId) && empty($paymentLinkId)) {
            return response()->json([
                'success' => false,
                'message' => 'Either razorpay_order_id or razorpay_payment_link_id is required.'
            ], 422);
        }

        $isSignatureValid = false;

        // Verify standard order signature
        if (!empty($orderId) && !empty($request->razorpay_signature)) {
            $generatedSignature = hash_hmac(
                'sha256',
                $orderId . '|' . $request->razorpay_payment_id,
                $credentials['key_secret']
            );
            $isSignatureValid = hash_equals($generatedSignature, $request->razorpay_signature);
        } elseif (!empty($paymentLinkId) && !empty($request->razorpay_signature)) {
            // Verify payment link signatures
            $sig1 = hash_hmac(
                'sha256',
                $paymentLinkId . '|' . ($request->razorpay_payment_link_reference_id ?? '') . '|' . ($request->razorpay_payment_link_status ?? '') . '|' . $request->razorpay_payment_id,
                $credentials['key_secret']
            );
            $sig2 = hash_hmac(
                'sha256',
                $paymentLinkId . '|' . $request->razorpay_payment_id,
                $credentials['key_secret']
            );
            $isSignatureValid = hash_equals($sig1, $request->razorpay_signature) || hash_equals($sig2, $request->razorpay_signature);
        }

        // Direct API verification fallback if signature matching failed
        if (!$isSignatureValid && !empty($credentials['key_id'])) {
            try {
                $response = Http::withBasicAuth($credentials['key_id'], $credentials['key_secret'])
                    ->get('https://api.razorpay.com/v1/payments/' . $request->razorpay_payment_id);
                if ($response->successful()) {
                    $paymentData = $response->json();
                    if (in_array($paymentData['status'] ?? '', ['captured', 'authorized'])) {
                        $isSignatureValid = true;
                    }
                }
            } catch (\Throwable $e) {
                // Ignore API error
            }
        }

        if ($isSignatureValid) {
            // Find existing transaction
            $transaction = null;
            if (!empty($orderId)) {
                $transaction = Transaction::where('razorpay_order_id', $orderId)->first();
            }
            if (!$transaction && !empty($paymentLinkId)) {
                $transaction = Transaction::where('payment_link_id', $paymentLinkId)->first();
            }
            if (!$transaction) {
                $transaction = Transaction::where('razorpay_payment_id', $request->razorpay_payment_id)->first();
            }

            if (!$transaction) {
                // Fallback transaction creation
                $transaction = Transaction::create([
                    'user_id' => auth()->id() ?? null,
                    'client_id' => $request->client_id ?? null,
                    'razorpay_order_id' => $orderId,
                    'payment_link_id' => $paymentLinkId,
                    'razorpay_payment_id' => $request->razorpay_payment_id,
                    'razorpay_signature' => $request->razorpay_signature,
                    'amount' => $request->amount ?? 0,
                    'currency' => $request->currency ?? 'INR',
                    'status' => 'pending',
                    'payment_method' => $paymentLinkId ? 'Razorpay Link' : 'Razorpay',
                    'payment_type' => $request->payment_type ?? 'purchase',
                    'description' => 'Payment recorded on verification fallback'
                ]);
            }

            // Complete transaction: marks paid, activates client, processes renewal, and sends client & admin emails
            $transaction = $this->completePaidTransaction(
                $transaction,
                $request->razorpay_payment_id,
                $request->razorpay_signature
            );

            return response()->json([
                'success' => true,
                'message' => 'Payment verified successfully.',
                'data' => [
                    'transaction_id' => $transaction->id ?? null,
                    'order_id' => $orderId,
                    'payment_link_id' => $paymentLinkId,
                    'payment_id' => $request->razorpay_payment_id,
                    'status' => 'paid',
                ]
            ]);
        }

        // Mark transaction as failed if verification failed
        if (!empty($orderId)) {
            $transaction = Transaction::where('razorpay_order_id', $orderId)->first();
            if ($transaction && $transaction->status !== 'paid') {
                $transaction->update(['status' => 'failed']);
            }
        } elseif (!empty($paymentLinkId)) {
            $transaction = Transaction::where('payment_link_id', $paymentLinkId)->first();
            if ($transaction && $transaction->status !== 'paid') {
                $transaction->update(['status' => 'failed']);
            }
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

        // Auto-sync status with Razorpay if still pending in local database
        if ($transaction->status !== 'paid') {
            $credentials = $this->getRazorpayCredentials();
            if (!empty($credentials['key_id']) && !empty($credentials['key_secret'])) {
                try {
                    $response = Http::withBasicAuth($credentials['key_id'], $credentials['key_secret'])
                        ->get('https://api.razorpay.com/v1/orders/' . $orderId . '/payments');
                    if ($response->successful()) {
                        $payments = $response->json();
                        foreach ($payments['items'] ?? [] as $payment) {
                            if (in_array($payment['status'] ?? '', ['captured', 'authorized'])) {
                                $transaction = $this->completePaidTransaction($transaction, $payment['id']);
                                break;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // Ignore sync error
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => $transaction->fresh()->load(['client.membership', 'client.product'])
        ]);
    }

    public function getPaymentLinkStatus($linkId)
    {
        $transaction = Transaction::where('payment_link_id', $linkId)
            ->with(['client.membership', 'client.product'])
            ->first();

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Payment link transaction not found.'
            ], 404);
        }

        // Auto-sync status with Razorpay if still pending in local database
        if ($transaction->status !== 'paid') {
            $credentials = $this->getRazorpayCredentials();
            if (!empty($credentials['key_id']) && !empty($credentials['key_secret'])) {
                try {
                    $response = Http::withBasicAuth($credentials['key_id'], $credentials['key_secret'])
                        ->get('https://api.razorpay.com/v1/payment_links/' . $linkId);
                    if ($response->successful()) {
                        $linkData = $response->json();
                        if (($linkData['status'] ?? '') === 'paid') {
                            $paymentId = null;
                            if (!empty($linkData['payments']) && is_array($linkData['payments'])) {
                                $lastPayment = end($linkData['payments']);
                                $paymentId = $lastPayment['payment_id'] ?? null;
                            }
                            $transaction = $this->completePaidTransaction($transaction, $paymentId);
                        }
                    }
                } catch (\Throwable $e) {
                    // Ignore sync error
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => $transaction->fresh()->load(['client.membership', 'client.product'])
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

        $client = Client::find($request->client_id);
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
                $transaction = $existingTransaction;
            } else {
                // Create a pending transaction record
                $transaction = Transaction::create([
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
                $existingRenewal = Renewal::where('client_id', $client->id)
                    ->whereIn('renewal_status', ['Upcoming', 'Overdue', 'Pending'])
                    ->first();
                    
                if (!$existingRenewal) {
                    $cycle = strtolower(trim($client->billing_title ?? ''));
                    $newEndDate = null;
                    if ($client->expiry_date) {
                        $newEndDate = (strpos($cycle, 'month') !== false) 
                            ? Carbon::parse($client->expiry_date)->addMonth()->toDateString() 
                            : Carbon::parse($client->expiry_date)->addYear()->toDateString();
                    }

                    Renewal::create([
                        'client_id' => $client->id,
                        'renewal_status' => 'Upcoming',
                        'amount' => $request->amount,
                        'previous_end_date' => $client->expiry_date,
                        'renewal_date' => null,
                        'new_end_date' => $newEndDate,
                    ]);
                }
            }

            // Send branded payment link invitation to client if client email is available
            if ($client && !empty($client->work_email)) {
                try {
                    $mailService = app(MailService::class);
                    $mailService->sendPaymentLinkEmail($transaction, $linkData['short_url'], $client);
                } catch (\Throwable $e) {
                    Log::warning('Could not send payment link email: ' . $e->getMessage());
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
                if ($transaction) {
                    $this->completePaidTransaction($transaction, $paymentId);
                }
            }
        } elseif ($event === 'payment.captured') {
            $orderId = $data['payload']['payment']['entity']['order_id'] ?? null;
            $paymentId = $data['payload']['payment']['entity']['id'] ?? null;
            
            if ($orderId) {
                $transaction = Transaction::where('razorpay_order_id', $orderId)->first();
                if ($transaction) {
                    $this->completePaidTransaction($transaction, $paymentId);
                }
            }
        }

        return response()->json(['success' => true]);
    }
}
