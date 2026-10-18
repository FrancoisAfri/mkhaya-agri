<?php

namespace App\Modules\Operations\ProjectManagement\Contracts\Providers;

/** Where an approved project expense goes. Local: kept in PM. Integrated: Accounting. */
interface ExpenseSink
{
    /**
     * @param  array<string,mixed>  $expense
     * @return string  the receiving system's reference for what it created
     */
    public function post(array $expense): string;

    /** False when expenses simply accumulate in PM for later export. */
    public function postsToLedger(): bool;
}
