<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * Get current Razorpay / Gateway settings.
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
     * Save or update Razorpay / Gateway settings.
     */
    public function updateRazorpaySettings(Request $request)
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
        } else {
            $setting = Setting::create($validated);
        }

        return response()->json([
            'success' => true,
            'message' => 'Razorpay settings saved successfully.',
            'data' => $setting
        ], 200);
    }

    /**
     * General settings index method.
     */
    public function index()
    {
        return $this->getRazorpaySettings();
    }

    /**
     * General settings store / update method.
     */
    public function store(Request $request)
    {
        return $this->updateRazorpaySettings($request);
    }

    /**
     * General settings update method.
     */
    public function update(Request $request)
    {
        return $this->updateRazorpaySettings($request);
    }
}
