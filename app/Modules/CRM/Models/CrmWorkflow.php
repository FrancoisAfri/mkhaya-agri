<?php

namespace App\Modules\CRM\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CrmWorkflow extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'crm_workflows';

    protected $fillable = [
        'uuid', 'name', 'description', 'trigger', 'trigger_config', 'match_type',
        'conditions', 'actions', 'steps', 'graph', 'is_active', 'run_order', 'last_run_at', 'run_count', 'created_by',
    ];

    protected $casts = [
        'trigger_config' => 'array',
        'conditions'     => 'array',
        'actions'        => 'array',
        'steps'          => 'array',
        'graph'          => 'array',
        'is_active'      => 'boolean',
        'run_order'      => 'integer',
        'run_count'      => 'integer',
        'last_run_at'    => 'datetime',
    ];

    const TRIGGERS = [
        'lead.created'        => 'When a lead is created',
        'lead.updated'        => 'When a lead is updated',
        'deal.created'        => 'When a deal is created',
        'deal.updated'        => 'When a deal is updated',
        'deal.stage_changed'  => 'When a deal changes stage',
        'deal.inactive'       => 'When a deal goes inactive',
        'deal.sla_breached'   => 'When a stage SLA is breached',
        'contact.updated'     => 'When a contact is updated',
        'account.updated'     => 'When an account is updated',
        'activity.completed'  => 'When an activity is completed',
        'date_reached'        => 'On a date (relative to a date field)',
        'email.opened'        => 'When a campaign email is opened',
        'email.clicked'       => 'When a campaign email link is clicked',
        'email.replied'       => 'When a sequence reply is received',
    ];

    const ACTION_TYPES = [
        'create_task'   => 'Create a task',
        'add_note'      => 'Add a note',
        'update_field'  => 'Update a field',
        'assign_owner'  => 'Assign owner',
        'escalate'      => 'Escalate (reassign + priority task)',
        'send_email'    => 'Send an email',
        'send_whatsapp' => 'Send a WhatsApp message',
        'notify_user'   => 'Notify a user (in-app)',
        'ai_action'     => 'Run an AI action',
        'create_deal'   => 'Create a deal',
        'http_request'  => 'Send an HTTP request',
    ];

    /** Entities + date fields a date_reached trigger can watch. */
    const DATE_FIELDS = [
        'deal' => ['expected_close_date', 'closed_at'],
        'lead' => ['created_at'],
    ];

    /** Multi-step flow step types. */
    const STEP_TYPES = [
        'action'    => 'Action',
        'wait'      => 'Wait',
        'condition' => 'Condition (gate)',
        'branch'    => 'Branch (if/else)',
    ];

    /** Which entity each trigger fires on — used to align condition fields. */
    const TRIGGER_ENTITY = [
        'lead.created'       => 'lead',
        'lead.updated'       => 'lead',
        'deal.created'       => 'deal',
        'deal.updated'       => 'deal',
        'deal.stage_changed' => 'deal',
        'deal.inactive'      => 'deal',
        'deal.sla_breached'  => 'deal',
        'contact.updated'    => 'contact',
        'account.updated'    => 'account',
        'activity.completed' => 'activity',
        'email.opened'       => 'contact',
        'email.clicked'      => 'contact',
        'email.replied'      => 'contact',
        // date_reached resolves its entity from trigger_config.entity (UI-handled).
    ];

    /** Fields available to conditions per entity (incl. a few related fields). */
    const CONDITION_FIELDS = [
        'lead'     => ['first_name', 'last_name', 'company', 'email', 'phone', 'source', 'status', 'score', 'owner_id', 'notes'],
        'deal'     => ['title', 'amount', 'currency', 'status', 'probability', 'stage_id', 'source', 'risk_level', 'expected_close_date', 'owner_id', 'account_id', 'contact_id', 'account.industry', 'account.name', 'contact.email'],
        'contact'  => ['first_name', 'last_name', 'email', 'phone', 'mobile', 'job_title', 'account_id', 'owner_id', 'account.industry', 'account.name'],
        'account'  => ['name', 'industry', 'website', 'email', 'phone', 'city', 'country', 'annual_revenue', 'employee_count', 'owner_id', 'health_status', 'churn_risk'],
        'activity' => ['type', 'status', 'subject', 'owner_id', 'related_type'],
    ];

    /** Prebuilt flow templates surfaced in the builder's "Start from template". */
    const TEMPLATES = [
        // ── Leads ──────────────────────────────────────────────────────────────
        [
            'key' => 'new_lead_nurture', 'name' => 'New-lead nurture', 'group' => 'Leads',
            'description' => 'Welcome a new lead, wait, then notify the owner if still untouched.',
            'trigger' => 'lead.created', 'trigger_config' => [],
            'steps' => [
                ['type' => 'action', 'action' => 'send_email', 'subject' => 'Thanks for getting in touch, {{first_name}}', 'body' => "Hi {{first_name}},\n\nThanks for reaching out to {{sender_company}} — we'll be in contact shortly.\n\n{{rep_name}}"],
                ['type' => 'wait', 'amount' => 2, 'unit' => 'days'],
                ['type' => 'condition', 'match_type' => 'all', 'conditions' => [['field' => 'status', 'operator' => '=', 'value' => 'new']]],
                ['type' => 'action', 'action' => 'notify_user', 'assign_to' => 'owner', 'title' => 'Follow up with {{full_name}}', 'body' => 'No progress in 2 days.'],
            ],
        ],
        [
            'key' => 'lead_auto_score', 'name' => 'Auto-score new leads', 'group' => 'Leads',
            'description' => 'AI-score every new lead so reps can prioritise instantly.',
            'trigger' => 'lead.created', 'trigger_config' => [],
            'steps' => [['type' => 'action', 'action' => 'ai_action', 'ai' => 'score_lead']],
        ],
        [
            'key' => 'hot_lead_alert', 'name' => 'Hot-lead alert', 'group' => 'Leads',
            'description' => 'Score a new lead and, if it scores 80+, alert the owner immediately.',
            'trigger' => 'lead.created', 'trigger_config' => [],
            'steps' => [
                ['type' => 'action', 'action' => 'ai_action', 'ai' => 'score_lead'],
                ['type' => 'branch', 'match_type' => 'all', 'conditions' => [['field' => 'score', 'operator' => '>=', 'value' => '80']],
                    'then' => [['type' => 'action', 'action' => 'notify_user', 'assign_to' => 'owner', 'title' => '🔥 Hot lead: {{full_name}}', 'body' => 'Scored 80+. Reach out today.']],
                    'else' => []],
            ],
        ],
        [
            'key' => 'lead_qualified_to_deal', 'name' => 'Qualified lead → deal', 'group' => 'Leads',
            'description' => 'When a lead becomes qualified, create a deal automatically.',
            'trigger' => 'lead.updated', 'trigger_config' => ['field' => 'status'],
            'steps' => [
                ['type' => 'branch', 'match_type' => 'all', 'conditions' => [['field' => 'status', 'operator' => '=', 'value' => 'qualified']],
                    'then' => [['type' => 'action', 'action' => 'create_deal', 'title' => 'Deal — {{company}}']],
                    'else' => []],
            ],
        ],

        // ── Deals ──────────────────────────────────────────────────────────────
        [
            'key' => 'deal_close_reminder', 'name' => 'Deal stage nudge', 'group' => 'Deals',
            'description' => 'When a deal changes stage, wait, then nudge the owner if still open.',
            'trigger' => 'deal.stage_changed', 'trigger_config' => [],
            'steps' => [
                ['type' => 'wait', 'amount' => 3, 'unit' => 'days'],
                ['type' => 'condition', 'match_type' => 'all', 'conditions' => [['field' => 'status', 'operator' => '=', 'value' => 'open']]],
                ['type' => 'action', 'action' => 'notify_user', 'assign_to' => 'owner', 'title' => 'Keep {{full_name}} moving', 'body' => 'Same stage for 3 days.'],
            ],
        ],
        [
            'key' => 'high_value_deal_alert', 'name' => 'High-value deal alert', 'group' => 'Deals',
            'description' => 'When a big deal is created, alert the owner and assess its risk.',
            'trigger' => 'deal.created', 'trigger_config' => [],
            'steps' => [
                ['type' => 'branch', 'match_type' => 'all', 'conditions' => [['field' => 'amount', 'operator' => '>=', 'value' => '100000']],
                    'then' => [
                        ['type' => 'action', 'action' => 'notify_user', 'assign_to' => 'owner', 'title' => '💰 High-value deal: {{full_name}}', 'body' => 'Worth prioritising.'],
                        ['type' => 'action', 'action' => 'ai_action', 'ai' => 'assess_deal_risk'],
                    ],
                    'else' => []],
            ],
        ],
        [
            'key' => 'deal_won_onboarding', 'name' => 'Deal won → onboarding', 'group' => 'Deals',
            'description' => 'When a deal is won, thank the customer and create an onboarding task.',
            'trigger' => 'deal.updated', 'trigger_config' => ['field' => 'status'],
            'steps' => [
                ['type' => 'branch', 'match_type' => 'all', 'conditions' => [['field' => 'status', 'operator' => '=', 'value' => 'won']],
                    'then' => [
                        ['type' => 'action', 'action' => 'send_email', 'subject' => 'Welcome aboard, {{first_name}}!', 'body' => "Hi {{first_name}},\n\nThrilled to have you with {{sender_company}}. Your onboarding starts now.\n\n{{rep_name}}"],
                        ['type' => 'action', 'action' => 'create_task', 'subject' => 'Onboard {{full_name}}', 'due_in_days' => 2, 'assign_to' => 'owner'],
                    ],
                    'else' => []],
            ],
        ],
        [
            'key' => 'deal_lost_review', 'name' => 'Deal lost → review', 'group' => 'Deals',
            'description' => 'When a deal is lost, notify the owner to capture learnings.',
            'trigger' => 'deal.updated', 'trigger_config' => ['field' => 'status'],
            'steps' => [
                ['type' => 'branch', 'match_type' => 'all', 'conditions' => [['field' => 'status', 'operator' => '=', 'value' => 'lost']],
                    'then' => [['type' => 'action', 'action' => 'notify_user', 'assign_to' => 'owner', 'title' => 'Deal lost: {{full_name}}', 'body' => 'Log the loss reason while it’s fresh.']],
                    'else' => []],
            ],
        ],
        [
            'key' => 'stale_deal_nudge', 'name' => 'Stale deal nudge', 'group' => 'Deals',
            'description' => 'When a deal goes inactive, alert the owner and AI-assess the risk.',
            'trigger' => 'deal.inactive', 'trigger_config' => [],
            'steps' => [
                ['type' => 'action', 'action' => 'notify_user', 'assign_to' => 'owner', 'title' => 'Deal stalled: {{full_name}}', 'body' => 'No activity recently.'],
                ['type' => 'action', 'action' => 'ai_action', 'ai' => 'assess_deal_risk'],
            ],
        ],
        [
            'key' => 'sla_escalation', 'name' => 'Stage-SLA escalation', 'group' => 'Deals',
            'description' => 'When a deal breaches its stage SLA, escalate it.',
            'trigger' => 'deal.sla_breached', 'trigger_config' => [],
            'steps' => [['type' => 'action', 'action' => 'escalate', 'subject' => 'SLA breached: {{full_name}}']],
        ],
        [
            'key' => 'renewal_play', 'name' => 'Renewal play', 'group' => 'Deals',
            'description' => '7 days before a deal’s close date, remind the owner and draft an AI follow-up.',
            'trigger' => 'date_reached', 'trigger_config' => ['entity' => 'deal', 'field' => 'expected_close_date', 'days_before' => 7],
            'steps' => [
                ['type' => 'action', 'action' => 'notify_user', 'assign_to' => 'owner', 'title' => 'Renewal due in 7 days', 'body' => 'Reach out about {{full_name}}.'],
                ['type' => 'action', 'action' => 'ai_action', 'ai' => 'draft_follow_up'],
            ],
        ],

        // ── Engagement ─────────────────────────────────────────────────────────
        [
            'key' => 'email_opened_followup', 'name' => 'Email opened → follow up', 'group' => 'Engagement',
            'description' => 'When a contact opens a campaign email, alert the owner and create a task.',
            'trigger' => 'email.opened', 'trigger_config' => [],
            'steps' => [
                ['type' => 'action', 'action' => 'notify_user', 'assign_to' => 'owner', 'title' => '{{full_name}} opened your email', 'body' => 'Good moment to reach out.'],
                ['type' => 'action', 'action' => 'create_task', 'subject' => 'Follow up — {{full_name}} opened email', 'due_in_days' => 1, 'assign_to' => 'owner'],
            ],
        ],
        [
            'key' => 'email_clicked_hot', 'name' => 'Email clicked → high intent', 'group' => 'Engagement',
            'description' => 'When a contact clicks a link, flag high intent and draft an AI follow-up.',
            'trigger' => 'email.clicked', 'trigger_config' => [],
            'steps' => [
                ['type' => 'action', 'action' => 'notify_user', 'assign_to' => 'owner', 'title' => '🔥 {{full_name}} clicked — high intent', 'body' => 'Strike while it’s hot.'],
                ['type' => 'action', 'action' => 'ai_action', 'ai' => 'draft_follow_up'],
            ],
        ],
        [
            'key' => 'email_replied_assign', 'name' => 'Email replied → notify', 'group' => 'Engagement',
            'description' => 'When a sequence reply comes in, notify the owner to respond.',
            'trigger' => 'email.replied', 'trigger_config' => [],
            'steps' => [['type' => 'action', 'action' => 'notify_user', 'assign_to' => 'owner', 'title' => 'Reply from {{full_name}}', 'body' => 'Respond promptly.']],
        ],

        // ── Accounts ───────────────────────────────────────────────────────────
        [
            'key' => 'account_churn_watch', 'name' => 'Churn-risk watch', 'group' => 'Accounts',
            'description' => 'When an account’s health flips to high churn risk, alert the owner.',
            'trigger' => 'account.updated', 'trigger_config' => ['field' => 'churn_risk'],
            'steps' => [
                ['type' => 'branch', 'match_type' => 'all', 'conditions' => [['field' => 'churn_risk', 'operator' => '=', 'value' => 'high']],
                    'then' => [['type' => 'action', 'action' => 'notify_user', 'assign_to' => 'owner', 'title' => '⚠️ Churn risk: {{full_name}}', 'body' => 'Schedule a save call.']],
                    'else' => []],
            ],
        ],
    ];

    /** AI actions a workflow can run. */
    const AI_ACTIONS = [
        'score_lead'       => 'Score the lead (0–100)',
        'assess_deal_risk' => 'Assess deal risk',
        'draft_follow_up'  => 'Draft a follow-up (saved as a note)',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(CrmWorkflowRun::class, 'workflow_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('CRM')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
