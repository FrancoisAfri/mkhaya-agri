<?php

namespace App\Modules\Communication\ContentManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Communication\ContentManagement\Models\EmployeeSpotlight;
use App\Modules\Communication\ContentManagement\Notifications\SpotlightCreatedNotification;
use Illuminate\Support\Facades\Auth;


class EmployeeSpotlightService
{
    public function __construct(
        protected AssetFileService $assetFileService
    ) {}

    /*
    |--------------------------------------------------------------------------
    | STORE
    |--------------------------------------------------------------------------
    */


    public function store(array $data)
    {
        $asset = null;

        if (!empty($data['image'])) {
            $asset = $this->assetFileService->saveImage($data['image']);
        }

        $spotlight = EmployeeSpotlight::create([
            'user_id'        => Auth::id(),
            'employee_id'        =>  $data['employee_id'],
            'asset_file_id'  => $asset?->id,

            'title'          => $data['title'],
            'description'    => $data['description'] ?? null,

            'type'           => $data['type'],
            'month' => $data['month'],

            'is_active'      =>  true,
            'meta_data'      => $data['meta_data'] ?? null,
        ]);

        // Load author relationship
        $spotlight = $spotlight->load('author');

        // Notify the employee being spotlighted
        $employee = User::findOrFail($data['employee_id']);
        $employee->notifyNow(new SpotlightCreatedNotification($spotlight));

        // Also notify all other active employees in the company
        $otherEmployees = User::where('id', '!=', $employee->id)
            ->where('id', '!=', Auth::id())
            ->whereHas('person', function ($query) {
                $query->where('status', 1); // active employees
            })->get();

        foreach ($otherEmployees as $otherEmployee) {
            $otherEmployee->notifyNow(new SpotlightCreatedNotification($spotlight));
        }

        return $spotlight;
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    public function update(array $data, int $id)
    {
        $spotlight = EmployeeSpotlight::findOrFail($id);
        $asset = $spotlight->asset;

        if (!empty($data['image'])) {
            $asset = $this->assetFileService->saveImage($data['image'], $asset);
        }

        $spotlight->update([
            'user_id'        => $data['user_id'] ?? $spotlight->user_id,
            'asset_file_id'  => $asset?->id,
            'employee_id'    => $data['employee_id'] ?? $spotlight->employee_id,

            'title'          => $data['title'] ?? $spotlight->title,
            'description'    => $data['description'] ?? $spotlight->description,

            'type'           => $data['type'] ?? $spotlight->type,
            'icon'       => $data['icon'] ?? $spotlight->icon,
            'department'     => $data['department'] ?? $spotlight->department,
            'month'     => $data['month'] ?? $spotlight->month,

            'is_active'      => $data['is_active'] ?? $spotlight->is_active,
        ]);

        return $spotlight;
    }
}
