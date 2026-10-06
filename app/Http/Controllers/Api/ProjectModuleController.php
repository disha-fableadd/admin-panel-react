<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProjectModule;
use Illuminate\Http\Request;

class ProjectModuleController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:Modules (Products),VIEW')->only(['index', 'show']);
        $this->middleware('permission:Modules (Products),ADD')->only(['store']);
        $this->middleware('permission:Modules (Products),EDIT')->only(['update']);
        $this->middleware('permission:Modules (Products),DELETE')->only(['destroy']);
    }

    public function index(Request $request)
    {
        // in get api only active modules show
        $query = ProjectModule::with(['product', 'project']);
        // $query->where('status', 'Active');
        
        return response()->json([
            'success' => true,
            'data' => $query->latest()->get()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'product_id' => 'required|integer',
            'project_id' => 'nullable|integer',
            'client_id' => 'nullable|array',
            'client_id.*' => 'integer',
            'status' => 'required|in:Active,Inactive',
            'description' => 'nullable|string',
        ]);

        $existingModule = ProjectModule::where('name', $validated['name'])
                                       ->where('product_id', $validated['product_id'])
                                       ->first();

        if ($existingModule) {
            $existingClients = $existingModule->client_id ?? [];
            $newClients = $validated['client_id'] ?? [];
            
            $mergedClients = array_unique(array_merge($existingClients, $newClients));
            $validated['client_id'] = array_values($mergedClients); // array_values ensures JSON array, not object
            
            $existingModule->update($validated);
            $projectModule = $existingModule;
            $message = 'Project Module updated with new clients successfully.';
        } else {
            $projectModule = ProjectModule::create($validated);
            $message = 'Project Module created successfully.';
        }

        $projectModule->load(['product', 'project']);

        $this->notifyAllUsers('Project Module Saved', 'A project module was added or updated.');

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $projectModule
        ], 201);
    }

    public function show(ProjectModule $projectModule)
    {
        $projectModule->load(['product', 'project']);
        return response()->json([
            'success' => true,
            'data' => $projectModule
        ]);
    }

    public function update(Request $request, ProjectModule $projectModule)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'product_id' => 'required|integer',
            'project_id' => 'nullable|integer',
            'client_id' => 'nullable|array',
            'client_id.*' => 'integer',
            'status' => 'required|in:Active,Inactive',
            'description' => 'nullable|string',
        ]);

        $projectModule->update($validated);
        $projectModule->load(['product', 'project']);

        return response()->json([
            'success' => true,
            'message' => 'Project Module updated successfully.',
            'data' => $projectModule
        ]);
    }

    public function destroy(ProjectModule $projectModule)
    {
        $projectModule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Project Module deleted successfully.'
        ]);
    }
}
