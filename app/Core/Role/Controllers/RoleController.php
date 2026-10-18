<?php

namespace App\Core\Role\Controllers;

use App\Core\Role\Services\RoleService;
use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    public function __construct(
        protected RoleService $roleService
    ) {}

    public function index(Request $request)
    {
        $roles = $this->roleService->getAll()
            ->load('permissions')
            ->loadCount('users');

        // Attach up to 4 sample users per role for avatar display in cards
        foreach ($roles as $role) {
            $role->setAttribute(
                'sample_users',
                User::whereHas('roles', fn ($q) => $q->where('roles.id', $role->id))
                    ->with('person:user_id,first_name,surname,uuid')
                    ->limit(4)
                    ->get(['id'])
            );
        }

        $permissions = Permission::orderBy('name')->get(['id', 'name']);

        // Paginated user table with role, search, and filter support
        $users = User::with([
                'roles:id,name',
                'person:user_id,first_name,surname,uuid',
            ])
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($inner) use ($request) {
                    $inner->whereHas('person', fn ($p) => $p
                        ->where('first_name', 'ilike', "%{$request->search}%")
                        ->orWhere('surname',    'ilike', "%{$request->search}%")
                    )->orWhere('email', 'ilike', "%{$request->search}%");
                });
            })
            ->when($request->role, function ($q) use ($request) {
                $q->whereHas('roles', fn ($r) => $r->where('name', $request->role));
            })
            ->orderBy('email')
            ->paginate((int) $request->input('per_page', 10))
            ->withQueryString();

        return Inertia::render('Core/Role/Index', [
            'roles'       => $roles,
            'permissions' => $permissions,
            'users'       => $users,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|unique:roles,name',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $role = $this->roleService->create($request->all());
          return redirect()->back()->with('success', 'Role created successfully.');

    }

    public function show(int $id): JsonResponse
    {
        $role = $this->roleService->findById($id);
        return response()->json($role);
    }

    public function update(Request $request, int $id)
    {
        $request->validate([
            'name' => 'sometimes|string|unique:roles,name,' . $id,
            'permissions' => 'sometimes|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $role = $this->roleService->update($id, $request->all());
        return redirect()->back()->with('success', 'Role updated successfully.');
    }

    public function destroy(int $id)
    {
        $this->roleService->delete($id);
        return redirect()->back()->with('success', 'Role deleted successfully.');
    }

    public function syncPermissions(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $role = $this->roleService->syncPermissions($id, $request->permissions);
        return response()->json($role);
    }
}
