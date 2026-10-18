<?php

namespace App\Modules\Communication\ContentManagement\Models;

use App\Modules\Communication\ContentManagement\Models\AssetsFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Illuminate\Filesystem\FilesystemAdapter;


class CeoMessage extends Model
{
    use SoftDeletes, LogsActivity;

    protected $fillable = [
        'assets_file_id',
        'message',
    ];
    protected $appends = ['image_url', 'video_url'];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function assetsFile()
    {
        return $this->belongsTo(AssetsFile::class, 'assets_file_id');
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVITY LOG
    |--------------------------------------------------------------------------
    */

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('CEO Message')
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

        // Extrait un aperçu court du message pour identifier le log sans l'ouvrir
        $preview = $this->message
            ? '"' . \Illuminate\Support\Str::limit(strip_tags($this->message), 60) . '"'
            : 'an unnamed message';

        $hasMedia = $this->assets_file_id ? ' (with attached media)' : '';

        return match ($eventName) {
            'created' => "{$actor} published a new CEO message — {$preview}{$hasMedia}.",

            'updated' => (function () use ($actor, $preview) {
                $changes = collect($this->getDirty())
                    ->except(['updated_at'])
                    ->keys()
                    ->map(fn($field) => match ($field) {
                        'message'        => 'the message content',
                        'assets_file_id' => 'the attached media',
                        default          => $field,
                    })
                    ->join(', ', ' and ');

                $summary = $changes
                    ? "Updated {$changes} on"
                    : 'Made changes to';

                return "{$actor} — {$summary} the CEO message {$preview}.";
            })(),

            'deleted' => "{$actor} removed the CEO message {$preview} from the platform.",

            default   => "{$actor} triggered a \"{$eventName}\" event on the CEO message {$preview}.",
        };
    }

    // Accesseur pour l'image URL (base64)
    public function getImageUrlAttribute()
    {
        if (!$this->assetsFile || !$this->assetsFile->image) {
            return null;
        }

        $imageData = $this->assetsFile->image;

        if (empty($imageData) || !isset($imageData[0]['data'])) {
            return null;
        }

        $image = $imageData[0];

        return 'data:' . ($image['mime'] ?? 'image/jpeg') . ';base64,' . $image['data'];
    }

    // Accesseur pour la vidéo URL

    public function getVideoUrlAttribute()
    {
        if (!$this->assetsFile || !$this->assetsFile->video) {
            return null;
        }

        $video = $this->assetsFile->video;

        if (!isset($video['path'])) {
            return null;
        }

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('public');

        return $disk->url($video['path']);
    }
}
