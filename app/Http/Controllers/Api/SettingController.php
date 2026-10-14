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

    /**
     * Helper to retrieve formatted company details from settings table.
     */
    public static function getCompanyDetails(): array
    {
        $settings = Setting::pluck('value', 'key')->toArray();

        $logo = $settings['company_logo'] ?? $settings['logo'] ?? null;
        $signature = $settings['company_signature'] ?? $settings['signature'] ?? null;

        $logoUrl = null;
        if (!empty($logo)) {
            $logoUrl = filter_var($logo, FILTER_VALIDATE_URL) ? $logo : url($logo);
        }

        $signatureUrl = null;
        if (!empty($signature)) {
            $signatureUrl = filter_var($signature, FILTER_VALIDATE_URL) ? $signature : url($signature);
        }

        return [
            'company_name'  => $settings['company_name'] ?? $settings['mail_from_name'] ?? 'Fablead Developers',
            'email'         => $settings['email'] ?? $settings['company_email'] ?? $settings['mail_from_address'] ?? '',
            'contact'       => $settings['contact'] ?? $settings['company_contact'] ?? '',
            'address'       => $settings['address'] ?? $settings['company_address'] ?? '',
            'logo'          => $logo ?: '',
            'logo_url'      => $logoUrl,
            'signature'     => $signature ?: '',
            'signature_url' => $signatureUrl,
        ];
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

            // Company Details
            'company_name' => 'Fablead Developers',
            'email' => '',
            'contact' => '',
            'address' => '',
            'logo' => '',
            'signature' => '',

            // GST Settings
            'gst_type' => 'withoutGst', // 'withGst' or 'withoutGst'
            'gst' => '0', // percentage
        ];

        $setting = array_merge($defaultSettings, $settings);

        // Add company details and direct URLs
        $companyDetails = self::getCompanyDetails();
        $setting['logo_url'] = $companyDetails['logo_url'];
        $setting['signature_url'] = $companyDetails['signature_url'];
        $setting['company_details'] = $companyDetails;

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

            // GST Settings
            'gst_type' => 'nullable|string|in:withGst,withoutGst,WithGst,WithoutGst,with_gst,without_gst',
            'gst' => 'nullable|numeric|min:0|max:100',
            'gst_percentage' => 'nullable|numeric|min:0|max:100',

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

            // Company Details
            'company_name' => 'nullable|string|max:255',
            'companyName' => 'nullable|string|max:255',
            'email' => 'nullable|string|email|max:255',
            'company_email' => 'nullable|string|email|max:255',
            'contact' => 'nullable|string|max:50',
            'company_contact' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:1000',
            'company_address' => 'nullable|string|max:1000',
            'logo' => 'nullable',
            'signature' => 'nullable',
        ]);

        // Standardize gst_type to withGst / withoutGst
        if (isset($validated['gst_type'])) {
            $lowerType = strtolower(str_replace('_', '', $validated['gst_type']));
            $validated['gst_type'] = ($lowerType === 'withgst') ? 'withGst' : 'withoutGst';
        }

        // Standardize gst / gst_percentage
        if (isset($validated['gst_percentage']) && !isset($validated['gst'])) {
            $validated['gst'] = $validated['gst_percentage'];
        }
        unset($validated['gst_percentage']);

        // Support both 'secret_key' and 'key_secret'
        if (isset($validated['secret_key']) && !isset($validated['key_secret'])) {
            $validated['key_secret'] = $validated['secret_key'];
        }
        unset($validated['secret_key']);

        $currentSettings = Setting::pluck('value', 'key')->toArray();

        // Handle uploaded company logo
        if ($request->hasFile('logo')) {
            $uploadDir = public_path('uploads/company');
            if (!\Illuminate\Support\Facades\File::exists($uploadDir)) {
                \Illuminate\Support\Facades\File::makeDirectory($uploadDir, 0755, true, true);
            }
            $oldLogo = $currentSettings['company_logo'] ?? $currentSettings['logo'] ?? null;
            if ($oldLogo && \Illuminate\Support\Facades\File::exists(public_path($oldLogo))) {
                @\Illuminate\Support\Facades\File::delete(public_path($oldLogo));
            }
            $file = $request->file('logo');
            $extension = $file->getClientOriginalExtension() ?: 'png';
            $filename = 'logo_' . time() . '_' . uniqid() . '.' . $extension;
            $file->move($uploadDir, $filename);
            $validated['logo'] = 'uploads/company/' . $filename;
            $validated['company_logo'] = 'uploads/company/' . $filename;
        }

        // Handle uploaded company signature
        if ($request->hasFile('signature')) {
            $uploadDir = public_path('uploads/company');
            if (!\Illuminate\Support\Facades\File::exists($uploadDir)) {
                \Illuminate\Support\Facades\File::makeDirectory($uploadDir, 0755, true, true);
            }
            $oldSig = $currentSettings['company_signature'] ?? $currentSettings['signature'] ?? null;
            if ($oldSig && \Illuminate\Support\Facades\File::exists(public_path($oldSig))) {
                @\Illuminate\Support\Facades\File::delete(public_path($oldSig));
            }
            $file = $request->file('signature');
            $extension = $file->getClientOriginalExtension() ?: 'png';
            $filename = 'signature_' . time() . '_' . uniqid() . '.' . $extension;
            $file->move($uploadDir, $filename);
            $validated['signature'] = 'uploads/company/' . $filename;
            $validated['company_signature'] = 'uploads/company/' . $filename;
        }

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
            'companyName' => 'company_name',
            'companyEmail' => 'email',
            'companyContact' => 'contact',
            'companyAddress' => 'address',
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
        $companyDetails = self::getCompanyDetails();
        $settings['logo_url'] = $companyDetails['logo_url'];
        $settings['signature_url'] = $companyDetails['signature_url'];
        $settings['company_details'] = $companyDetails;

        return response()->json([
            'success' => true,
            'message' => 'Settings saved successfully.',
            'data' => $settings,
            'company_details' => $companyDetails
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
