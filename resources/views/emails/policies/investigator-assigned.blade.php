@component('mail::message')
# Investigation Assigned

You have been assigned to investigate a policy violation.

| Field | Detail |
|---|---|
| Violation # | {{ $violationId }} |
| Policy # | {{ $policyId }} |
| Assigned At | {{ $assignedAt }} |

Please log in to review the case details, gather evidence, and record your findings.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
