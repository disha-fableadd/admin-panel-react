<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * 1. GET: Fetch current Razorpay / Gateway settings.
     */
    public function getRazorpaySettings()
    {
        $setting = Setting::first();

        if (!$setting) {
            $setting = [
                'id' => null,
                'gateway_environment' => 'Live Production Mode',
                'settlement_currency' => 'INR (₹ - Indian Rupee)',
                'auto_capture' => 'Immediate Capture (Recommended)',
                'key_id' => '',
                'key_secret' => '',
                'webhook_secret' => '',
                'status' => 'Active',
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $setting
        ], 200);
    }

    /**
     * 2. POST: Save or Update Razorpay / Gateway settings.
     * If setting data does not exist, it creates (Add).
     * If setting data exists, it updates (Update).
     */
    public function saveRazorpaySettings(Request $request)
    {
        $validated = $request->validate([
            'gateway_environment' => 'nullable|string|max:255',
            'settlement_currency' => 'nullable|string|max:255',
            'auto_capture' => 'nullable|string|max:255',
            'key_id' => 'nullable|string|max:255',
            'key_secret' => 'nullable|string',
            'secret_key' => 'nullable|string',
            'webhook_secret' => 'nullable|string',
            'status' => 'nullable|in:Active,Inactive',
        ]);

        // Support both 'secret_key' and 'key_secret' field names
        if (isset($validated['secret_key']) && !isset($validated['key_secret'])) {
            $validated['key_secret'] = $validated['secret_key'];
        }
        unset($validated['secret_key']);

        $setting = Setting::first();

        if ($setting) {
            $setting->update($validated);
            $message = 'Razorpay settings updated successfully.';
        } else {
            $setting = Setting::create($validated);
            $message = 'Razorpay settings saved successfully.';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $setting
        ], 200);
    }
}
