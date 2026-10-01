<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StaffController extends Controller
{
    /**
     * Display a listing of the staff.
     */
    public function index()
    {
        $staff = User::whereHas('role', function($q) {
                         $q->where('name', '!=', 'Super Admin');
                     })
                     ->with(['role', 'permissions.module'])
                     ->get();

        return response()->json([
            'success' => true, 
            'data' => $staff
        ], 200);
    }

    /**
     * Store a newly created staff in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role_id' => 'required|exists:roles,id',
            'permissions' => 'nullable|array', // e.g., [{"module_id": 1, "permission": ["VIEW", "ADD"]}, ...]
        ]);

        $staff = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role_id' => $request->role_id,
            'status' => 'Active',
        ]);

        if ($request->has('permissions')) {
            foreach ($request->permissions as $perm) {
                UserPermission::create([
                    'user_id' => $staff->id,
                    'module_id' => $perm['module_id'],
                    'permission' => $perm['permission']
                ]);
            }
        }

        return response()->json([
            'success' => true, 
            'message' => 'Staff created successfully.', 
            'data' => $staff->load('permissions')
        ], 201);
    }

    /**
     * Display the specified staff.
     */
    public function show($id)
    {
        $staff = User::with(['role', 'permissions.module'])->find($id);

        if (!$staff) {
            return response()->json(['success' => false, 'message' => 'Staff not found.'], 404);
        }

        return response()->json(['success' => true, 'data' => $staff], 200);
    }

    /**
     * Update the specified staff in storage.
     */
    public function update(Request $request, $id)
    {
        $staff = User::find($id);

        if (!$staff) {
            return response()->json(['success' => false, 'message' => 'Staff not found.'], 404);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,'.$id,
            'role_id' => 'required|exists:roles,id',
            'permissions' => 'nullable|array',
        ]);

        $staff->update([
            'name' => $request->name,
            'email' => $request->email,
            'role_id' => $request->role_id,
        ]);

        if ($request->has('permissions')) {
            // Delete old permissions
            UserPermission::where('user_id', $staff->id)->delete();
            
            // Add new permissions
            foreach ($request->permissions as $perm) {
                UserPermission::create([
                    'user_id' => $staff->id,
                    'module_id' => $perm['module_id'],
                    'permission' => $perm['permission']
                ]);
            }
        }

        return response()->json([
            'success' => true, 
            'message' => 'Staff updated successfully.', 
            'data' => $staff->load('permissions')
        ], 200);
    }

    /**
     * Remove the specified staff from storage.
     */
    public function destroy($id)
    {
        $staff = User::find($id);

        if (!$staff) {
            return response()->json(['success' => false, 'message' => 'Staff not found.'], 404);
        }

        $staff->delete();

        return response()->json(['success' => true, 'message' => 'Staff deleted successfully.'], 200);
    }
}
