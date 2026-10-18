@component('mail::message')
# You have been invited

@if($projectName)
You have been given access to **{{ $projectName }}**@if($projectCode) ({{ $projectCode }})@endif as a **{{ $roleLabel }}**.
@else
You have been given access to a project as a **{{ $roleLabel }}**.
@endif

Use the link below to set a password and see progress, documents and anything waiting on you.

@component('mail::button', ['url' => $acceptUrl])
Set up your access
@endcomponent

This link expires on **{{ $expiresAt }}** and can only be used once.

If you already have an account with this email address, the link will simply add this project to it — you will be asked to sign in as usual rather than to set a new password.

If you were not expecting this invitation, you can ignore this email; nothing will be created.

Thanks,
{{ config('app.name') }}
@endcomponent
