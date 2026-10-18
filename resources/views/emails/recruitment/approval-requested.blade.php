@component('mail::message')
# Approval Required

Your action is needed on the following item.

| Field | Value |
|---|---|
| Type | {{ ucfirst($entityType) }} |
| Reference | #{{ $entityId }} |
| Subject | {{ $entityLabel }} |
| Stage | {{ $stageName }} |

Please log in to review and decide.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
