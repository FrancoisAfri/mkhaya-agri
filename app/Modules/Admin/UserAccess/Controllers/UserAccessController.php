<?php

namespace App\Modules\Admin\UserAccess\Controllers;

use App\Modules\Admin\UserAccess\Services\UserAccessService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Core\Role\Models\Role;

class UserAccessController extends Controller
{
    protected $service;

    public function __construct(UserAccessService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $users = $this->service->getUsersAccess();
        $roles = Role::orderBy('name')->get(['id', 'name']);
        return Inertia::render('Core/UserAccess/Index', [
            'users' => $users,
            'roles' => $roles,
        ]);
    }

    public function getUserAccess($userId)
    {
        return response()->json($this->service->getUserAccess($userId));
    }

    public function saveCategoryAccess(Request $request, $userId, $categoryId)
    {
        $request->validate(['access_level' => 'required|integer|min:0|max:5']);
        $this->service->saveCategoryAccess($userId, $categoryId, $request->access_level);
        return response()->json(['success' => true]);
    }

    public function saveModuleAccess(Request $request, $userId, $moduleId)
    {
        $request->validate(['access_level' => 'required|integer|min:0|max:5']);
        return response()->json($this->service->saveModuleAccess($userId, $moduleId, (int) $request->access_level));
    }

    public function saveRibbonAccess(Request $request, $userId, $ribbonId)
    {
        $request->validate(['access_level' => 'required|integer|min:0|max:5']);
        return response()->json($this->service->saveRibbonAccess($userId, $ribbonId, (int) $request->access_level));
    }
}
