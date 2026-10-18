<?php

namespace App\Core\Permission\Controllers;

use App\Core\Permission\Services\PermissionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;

class PermissionController extends Controller
{
    public function __construct(
        protected PermissionService $permissionService
    ) {}

    public function index()
    {
        $permissions = $this->permissionService->getAll()->loadCount('roles');

        return Inertia::render('Core/Permissions/Index', [
            'permissions' => $permissions,
        ]);
    }
    public function store()
    {
        $data = request()->validate([
            'name' => 'required|string|unique:permissions,name',
            'guard_name' => 'nullable|string',
        ]);

        $permission = $this->permissionService->create($data);
        return redirect()->route('permissions.index')->with('success', 'Permission created successfully.');
    }

    public function show(int $id): JsonResponse
    {
        $permission = $this->permissionService->find($id);
        if (!$permission) {
            return response()->json(['message' => 'Permission not found'], 404);
        }
        return response()->json($permission);
    }

    public function update(int $id)
    {
        $data = request()->validate([
            'name' => 'required|string|unique:permissions,name,' . $id,
            'guard_name' => 'nullable|string',
        ]);

        $updated = $this->permissionService->update($id, $data);
        if (!$updated) {
            return response()->json(['message' => 'Failed to update permission'], 400);
        }

        $permission = $this->permissionService->find($id);
        return redirect()->route('permissions.index')->with('success', 'Permission updated successfully.');
    }

    public function destroy(int $id)
    {
        $deleted = $this->permissionService->delete($id);
        if (!$deleted) {
            return response()->json(['message' => 'Failed to delete permission'], 400);
        }
        return redirect()->route('permissions.index')->with('success', 'Permission deleted successfully.');;
    }
}
