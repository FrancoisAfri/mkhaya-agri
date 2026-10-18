@component('mail::message')
# Contract Ready for Signing

@if($signatoryType === 'hr')
A contract for the position of **{{ $jobTitle }}** is ready for your review and signature.
@else
Your employment contract for **{{ $jobTitle }}** is ready for your signature.
@endif

Please click the button below to review and sign the document.
@if($expiresAt)
This signing link expires on **{{ $expiresAt }}**.
@endif

@component('mail::button', ['url' => $portalUrl, 'color' => 'success'])
Review & Sign Contract
@endcomponent

Thanks,
{{ config('app.name') }}
@endcomponent
