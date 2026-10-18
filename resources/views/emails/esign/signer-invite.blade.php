@component('mail::message')
# Document Signing Request

Hi {{ $signerName }},

You have been invited to sign the following document: **{{ $envelopeTitle }}**.

@if($message)
{{ $message }}

@endif
@component('mail::button', ['url' => $portalUrl])
Review & Sign Document
@endcomponent

@if($expiresAt)
This link expires on **{{ $expiresAt }}**. Please sign before then.
@endif

If you were not expecting this request, you may safely ignore this email.

Thanks,
{{ config('app.name') }}
@endcomponent
