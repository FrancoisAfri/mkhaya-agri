<?php

namespace App\Shared\Attributes;

use Attribute;

/**
 * Marks a model property as Personal Health / Protected Information (PHI/PII)
 * under POPIA (Act 4 of 2013).
 *
 * Usage:
 *   #[Phi(justification: 'Required for SARS payroll submissions')]
 *   protected $id_number;
 *
 * The HasPhiAudit trait reads this attribute via reflection to build the
 * per-model PHI field list automatically.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Phi
{
    public function __construct(
        public readonly string $justification = '',
        public readonly bool   $encrypted     = true,
    ) {}
}
