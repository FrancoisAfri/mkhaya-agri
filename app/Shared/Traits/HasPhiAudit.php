<?php

namespace App\Shared\Traits;

use App\Core\Compliance\Models\PhiAccessLog;
use App\Shared\Attributes\Phi;
use Illuminate\Support\Facades\Auth;
use ReflectionClass;

/**
 * Gives a model the ability to:
 *  1. Declare PHI fields via the #[Phi] attribute or a $phiFields array.
 *  2. Emit a PhiAccessLog record whenever PHI is intentionally surfaced.
 *
 * Usage in a controller:
 *   $person->logPhiRead('id_number', request());
 *
 * To auto-detect via PHP attributes, annotate model properties with #[Phi].
 * Alternatively override $phiFields = ['id_number', 'passport_number'] on the model.
 */
trait HasPhiAudit
{
    public function logPhiRead(string $field, ?\Illuminate\Http\Request $request = null): void
    {
        PhiAccessLog::create([
            'subject_type'  => static::class,
            'subject_id'    => $this->getKey(),
            'field'         => $field,
            'accessed_by'   => Auth::id(),
            'ip_address'    => $request?->ip(),
            'user_agent'    => $request?->userAgent(),
        ]);
    }

    public function logPhiReads(array $fields, ?\Illuminate\Http\Request $request = null): void
    {
        foreach ($fields as $field) {
            $this->logPhiRead($field, $request);
        }
    }

    /**
     * Returns the list of PHI field names for this model.
     * Reads from $phiFields if declared, otherwise discovers via #[Phi] attributes.
     */
    public function getPhiFieldNames(): array
    {
        if (property_exists($this, 'phiFields')) {
            return $this->phiFields;
        }

        $fields = [];
        $rc = new ReflectionClass($this);

        foreach ($rc->getProperties() as $property) {
            if ($property->getAttributes(Phi::class)) {
                $fields[] = $property->getName();
            }
        }

        return $fields;
    }
}
