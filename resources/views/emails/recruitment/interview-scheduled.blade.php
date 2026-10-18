@component('mail::message')
@if($recipient === 'interviewer')
# Interview Scheduled: {{ $candidateName }}

You have been scheduled to interview **{{ $candidateName }}** for the role of **{{ $jobTitle }}**.
@else
# Your Interview Has Been Scheduled

Dear {{ $candidateName }},

Your interview for **{{ $jobTitle }}** has been confirmed.
@endif

**Date & Time:** {{ $scheduledAt }}
**Duration:** {{ $duration }} minutes
**Type:** {{ $type }}

@if($location)
**Location:** {{ $location }}
@endif

@if($meetingUrl)
@component('mail::button', ['url' => $meetingUrl])
Join Meeting
@endcomponent
@endif

Thanks,
{{ config('app.name') }}
@endcomponent
