@component('mail::message')
# Policy Exception Update

Hi {{ $recipientName }},

{{ $message }}

@if($expiryDate)
**Expiry Date:** {{ $expiryDate }}
@endif

Please log in to review your policy exceptions.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
