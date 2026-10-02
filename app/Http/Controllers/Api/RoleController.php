<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    /**
     * Apply middleware based on permissions.
     */
    public function __construct()
    {
        $this->middleware('permission:Role Access,VIEW')->only(['index', 'show']);
        $this->middleware('permission:Role Access,ADD')->only(['store']);
        $this->middleware('permission:Role Access,EDIT')->only(['update']);
        $this->middleware('permission:Role Access,DELETE')->only(['destroy']);
    }
    /**
     * Display a listing of the roles.
     */
    public function index()
    {
        $roles = Role::where('name', '!=', 'Super Admin')->get();
        return response()->json([
            'success' => true, 
            'data' => $roles
        ], 200);
    }

    /**
     * Store a newly created role in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:Active,Inactive'
        ]);

        $role = Role::create($request->all());

        return response()->json([
            'success' => true, 
            'message' => 'Role created successfully.', 
            'data' => $role
        ], 201);
    }

    /**
     * Display the specified role.
     */
    public function show($id)
    {
        $role = Role::find($id);

        if (!$role) {
            return response()->json([
                'success' => false, 
                'message' => 'Role not found.'
            ], 404);
        }

        return response()->json([
            'success' => true, 
            'data' => $role
        ], 200);
    }

    /**
     * Update the specified role in storage.
     */
    public function update(Request $request, $id)
    {
        $role = Role::find($id);

        if (!$role) {
            return response()->json([
                'success' => false, 
                'message' => 'Role not found.'
            ], 404);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:Active,Inactive'
        ]);

        $role->update($request->all());

        return response()->json([
            'success' => true, 
            'message' => 'Role updated successfully.', 
            'data' => $role
        ], 200);
    }

    /**
     * Remove the specified role from storage.
     */
    public function destroy($id)
    {
        $role = Role::find($id);

        if (!$role) {
            return response()->json([
                'success' => false, 
                'message' => 'Role not found.'
            ], 404);
        }

        if ($role->name === 'Super Admin') {
            return response()->json([
                'success' => false,
                'message' => 'The Super Admin role cannot be deleted.'
            ], 403);
        }

        $role->delete();

        return response()->json([
            'success' => true, 
            'message' => 'Role deleted successfully.'
        ], 200);
    }
}
