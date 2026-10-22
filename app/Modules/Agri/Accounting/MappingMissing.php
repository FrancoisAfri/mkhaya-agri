<?php

namespace App\Modules\Agri\Accounting;

use RuntimeException;

/** A document could not be pushed because one of our accounts, tax codes or contacts is not mapped yet. */
class MappingMissing extends RuntimeException {}
