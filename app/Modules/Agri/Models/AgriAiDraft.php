<?php

namespace App\Modules\Agri\Models;

/** Something the assistant proposes; nothing happens until a person with the right permission accepts it (§10.3). */
class AgriAiDraft extends AgriModel
{
    /** What the assistant may prepare. */
    public const KINDS = ['work_order', 'requisition', 'inspection', 'report'];

    /** What it may never do, however asked (§10.3). */
    public const NEVER = ['payment', 'pesticide_application', 'payroll_change', 'journal', 'stock_disposal', 'machine_activation'];

    protected $table = 'agri_ai_drafts';

    protected $casts = ['payload' => 'array', 'decided_at' => \App\Modules\Agri\Casts\FarmDateTime::class];
}
