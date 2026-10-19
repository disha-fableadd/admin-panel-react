<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubModule;
use App\Models\Module;
use Illuminate\Http\Request;

class SubModuleController extends Controller
{
    /**
     * Apply middleware based on permissions.
     */
    public function __construct()
    {
        $this->middleware('permission:Modules (Products),VIEW')->only(['index', 'show', 'getByModule']);
        $this->middleware('permission:Modules (Products),ADD')->only(['store']);
        $this->middleware('permission:Modules (Products),EDIT')->only(['update']);
        $this->middleware('permission:Modules (Products),DELETE')->only(['destroy']);
    }

    /**
     * Display a listing of sub modules.
     * Can filter by module_id and/or status.
     */
    public function index(Request $request)
    {
        $query = SubModule::with('module');

        if ($request->filled('module_id')) {
            $query->where('module_id', $request->module_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $subModules = $query->latest()->get();

        return response()->json([
            'success' => true,
            'data' => $subModules
        ], 200);
    }

    /**
     * Display sub modules for a specific module.
     */
    public function getByModule(Request $request, $moduleId)
    {
        $module = Module::without('subModules')->find($moduleId);

        if (!$module) {
            return response()->json([
                'success' => false,
                'message' => 'Module not found.'
            ], 404);
        }

        $query = SubModule::where('module_id', $moduleId);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $subModules = $query->latest()->get();

        return response()->json([
            'success' => true,
            'module' => $module,
            'data' => $subModules
        ], 200);
    }

    /**
     * Store a newly created sub module in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'module_id' => 'required|exists:modules,id',
            'name' => 'required|string|max:255',
            'permission' => 'nullable|array',
            'status' => 'nullable|in:Active,Inactive',
        ]);

        $subModule = SubModule::create([
            'module_id' => $request->module_id,
            'name' => $request->name,
            'permission' => $request->permission,
            'status' => $request->status ?? 'Active',
        ]);

        $this->notifyAllUsers('New Sub Module Created', "A new sub module '{$subModule->name}' was added.");

        return response()->json([
            'success' => true,
            'message' => 'Sub module created successfully.',
            'data' => $subModule->load('module')
        ], 201);
    }

    /**
     * Display the specified sub module.
     */
    public function show($id)
    {
        $subModule = SubModule::with('module')->find($id);

        if (!$subModule) {
            return response()->json([
                'success' => false,
                'message' => 'Sub module not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $subModule
        ], 200);
    }

    /**
     * Update the specified sub module in storage.
     */
    public function update(Request $request, $id)
    {
        $subModule = SubModule::find($id);

        if (!$subModule) {
            return response()->json([
                'success' => false,
                'message' => 'Sub module not found.'
            ], 404);
        }

        $request->validate([
            'module_id' => 'sometimes|required|exists:modules,id',
            'name' => 'required|string|max:255',
            'permission' => 'nullable|array',
            'status' => 'nullable|in:Active,Inactive',
        ]);

        $subModule->update($request->only(['module_id', 'name', 'permission', 'status']));

        return response()->json([
            'success' => true,
            'message' => 'Sub module updated successfully.',
            'data' => $subModule->load('module')
        ], 200);
    }

    /**
     * Remove the specified sub module from storage.
     */
    public function destroy($id)
    {
        $subModule = SubModule::find($id);

        if (!$subModule) {
            return response()->json([
                'success' => false,
                'message' => 'Sub module not found.'
            ], 404);
        }

        $subModule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sub module deleted successfully.'
        ], 200);
    }
}
