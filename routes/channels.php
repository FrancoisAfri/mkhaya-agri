<?php

use Illuminate\Support\Facades\Broadcast;

// Public channel — all authenticated users can subscribe.
// Upgrade to a private channel and add role checks if finer access is needed.
Broadcast::channel('attendance.live', fn ($user) => (bool) $user);
