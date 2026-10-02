<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Billing;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    /**
     * Display a listing of billings.
     */
    public function index(Request $request)
    {
        $query = Billing::query();

        if ($request->has('status') && !empty($request->status)) {
            $query->where('status', $request->status);
        }

        if ($request->has('search') && !empty($request->search)) {
            $query->where('title', 'like', '%' . $request->search . '%');
        }

        $billings = $query->latest()->get();

        return response()->json([
            'success' => true,
            'data' => $billings
        ], 200);
    }

    /**
     * Store a newly created billing in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:Active,Inactive',
        ]);

        if (!isset($validated['status'])) {
            $validated['status'] = 'Active';
        }

        $billing = Billing::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Billing created successfully.',
            'data' => $billing
        ], 201);
    }

    /**
     * Display the specified billing.
     */
    public function show($id)
    {
        $billing = Billing::find($id);

        if (!$billing) {
            return response()->json([
                'success' => false,
                'message' => 'Billing not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $billing
        ], 200);
    }

    /**
     * Update the specified billing in storage.
     */
    public function update(Request $request, $id)
    {
        $billing = Billing::find($id);

        if (!$billing) {
            return response()->json([
                'success' => false,
                'message' => 'Billing not found.'
            ], 404);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:Active,Inactive',
        ]);

        $billing->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Billing updated successfully.',
            'data' => $billing
        ], 200);
    }

    /**
     * Remove the specified billing from storage.
     */
    public function destroy($id)
    {
        $billing = Billing::find($id);

        if (!$billing) {
            return response()->json([
                'success' => false,
                'message' => 'Billing not found.'
            ], 404);
        }

        $billing->delete();

        return response()->json([
            'success' => true,
            'message' => 'Billing deleted successfully.'
        ], 200);
    }
}
