<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One step a project's work moves through — a board column, named by the people running the job.
 *
 * A step is not a free-form label. Its `category` is one of the five built-in states, and that is
 * what the evidence ledger, progress derivation, open-work counts, claims and the scheduler read.
 * "Awaiting materials" is a step whose category is `blocked`; "With the engineer" is one whose
 * category is `in_progress`. The board shows the step, the rest of the module sees the meaning —
 * which is what lets somebody name their own columns without silently turning off completion
 * events and progress rollup.
 */
class TaskStatus extends Model
{
    use BelongsToCompany, HasPublicUuid, SoftDeletes;

    protected $table = 'pm_task_statuses';

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'key', 'label', 'category',
        'position', 'is_default', 'is_active', 'colour', 'created_by',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
        'position'   => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    /**
     * A stable key from a label somebody typed.
     *
     * Keys are what tasks reference, so this is only ever used at creation — renaming a step keeps
     * its key, or every task sitting in that column would be stranded.
     */
    public static function keyFor(string $label): string
    {
        $key = Str::slug($label, '_');

        return $key !== '' ? Str::limit($key, 40, '') : 'step_' . Str::lower(Str::random(6));
    }

    /**
     * The five built-in steps, for a project that has not defined its own.
     *
     * Returned in the shape the board consumes rather than as models, because they are not rows:
     * materialising them per project would put five identical columns in the database for every
     * job anybody ever created, purely so the default could look like a choice.
     *
     * @return list<array{key: string, label: string, category: string, is_default: bool, colour: ?string}>
     */
    public static function builtIn(): array
    {
        return array_map(fn (string $status) => [
            'key'        => $status,
            // No label: a built-in column is named by the module, not by this project, and the
            // browser already carries `task_status.*` in twelve languages. Translating it here
            // would bake one locale into the payload and give the queue a locale it never set.
            'label'      => null,
            'category'   => $status,
            'is_default' => $status === 'not_started',
            'colour'     => null,
        ], Task::BOARD_STATUSES);
    }
}
