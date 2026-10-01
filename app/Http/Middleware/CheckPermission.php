<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Module;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, $moduleName, $permission): Response
    {
        $user = $request->user();
        $user->load('roleModel');

        if (!$user->roleModel) {
            return response()->json(['success' => false, 'message' => 'Unauthorized. No role assigned.'], 403);
        }

        // Super Admin gets full access
        $roleName = strtolower($user->roleModel->name);
        if ($roleName === 'super admin' || $roleName === 'admin') {
            return $next($request);
        }

        // Find the module by name
        $module = Module::where('name', $moduleName)->where('status', 'Active')->first();
        
        if (!$module) {
            return response()->json(['success' => false, 'message' => 'Module not found or inactive.'], 404);
        }

        // Check if the user has the required permission in their user_permissions table
        $hasPermission = $user->permissions()
            ->where('module_id', $module->id)
            ->whereJsonContains('permission', $permission)
            ->exists();

        if (!$hasPermission) {
            return response()->json([
                'success' => false, 
                'message' => 'You do not have permission to ' . $permission . ' this module.'
            ], 403);
        }

        return $next($request);
    }
}
