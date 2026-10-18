<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\ViewServiceProvider::class,
    App\Providers\RepositoryServiceProvider::class,
    App\Modules\Operations\Sheq\Providers\SheqServiceProvider::class,
    App\Modules\Operations\WarehouseManagement\Providers\WarehouseServiceProvider::class,
    App\Modules\Workflow\Providers\WorkflowServiceProvider::class,
];
