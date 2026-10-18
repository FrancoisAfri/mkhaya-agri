<?php

namespace App\Modules\Operations\ProjectManagement\Contracts\Providers;

use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;

/** Purchase requisitions and orders raised for a project. Integrated: Procurement. */
interface PurchaseProvider
{
    /** @return list<ProvidedRecord> */
    public function forProject(string $projectId): array;

    public function isAvailable(): bool;

    /**
     * Can a purchase actually be started from a project on this workspace?
     *
     * Separate from `isAvailable()` because reading and writing are different privileges: a tenant
     * may have Procurement installed for reporting while purchases are raised elsewhere, and a
     * button that always 500s is worse than no button.
     */
    public function canRaise(): bool;

    /**
     * Ask to buy something for this project.
     *
     * PM does not invent a purchasing workflow of its own — it opens Procurement's front door and
     * lets that module's validation, budget checks and approval chain run. What comes back is the
     * request as Procurement now holds it, including whether the submission was blocked, so the
     * person who asked is told rather than left assuming it went through.
     *
     * @param  array{title:string,justification?:?string,needed_by?:?string,currency?:?string,urgency?:?string,items:list<array{name:string,quantity:float,unit_price:float,description?:?string}>}  $data
     *
     * @throws \RuntimeException when this workspace cannot raise purchases
     */
    public function raise(string $projectId, array $data): ProvidedRecord;
}
