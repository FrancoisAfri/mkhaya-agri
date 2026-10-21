<?php

namespace App\Modules\Agri\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A machine's document (manual, warranty, licence, inspection certificate…) is a Fleet document of
 * its asset: fleet_documents, the file kept in the database as Fleet keeps it (a JSON record with
 * the bytes base64-encoded). Farm access follows the machine — open it through its machine.
 */
class MachineDocument extends AgriModel
{
    use \App\Support\Eloquent\AliasesColumns;

    public const KINDS = ['manual', 'warranty', 'insurance', 'licence', 'inspection', 'invoice', 'photo', 'other'];

    protected $table = 'fleet_documents';

    protected $hidden = ['file_path'];

    protected $casts = ['expiry_date' => 'date'];

    protected function columnAliases(): array
    {
        return ['machine_id' => 'vehicle_id', 'kind' => 'document_type', 'expires_on' => 'expiry_date', 'uploaded_by' => 'created_by'];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(FarmMachine::class, 'vehicle_id');
    }

    /** The stored file: {original_name, mime, type, data (base64), size}. */
    private function file(): array
    {
        return json_decode((string) ($this->attributes['file_path'] ?? ''), true) ?: [];
    }

    private function putFile(array $changes): void
    {
        $this->attributes['file_path'] = json_encode(array_merge($this->file(), $changes));
    }

    public function getNameAttribute(): ?string { return $this->file()['original_name'] ?? $this->attributes['document_number'] ?? null; }
    public function setNameAttribute($v): void { $this->putFile(['original_name' => $v]); $this->attributes['document_number'] = mb_strimwidth((string) $v, 0, 100); }
    public function getMimeAttribute(): ?string { return $this->file()['mime'] ?? null; }
    public function setMimeAttribute($v): void { $this->putFile(['mime' => $v, 'type' => str_starts_with((string) $v, 'image/') ? 'image' : 'document']); }
    public function getSizeAttribute(): ?int { return isset($this->file()['size']) ? (int) $this->file()['size'] : null; }
    public function setSizeAttribute($v): void { $this->putFile(['size' => (int) $v]); }
    public function getContentAttribute(): ?string { return $this->file()['data'] ?? null; }
    public function setContentAttribute($v): void { $this->putFile(['data' => $v]); }

    protected static function booted(): void
    {
        // farm_id is the machine's; a Fleet document carries none of its own.
        static::saving(function (MachineDocument $d) {
            unset($d->attributes['farm_id']);
            $d->attributes['status'] ??= 'valid';
        });
    }
}
