@component('mail::message')
# Support summary — {{ $period }}

Hello{{ $report->party?->name ? ' ' . $report->party->name : '' }},

Attached is the summary of support work carried out for you during {{ $period }}.

**Tickets handled:** {{ $report->ticket_count }}
**Time recorded:** {{ $report->totalHours() }} hours
**Chargeable time:** {{ $report->billableHours() }} hours
@if ($report->entitlement_minutes !== null)
**Included in your agreement:** {{ round($report->entitlement_minutes / 60, 2) }} hours
@if ($report->overage_minutes > 0)
**Beyond included hours:** {{ $report->overageHours() }} hours
@else
All work this period fell within your included hours.
@endif
@endif

@if ($report->summary)
{{ $report->summary }}
@endif

The attached PDF lists each ticket and what was done.

Thanks,<br>
{{ $companyName }}
@endcomponent
