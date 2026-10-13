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
     * Cache sent client IDs and transaction IDs in current request to prevent accidental duplicate emails.
     */
    protected static array $sentActiveClientIds = [];
    protected static array $sentPaymentTransactionIds = [];
    protected static array $sentPaymentAdminTransactionIds = [];

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
        $fromName    = $settings['mail_from_name'] ?? $settings['mailFromName'] ?? config('app.name', 'Fablead Developers');

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
     * Helper to retrieve company / website brand name.
     */
    public function getCompanyName(): string
    {
        $settings = Setting::pluck('value', 'key')->toArray();
        $name = $settings['company_name'] ?? $settings['mail_from_name'] ?? $settings['mailFromName'] ?? config('app.name', 'Fablead Developers');
        if (empty($name) || strtolower($name) === 'laravel') {
            return 'Fablead Developers';
        }
        return $name;
    }

    /**
     * Helper to retrieve client portal / app login URL.
     */
    public function getPortalUrl(): string
    {
        $url = env('CLIENT_PORTAL_URL', env('APP_URL', 'https://admin-products.fableadtech.com'));
        if (empty($url) || strpos($url, 'localhost') !== false) {
            return 'https://admin-products.fableadtech.com';
        }
        return rtrim($url, '/');
    }

    /**
     * Send email to client when their status is Active (with account credentials & subscription details).
     * Matches the website brand color (#E04F2C coral-orange with #181B1F dark charcoal).
     */
    public function sendClientAccountActiveEmail(Client $client, bool $force = false): bool
    {
        if (empty($client->work_email)) {
            return false;
        }

        // Prevent duplicate sending within the same request lifecycle
        if (!$force && in_array($client->id, self::$sentActiveClientIds)) {
            return true;
        }

        if (!$this->configureSmtp()) {
            return false;
        }

        try {
            $client->loadMissing(['product', 'project', 'membership']);

            $companyName    = $this->getCompanyName();
            $portalUrl      = $this->getPortalUrl();
            $productTitle   = $client->product ? $client->product->title : 'N/A';
            $projectName    = $client->project ? $client->project->name : 'N/A';
            $membershipPlan = $client->membership ? $client->membership->plan_name : 'N/A';
            $startDate      = $client->calculated_start_date ?? ($client->start_date ?: date('Y-m-d'));
            $expiryDate     = $client->calculated_end_date ?? ($client->expiry_date ?: 'N/A');
            $passwordText   = !empty($client->password) ? $client->password : '(Managed by administrator)';
            $year           = date('Y');

            $html = "
            <!DOCTYPE html>
            <html lang='en'>
            <head>
                <meta charset='utf-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Account Active - {$companyName}</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 25px 15px; color: #1f2937; }
                    .card { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.08), 0 8px 10px -6px rgba(0,0,0,0.04); border: 1px solid #e5e7eb; }
                    .brand-bar { background-color: #181b1f; padding: 12px 25px; text-align: left; }
                    .brand-logo { color: #ffffff; font-size: 14px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase; }
                    .brand-logo span { color: #e04f2c; }
                    .header { background: linear-gradient(135deg, #e04f2c 0%, #f15a36 100%); padding: 32px 25px; text-align: center; color: #ffffff; }
                    .header .badge { display: inline-block; background-color: rgba(255, 255, 255, 0.22); padding: 5px 14px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; }
                    .header h1 { margin: 0; font-size: 25px; font-weight: 800; letter-spacing: -0.5px; }
                    .header p { margin: 8px 0 0 0; opacity: 0.95; font-size: 14px; }
                    .content { padding: 30px 28px; }
                    .greeting { font-size: 15px; line-height: 1.6; margin-bottom: 20px; color: #374151; }
                    
                    /* Status card */
                    .status-card { background: #ecfdf5; border: 1.5px solid #a7f3d0; border-radius: 10px; padding: 15px 18px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; }
                    .status-label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #047857; }
                    .status-badge { display: inline-block; background-color: #10b981; color: #ffffff; font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 12px; text-transform: uppercase; letter-spacing: 0.3px; }
                    
                    /* Info table */
                    .info-table { width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
                    .info-table th { background-color: #181b1f; color: #ffffff; padding: 11px 16px; font-size: 13px; font-weight: 600; text-align: left; }
                    .info-table td { padding: 11px 16px; border-bottom: 1px solid #edf2f7; font-size: 14px; }
                    .info-table tr:last-child td { border-bottom: none; }
                    .info-label { font-weight: 600; color: #4b5563; width: 38%; background-color: #f9fafb; }
                    .info-value { color: #111827; }
                    
                    /* Highlight badge matching orange theme */
                    .highlight-code { background-color: #fff1ec; color: #c23817; border: 1px solid #fed7aa; padding: 3px 8px; border-radius: 4px; font-family: monospace; font-size: 13.5px; font-weight: 600; }
                    
                    /* Primary Button */
                    .btn-container { text-align: center; margin: 28px 0 16px 0; }
                    .btn-primary { display: inline-block; background: linear-gradient(135deg, #e04f2c 0%, #f15a36 100%); color: #ffffff !important; text-decoration: none; padding: 13px 32px; border-radius: 8px; font-size: 15px; font-weight: 700; text-align: center; box-shadow: 0 4px 14px rgba(224, 79, 44, 0.35); }
                    
                    .footer { text-align: center; padding: 22px 20px; font-size: 12px; color: #9ca3af; background-color: #181b1f; border-top: 1px solid #23272d; }
                    .footer p { margin: 3px 0; }
                </style>
            </head>
            <body>
                <div class='card'>
                    <div class='brand-bar'>
                        <div class='brand-logo'><span>●</span> {$companyName}</div>
                    </div>
                    <div class='header'>
                        <span class='badge'>✓ STATUS: ACTIVE</span>
                        <h1>Your Account is Active!</h1>
                        <p>Welcome to our platform. Your account and subscription are now fully active.</p>
                    </div>
                    <div class='content'>
                        <p class='greeting'>Hello <strong>{$client->client_name}</strong>,</p>
                        <p class='greeting'>Great news! Your client account status has been updated to <strong>Active</strong>. You now have full access to your registered services, modules, and platform features.</p>
                        
                        <div class='status-card'>
                            <div>
                                <span class='status-label'>Current Account Status</span>
                                <div style='font-size: 16px; font-weight: 800; color: #065f46; margin-top: 3px;'>● Active &amp; Verified</div>
                            </div>
                            <div>
                                <span class='status-badge'>ACTIVE</span>
                            </div>
                        </div>

                        <table class='info-table'>
                            <thead>
                                <tr>
                                    <th colspan='2'>Account &amp; Project Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class='info-label'>Client Name:</td>
                                    <td class='info-value'><strong>{$client->client_name}</strong></td>
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
                                    <td class='info-label'>Membership:</td>
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
                                    <td class='info-value'><span class='highlight-code'>{$client->work_email}</span></td>
                                </tr>
                                <tr>
                                    <td class='info-label'>Login Password:</td>
                                    <td class='info-value'><span class='highlight-code'>{$passwordText}</span></td>
                                </tr>
                            </tbody>
                        </table>

                        <div class='btn-container'>
                            <a href='{$portalUrl}' class='btn-primary' target='_blank'>Log In to Your Portal &rarr;</a>
                        </div>

                        <p style='font-size: 13px; color: #6b7280; line-height: 1.6; margin-top: 25px;'>
                            Please keep your credentials confidential. You can log into your client portal anytime to view your assigned modules, invoices, and service updates.
                        </p>
                    </div>
                    <div class='footer'>
                        <p style='color: #e5e7eb; font-weight: 600; font-size: 13px;'>{$companyName}</p>
                        <p>&copy; {$year} {$companyName}. All rights reserved.</p>
                        <p style='opacity: 0.7;'>This is an automated confirmation sent to {$client->work_email}.</p>
                    </div>
                </div>
            </body>
            </html>
            ";

            Mail::html($html, function ($message) use ($client, $companyName) {
                $message->to($client->work_email, $client->client_name)
                        ->subject("Your Account is Active - {$companyName}");
            });

            self::$sentActiveClientIds[] = $client->id;
            Log::info("Account Active email sent successfully to client: {$client->work_email}");
            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to send Account Active email to client ({$client->work_email}): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send welcome email to newly created client.
     * Aliases to sendClientAccountActiveEmail with branded styling.
     */
    public function sendClientWelcomeEmail(Client $client): bool
    {
        return $this->sendClientAccountActiveEmail($client);
    }

    /**
     * Send new client registration notification email to Administrator(s).
     * Matches the website brand color scheme.
     */
    public function sendAdminNewClientEmail(Client $client): bool
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

            $companyName    = $this->getCompanyName();
            $productTitle   = $client->product ? $client->product->title : 'N/A';
            $projectName    = $client->project ? $client->project->name : 'N/A';
            $membershipPlan = $client->membership ? $client->membership->plan_name : 'N/A';
            $amountText     = !empty($client->amount) && is_array($client->amount) ? json_encode($client->amount) : 'N/A';
            $year           = date('Y');

            $statusBadgeColor = (strtolower($client->status ?? '') === 'active') ? '#10b981' : '#f59e0b';
            $statusText = $client->status ?: 'Pending';

            $html = "
            <!DOCTYPE html>
            <html lang='en'>
            <head>
                <meta charset='utf-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>New Client Registered - {$companyName}</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 25px 15px; color: #1f2937; }
                    .card { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.08); border: 1px solid #e5e7eb; }
                    .brand-bar { background-color: #181b1f; padding: 12px 25px; text-align: left; }
                    .brand-logo { color: #ffffff; font-size: 14px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase; }
                    .brand-logo span { color: #e04f2c; }
                    .header { background: linear-gradient(135deg, #181b1f 0%, #292d33 50%, #e04f2c 100%); padding: 30px 25px; text-align: center; color: #ffffff; }
                    .header .badge { display: inline-block; background-color: rgba(224, 79, 44, 0.4); border: 1px solid rgba(224, 79, 44, 0.7); padding: 5px 14px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; }
                    .header h1 { margin: 0; font-size: 24px; font-weight: 800; }
                    .header p { margin: 6px 0 0 0; opacity: 0.9; font-size: 14px; }
                    .content { padding: 30px 28px; }
                    .info-table { width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
                    .info-table th { background-color: #181b1f; color: #ffffff; padding: 11px 16px; font-size: 13px; font-weight: 600; text-align: left; }
                    .info-table td { padding: 11px 16px; border-bottom: 1px solid #edf2f7; font-size: 14px; }
                    .info-table tr:last-child td { border-bottom: none; }
                    .info-label { font-weight: 600; color: #4b5563; width: 38%; background-color: #f9fafb; }
                    .info-value { color: #111827; }
                    .footer { text-align: center; padding: 20px; font-size: 12px; color: #9ca3af; background-color: #181b1f; border-top: 1px solid #23272d; }
                </style>
            </head>
            <body>
                <div class='card'>
                    <div class='brand-bar'>
                        <div class='brand-logo'><span>●</span> {$companyName} Admin</div>
                    </div>
                    <div class='header'>
                        <span class='badge'>Admin Alert</span>
                        <h1>New Client Registered</h1>
                        <p>A new client has just been added to the system</p>
                    </div>
                    <div class='content'>
                        <p style='font-size: 15px; margin-top: 0;'>A new client record has been created with the following details:</p>
                        
                        <table class='info-table'>
                            <thead>
                                <tr>
                                    <th colspan='2'>Client Summary</th>
                                </tr>
                            </thead>
                            <tbody>
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
                                    <td class='info-value'><strong>{$productTitle}</strong></td>
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
                                    <td class='info-value'><span style='display:inline-block; background-color: {$statusBadgeColor}; color: #ffffff; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; text-transform: uppercase;'>{$statusText}</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class='footer'>
                        &copy; {$year} {$companyName}. Admin System Notification.
                    </div>
                </div>
            </body>
            </html>
            ";

            foreach ($adminEmails as $adminEmail) {
                Mail::html($html, function ($message) use ($adminEmail, $client, $companyName) {
                    $message->to($adminEmail)
                            ->subject("New Client Registered: {$client->client_name} - {$companyName}");
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
     * Matches website brand colors.
     */
    public function sendPaymentSuccessClientEmail(Transaction $transaction, ?Client $client = null): bool
    {
        // Prevent duplicate sending for the same transaction
        if ($transaction->id && in_array($transaction->id, self::$sentPaymentTransactionIds)) {
            return true;
        }

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
            $companyName    = $this->getCompanyName();
            $portalUrl      = $this->getPortalUrl();

            $productTitle   = $client && $client->product ? $client->product->title : 'N/A';
            $projectName    = $client && $client->project ? $client->project->name : 'N/A';
            $membershipPlan = $client && $client->membership ? $client->membership->plan_name : 'N/A';
            $startDate      = ($client && $client->calculated_start_date) ? $client->calculated_start_date : (($client && $client->start_date) ? $client->start_date : date('Y-m-d'));
            $expiryDate     = $client && $client->expiry_date ? \Carbon\Carbon::parse($client->expiry_date)->format('d M, Y') : 'N/A';
            
            $currencySymbol = ($transaction->currency === 'INR') ? '₹' : ($transaction->currency . ' ');
            $amountFormatted = $currencySymbol . number_format((float) $transaction->amount, 2);
            $paymentTypeFormatted = ucfirst($transaction->payment_type ?? 'Purchase');
            $paymentId = $transaction->razorpay_payment_id ?: 'N/A';
            $referenceId = $transaction->razorpay_order_id ?: ($transaction->payment_link_id ?: 'N/A');
            $paidDate = $transaction->updated_at ? $transaction->updated_at->format('d M Y, h:i A') : date('d M Y, h:i A');
            $paymentMethod = $transaction->payment_method ?: 'Razorpay Online';
            $year = date('Y');

            $credentialsBox = '';
            if ($client && !empty($client->password)) {
                $credentialsBox = "
                <div style='background-color: #fff7ed; border: 1.5px solid #fed7aa; border-radius: 10px; padding: 16px 20px; margin: 22px 0;'>
                    <h3 style='margin: 0 0 8px 0; font-size: 15px; color: #9a3412; font-weight: 700;'>Client Portal Credentials</h3>
                    <p style='margin: 0 0 10px 0; font-size: 13px; color: #c2410c;'>You can log into your portal anytime using your registered credentials:</p>
                    <table style='width: 100%; font-size: 13.5px;'>
                        <tr>
                            <td style='width: 35%; font-weight: 600; color: #9a3412; padding: 4px 0;'>Login Email:</td>
                            <td style='color: #7c2d12; font-family: monospace; font-weight: 700; background: #ffedd5; padding: 3px 8px; border-radius: 4px;'>{$client->work_email}</td>
                        </tr>
                        <tr>
                            <td style='font-weight: 600; color: #9a3412; padding: 4px 0;'>Password:</td>
                            <td style='color: #7c2d12; font-family: monospace; font-weight: 700; background: #ffedd5; padding: 3px 8px; border-radius: 4px;'>{$client->password}</td>
                        </tr>
                    </table>
                </div>
                ";
            }

            $html = "
            <!DOCTYPE html>
            <html lang='en'>
            <head>
                <meta charset='utf-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Payment Receipt - {$companyName}</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 25px 15px; color: #1f2937; }
                    .card { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.08); border: 1px solid #e5e7eb; }
                    .brand-bar { background-color: #181b1f; padding: 12px 25px; text-align: left; }
                    .brand-logo { color: #ffffff; font-size: 14px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase; }
                    .brand-logo span { color: #e04f2c; }
                    .header { background: linear-gradient(135deg, #e04f2c 0%, #f15a36 100%); padding: 35px 25px; text-align: center; color: #ffffff; }
                    .header .badge { display: inline-block; background-color: rgba(255, 255, 255, 0.22); padding: 5px 14px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; }
                    .header h1 { margin: 0; font-size: 26px; font-weight: 800; }
                    .header p { margin: 8px 0 0 0; opacity: 0.95; font-size: 14px; }
                    .content { padding: 30px 28px; }
                    .greeting { font-size: 15px; line-height: 1.6; margin-bottom: 22px; color: #374151; }
                    .amount-card { background: #fafafa; border: 2px dashed #e04f2c; border-radius: 10px; padding: 18px 20px; text-align: center; margin-bottom: 25px; }
                    .amount-label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280; margin-bottom: 5px; }
                    .amount-value { font-size: 32px; font-weight: 800; color: #e04f2c; }
                    .status-paid { display: inline-block; background-color: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 12px; text-transform: uppercase; margin-top: 6px; }
                    .info-table { width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
                    .info-table th { background-color: #181b1f; color: #ffffff; padding: 11px 16px; font-size: 13px; font-weight: 600; text-align: left; }
                    .info-table td { padding: 11px 16px; border-bottom: 1px solid #edf2f7; font-size: 14px; }
                    .info-table tr:last-child td { border-bottom: none; }
                    .info-label { font-weight: 600; color: #4b5563; width: 40%; background-color: #f9fafb; }
                    .info-value { color: #111827; }
                    .code-badge { background-color: #fff1ec; color: #c23817; border: 1px solid #fed7aa; padding: 2px 7px; border-radius: 4px; font-family: monospace; font-size: 13px; }
                    .btn-container { text-align: center; margin: 25px 0 10px 0; }
                    .btn-primary { display: inline-block; background: linear-gradient(135deg, #e04f2c 0%, #f15a36 100%); color: #ffffff !important; text-decoration: none; padding: 13px 30px; border-radius: 8px; font-size: 15px; font-weight: 700; box-shadow: 0 4px 14px rgba(224, 79, 44, 0.35); }
                    .footer { text-align: center; padding: 22px 20px; font-size: 12px; color: #9ca3af; background-color: #181b1f; border-top: 1px solid #23272d; }
                </style>
            </head>
            <body>
                <div class='card'>
                    <div class='brand-bar'>
                        <div class='brand-logo'><span>●</span> {$companyName}</div>
                    </div>
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
                            <thead>
                                <tr>
                                    <th colspan='2'>Transaction Summary</th>
                                </tr>
                            </thead>
                            <tbody>
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
                                    <td class='info-label'>Account Status:</td>
                                    <td class='info-value'><span style='display:inline-block; background-color:#ecfdf5; color:#047857; border:1px solid #a7f3d0; font-size:11px; font-weight:700; padding:3px 10px; border-radius:12px; text-transform:uppercase;'>● Active</span></td>
                                </tr>
                                <tr>
                                    <td class='info-label'>Membership:</td>
                                    <td class='info-value'>{$membershipPlan}</td>
                                </tr>
                                <tr>
                                    <td class='info-label'>Start Date:</td>
                                    <td class='info-value'>{$startDate}</td>
                                </tr>
                                <tr>
                                    <td class='info-label'>Valid Until / Expiry:</td>
                                    <td class='info-value'>{$expiryDate}</td>
                                </tr>
                                <tr>
                                    <td class='info-label'>Payment Date:</td>
                                    <td class='info-value'>{$paidDate}</td>
                                </tr>
                            </tbody>
                        </table>

                        {$credentialsBox}

                        <div class='btn-container'>
                            <a href='{$portalUrl}' class='btn-primary' target='_blank'>Go to Client Portal &rarr;</a>
                        </div>

                        <p style='font-size: 13px; color: #6b7280; line-height: 1.6; margin-top: 25px;'>
                            Please keep this confirmation email for your records. If you have any inquiries regarding this payment, please contact our support team.
                        </p>
                    </div>
                    <div class='footer'>
                        &copy; {$year} {$companyName}. All rights reserved.<br>
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

            if ($transaction->id) {
                self::$sentPaymentTransactionIds[] = $transaction->id;
            }

            Log::info("Payment success email sent successfully to client: {$recipientEmail} for Transaction #{$transaction->id}");
            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to send payment success email to client ({$recipientEmail}): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send payment received notification alert to Administrator(s).
     * Matches website brand colors.
     */
    public function sendPaymentSuccessAdminEmail(Transaction $transaction, ?Client $client = null): bool
    {
        // Prevent duplicate sending for the same transaction
        if ($transaction->id && in_array($transaction->id, self::$sentPaymentAdminTransactionIds)) {
            return true;
        }

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

            $companyName    = $this->getCompanyName();
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
            $year = date('Y');

            $html = "
            <!DOCTYPE html>
            <html lang='en'>
            <head>
                <meta charset='utf-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Payment Received - {$companyName}</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 25px 15px; color: #1f2937; }
                    .card { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.08); border: 1px solid #e5e7eb; }
                    .brand-bar { background-color: #181b1f; padding: 12px 25px; text-align: left; }
                    .brand-logo { color: #ffffff; font-size: 14px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase; }
                    .brand-logo span { color: #e04f2c; }
                    .header { background: linear-gradient(135deg, #181b1f 0%, #292d33 50%, #e04f2c 100%); padding: 32px 25px; text-align: center; color: #ffffff; }
                    .header .badge { display: inline-block; background-color: rgba(224, 79, 44, 0.4); border: 1px solid rgba(224, 79, 44, 0.7); padding: 5px 14px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; }
                    .header h1 { margin: 0; font-size: 24px; font-weight: 800; }
                    .header p { margin: 8px 0 0 0; opacity: 0.95; font-size: 14px; }
                    .content { padding: 30px 28px; }
                    .amount-card { background: #fafafa; border: 2px dashed #e04f2c; border-radius: 10px; padding: 18px 20px; text-align: center; margin-bottom: 25px; }
                    .amount-label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280; margin-bottom: 4px; }
                    .amount-value { font-size: 32px; font-weight: 800; color: #e04f2c; }
                    .info-table { width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
                    .info-table th { background-color: #181b1f; color: #ffffff; padding: 11px 16px; font-size: 13px; font-weight: 600; text-align: left; }
                    .info-table td { padding: 11px 16px; border-bottom: 1px solid #edf2f7; font-size: 14px; }
                    .info-table tr:last-child td { border-bottom: none; }
                    .info-label { font-weight: 600; color: #4b5563; width: 38%; background-color: #f9fafb; }
                    .info-value { color: #111827; }
                    .code-badge { background-color: #fff1ec; color: #c23817; border: 1px solid #fed7aa; padding: 2px 7px; border-radius: 4px; font-family: monospace; font-size: 13px; }
                    .footer { text-align: center; padding: 20px; font-size: 12px; color: #9ca3af; background-color: #181b1f; border-top: 1px solid #23272d; }
                </style>
            </head>
            <body>
                <div class='card'>
                    <div class='brand-bar'>
                        <div class='brand-logo'><span>●</span> {$companyName} Admin</div>
                    </div>
                    <div class='header'>
                        <span class='badge'>Admin Alert</span>
                        <h1>Payment Received</h1>
                        <p>A client has completed payment of {$amountFormatted}</p>
                    </div>
                    <div class='content'>
                        <div class='amount-card'>
                            <div class='amount-label'>Amount Collected</div>
                            <div class='amount-value'>{$amountFormatted}</div>
                            <div style='color: #047857; font-size: 12px; font-weight: 700; margin-top: 4px;'>● STATUS: PAID</div>
                        </div>

                        <table class='info-table'>
                            <thead>
                                <tr>
                                    <th colspan='2'>Transaction &amp; Client Overview</th>
                                </tr>
                            </thead>
                            <tbody>
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
                            </tbody>
                        </table>
                    </div>
                    <div class='footer'>
                        &copy; {$year} {$companyName}. System Payment Notification.
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

            if ($transaction->id) {
                self::$sentPaymentAdminTransactionIds[] = $transaction->id;
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
     * Matches website brand colors.
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
            $companyName    = $this->getCompanyName();
            $productTitle   = $client && $client->product ? $client->product->title : 'N/A';
            $projectName    = $client && $client->project ? $client->project->name : 'N/A';
            $membershipPlan = $client && $client->membership ? $client->membership->plan_name : 'N/A';

            $currencySymbol = ($transaction->currency === 'INR') ? '₹' : ($transaction->currency . ' ');
            $amountFormatted = $currencySymbol . number_format((float) $transaction->amount, 2);
            $year = date('Y');

            $html = "
            <!DOCTYPE html>
            <html lang='en'>
            <head>
                <meta charset='utf-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Payment Invoice - {$companyName}</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 25px 15px; color: #1f2937; }
                    .card { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.08); border: 1px solid #e5e7eb; }
                    .brand-bar { background-color: #181b1f; padding: 12px 25px; text-align: left; }
                    .brand-logo { color: #ffffff; font-size: 14px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase; }
                    .brand-logo span { color: #e04f2c; }
                    .header { background: linear-gradient(135deg, #e04f2c 0%, #f15a36 100%); padding: 32px 25px; text-align: center; color: #ffffff; }
                    .header h1 { margin: 0; font-size: 25px; font-weight: 800; }
                    .header p { margin: 8px 0 0 0; opacity: 0.95; font-size: 14px; }
                    .content { padding: 30px 28px; }
                    .greeting { font-size: 15px; line-height: 1.6; margin-bottom: 20px; color: #374151; }
                    .btn-pay { display: inline-block; background: linear-gradient(135deg, #e04f2c 0%, #f15a36 100%); color: #ffffff !important; text-decoration: none; padding: 14px 34px; border-radius: 8px; font-size: 16px; font-weight: 700; margin: 15px 0; text-align: center; box-shadow: 0 4px 14px rgba(224, 79, 44, 0.35); }
                    .info-table { width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
                    .info-table th { background-color: #181b1f; color: #ffffff; padding: 11px 16px; font-size: 13px; font-weight: 600; text-align: left; }
                    .info-table td { padding: 11px 16px; border-bottom: 1px solid #edf2f7; font-size: 14px; }
                    .info-table tr:last-child td { border-bottom: none; }
                    .info-label { font-weight: 600; color: #4b5563; width: 38%; background-color: #f9fafb; }
                    .info-value { color: #111827; }
                    .footer { text-align: center; padding: 20px; font-size: 12px; color: #9ca3af; background-color: #181b1f; border-top: 1px solid #23272d; }
                </style>
            </head>
            <body>
                <div class='card'>
                    <div class='brand-bar'>
                        <div class='brand-logo'><span>●</span> {$companyName}</div>
                    </div>
                    <div class='header'>
                        <h1>Payment Invoice</h1>
                        <p>Complete your payment securely online</p>
                    </div>
                    <div class='content'>
                        <p class='greeting'>Hello <strong>{$recipientName}</strong>,</p>
                        <p class='greeting'>A payment invoice of <strong>{$amountFormatted}</strong> has been generated for your account. Please click the button below to pay securely online:</p>
                        
                        <div style='text-align: center; margin: 25px 0;'>
                            <a href='{$paymentLinkUrl}' class='btn-pay' target='_blank'>Pay Now ({$amountFormatted}) &rarr;</a>
                            <p style='font-size: 12px; color: #6b7280; margin-top: 10px;'>Direct Link: <a href='{$paymentLinkUrl}' style='color: #e04f2c; word-break: break-all;'>{$paymentLinkUrl}</a></p>
                        </div>

                        <table class='info-table'>
                            <thead>
                                <tr>
                                    <th colspan='2'>Invoice Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class='info-label'>Amount Due:</td>
                                    <td class='info-value'><strong style='color:#e04f2c; font-size: 16px;'>{$amountFormatted}</strong></td>
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
                                    <td class='info-label'>Membership:</td>
                                    <td class='info-value'>{$membershipPlan}</td>
                                </tr>
                            </tbody>
                        </table>

                        <p style='font-size: 13px; color: #6b7280; line-height: 1.6; margin-top: 25px;'>
                            You can pay via UPI, Credit/Debit Cards, Net Banking, or Wallets using this secure link.
                        </p>
                    </div>
                    <div class='footer'>
                        &copy; {$year} {$companyName}. All rights reserved.
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
     * Test SMTP configuration by sending a branded HTML test message.
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
            $companyName = $this->getCompanyName();
            $year        = date('Y');

            $html = "
            <!DOCTYPE html>
            <html lang='en'>
            <head>
                <meta charset='utf-8'>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background-color: #f4f6f9; margin: 0; padding: 25px 15px; color: #1f2937; }
                    .card { max-width: 550px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border: 1px solid #e5e7eb; }
                    .header { background: linear-gradient(135deg, #e04f2c 0%, #f15a36 100%); padding: 25px 20px; text-align: center; color: #ffffff; }
                    .content { padding: 25px; font-size: 15px; line-height: 1.6; }
                    .badge { display: inline-block; background-color: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; padding: 4px 12px; border-radius: 12px; font-weight: 700; font-size: 12px; margin-bottom: 15px; }
                    .footer { text-align: center; padding: 15px; font-size: 12px; color: #9ca3af; background-color: #181b1f; }
                </style>
            </head>
            <body>
                <div class='card'>
                    <div class='header'>
                        <h2 style='margin:0;'>{$companyName}</h2>
                        <p style='margin:5px 0 0 0; opacity:0.9;'>SMTP Configuration Test</p>
                    </div>
                    <div class='content'>
                        <div><span class='badge'>✓ Connection Verified</span></div>
                        <p>Hello,</p>
                        <p>This test email confirms that your SMTP mail settings in the Admin Panel have been configured correctly and are operating successfully.</p>
                        <p style='font-size: 13px; color: #6b7280;'>Timestamp: " . date('d M Y, h:i:s A') . "</p>
                    </div>
                    <div class='footer'>
                        &copy; {$year} {$companyName}. All rights reserved.
                    </div>
                </div>
            </body>
            </html>
            ";

            Mail::html($html, function ($msg) use ($toEmail, $companyName) {
                $msg->to($toEmail)->subject("SMTP Configuration Test - {$companyName}");
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
