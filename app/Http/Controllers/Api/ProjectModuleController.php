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
        $query = ProjectModule::with(['product']);
        
        $projectId = $request->query('project_id');
        
        // If no specific project requested, default to the one in settings
        if (!$projectId) {
            $defaultProject = \App\Models\Setting::where('key', 'is_default_project')->value('value');
            if ($defaultProject) {
                $projectId = $defaultProject;
            }
        }
        
        if ($projectId && $projectId !== 'all') {
            $query->where(function($q) use ($projectId) {
                $q->whereJsonContains('project_id', (string)$projectId)
                  ->orWhereJsonContains('project_id', (int)$projectId)
                  ->orWhere('project_id', 'like', '%"'.$projectId.'"%');
            });
        }

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
            'project_id' => 'nullable|array',
            'project_id.*' => 'integer',
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
            $validated['client_id'] = array_values($mergedClients);

            $existingProjects = $existingModule->project_id ?? [];
            $newProjects = $validated['project_id'] ?? [];
            $mergedProjects = array_unique(array_merge($existingProjects, $newProjects));
            $validated['project_id'] = array_values($mergedProjects);
            
            $existingModule->update($validated);
            $projectModule = $existingModule;
            $message = 'Project Module updated with new clients successfully.';
        } else {
            $projectModule = ProjectModule::create($validated);
            $message = 'Project Module created successfully.';
        }

        $projectModule->load(['product']);

        $this->notifyAllUsers('Project Module Saved', 'A project module was added or updated.');

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $projectModule
        ], 201);
    }

    public function show(ProjectModule $projectModule)
    {
        $projectModule->load(['product']);
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
            'project_id' => 'nullable|array',
            'project_id.*' => 'integer',
            'client_id' => 'nullable|array',
            'client_id.*' => 'integer',
            'status' => 'required|in:Active,Inactive',
            'description' => 'nullable|string',
        ]);

        $projectModule->update($validated);
        $projectModule->load(['product']);

        return response()->json([
            'success' => true,
            'message' => 'Project Module updated successfully.',
            'data' => $projectModule
        ]);
    }

    public function destroy(ProjectModule $projectModule)
    {
        $id = $projectModule->id;

        // Check pivot table
        $hasClients = \Illuminate\Support\Facades\DB::table('client_project_modules')->where('project_module_id', $id)->exists();
        // Also check if any membership references this ID in its JSON array
        $hasMemberships = \App\Models\Membership::where('project_modules_id', 'like', '%"'.$id.'"%')->exists();

        if ($hasClients || $hasMemberships) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete this module because it is assigned to existing clients or memberships. Please remove the assignment first.'
            ], 400);
        }

        $projectModule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Project Module deleted successfully.'
        ]);
    }
}
