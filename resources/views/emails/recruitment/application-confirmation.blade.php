@component('mail::message')
# Application Received

Hi {{ $candidateName }},

Thank you for applying for the **{{ $jobTitle }}** position. We have received your application and our recruitment team will review it shortly.

You will be notified of any updates to your application status via email.

Thanks,<br>
{{ config('app.name') }} Recruitment Team
@endcomponent
