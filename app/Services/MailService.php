<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Setting;
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

            $settings = Setting::pluck('value', 'key')->toArray();

            // Find admin recipients
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

            $adminEmails = array_values(array_unique(array_filter($adminEmails)));

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
