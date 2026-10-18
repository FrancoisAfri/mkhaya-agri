@component('mail::message')
# You Have Received a Job Offer

Dear {{ $candidateName }},

We are pleased to offer you the position of **{{ $jobTitle }}**.

Please review your offer letter and respond by clicking the button below.
This link will expire on **{{ $expiresAt }}**.

@component('mail::button', ['url' => $portalUrl, 'color' => 'success'])
View & Respond to Offer
@endcomponent

If you have any questions, please contact your recruitment team.

Thanks,
{{ config('app.name') }}
@endcomponent
