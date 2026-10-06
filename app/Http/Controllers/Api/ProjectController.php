<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    /**
     * Display a listing of projects.
     */
    public function __construct()
    {
        $this->middleware('permission:Modules (Products),VIEW')->only(['index', 'show']);
        $this->middleware('permission:Modules (Products),ADD')->only(['store']);
        $this->middleware('permission:Modules (Products),EDIT')->only(['update']);
        $this->middleware('permission:Modules (Products),DELETE')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $query = Project::with('product');

        if ($request->has('product_id') && !empty($request->product_id)) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->has('status') && !empty($request->status)) {
            $query->where('status', $request->status);
        }

        if ($request->has('search') && !empty($request->search)) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $projects = $query->latest()->get();

        return response()->json([
            'success' => true,
            'data' => $projects
        ], 200);
    }

    /**
     * Store a newly created project in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|max:50',
        ]);

        if (!isset($validated['status'])) {
            $validated['status'] = 'Active';
        }

        $project = Project::create($validated);
        $project->load('product');

        $this->notifyAllUsers('New Project Created', 'A new project was added.');

        return response()->json([
            'success' => true,
            'message' => 'Project created successfully.',
            'data' => $project
        ], 201);
    }

    /**
     * Display the specified project.
     */
    public function show($id)
    {
        $project = Project::with('product')->find($id);

        if (!$project) {
            return response()->json([
                'success' => false,
                'message' => 'Project not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $project
        ], 200);
    }

    /**
     * Update the specified project in storage.
     */
    public function update(Request $request, $id)
    {
        $project = Project::find($id);

        if (!$project) {
            return response()->json([
                'success' => false,
                'message' => 'Project not found.'
            ], 404);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|max:50',
        ]);

        $project->update($validated);
        $project->load('product');

        return response()->json([
            'success' => true,
            'message' => 'Project updated successfully.',
            'data' => $project
        ], 200);
    }

    /**
     * Remove the specified project from storage.
     */
    public function destroy($id)
    {
        $project = Project::find($id);

        if (!$project) {
            return response()->json([
                'success' => false,
                'message' => 'Project not found.'
            ], 404);
        }

        $hasModules = \App\Models\ProjectModule::where('project_id', $id)->exists();
        $hasMemberships = \App\Models\Membership::where('project_id', $id)->exists();
        $hasClients = \App\Models\Client::where('project_id', $id)->exists();

        if ($hasModules || $hasMemberships || $hasClients) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete this project because it is assigned to existing modules, memberships, or clients. Please delete them first.'
            ], 400);
        }

        $project->delete();

        return response()->json([
            'success' => true,
            'message' => 'Project deleted successfully.'
        ], 200);
    }
}
