<?php

use Illuminate\Support\Facades\Broadcast;

// Public channel — all authenticated users can subscribe.
// Upgrade to a private channel and add role checks if finer access is needed.
Broadcast::channel('attendance.live', fn ($user) => (bool) $user);

// The Command & Control Centre of one organisation: only its own people who may watch it.
Broadcast::channel('command-centre.{companyId}', fn ($user, int $companyId) => (int) $user->company_id === $companyId
    && ($user->can(\App\Modules\Operations\Manufacturing\Support\RoleCatalog::VIEW_COMMAND) || $user->can(\App\Modules\Operations\Manufacturing\Support\RoleCatalog::RESPOND_INCIDENTS)));
