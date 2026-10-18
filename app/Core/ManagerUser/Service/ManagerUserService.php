<?php

namespace App\Core\ManagerUser\Service;

use App\Core\Category\Models\CategoryAccess;
use App\Core\Module\Models\ModuleAccess;
use App\Core\User\Models\User;


class ManagerUserService
{
    public function getUsers()
    {
        return User::with(['person', 'roles'])
            ->get();
    }
public static function giveUserAccess($userID, $CategoryID, $moduleID, $accessLevel)
{
    // Vérifier si l'accès à la catégorie existe déjà
    $categoryAccess = CategoryAccess::where('category_id', $CategoryID)
        ->where('user_id', $userID)
        ->first();

    if (!$categoryAccess) {
        // Créer un nouvel accès catégorie
        CategoryAccess::create([
            'user_id' => $userID,
            'category_id' => $CategoryID,
            'access_level' => $accessLevel,
            'active' => 1
        ]);
    } else {
        if ($categoryAccess->access_level < $accessLevel) {
            $categoryAccess->access_level = $accessLevel;
            $categoryAccess->save();
        }
    }

    ModuleAccess::where('module_id', $moduleID)
        ->where('user_id', $userID)
        ->delete();

    ModuleAccess::create([
        'user_id' => $userID,
        'module_id' => $moduleID,
        'access_level' => $accessLevel,
        'active'=>1
    ]);
}
}
