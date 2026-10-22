{{-- The standard notification email, wearing a white-labelled company's name instead of the product's (see AgriMail::brand). --}}
<x-mail::layout>
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ $brandName }}
</x-mail::header>
</x-slot:header>

@if (! empty($greeting))
# {{ $greeting }}
@endif

@foreach ($introLines as $line)
{{ $line }}

@endforeach

@isset($actionText)
<x-mail::button :url="$actionUrl" :color="match ($level) { 'success', 'error' => $level, default => 'primary' }">
{{ $actionText }}
</x-mail::button>
@endisset

@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{ $salutation ?? $brandName }}

@isset($actionText)
<x-slot:subcopy>
<x-mail::subcopy>
{{ $brandLinkHint }} <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ $brandName }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
