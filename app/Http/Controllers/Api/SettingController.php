<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * 1. GET: Fetch current settings.
     */
    public function __construct()
    {
        $this->middleware('permission:Settings,VIEW')->only(['getSettings']);
        $this->middleware('permission:Settings,EDIT')->only(['saveSettings', 'sendTestEmail']);
    }

    public function getSettings()
    {
        // Automatically delete any old duplicate camelCase rows if present in DB
        Setting::whereIn('key', [
            'smtpActive', 'mailMailer', 'mailHost', 'mailPort',
            'mailUsername', 'mailPassword', 'mailEncryption',
            'mailFromAddress', 'mailFromName', 'adminEmail', 'smtp_status'
        ])->delete();

        $settings = Setting::pluck('value', 'key')->toArray();

        $defaultSettings = [
            'gateway_environment' => 'Live Production Mode',
            'settlement_currency' => 'INR (₹ - Indian Rupee)',
            'auto_capture' => 'Immediate Capture (Recommended)',
            'key_id' => '',
            'key_secret' => '',
            'webhook_secret' => '',
            'status' => 'Active',
            'razorpay_active' => '1',
            'is_default_project' => null,

            // SMTP Email credentials
            'smtp_active' => '1',
            'mail_mailer' => 'smtp',
            'mail_host' => 'smtp.hostinger.com',
            'mail_port' => '465',
            'mail_username' => '',
            'mail_password' => '',
            'mail_encryption' => 'ssl',
            'mail_from_address' => '',
            'mail_from_name' => '',
            'admin_email' => '',
        ];

        $setting = array_merge($defaultSettings, $settings);

        return response()->json([
            'success' => true,
            'data' => $setting
        ], 200);
    }

    /**
     * 2. POST: Save or Update settings (stores single standard keys only).
     */
    public function saveSettings(Request $request)
    {
        $validated = $request->validate([
            // Gateway / Razorpay
            'gateway_environment' => 'nullable|string|max:255',
            'settlement_currency' => 'nullable|string|max:255',
            'auto_capture' => 'nullable|string|max:255',
            'key_id' => 'nullable|string|max:255',
            'key_secret' => 'nullable|string',
            'secret_key' => 'nullable|string',
            'webhook_secret' => 'nullable|string',
            'status' => 'nullable|in:Active,Inactive',
            'razorpay_active' => 'nullable',
            'is_default_project' => 'nullable',

            // SMTP Email Credentials
            'smtp_active' => 'nullable',
            'smtp_status' => 'nullable',
            'smtpActive' => 'nullable',
            'mail_mailer' => 'nullable|string|max:255',
            'mailMailer' => 'nullable|string|max:255',
            'mail_host' => 'nullable|string|max:255',
            'mailHost' => 'nullable|string|max:255',
            'mail_port' => 'nullable|string|max:10',
            'mailPort' => 'nullable|string|max:10',
            'mail_username' => 'nullable|string|max:255',
            'mailUsername' => 'nullable|string|max:255',
            'mail_password' => 'nullable|string|max:255',
            'mailPassword' => 'nullable|string|max:255',
            'mail_encryption' => 'nullable|string|max:255',
            'mailEncryption' => 'nullable|string|max:255',
            'mail_from_address' => 'nullable|string|max:255',
            'mailFromAddress' => 'nullable|string|max:255',
            'mail_from_name' => 'nullable|string|max:255',
            'mailFromName' => 'nullable|string|max:255',
            'admin_email' => 'nullable|string|max:255',
            'adminEmail' => 'nullable|string|max:255',
        ]);

        // Support both 'secret_key' and 'key_secret'
        if (isset($validated['secret_key']) && !isset($validated['key_secret'])) {
            $validated['key_secret'] = $validated['secret_key'];
        }
        unset($validated['secret_key']);

        // Normalize camelCase to snake_case and unset camelCase duplicates
        $aliases = [
            'mailMailer' => 'mail_mailer',
            'mailHost' => 'mail_host',
            'mailPort' => 'mail_port',
            'mailUsername' => 'mail_username',
            'mailPassword' => 'mail_password',
            'mailEncryption' => 'mail_encryption',
            'mailFromAddress' => 'mail_from_address',
            'mailFromName' => 'mail_from_name',
            'smtpActive' => 'smtp_active',
            'smtp_status' => 'smtp_active',
            'adminEmail' => 'admin_email',
        ];

        foreach ($aliases as $camel => $snake) {
            if (isset($validated[$camel])) {
                if (!isset($validated[$snake])) {
                    $validated[$snake] = $validated[$camel];
                }
                unset($validated[$camel]); // Remove camelCase so it is NOT saved as duplicate row
            }
        }

        // Clean up any old duplicate camelCase rows in the table
        Setting::whereIn('key', array_keys($aliases))->delete();

        foreach ($validated as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $value]
            );
        }
        
        $settings = Setting::pluck('value', 'key')->toArray();

        return response()->json([
            'success' => true,
            'message' => 'Settings saved successfully.',
            'data' => $settings
        ], 200);
    }

    /**
     * Send test email to verify SMTP configuration
     */
    public function sendTestEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        $mailService = app(\App\Services\MailService::class);
        $result = $mailService->sendTestEmail($request->email);

        return response()->json($result, $result['success'] ? 200 : 400);
    }
}
