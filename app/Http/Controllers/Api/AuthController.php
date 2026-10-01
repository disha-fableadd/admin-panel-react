<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;

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

            // Check if user is super admin or staff
            if (in_array($user->role, ['super_admin', 'staff'])) {
                $token = $user->createToken('AdminPanelToken')->accessToken;
                
                return response()->json([
                    'success' => true,
                    'message' => 'Login successful.',
                    'data' => [
                        'token' => $token,
                        'user' => $user
                    ]
                ], 200);
            } else {
                // Logout the user since they don't have permission
                Auth::logout();
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only Super Admin and Staff can login.'
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
        
        return response()->json([
            'success' => true,
            'message' => 'Profile retrieved successfully.',
            'data' => $user
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
     * Logout API
     */
    public function logout(Request $request)
    {
        // Revoke the token that was used to authenticate the current request
        $request->user()->token()->revoke();

        return response()->json([
            'success' => true,
            'message' => 'Successfully logged out.'
        ], 200);
    }
}
