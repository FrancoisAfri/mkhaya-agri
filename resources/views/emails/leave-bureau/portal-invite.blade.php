@component('mail::message')
# Your leave portal is ready

Hello {{ $name }},

@if ($companyName)
{{ $invitedBy ? $invitedBy . ' has' : 'Your payroll bureau has' }} set up a leave portal for **{{ $companyName }}**.
@else
Your payroll bureau has set up your leave portal.
@endif

@if ($isEmployee)
You can check your leave balance, apply for leave and see where your requests have got to.
@else
You can see your company's leave, approve requests and pull reports.
@endif

@component('mail::button', ['url' => $acceptUrl])
Choose a password
@endcomponent

@if ($expiresAt)
This invitation is valid until **{{ $expiresAt }}**. If it lapses, your payroll bureau can send another.
@endif

If you were not expecting this, you can ignore it — the link does nothing until a password is set.

Thanks,
{{ config('app.name') }}
@endcomponent
