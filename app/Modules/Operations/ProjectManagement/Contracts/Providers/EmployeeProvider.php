<?php

namespace App\Modules\Operations\ProjectManagement\Contracts\Providers;

use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;

/**
 * People who can be assigned work. Local: pm_project_members. Integrated: HR employees.
 *
 * One of the ten integration seams of Project Management (PM-1041). Each has a Local
 * implementation backed by PM's own tables and, where the platform offers it, an Integrated one
 * backed by another module. Which you get is decided at runtime by capability lookup — never by a
 * config file, never by an `if (hrInstalled)` in feature code. PM works standalone; installing a
 * module *adds* capability rather than switching PM on.
 */
interface EmployeeProvider
{
    /** @return list<ProvidedRecord> */
    public function search(string $query, int $limit = 20): array;

    public function get(string $id): ?ProvidedRecord;

    /**
     * Whether the person is free across a window.
     *
     * The local implementation cannot know about leave, so it answers "available, and I do not
     * know about leave" rather than pretending. Callers must read `supportsLeave()` before telling
     * a user that someone is definitely free.
     */
    public function isAvailable(string $id, string $from, string $to): AvailabilityResult;

    public function supportsLeave(): bool;

    /**
     * What one hour of this person costs (`hr.labour_rates`).
     *
     * Null when there is no authoritative rate — which is the honest answer far more often than a
     * number is. A costing built on a guessed rate is worse than one the user was asked to fill in,
     * because nobody can tell it was guessed.
     */
    public function hourlyRateFor(string $id): ?float;

    /**
     * Hours actually recorded by attendance across a window (`hr.attendance`).
     *
     * Null means "not tracked here", not "zero". The Delivery page shows booked hours beside
     * attended hours so a gap is visible; showing 0 against 40 booked would invent a discrepancy.
     */
    public function attendedHours(string $id, string $from, string $to): ?float;

    /**
     * Qualifications the person holds (`lms.competencies`), for checking they may do the work.
     *
     * @return list<array{name:string, expires_at:?string, expired:bool}>
     */
    public function qualificationsFor(string $id): array;
}
