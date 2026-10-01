<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
