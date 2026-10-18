@php
    $money = fn ($n) => number_format((float) $n, 2, '.', ' ');
@endphp
<!doctype html>
<html>
<head><meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
    .head { border-bottom: 3px solid #0b6b3a; padding-bottom: 8px; margin-bottom: 14px; }
    .title { font-size: 18px; font-weight: bold; color: #0b6b3a; }
    .muted { color: #666; }
    table.form { width: 100%; border-collapse: collapse; margin-top: 6px; }
    table.form th { background: #0b6b3a; color: #fff; text-align: left; padding: 6px 8px; font-size: 10px; }
    table.form td { padding: 6px 8px; border-bottom: 1px solid #eee; }
    .r { text-align: right; }
    .total td { font-weight: bold; border-top: 2px solid #0b6b3a; }
    .kv td { border: 0; padding: 2px 8px 2px 0; }
    .notice { margin-top: 14px; padding: 8px 10px; background: #fff7e6; border: 1px solid #f0c36d; font-size: 10px; }
    .footer { margin-top: 20px; font-size: 9px; color: #999; border-top: 1px solid #eee; padding-top: 6px; }
</style></head>
<body>
    <div class="head">
        <div class="title">{{ $form->name }} — eFiling capture sheet</div>
        <div class="muted">{{ $return->reference }} · version {{ $return->version }}{{ $return->purpose === 'amendment' ? ' (amendment)' : '' }}</div>
    </div>

    <table class="kv">
        <tr><td class="muted">Business</td><td>{{ $business }}</td><td class="muted">Reference number</td><td>{{ $registration->reference }}</td></tr>
        <tr><td class="muted">Tax period</td><td>{{ $period->label }} ({{ $period->start_date->toDateString() }} to {{ $period->end_date->toDateString() }})</td>
            <td class="muted">Due</td><td>{{ $period->filing_due_date?->toDateString() }}</td></tr>
        <tr><td class="muted">Approved by</td><td>{{ $approvedBy }} on {{ $return->approved_at?->toDateTimeString() }}</td>
            <td class="muted">Field mapping</td><td>{{ $form->code }} v{{ $form->version }}</td></tr>
    </table>

    <table class="form">
        <thead><tr><th style="width: 60px">Field</th><th>Description</th><th class="r" style="width: 140px">Amount (R)</th></tr></thead>
        <tbody>
        @foreach ($lines as $line)
            <tr class="{{ $line->kind === 'total' ? 'total' : '' }}">
                <td>{{ $line->field }}</td>
                <td>{{ $line->label }}@if ($line->adjustment_reason)<br><span class="muted">Adjusted: {{ $line->adjustment_reason }}</span>@endif</td>
                <td class="r">{{ $money($line->final) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="notice">
        This sheet is the approved return, laid out for capture on SARS eFiling. It is not a submission.
        After filing, record the SARS reference against {{ $return->reference }} in Mkhaya Hub and attach the eFiling receipt.
    </div>

    <div class="footer">Generated {{ $generatedAt }} · report version 1 · Mkhaya Hub Tax Management</div>
</body>
</html>
