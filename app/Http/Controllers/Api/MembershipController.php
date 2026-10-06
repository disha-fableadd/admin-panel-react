<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use Illuminate\Http\Request;

class MembershipController extends Controller
{
    /**
     * Display a listing of memberships.
     */
    public function __construct()
    {
        $this->middleware('permission:Membership Plans,VIEW')->only(['index', 'show']);
        $this->middleware('permission:Membership Plans,ADD')->only(['store']);
        $this->middleware('permission:Membership Plans,EDIT')->only(['update']);
        $this->middleware('permission:Membership Plans,DELETE')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $query = Membership::with(['product', 'project']);

        if ($request->has('product_id') && !empty($request->product_id)) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->has('project_id') && !empty($request->project_id)) {
            $query->where('project_id', $request->project_id);
        }


        if ($request->has('project_modules_id') && !empty($request->project_modules_id)) {
            $query->where('project_modules_id', $request->project_modules_id);
        }

        if ($request->has('status') && !empty($request->status)) {
            $query->where('status', $request->status);
        }

        if ($request->has('search') && !empty($request->search)) {
            $query->where('plan_name', 'like', '%' . $request->search . '%');
        }

        $memberships = $query->latest()->get();

        return response()->json([
            'success' => true,
            'data' => $memberships
        ], 200);
    }

    /**
     * Store a newly created membership in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'project_id' => 'required|exists:projects,id',
            'project_modules_id' => 'required|array',
            'project_modules_id.*' => 'integer',
            'is_custom_billing' => 'boolean',
            'billing_title' => 'nullable|string|max:255',
            'plan_name' => 'required|string|max:255',
            'status' => 'nullable|in:Active,Inactive',
            'amount' => 'nullable|array',
            'renewal_amount' => 'nullable|array',
            'max_user' => 'nullable|integer|min:0',
            'max_branch' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
        ]);

        if (!isset($validated['status'])) {
            $validated['status'] = 'Active';
        }

        $membership = Membership::create($validated);
        $membership->load(['product', 'project']);

        $this->notifyAllUsers('New Membership Created', 'A new membership plan was added.');

        return response()->json([
            'success' => true,
            'message' => 'Membership plan created successfully.',
            'data' => $membership
        ], 201);
    }

    /**
     * Display the specified membership.
     */
    public function show($id)
    {
        $membership = Membership::with(['product', 'project'])->find($id);

        if (!$membership) {
            return response()->json([
                'success' => false,
                'message' => 'Membership plan not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $membership
        ], 200);
    }

    /**
     * Update the specified membership in storage.
     */
    public function update(Request $request, $id)
    {
        $membership = Membership::find($id);

        if (!$membership) {
            return response()->json([
                'success' => false,
                'message' => 'Membership plan not found.'
            ], 404);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'project_id' => 'required|exists:projects,id',
            'project_modules_id' => 'required|array',
            'project_modules_id.*' => 'integer',
            'is_custom_billing' => 'boolean',
            'billing_title' => 'nullable|string|max:255',
            'plan_name' => 'required|string|max:255',
            'status' => 'nullable|in:Active,Inactive',
            'amount' => 'nullable|array',
            'renewal_amount' => 'nullable|array',
            'max_user' => 'nullable|integer|min:0',
            'max_branch' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
        ]);

        $membership->update($validated);
        $membership->load(['product', 'project']);

        return response()->json([
            'success' => true,
            'message' => 'Membership plan updated successfully.',
            'data' => $membership
        ], 200);
    }

    /**
     * Remove the specified membership from storage.
     */
    public function destroy($id)
    {
        $membership = Membership::find($id);

        if (!$membership) {
            return response()->json([
                'success' => false,
                'message' => 'Membership plan not found.'
            ], 404);
        }

        $membership->delete();

        return response()->json([
            'success' => true,
            'message' => 'Membership plan deleted successfully.'
        ], 200);
    }
}
