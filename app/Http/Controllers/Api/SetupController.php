<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setup;
use Illuminate\Http\Request;

class SetupController extends Controller
{
    public function index()
    {
        $setups = Setup::with(['client.product', 'client.membership.billing', 'client.membership.project'])->get();
        return response()->json([
            'success' => true,
            'data' => $setups
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'domain' => 'required|string|max:255',
            'db_credential' => 'nullable|array',
        ]);

        $setup = Setup::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Setup created successfully.',
            'data' => $setup
        ], 201);
    }

    public function show(Setup $setup)
    {
        $setup->load(['client.product', 'client.membership.billing', 'client.membership.project']);
        return response()->json([
            'success' => true,
            'data' => $setup
        ]);
    }

    public function update(Request $request, Setup $setup)
    {
        $validated = $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'domain' => 'required|string|max:255',
            'db_credential' => 'nullable|array',
        ]);

        $setup->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Setup updated successfully.',
            'data' => $setup
        ]);
    }

    public function destroy(Setup $setup)
    {
        $setup->delete();
        return response()->json([
            'success' => true,
            'message' => 'Setup deleted successfully.'
        ]);
    }
}
