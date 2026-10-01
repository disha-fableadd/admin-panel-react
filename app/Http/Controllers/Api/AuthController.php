<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use App\Models\Module;

class AuthController extends Controller
{
    /**
     * Login API
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (Auth::attempt(['email' => $request->email, 'password' => $request->password])) {
            $user = Auth::user();
            $user->load('role');

            // Check if user has a role
            if ($user->role) {
                $token = $user->createToken('AdminPanelToken')->plainTextToken;
                
                // Load permissions
                $permissions = [];
                // Check role name dynamically from roles table
                if (strtolower($user->role->name) === 'super admin' || strtolower($user->role->name) === 'admin') {
                    $permissions = Module::where('status', 'Active')->get()->map(function($module) {
                        return [
                            'module_id' => $module->id,
                            'module_name' => $module->name,
                            'permission' => ['VIEW', 'ADD', 'EDIT', 'DELETE']
                        ];
                    });
                } else {
                    $user->load('permissions.module');
                    $permissions = $user->permissions->map(function($perm) {
                        return [
                            'module_id' => $perm->module_id,
                            'module_name' => $perm->module ? $perm->module->name : null,
                            'permission' => $perm->permission
                        ];
                    });
                    // Hide the loaded relationship to keep user object clean
                    $user->unsetRelation('permissions'); 
                }
                
                return response()->json([
                    'success' => true,
                    'message' => 'Login successful.',
                    'data' => [
                        'token' => $token,
                        'user' => $user,
                        'permissions' => $permissions
                    ]
                ], 200);
            } else {
                // Logout the user since they don't have permission
                Auth::logout();
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Please assign a role to this user.'
                ], 403);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Invalid email or password.'
        ], 401);
    }

    /**
     * Get Profile API
     */
    public function getProfile(Request $request)
    {
        $user = $request->user();
        $user->load('role');
        
        $permissions = [];
        if ($user->role && (strtolower($user->role->name) === 'super admin' || strtolower($user->role->name) === 'admin')) {
            $permissions = Module::where('status', 'Active')->get()->map(function($module) {
                return [
                    'module_id' => $module->id,
                    'module_name' => $module->name,
                    'permission' => ['VIEW', 'ADD', 'EDIT', 'DELETE']
                ];
            });
        } else {
            $user->load('permissions.module');
            $permissions = $user->permissions->map(function($perm) {
                return [
                    'module_id' => $perm->module_id,
                    'module_name' => $perm->module ? $perm->module->name : null,
                    'permission' => $perm->permission
                ];
            });
            $user->unsetRelation('permissions');
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Profile retrieved successfully.',
            'data' => [
                'user' => $user,
                'permissions' => $permissions
            ]
        ], 200);
    }

    /**
     * Update Profile API
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,'.$user->id,
            'position' => 'nullable|string|max:255',
            'phone_number' => 'nullable|string|max:20',
            'password' => 'nullable|string|min:8',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        $user->name = $request->name;
        $user->email = $request->email;
        $user->position = $request->position ?? $user->position;
        $user->phone_number = $request->phone_number ?? $user->phone_number;

        if ($request->hasFile('avatar')) {
            // Delete old avatar if exists
            if ($user->avatar) {
                Storage::disk('public')->delete(str_replace('/storage/', '', $user->avatar));
            }

            $avatarPath = $request->file('avatar')->store('avatars', 'public');
            $user->avatar = '/storage/' . $avatarPath;
        }

        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'data' => $user
        ], 200);
    }

    /**
     * Change Password API
     */
    public function changePassword(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        // Check if current password matches
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password does not match.'
            ], 400);
        }

        // Update password
        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.'
        ], 200);
    }

    /**
     * Logout API
     */
    public function logout(Request $request)
    {
        // Revoke the token that was used to authenticate the current request
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Successfully logged out.'
        ], 200);
    }
}
