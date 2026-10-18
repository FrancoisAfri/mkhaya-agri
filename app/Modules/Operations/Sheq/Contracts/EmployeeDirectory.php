<?php

namespace App\Modules\Operations\Sheq\Contracts;

/**
 * What SHEQ needs to know about a person, in SHEQ's language (SHEQ-ADR-002).
 *
 * HR owns employees; SHEQ stores references and compliance state, never copies (principle P3).
 * This interface is the seam: today an adapter reads HR's models, tomorrow it consumes HR events,
 * and no caller changes either way.
 *
 * Note what is *absent*: date of birth, ID number, address, salary. SHEQ has no business reading
 * them (SHEQ-DATA-4), so they are not on the interface — the cheapest way to keep PII out of an
 * incident payload is to make it unavailable.
 */
interface EmployeeDirectory
{
    /**
     * @return array{
     *   id:int, employee_number:?string, full_name:string, job_title:?string,
     *   department:?string, manager_name:?string, employment_status:?string
     * }|null  null when the person is unknown to HR, which a report must survive
     */
    public function find(int $hrPersonId): ?array;

    /**
     * @param  list<int>  $hrPersonIds
     * @return array<int,array<string,mixed>> keyed by hr person id
     */
    public function findMany(array $hrPersonIds): array;

    /**
     * The people a report can name, for a picker.
     *
     * Here rather than a query in a controller because of SHEQ-ADR-002: the adapter is the only
     * place in this module that knows HR exists, and a roster query written anywhere else is a
     * second place that would have to change when HR does.
     *
     * Carries the same fields as find() and no more — a picker needs a name and something to tell
     * two people of the same name apart, and nothing here should make it easier to put a date of
     * birth into an incident payload.
     *
     * @return list<array{id:int, full_name:string, employee_number:?string, job_title:?string}>
     */
    public function roster(int $companyId): array;

    /** Whether the module answering this interface is available to the current tenant. */
    public function isAvailable(): bool;
}
