<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProjectModule;
use Illuminate\Http\Request;

class ProjectModuleController extends Controller
{
    public function index(Request $request)
    {
        // in get api only active modules show
        $query = ProjectModule::with(['product', 'project']);
        // $query->where('status', 'Active');
        
        return response()->json([
            'success' => true,
            'data' => $query->get()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'product_id' => 'required|integer',
            'project_id' => 'nullable|integer',
            'status' => 'required|in:Active,Inactive',
            'description' => 'nullable|string',
        ]);

        $projectModule = ProjectModule::create($validated);
        $projectModule->load(['product', 'project']);

        $this->notifyAllUsers('New Project Module Created', 'A new project module was added.');

        return response()->json([
            'success' => true,
            'message' => 'Project Module created successfully.',
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
