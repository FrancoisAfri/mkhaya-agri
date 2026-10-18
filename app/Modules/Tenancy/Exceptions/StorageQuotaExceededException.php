<?php

namespace App\Modules\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when a company has used its entire database-storage allowance and a new
 * upload would exceed it. The tenant must free space or buy/request more storage.
 */
class StorageQuotaExceededException extends RuntimeException
{
    public function __construct(string $message = 'Your storage space is full. Buy or request more storage to upload more files.')
    {
        parent::__construct($message);
    }
}
