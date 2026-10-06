<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Module;
use Illuminate\Http\Request;

class ModuleController extends Controller
{
    /**
     * Apply middleware based on permissions.
     */
    public function __construct()
    {
        $this->middleware('permission:Modules (Products),VIEW')->only(['index', 'show']);
        $this->middleware('permission:Modules (Products),ADD')->only(['store']);
        $this->middleware('permission:Modules (Products),EDIT')->only(['update']);
        $this->middleware('permission:Modules (Products),DELETE')->only(['destroy']);
    }
    /**
     * Display a listing of the modules.
     */
    public function index()
    {
        $modules = Module::where('status', 'Active')->latest()->get();
        return response()->json([
            'success' => true, 
            'data' => $modules
        ], 200);
    }

    /**
     * Store a newly created module in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'permission' => 'nullable|array',
            'status' => 'nullable|in:Active,Inactive'
        ]);

        $module = Module::create($request->all());

        $this->notifyAllUsers('New Module Created', 'A new module was added.');

        return response()->json([
            'success' => true, 
            'message' => 'Module created successfully.', 
            'data' => $module
        ], 201);
    }

    /**
     * Display the specified module.
     */
    public function show($id)
    {
        $module = Module::find($id);

        if (!$module) {
            return response()->json([
                'success' => false, 
                'message' => 'Module not found.'
            ], 404);
        }

        return response()->json([
            'success' => true, 
            'data' => $module
        ], 200);
    }

    /**
     * Update the specified module in storage.
     */
    public function update(Request $request, $id)
    {
        $module = Module::find($id);

        if (!$module) {
            return response()->json([
                'success' => false, 
                'message' => 'Module not found.'
            ], 404);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'permission' => 'nullable|array',
            'status' => 'nullable|in:Active,Inactive'
        ]);

        $module->update($request->all());

        return response()->json([
            'success' => true, 
            'message' => 'Module updated successfully.', 
            'data' => $module
        ], 200);
    }

    /**
     * Remove the specified module from storage.
     */
    public function destroy($id)
    {
        $module = Module::find($id);

        if (!$module) {
            return response()->json([
                'success' => false, 
                'message' => 'Module not found.'
            ], 404);
        }

        $module->delete();

        return response()->json([
            'success' => true, 
            'message' => 'Module deleted successfully.'
        ], 200);
    }
}
