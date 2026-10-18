@component('mail::message')
# Shortlist Ready for Review

An AI-generated shortlist is ready for your approval.

| Field | Value |
|---|---|
| Shortlist # | {{ $shortlistId }} |
| Position | {{ $jobTitle }} |
| Candidates | {{ $candidateCount }} |

Please review the shortlist and approve or request changes.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
