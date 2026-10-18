<?php

namespace App\Core\DocumentType\Model;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SystemDocType extends Model
{
    use HasFactory, LogsActivity, BelongsToCompany;

    protected $table = 'system_doc_types';
    protected $fillable = ['name','description','is_active'];
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('System Document Type')
            ->logOnly(['name','is_active', 'description'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->setDescriptionForEvent(fn(string $eventName) => $this->getDescriptionForEvent($eventName));
    }
      protected function getDescriptionForEvent(string $eventName): string
    {
        $user = Auth::user();

        return match ($eventName) {
            'created' => "Document type '{$this->name}' was created by " . ($user?->name ?? 'System'),
            'updated' => "Document type  '{$this->name}' was updated by " . ($user?->name ?? 'System'),
            'deleted' => "Document type  '{$this->name}' was deleted by " . ($user?->name ?? 'System'),
            default => "Document type  '{$this->name}' event: {$eventName}",
        };
    }
}
