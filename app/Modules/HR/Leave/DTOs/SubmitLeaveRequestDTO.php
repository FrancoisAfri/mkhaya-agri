<?php

namespace App\Modules\HR\Leave\DTOs;

readonly class SubmitLeaveRequestDTO
{
    private function __construct(
        public int     $employeeId,
        public int     $leaveTypeId,
        public string  $startDate,
        public string  $endDate,
        public string  $requestMode,
        public ?string $startTime,
        public ?string $endTime,
        public ?string $notes,
        public bool    $isOvertime,
        public bool    $isBulk,
        public ?int    $submittedBy,
        public bool    $onBehalf = false,
    ) {}

    public static function from(array $data): self
    {
        return new self(
            employeeId:  (int)   $data['employee_id'],
            leaveTypeId: (int)   $data['leave_type_id'],
            startDate:           $data['start_date'],
            endDate:             $data['end_date'],
            requestMode:         $data['request_mode'] ?? 'daily',
            startTime:           $data['start_time']   ?? null,
            endTime:             $data['end_time']     ?? null,
            notes:               $data['notes']        ?? null,
            isOvertime:  (bool) ($data['is_overtime']  ?? false),
            isBulk:      (bool) ($data['is_bulk']      ?? false),
            submittedBy:         isset($data['submitted_by']) ? (int) $data['submitted_by'] : null,
            onBehalf:    (bool) ($data['on_behalf'] ?? false),
        );
    }

    public function toArray(): array
    {
        return [
            'employee_id'   => $this->employeeId,
            'leave_type_id' => $this->leaveTypeId,
            'start_date'    => $this->startDate,
            'end_date'      => $this->endDate,
            'request_mode'  => $this->requestMode,
            'start_time'    => $this->startTime,
            'end_time'      => $this->endTime,
            'notes'         => $this->notes,
            'is_overtime'   => $this->isOvertime,
            'is_bulk'       => $this->isBulk,
            'submitted_by'  => $this->submittedBy,
        ];
    }
}
