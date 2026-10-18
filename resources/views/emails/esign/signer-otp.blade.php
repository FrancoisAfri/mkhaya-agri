@component('mail::message')
# Your Document Signing OTP

Hi {{ $signerName }},

Use the one-time password below to complete your signature. It expires in **{{ $expiresIn }}**.

@component('mail::panel')
# {{ $otp }}
@endcomponent

Do not share this code with anyone. If you did not request this, please ignore this email.

Thanks,
{{ config('app.name') }}
@endcomponent
