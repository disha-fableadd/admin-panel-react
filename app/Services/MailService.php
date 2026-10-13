<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MailService
{
    /**
     * Dynamically configure Laravel Mail using settings from database.
     */
    public function configureSmtp(): bool
    {
        $settings = Setting::pluck('value', 'key')->toArray();

        $smtpActive = $settings['smtp_active'] ?? $settings['smtp_status'] ?? $settings['smtp_enabled'] ?? $settings['is_smtp_active'] ?? '1';
        if ($smtpActive === '0' || $smtpActive === 'false' || $smtpActive === false) {
            return false;
        }

        $mailer     = $settings['mail_mailer'] ?? $settings['mailMailer'] ?? 'smtp';
        $host       = $settings['mail_host'] ?? $settings['mailHost'] ?? null;
        $port       = $settings['mail_port'] ?? $settings['mailPort'] ?? 465;
        $username   = $settings['mail_username'] ?? $settings['mailUsername'] ?? null;
        $password   = $settings['mail_password'] ?? $settings['mailPassword'] ?? null;
        $encryption = $settings['mail_encryption'] ?? $settings['mailEncryption'] ?? 'ssl';
        $fromAddress = $settings['mail_from_address'] ?? $settings['mailFromAddress'] ?? $username;
        $fromName    = $settings['mail_from_name'] ?? $settings['mailFromName'] ?? config('app.name', 'Admin Panel');

        if (empty($host) || empty($username) || empty($password)) {
            Log::warning('SMTP Configuration is incomplete in settings table. Cannot send email.');
            return false;
        }

        Config::set('mail.default', $mailer ?: 'smtp');
        Config::set('mail.mailers.smtp.transport', 'smtp');
        Config::set('mail.mailers.smtp.host', $host);
        Config::set('mail.mailers.smtp.port', (int) $port);
        Config::set('mail.mailers.smtp.encryption', ($encryption === 'none' || empty($encryption)) ? null : strtolower($encryption));
        Config::set('mail.mailers.smtp.username', $username);
        Config::set('mail.mailers.smtp.password', $password);
        Config::set('mail.from.address', $fromAddress);
        Config::set('mail.from.name', $fromName);

        // Purge cached transport so newly configured transport takes effect immediately
        try {
            Mail::purge('smtp');
        } catch (\Throwable $e) {
            // Ignore purge exception
        }

        return true;
    }

    /**
     * Helper to retrieve all active administrator recipient emails.
     */
    public function getAdminRecipients(): array
    {
        $settings = Setting::pluck('value', 'key')->toArray();

        $adminEmails = [];
        if (!empty($settings['admin_email'])) {
            $adminEmails[] = trim($settings['admin_email']);
        }
        if (!empty($settings['adminEmail'])) {
            $adminEmails[] = trim($settings['adminEmail']);
        }

        // Fallback: look up super admin / admin users from database
        if (empty($adminEmails)) {
            $dbAdmins = User::whereHas('roleModel', function ($q) {
                $q->whereIn('name', ['Super Admin', 'super admin', 'Admin', 'admin']);
            })->pluck('email')->toArray();

            $adminEmails = array_merge($adminEmails, $dbAdmins);
        }

        // Fallback to mail_from_address
        if (empty($adminEmails) && !empty($settings['mail_from_address'])) {
            $adminEmails[] = trim($settings['mail_from_address']);
        }

        return array_values(array_unique(array_filter($adminEmails)));
    }

    /**
     * Send welcome email to newly created client with full account and project details.
     */
    public function sendClientWelcomeEmail(Client $client)
    {
        if (empty($client->work_email)) {
            return false;
        }

        if (!$this->configureSmtp()) {
            return false;
        }

        try {
            $client->loadMissing(['product', 'project', 'membership']);

            $productTitle   = $client->product ? $client->product->title : 'N/A';
            $projectName    = $client->project ? $client->project->name : 'N/A';
            $membershipPlan = $client->membership ? $client->membership->plan_name : 'N/A';
            $startDate      = $client->calculated_start_date ?? ($client->start_date ?: date('Y-m-d'));
            $expiryDate     = $client->calculated_end_date ?? ($client->expiry_date ?: 'N/A');
            $passwordText   = !empty($client->password) ? $client->password : '(Not set or managed by administrator)';

            $html = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='utf-8'>
                <style>
                    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; margin: 0; padding: 20px; color: #333; }
                    .card { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
                    .header { background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); padding: 30px 20px; text-align: center; color: #ffffff; }
                    .header h1 { margin: 0; font-size: 24px; font-weight: 700; }
                    .content { padding: 30px 25px; }
                    .greeting { font-size: 16px; margin-bottom: 20px; line-height: 1.5; }
                    .info-table { width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #f9fafb; border-radius: 8px; overflow: hidden; }
                    .info-table td { padding: 12px 15px; border-bottom: 1px solid #edf2f7; font-size: 14px; }
                    .info-table tr:last-child td { border-bottom: none; }
                    .info-label { font-weight: 600; color: #4b5563; width: 38%; }
                    .info-value { color: #111827; }
                    .highlight { background-color: #eef2ff; color: #4338ca; padding: 3px 8px; border-radius: 4px; font-family: monospace; font-size: 14px; }
                    .footer { text-align: center; padding: 20px; font-size: 12px; color: #9ca3af; border-top: 1px solid #edf2f7; }
                </style>
            </head>
            <body>
                <div class='card'>
                    <div class='header'>
                        <h1>Welcome to Our Platform</h1>
                        <p style='margin: 8px 0 0 0; opacity: 0.9;'>Your Account & Project Details</p>
                    </div>
                    <div class='content'>
                        <p class='greeting'>Hello <strong>{$client->client_name}</strong>,</p>
                        <p class='greeting'>Thank you for your purchase! Your client account and subscription have been configured successfully. Here are your account credentials and project details:</p>
                        
                        <table class='info-table'>
                            <tr>
                                <td class='info-label'>Client Name:</td>
                                <td class='info-value'>{$client->client_name}</td>
                            </tr>
                            " . (!empty($client->brand_name) ? "<tr><td class='info-label'>Brand Name:</td><td class='info-value'>{$client->brand_name}</td></tr>" : "") . "
                            <tr>
                                <td class='info-label'>Product:</td>
                                <td class='info-value'><strong>{$productTitle}</strong></td>
                            </tr>
                            <tr>
                                <td class='info-label'>Project:</td>
                                <td class='info-value'><strong>{$projectName}</strong></td>
                            </tr>
                            <tr>
                                <td class='info-label'>Membership Plan:</td>
                                <td class='info-value'>{$membershipPlan}</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Start Date:</td>
                                <td class='info-value'>{$startDate}</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Expiry Date:</td>
                                <td class='info-value'>{$expiryDate}</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Login Email:</td>
                                <td class='info-value'><span class='highlight'>{$client->work_email}</span></td>
                            </tr>
                            <tr>
                                <td class='info-label'>Login Password:</td>
                                <td class='info-value'><span class='highlight'>{$passwordText}</span></td>
                            </tr>
                        </table>

                        <p style='font-size: 13px; color: #6b7280; line-height: 1.6; margin-top: 25px;'>
                            Please keep this information secure. You can use your email and password to log into your client portal at any time.
                        </p>
                    </div>
                    <div class='footer'>
                        &copy; " . date('Y') . " " . config('app.name', 'Admin Panel') . ". All rights reserved.
                    </div>
                </div>
            </body>
            </html>
            ";

            Mail::html($html, function ($message) use ($client) {
                $message->to($client->work_email, $client->client_name)
                        ->subject('Your Account & Project Details - ' . config('app.name', 'Admin Panel'));
            });

            Log::info("Welcome email sent successfully to client: {$client->work_email}");
            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to send welcome email to client ({$client->work_email}): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send new client registration notification email to Administrator(s).
     */
    public function sendAdminNewClientEmail(Client $client)
    {
        if (!$this->configureSmtp()) {
            return false;
        }

        try {
            $client->loadMissing(['product', 'project', 'membership']);
            $adminEmails = $this->getAdminRecipients();

            if (empty($adminEmails)) {
                Log::warning('No admin email recipient found to notify about new client creation.');
                return false;
            }

            $productTitle   = $client->product ? $client->product->title : 'N/A';
            $projectName    = $client->project ? $client->project->name : 'N/A';
            $membershipPlan = $client->membership ? $client->membership->plan_name : 'N/A';
            $amountText     = !empty($client->amount) && is_array($client->amount) ? json_encode($client->amount) : 'N/A';

            $html = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='utf-8'>
                <style>
                    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; margin: 0; padding: 20px; color: #333; }
                    .card { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
                    .header { background: linear-gradient(135deg, #0ea5e9 0%, #2563eb 100%); padding: 25px 20px; text-align: center; color: #ffffff; }
                    .header h1 { margin: 0; font-size: 22px; font-weight: 700; }
                    .content { padding: 30px 25px; }
                    .info-table { width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #f9fafb; border-radius: 8px; overflow: hidden; }
                    .info-table td { padding: 12px 15px; border-bottom: 1px solid #edf2f7; font-size: 14px; }
                    .info-table tr:last-child td { border-bottom: none; }
                    .info-label { font-weight: 600; color: #4b5563; width: 38%; }
                    .info-value { color: #111827; }
                    .footer { text-align: center; padding: 20px; font-size: 12px; color: #9ca3af; border-top: 1px solid #edf2f7; }
                </style>
            </head>
            <body>
                <div class='card'>
                    <div class='header'>
                        <h1>New Client Registered</h1>
                        <p style='margin: 6px 0 0 0; opacity: 0.9;'>Antigravity Admin Notification</p>
                    </div>
                    <div class='content'>
                        <p style='font-size: 15px;'>A new client has just been created in the system:</p>
                        
                        <table class='info-table'>
                            <tr>
                                <td class='info-label'>Client Name:</td>
                                <td class='info-value'><strong>{$client->client_name}</strong></td>
                            </tr>
                            <tr>
                                <td class='info-label'>Brand Name:</td>
                                <td class='info-value'>" . ($client->brand_name ?: 'N/A') . "</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Work Email:</td>
                                <td class='info-value'>{$client->work_email}</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Mobile:</td>
                                <td class='info-value'>" . ($client->mobile ?: 'N/A') . "</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Product:</td>
                                <td class='info-value'>{$productTitle}</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Project:</td>
                                <td class='info-value'>{$projectName}</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Membership:</td>
                                <td class='info-value'>{$membershipPlan}</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Billing Title:</td>
                                <td class='info-value'>" . ($client->billing_title ?: 'N/A') . "</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Amount:</td>
                                <td class='info-value'>{$amountText}</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Status:</td>
                                <td class='info-value'>{$client->status}</td>
                            </tr>
                        </table>
                    </div>
                    <div class='footer'>
                        &copy; " . date('Y') . " " . config('app.name', 'Admin Panel') . ". Admin System Notification.
                    </div>
                </div>
            </body>
            </html>
            ";

            foreach ($adminEmails as $adminEmail) {
                Mail::html($html, function ($message) use ($adminEmail, $client) {
                    $message->to($adminEmail)
                            ->subject("New Client Registered: {$client->client_name} (" . config('app.name', 'Admin Panel') . ")");
                });
            }

            Log::info("Admin notification email sent for client {$client->client_name} to: " . implode(', ', $adminEmails));
            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to send admin notification email: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send payment success confirmation emails to both client and administrator(s).
     */
    public function sendPaymentSuccessNotifications(Transaction $transaction, ?Client $client = null): array
    {
        $clientSent = $this->sendPaymentSuccessClientEmail($transaction, $client);
        $adminSent  = $this->sendPaymentSuccessAdminEmail($transaction, $client);

        return [
            'client_email_sent' => $clientSent,
            'admin_email_sent'  => $adminSent,
        ];
    }

    /**
     * Send payment confirmation and receipt email to Client.
     */
    public function sendPaymentSuccessClientEmail(Transaction $transaction, ?Client $client = null): bool
    {
        if (!$client && $transaction->client_id) {
            $client = Client::find($transaction->client_id);
        }

        if ($client) {
            $client->loadMissing(['product', 'project', 'membership']);
        }

        $recipientEmail = $client ? $client->work_email : null;
        $recipientName  = $client ? $client->client_name : 'Valued Customer';

        if (empty($recipientEmail)) {
            Log::warning("No client email found to send payment confirmation for transaction #{$transaction->id}");
            return false;
        }

        if (!$this->configureSmtp()) {
            return false;
        }

        try {
            $settings = Setting::pluck('value', 'key')->toArray();
            $companyName = $settings['mail_from_name'] ?? $settings['mailFromName'] ?? config('app.name', 'Admin Panel');

            $productTitle   = $client && $client->product ? $client->product->title : 'N/A';
            $projectName    = $client && $client->project ? $client->project->name : 'N/A';
            $membershipPlan = $client && $client->membership ? $client->membership->plan_name : 'N/A';
            $expiryDate     = $client && $client->expiry_date ? \Carbon\Carbon::parse($client->expiry_date)->format('d M, Y') : 'N/A';
            
            $currencySymbol = ($transaction->currency === 'INR') ? '₹' : ($transaction->currency . ' ');
            $amountFormatted = $currencySymbol . number_format((float) $transaction->amount, 2);
            $paymentTypeFormatted = ucfirst($transaction->payment_type ?? 'Purchase');
            $paymentId = $transaction->razorpay_payment_id ?: 'N/A';
            $referenceId = $transaction->razorpay_order_id ?: ($transaction->payment_link_id ?: 'N/A');
            $paidDate = $transaction->updated_at ? $transaction->updated_at->format('d M Y, h:i A') : date('d M Y, h:i A');
            $paymentMethod = $transaction->payment_method ?: 'Razorpay Online';

            $credentialsBox = '';
            if (strtolower($transaction->payment_type ?? '') !== 'renewal' && $client && !empty($client->password)) {
                $credentialsBox = "
                <div style='background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 15px 18px; margin: 20px 0;'>
                    <h3 style='margin: 0 0 10px 0; font-size: 15px; color: #166534;'>Client Portal Credentials</h3>
                    <p style='margin: 0 0 8px 0; font-size: 13px; color: #15803d;'>You can log into your client portal anytime using your registered credentials:</p>
                    <table style='width: 100%; font-size: 13px;'>
                        <tr>
                            <td style='width: 35%; font-weight: 600; color: #166534; padding: 3px 0;'>Login Email:</td>
                            <td style='color: #14532d; font-family: monospace; font-weight: 600;'>{$client->work_email}</td>
                        </tr>
                        <tr>
                            <td style='font-weight: 600; color: #166534; padding: 3px 0;'>Password:</td>
                            <td style='color: #14532d; font-family: monospace; font-weight: 600;'>{$client->password}</td>
                        </tr>
                    </table>
                </div>
                ";
            }

            $html = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='utf-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <style>
                    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; margin: 0; padding: 25px 15px; color: #1f2937; }
                    .card { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.08), 0 8px 10px -6px rgba(0,0,0,0.04); border: 1px solid #e5e7eb; }
                    .header { background: linear-gradient(135deg, #059669 0%, #0d9488 50%, #0284c7 100%); padding: 35px 25px; text-align: center; color: #ffffff; }
                    .header .badge { display: inline-block; background-color: rgba(255, 255, 255, 0.2); padding: 5px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; }
                    .header h1 { margin: 0; font-size: 26px; font-weight: 700; }
                    .header p { margin: 8px 0 0 0; opacity: 0.95; font-size: 15px; }
                    .content { padding: 30px 28px; }
                    .greeting { font-size: 15px; line-height: 1.6; margin-bottom: 22px; color: #374151; }
                    .amount-card { background: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 10px; padding: 18px 20px; text-align: center; margin-bottom: 25px; }
                    .amount-label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 5px; }
                    .amount-value { font-size: 30px; font-weight: 800; color: #0f172a; }
                    .status-paid { display: inline-block; background-color: #dcfce7; color: #15803d; font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 12px; text-transform: uppercase; margin-top: 6px; }
                    .info-table { width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
                    .info-table td { padding: 11px 16px; border-bottom: 1px solid #edf2f7; font-size: 14px; }
                    .info-table tr:last-child td { border-bottom: none; }
                    .info-label { font-weight: 600; color: #475569; width: 40%; background-color: #f8fafc; }
                    .info-value { color: #0f172a; }
                    .code-badge { background-color: #f1f5f9; color: #334155; padding: 2px 7px; border-radius: 4px; font-family: monospace; font-size: 13px; }
                    .footer { text-align: center; padding: 22px 20px; font-size: 12px; color: #94a3b8; background-color: #f8fafc; border-top: 1px solid #e2e8f0; }
                </style>
            </head>
            <body>
                <div class='card'>
                    <div class='header'>
                        <span class='badge'>✓ Payment Successful</span>
                        <h1>Payment Receipt</h1>
                        <p>Thank you! Your payment has been received and verified.</p>
                    </div>
                    <div class='content'>
                        <p class='greeting'>Hello <strong>{$recipientName}</strong>,</p>
                        <p class='greeting'>We have successfully received your payment of <strong>{$amountFormatted}</strong>. Below is your official payment receipt and transaction summary:</p>
                        
                        <div class='amount-card'>
                            <div class='amount-label'>Total Amount Paid</div>
                            <div class='amount-value'>{$amountFormatted}</div>
                            <div><span class='status-paid'>● Paid Successfully</span></div>
                        </div>

                        <table class='info-table'>
                            <tr>
                                <td class='info-label'>Payment ID:</td>
                                <td class='info-value'><span class='code-badge'>{$paymentId}</span></td>
                            </tr>
                            <tr>
                                <td class='info-label'>Order / Link Ref:</td>
                                <td class='info-value'><span class='code-badge'>{$referenceId}</span></td>
                            </tr>
                            <tr>
                                <td class='info-label'>Payment Type:</td>
                                <td class='info-value'><strong>{$paymentTypeFormatted}</strong></td>
                            </tr>
                            <tr>
                                <td class='info-label'>Payment Method:</td>
                                <td class='info-value'>{$paymentMethod}</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Product:</td>
                                <td class='info-value'><strong>{$productTitle}</strong></td>
                            </tr>
                            <tr>
                                <td class='info-label'>Project:</td>
                                <td class='info-value'>{$projectName}</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Membership Plan:</td>
                                <td class='info-value'>{$membershipPlan}</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Valid Until / Expiry:</td>
                                <td class='info-value'>{$expiryDate}</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Payment Date:</td>
                                <td class='info-value'>{$paidDate}</td>
                            </tr>
                        </table>

                        {$credentialsBox}

                        <p style='font-size: 13px; color: #64748b; line-height: 1.6; margin-top: 25px;'>
                            Please keep this confirmation email for your records. If you have any inquiries regarding this payment, please contact our support team.
                        </p>
                    </div>
                    <div class='footer'>
                        &copy; " . date('Y') . " {$companyName}. All rights reserved.<br>
                        This is an automated receipt generated upon successful payment.
                    </div>
                </div>
            </body>
            </html>
            ";

            Mail::html($html, function ($message) use ($recipientEmail, $recipientName, $companyName, $amountFormatted) {
                $message->to($recipientEmail, $recipientName)
                        ->subject("Payment Receipt: {$amountFormatted} - {$companyName}");
            });

            Log::info("Payment success email sent successfully to client: {$recipientEmail} for Transaction #{$transaction->id}");
            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to send payment success email to client ({$recipientEmail}): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send payment received notification alert to Administrator(s).
     */
    public function sendPaymentSuccessAdminEmail(Transaction $transaction, ?Client $client = null): bool
    {
        if (!$this->configureSmtp()) {
            return false;
        }

        try {
            $adminEmails = $this->getAdminRecipients();
            if (empty($adminEmails)) {
                Log::warning("No admin email recipient found for payment success notification (Transaction #{$transaction->id}).");
                return false;
            }

            if (!$client && $transaction->client_id) {
                $client = Client::find($transaction->client_id);
            }

            if ($client) {
                $client->loadMissing(['product', 'project', 'membership']);
            }

            $settings = Setting::pluck('value', 'key')->toArray();
            $companyName = $settings['mail_from_name'] ?? $settings['mailFromName'] ?? config('app.name', 'Admin Panel');

            $clientName     = $client ? $client->client_name : ('Client #' . ($transaction->client_id ?? 'N/A'));
            $clientEmail    = $client ? ($client->work_email ?: 'N/A') : 'N/A';
            $clientMobile   = $client ? ($client->mobile ?: 'N/A') : 'N/A';
            $brandName      = $client ? ($client->brand_name ?: 'N/A') : 'N/A';
            $productTitle   = $client && $client->product ? $client->product->title : 'N/A';
            $projectName    = $client && $client->project ? $client->project->name : 'N/A';
            $membershipPlan = $client && $client->membership ? $client->membership->plan_name : 'N/A';
            $expiryDate     = $client && $client->expiry_date ? \Carbon\Carbon::parse($client->expiry_date)->format('d M, Y') : 'N/A';

            $currencySymbol = ($transaction->currency === 'INR') ? '₹' : ($transaction->currency . ' ');
            $amountFormatted = $currencySymbol . number_format((float) $transaction->amount, 2);
            $paymentTypeFormatted = ucfirst($transaction->payment_type ?? 'Purchase');
            $paymentId = $transaction->razorpay_payment_id ?: 'N/A';
            $referenceId = $transaction->razorpay_order_id ?: ($transaction->payment_link_id ?: 'N/A');
            $paidDate = $transaction->updated_at ? $transaction->updated_at->format('d M Y, h:i A') : date('d M Y, h:i A');
            $paymentMethod = $transaction->payment_method ?: 'Razorpay Online';

            $html = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='utf-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <style>
                    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; margin: 0; padding: 25px 15px; color: #1f2937; }
                    .card { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.08), 0 8px 10px -6px rgba(0,0,0,0.04); border: 1px solid #e5e7eb; }
                    .header { background: linear-gradient(135deg, #1e40af 0%, #3b82f6 50%, #0284c7 100%); padding: 32px 25px; text-align: center; color: #ffffff; }
                    .header .badge { display: inline-block; background-color: rgba(255, 255, 255, 0.2); padding: 5px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; }
                    .header h1 { margin: 0; font-size: 24px; font-weight: 700; }
                    .header p { margin: 8px 0 0 0; opacity: 0.95; font-size: 14px; }
                    .content { padding: 30px 28px; }
                    .amount-card { background: #f0fdf4; border: 2px solid #86efac; border-radius: 10px; padding: 18px 20px; text-align: center; margin-bottom: 25px; }
                    .amount-label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #15803d; margin-bottom: 4px; }
                    .amount-value { font-size: 32px; font-weight: 800; color: #14532d; }
                    .info-table { width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
                    .info-table td { padding: 11px 16px; border-bottom: 1px solid #edf2f7; font-size: 14px; }
                    .info-table tr:last-child td { border-bottom: none; }
                    .info-label { font-weight: 600; color: #475569; width: 38%; background-color: #f8fafc; }
                    .info-value { color: #0f172a; }
                    .code-badge { background-color: #f1f5f9; color: #334155; padding: 2px 7px; border-radius: 4px; font-family: monospace; font-size: 13px; }
                    .footer { text-align: center; padding: 20px; font-size: 12px; color: #94a3b8; background-color: #f8fafc; border-top: 1px solid #e2e8f0; }
                </style>
            </head>
            <body>
                <div class='card'>
                    <div class='header'>
                        <span class='badge'>Admin Alert</span>
                        <h1>Payment Received</h1>
                        <p>A client has successfully completed a payment via Razorpay</p>
                    </div>
                    <div class='content'>
                        <div class='amount-card'>
                            <div class='amount-label'>Amount Collected</div>
                            <div class='amount-value'>{$amountFormatted}</div>
                            <div style='color: #166534; font-size: 12px; font-weight: 600; margin-top: 4px;'>Status: PAID</div>
                        </div>

                        <table class='info-table'>
                            <tr>
                                <td class='info-label'>Client Name:</td>
                                <td class='info-value'><strong>{$clientName}</strong></td>
                            </tr>
                            <tr>
                                <td class='info-label'>Brand Name:</td>
                                <td class='info-value'>{$brandName}</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Client Email:</td>
                                <td class='info-value'>{$clientEmail}</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Mobile:</td>
                                <td class='info-value'>{$clientMobile}</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Payment ID:</td>
                                <td class='info-value'><span class='code-badge'>{$paymentId}</span></td>
                            </tr>
                            <tr>
                                <td class='info-label'>Order / Link Ref:</td>
                                <td class='info-value'><span class='code-badge'>{$referenceId}</span></td>
                            </tr>
                            <tr>
                                <td class='info-label'>Payment Type:</td>
                                <td class='info-value'><strong>{$paymentTypeFormatted}</strong></td>
                            </tr>
                            <tr>
                                <td class='info-label'>Payment Method:</td>
                                <td class='info-value'>{$paymentMethod}</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Product:</td>
                                <td class='info-value'>{$productTitle}</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Project:</td>
                                <td class='info-value'>{$projectName}</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Membership:</td>
                                <td class='info-value'>{$membershipPlan}</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Valid Until:</td>
                                <td class='info-value'>{$expiryDate}</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Timestamp:</td>
                                <td class='info-value'>{$paidDate}</td>
                            </tr>
                        </table>
                    </div>
                    <div class='footer'>
                        &copy; " . date('Y') . " {$companyName}. System Payment Notification.
                    </div>
                </div>
            </body>
            </html>
            ";

            foreach ($adminEmails as $adminEmail) {
                Mail::html($html, function ($message) use ($adminEmail, $clientName, $amountFormatted, $paymentTypeFormatted, $companyName) {
                    $message->to($adminEmail)
                            ->subject("Payment Received: {$amountFormatted} from {$clientName} ({$paymentTypeFormatted}) - {$companyName}");
                });
            }

            Log::info("Admin payment notification email sent for Client {$clientName} to: " . implode(', ', $adminEmails));
            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to send admin payment notification email: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send payment invoice link email to Client with pay button.
     */
    public function sendPaymentLinkEmail(Transaction $transaction, string $paymentLinkUrl, ?Client $client = null): bool
    {
        if (!$client && $transaction->client_id) {
            $client = Client::find($transaction->client_id);
        }

        if ($client) {
            $client->loadMissing(['product', 'project', 'membership']);
        }

        $recipientEmail = $client ? $client->work_email : null;
        $recipientName  = $client ? $client->client_name : 'Customer';

        if (empty($recipientEmail)) {
            return false;
        }

        if (!$this->configureSmtp()) {
            return false;
        }

        try {
            $settings = Setting::pluck('value', 'key')->toArray();
            $companyName = $settings['mail_from_name'] ?? $settings['mailFromName'] ?? config('app.name', 'Admin Panel');

            $productTitle   = $client && $client->product ? $client->product->title : 'N/A';
            $projectName    = $client && $client->project ? $client->project->name : 'N/A';
            $membershipPlan = $client && $client->membership ? $client->membership->plan_name : 'N/A';

            $currencySymbol = ($transaction->currency === 'INR') ? '₹' : ($transaction->currency . ' ');
            $amountFormatted = $currencySymbol . number_format((float) $transaction->amount, 2);

            $html = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='utf-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <style>
                    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; margin: 0; padding: 25px 15px; color: #1f2937; }
                    .card { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.08); border: 1px solid #e5e7eb; }
                    .header { background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); padding: 32px 25px; text-align: center; color: #ffffff; }
                    .header h1 { margin: 0; font-size: 24px; font-weight: 700; }
                    .header p { margin: 8px 0 0 0; opacity: 0.95; font-size: 14px; }
                    .content { padding: 30px 28px; }
                    .greeting { font-size: 15px; line-height: 1.6; margin-bottom: 20px; color: #374151; }
                    .btn { display: inline-block; background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); color: #ffffff !important; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-size: 16px; font-weight: 700; margin: 15px 0; text-align: center; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3); }
                    .info-table { width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #f8fafc; border-radius: 8px; overflow: hidden; }
                    .info-table td { padding: 11px 16px; border-bottom: 1px solid #edf2f7; font-size: 14px; }
                    .info-table tr:last-child td { border-bottom: none; }
                    .info-label { font-weight: 600; color: #475569; width: 38%; }
                    .info-value { color: #0f172a; }
                    .footer { text-align: center; padding: 20px; font-size: 12px; color: #94a3b8; background-color: #f8fafc; border-top: 1px solid #e2e8f0; }
                </style>
            </head>
            <body>
                <div class='card'>
                    <div class='header'>
                        <h1>Payment Invoice</h1>
                        <p>Complete your payment securely via Razorpay</p>
                    </div>
                    <div class='content'>
                        <p class='greeting'>Hello <strong>{$recipientName}</strong>,</p>
                        <p class='greeting'>A payment invoice of <strong>{$amountFormatted}</strong> has been generated for your account. Please click the button below to pay securely online:</p>
                        
                        <div style='text-align: center; margin: 25px 0;'>
                            <a href='{$paymentLinkUrl}' class='btn' target='_blank'>Pay Now ({$amountFormatted}) &rarr;</a>
                            <p style='font-size: 12px; color: #64748b; margin-top: 8px;'>Direct Link: <a href='{$paymentLinkUrl}' style='color: #4f46e5;'>{$paymentLinkUrl}</a></p>
                        </div>

                        <table class='info-table'>
                            <tr>
                                <td class='info-label'>Amount Due:</td>
                                <td class='info-value'><strong>{$amountFormatted}</strong></td>
                            </tr>
                            <tr>
                                <td class='info-label'>Product:</td>
                                <td class='info-value'>{$productTitle}</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Project:</td>
                                <td class='info-value'>{$projectName}</td>
                            </tr>
                            <tr>
                                <td class='info-label'>Membership:</td>
                                <td class='info-value'>{$membershipPlan}</td>
                            </tr>
                        </table>

                        <p style='font-size: 13px; color: #64748b; line-height: 1.6; margin-top: 25px;'>
                            You can pay via UPI, Credit/Debit Cards, Net Banking, or Wallets using this secure Razorpay link.
                        </p>
                    </div>
                    <div class='footer'>
                        &copy; " . date('Y') . " {$companyName}. All rights reserved.
                    </div>
                </div>
            </body>
            </html>
            ";

            Mail::html($html, function ($message) use ($recipientEmail, $recipientName, $companyName, $amountFormatted) {
                $message->to($recipientEmail, $recipientName)
                        ->subject("Payment Invoice ({$amountFormatted}) - {$companyName}");
            });

            Log::info("Payment link email sent to {$recipientEmail} for Transaction #{$transaction->id}");
            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to send payment link email to {$recipientEmail}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Test SMTP configuration by sending a test message.
     */
    public function sendTestEmail($toEmail): array
    {
        if (!$this->configureSmtp()) {
            return [
                'success' => false,
                'message' => 'SMTP is either disabled or missing required host/username/password in settings.'
            ];
        }

        try {
            Mail::raw('This is a test email confirming that your SMTP configuration in the Admin Panel is working successfully!', function ($msg) use ($toEmail) {
                $msg->to($toEmail)->subject('SMTP Configuration Test - Success');
            });

            return [
                'success' => true,
                'message' => 'Test email sent successfully to ' . $toEmail
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Failed to send test email: ' . $e->getMessage()
            ];
        }
    }
}
