@component('mail::message')
# Your Contract Signing OTP

Use the following one-time password to sign your contract. It expires in **{{ $expiresIn }}**.

@component('mail::panel')
# {{ $otp }}
@endcomponent

Do not share this code with anyone. If you did not request this, please ignore this email.

Thanks,
{{ config('app.name') }}
@endcomponent
