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
     * Apply middleware based on permissions.
     */
    public function __construct()
    {
        $this->middleware('permission:Staff,VIEW')->only(['index', 'show']);
        $this->middleware('permission:Staff,ADD')->only(['store']);
        $this->middleware('permission:Staff,EDIT')->only(['update']);
        $this->middleware('permission:Staff,DELETE')->only(['destroy']);
    }

    /**
     * Display a listing of the staff.
     */
    public function index()
    {
        $staff = User::whereHas('roleModel', function($q) {
                         $q->where('name', '!=', 'Super Admin');
                     })
                     ->with(['roleModel', 'permissions.module'])
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
            'position' => 'nullable|string|max:255',
            'role_id' => 'required|exists:roles,id',
            'status' => 'required|string|in:Active,Inactive',
            'email' => 'required|string|email|max:255|unique:users',
            'phone_number' => 'nullable|string|max:20',
            'password' => 'required|string|min:8',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'permissions' => 'nullable|array', // e.g., [{"module_id": 1, "permission": ["VIEW", "ADD"]}, ...]
        ]);

        $avatarPath = null;
        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $filename = time() . '_' . $file->getClientOriginalName();
            $file->move(public_path('uploads/avatar'), $filename);
            $avatarPath = 'uploads/avatar/' . $filename;
        }

        $staff = User::create([
            'name' => $request->name,
            'position' => $request->position,
            'role_id' => $request->role_id,
            'status' => $request->status,
            'email' => $request->email,
            'phone_number' => $request->phone_number,
            'password' => Hash::make($request->password),
            'avatar' => $avatarPath,
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
        $staff = User::with(['roleModel', 'permissions.module'])->find($id);

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
            'position' => 'nullable|string|max:255',
            'role_id' => 'required|exists:roles,id',
            'status' => 'required|string|in:Active,Inactive',
            'email' => 'required|string|email|max:255|unique:users,email,'.$id,
            'phone_number' => 'nullable|string|max:20',
            'password' => 'nullable|string|min:8',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'permissions' => 'nullable|array',
        ]);

        if ($request->hasFile('avatar')) {
            if ($staff->avatar && file_exists(public_path($staff->avatar))) {
                unlink(public_path($staff->avatar));
            }
            $file = $request->file('avatar');
            $filename = time() . '_' . $file->getClientOriginalName();
            $file->move(public_path('uploads/avatar'), $filename);
            $staff->avatar = 'uploads/avatar/' . $filename;
        }

        $staff->name = $request->name;
        $staff->position = $request->position;
        $staff->role_id = $request->role_id;
        $staff->status = $request->status;
        $staff->email = $request->email;
        $staff->phone_number = $request->phone_number;

        if ($request->filled('password')) {
            $staff->password = Hash::make($request->password);
        }

        $staff->save();

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
