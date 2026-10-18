@component('mail::message')
# Your Offer Signing Code

To proceed with reviewing and accepting your employment offer, enter the verification code below:

@component('mail::panel')
**{{ $otp }}**
@endcomponent

This code expires in **10 minutes**. Do not share it with anyone.

If you did not request this, please ignore this email.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
