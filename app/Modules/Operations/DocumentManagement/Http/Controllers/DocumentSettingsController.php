<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\DocumentManagement\Models\DocumentCategory;
use App\Modules\Operations\DocumentManagement\Models\DocumentSetting;
use App\Modules\Operations\DocumentManagement\Services\FileTypeGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** What this organisation accepts: size, count, types, and how documents get named. */
class DocumentSettingsController extends Controller
{
    public function __construct(private readonly FileTypeGuard $guard)
    {
    }

    public function index(): Response
    {
        $this->authorize('manageSettings', \App\Modules\Operations\DocumentManagement\Models\Document::class);

        $settings = DocumentSetting::current();

        return Inertia::render('Modules/DocumentManagement/Pages/Settings', [
            'settings' => [
                'max_file_size_mb'     => $settings->max_file_size_mb,
                'max_files_per_upload' => $settings->max_files_per_upload,
                'allowed_extensions'   => $this->guard->allowedFor($settings),
                'blocked_extensions'   => $settings->blocked_extensions ?? [],
                'naming_pattern'       => $settings->naming_pattern,
                'auto_name'            => (bool) $settings->auto_name,
                'require_description'  => (bool) $settings->require_description,

                // Expiry chasing, which is configurable because the right lead time depends on
                // what the renewal costs — a B-BBEE re-verification takes months, a licence an
                // afternoon.
                'expiry_reminders_enabled' => (bool) ($settings->expiry_reminders_enabled ?? true),
                'sharing_enabled' => (bool) ($settings->sharing_enabled ?? false),
                'ai_enabled' => (bool) ($settings->ai_enabled ?? false),
                'ai_include_confidential' => (bool) ($settings->ai_include_confidential ?? false),
                'watermark_shares_by_default' => (bool) ($settings->watermark_shares_by_default ?? false),
                'watermark_previews' => (bool) ($settings->watermark_previews ?? false),
                'watermark_confidential_previews' => (bool) ($settings->watermark_confidential_previews ?? true),
                'download_request_days' => (int) ($settings->download_request_days ?? 7),
                'lock_hours' => (int) ($settings->lock_hours ?? 24),
                'auto_delete_enabled' => (bool) ($settings->auto_delete_enabled ?? false),
                'disposal_grace_days' => (int) ($settings->disposal_grace_days ?? 7),
                'share_max_days' => (int) ($settings->share_max_days ?? 30),
                'expiry_reminder_days' => $settings->expiry_reminder_days
                    ?: \App\Modules\Operations\DocumentManagement\Services\ExpiryService::DEFAULT_THRESHOLDS,
                'expiry_notify_roles' => $settings->expiry_notify_roles ?? [],
            ],
            'roles' => \Spatie\Permission\Models\Role::orderBy('name')->pluck('name')->all(),
            'defaults'      => FileTypeGuard::DEFAULT_ALLOWED,
            // Shown so an administrator can see the list is deliberate rather than an oversight.
            'never_allowed' => $this->guard->neverAllowed(),

            /*
             * Categories are managed here rather than on their own screen: an administrator setting
             * up the repository does both in one sitting, and a separate page for eighteen rows is
             * a page nobody finds.
             *
             * Every category is listed, including the switched-off ones — a category you cannot see
             * is one you cannot switch back on.
             */
            // Named here rather than hard-coded in the page: a basis the editor offers but the
            // service does not accept is a schedule that silently never fires.
            'retention_bases' => collect(\App\Modules\Operations\DocumentManagement\Services\RetentionService::BASES)
                ->map(fn (string $basis) => [
                    'value' => $basis,
                    'label' => __('document_management.retention_basis_' . $basis),
                ])->all(),
            'categories' => DocumentCategory::orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (DocumentCategory $c) => [
                    'uuid' => $c->uuid,
                    'name' => $c->name,
                    'description' => $c->description,
                    'colour' => $c->colour,
                    'icon' => $c->icon,
                    'is_system' => (bool) $c->is_system,
                    'is_active' => (bool) $c->is_active,
                    'sort_order' => (int) $c->sort_order,
                    'retention_years' => $c->retention_years,
                    'retention_basis' => $c->retention_basis,
                    'retention_note' => $c->retention_note,
                    'archive_after_years' => $c->archive_after_years,
                    'auto_archive' => (bool) $c->auto_archive,
                    'auto_delete' => (bool) $c->auto_delete,
                    'documents' => $c->documents()->count(),
                    // Said by the server, so the button can explain itself before it is pressed
                    // rather than after the request comes back refused.
                    'can_delete' => $c->isDeletable() && $c->documents()->count() === 0,

                    // The rules that belong to this kind of document rather than to the repository.
                    'number_pattern' => $c->number_pattern,
                    'number_next' => (int) ($c->number_next ?? 1),
                    'workflow_required' => (bool) $c->workflow_required,
                    'default_workflow' => $c->default_workflow_id
                        ? \App\Modules\Operations\DocumentManagement\Models\DocumentApprovalWorkflow::where('id', $c->default_workflow_id)->value('uuid')
                        : null,
                    'fields' => \App\Modules\Operations\DocumentManagement\Models\DocumentCategoryField::query()
                        ->where('category_id', $c->id)
                        ->orderBy('sort_order')
                        ->get()
                        ->map(fn ($f) => [
                            'key' => $f->key,
                            'label' => $f->label,
                            'type' => $f->type,
                            'options' => $f->options ?? [],
                            'hint' => $f->hint,
                            'required' => (bool) $f->is_required,
                        ])->values(),
                ])->all(),

            'workflows' => \App\Modules\Operations\DocumentManagement\Models\DocumentApprovalWorkflow::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn ($w) => ['uuid' => $w->uuid, 'name' => $w->name])
                ->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('manageSettings', \App\Modules\Operations\DocumentManagement\Models\Document::class);

        $data = $request->validate([
            'max_file_size_mb'     => ['required', 'integer', 'min:1', 'max:2048'],
            'max_files_per_upload' => ['required', 'integer', 'min:1', 'max:200'],
            'allowed_extensions'   => ['nullable', 'array'],
            'allowed_extensions.*' => ['string', 'max:20'],
            'blocked_extensions'   => ['nullable', 'array'],
            'blocked_extensions.*' => ['string', 'max:20'],
            'naming_pattern'       => ['nullable', 'string', 'max:150'],
            'auto_name'            => ['nullable', 'boolean'],
            'require_description'  => ['nullable', 'boolean'],
            'expiry_reminders_enabled' => ['nullable', 'boolean'],
            'sharing_enabled' => ['nullable', 'boolean'],
            'ai_enabled' => ['nullable', 'boolean'],
            'ai_include_confidential' => ['nullable', 'boolean'],
            'watermark_shares_by_default' => ['nullable', 'boolean'],
            'watermark_previews' => ['nullable', 'boolean'],
            'watermark_confidential_previews' => ['nullable', 'boolean'],
            'download_request_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'lock_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'auto_delete_enabled' => ['nullable', 'boolean'],
            'disposal_grace_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'share_max_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'expiry_reminder_days'   => ['nullable', 'array'],
            'expiry_reminder_days.*' => ['integer', 'min:0', 'max:365'],
            'expiry_notify_roles'    => ['nullable', 'array'],
            'expiry_notify_roles.*'  => ['string', 'max:150'],
        ]);

        $clean = fn (?array $list) => array_values(array_unique(array_filter(array_map(
            fn ($e) => Str::lower(ltrim(trim((string) $e), '.')),
            $list ?? [],
        ))));

        $allowed = $clean($data['allowed_extensions'] ?? []);
        $refused = array_intersect($allowed, $this->guard->neverAllowed());

        // Said plainly rather than dropped silently: an administrator who adds .html and finds it
        // gone without explanation will assume the screen is broken.
        $allowed = array_values(array_diff($allowed, $this->guard->neverAllowed()));

        DocumentSetting::updateOrCreate([], [
            'max_file_size_mb'     => $data['max_file_size_mb'],
            'max_files_per_upload' => $data['max_files_per_upload'],
            'allowed_extensions'   => $allowed ?: null,
            'blocked_extensions'   => $clean($data['blocked_extensions'] ?? []) ?: null,
            'naming_pattern'       => $data['naming_pattern'] ?? null,
            'auto_name'            => $request->boolean('auto_name'),
            'require_description'  => $request->boolean('require_description'),

            'expiry_reminders_enabled' => $request->boolean('expiry_reminders_enabled'),
            'sharing_enabled' => $request->boolean('sharing_enabled'),
            'ai_enabled' => $request->boolean('ai_enabled'),
            // Meaningless on its own, and dangerous to leave set: turning the assistant off and on
            // again should not silently restore permission to send confidential documents.
            'ai_include_confidential' => $request->boolean('ai_enabled') && $request->boolean('ai_include_confidential'),
            'watermark_shares_by_default' => $request->boolean('watermark_shares_by_default'),
            'watermark_previews' => $request->boolean('watermark_previews'),
            'watermark_confidential_previews' => $request->boolean('watermark_confidential_previews'),
            'download_request_days' => (int) ($data['download_request_days'] ?? 7),
            'lock_hours' => (int) ($data['lock_hours'] ?? 24),
            'auto_delete_enabled' => $request->boolean('auto_delete_enabled'),
            'disposal_grace_days' => $data['disposal_grace_days'] ?? 7,
            'share_max_days' => $data['share_max_days'] ?? 30,
            // Sorted furthest-out first and deduplicated, so the list reads in the order somebody
            // would be warned and a repeated entry cannot double a notification.
            'expiry_reminder_days' => $this->thresholds($data['expiry_reminder_days'] ?? null),
            'expiry_notify_roles'  => array_values(array_unique($data['expiry_notify_roles'] ?? [])) ?: null,
        ]);

        return $refused === []
            ? back()->with('success', 'Settings saved.')
            : back()->with('warning', 'Settings saved. These types cannot be enabled and were left off: '
                . implode(', ', $refused) . ' — a browser or server can execute them.');
    }

    /**
     * The reminder thresholds, tidied.
     *
     * Empty means the defaults rather than "never warn": switching reminders off is what the toggle
     * is for, and an empty list is far more likely to be somebody clearing the field by accident
     * than a deliberate choice to be told nothing.
     *
     * @return list<int>|null
     */
    private function thresholds(?array $days): ?array
    {
        if ($days === null || $days === []) {
            return null;
        }

        $clean = array_values(array_unique(array_map('intval', $days)));
        $clean = array_values(array_filter($clean, fn (int $d) => $d >= 0 && $d <= 365));
        rsort($clean);

        return $clean ?: null;
    }
}
