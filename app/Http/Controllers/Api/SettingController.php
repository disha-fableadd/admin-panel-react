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
    public function getSettings()
    {
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
        ];

        $setting = array_merge($defaultSettings, $settings);

        return response()->json([
            'success' => true,
            'data' => $setting
        ], 200);
    }

    /**
     * 2. POST: Save or Update settings.
     * If setting data does not exist, it creates (Add).
     * If setting data exists, it updates (Update).
     */
    public function saveSettings(Request $request)
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
            'razorpay_active' => 'nullable|in:1,0,true,false',
            'is_default_project' => 'nullable', // Can be integer or string depending on project ID
        ]);

        // Support both 'secret_key' and 'key_secret' field names
        if (isset($validated['secret_key']) && !isset($validated['key_secret'])) {
            $validated['key_secret'] = $validated['secret_key'];
        }
        unset($validated['secret_key']);

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
}
