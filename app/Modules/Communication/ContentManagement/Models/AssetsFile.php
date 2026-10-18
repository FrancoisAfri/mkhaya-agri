<?php

namespace App\Modules\Communication\ContentManagement\Models;

use App\Modules\Communication\ContentManagement\Models\CeoMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AssetsFile extends Model
{
    use SoftDeletes, LogsActivity;

    protected $fillable = [
        'title',
        'image',
        'video',
        'document',
    ];

 protected $casts = [
    'image'    => 'array',
    'video'    => 'array',
    'document' => 'array',
];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function ceoMessages()
    {
        return $this->hasMany(CeoMessage::class, 'assets_file_id');
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Retourne la liste des types de fichiers présents dans cet asset.
     * Ex: "image, video" ou "document" ou "image, document"
     */
    protected function getFileTypeSummary(): string
    {
        $types = [];

        if (!empty($this->image))    $types[] = 'image';
        if (!empty($this->video))    $types[] = 'video';
        if (!empty($this->document)) $types[] = 'document';

        return count($types) > 0
            ? implode(', ', $types)
            : 'no files';
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVITY LOG
    |--------------------------------------------------------------------------
    */

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Assets File')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->setDescriptionForEvent(
                fn(string $eventName) => $this->getDescriptionForEvent($eventName)
            );
    }

    protected function getDescriptionForEvent(string $eventName): string
    {
        $actor   = Auth::user()?->name ?? 'System';
        $title   = $this->title ?? 'Untitled asset';
        $types   = $this->getFileTypeSummary();

        return match ($eventName) {
            'created' => "{$actor} uploaded a new asset file \"{$title}\" containing: {$types}.",

            'updated' => (function () use ($actor, $title) {
                $changes = collect($this->getDirty())
                    ->except(['updated_at'])
                    ->keys()
                    ->map(fn($field) => match ($field) {
                        'title'    => 'the asset name',
                        'image'    => 'the image files',
                        'video'    => 'the video file',
                        'document' => 'the document files',
                        default    => $field,
                    })
                    ->join(', ', ' and ');

                $summary = $changes
                    ? "Updated {$changes} on"
                    : 'Made changes to';

                return "{$actor} — {$summary} the asset file \"{$title}\".";
            })(),

            'deleted' => "{$actor} deleted the asset file \"{$title}\" and all its associated files from the platform.",

            default   => "{$actor} triggered a \"{$eventName}\" event on the asset file \"{$title}\".",
        };
    }

}
