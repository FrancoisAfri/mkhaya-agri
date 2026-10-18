@component('mail::message')
# Requisition Approved

A staffing requisition has been approved and is ready for the recruitment process.

| Field | Value |
|---|---|
| Requisition # | {{ $requisitionId }} |
| Position | {{ $positionTitle }} |
| Department | {{ $department }} |
| Headcount | {{ $headcount }} |

Please review the requisition and proceed with sourcing candidates.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
