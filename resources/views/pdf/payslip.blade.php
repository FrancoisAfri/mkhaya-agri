<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Payslip {{ substr($payslip->uuid, 0, 8) }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #1a1a1a;
            background: #ffffff;
            padding: 30px;
        }

        /* ── Header ──────────────────────────────────────────────────────── */
        .header {
            background: #1e3a5f;
            color: #ffffff;
            padding: 18px 22px;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        .header .company-name {
            font-size: 20px;
            font-weight: bold;
            letter-spacing: 1px;
        }

        .header .payslip-meta {
            margin-top: 6px;
            font-size: 10px;
            color: #c8d8e8;
        }

        .header .payslip-meta span {
            margin-right: 24px;
        }

        /* ── Employee section ────────────────────────────────────────────── */
        .employee-section {
            border: 1px solid #d0d7de;
            border-radius: 4px;
            padding: 14px 18px;
            margin-bottom: 20px;
            background: #f8fafc;
        }

        .employee-section .section-title {
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            color: #6b7280;
            margin-bottom: 10px;
            letter-spacing: 0.5px;
        }

        .employee-grid {
            width: 100%;
        }

        .employee-grid td {
            padding: 3px 8px 3px 0;
            vertical-align: top;
            width: 50%;
        }

        .employee-grid .label {
            font-weight: bold;
            color: #374151;
            min-width: 110px;
            display: inline-block;
        }

        .employee-grid .value {
            color: #111827;
        }

        /* ── Earnings / Deductions tables ────────────────────────────────── */
        .table-section {
            margin-bottom: 20px;
        }

        .table-section-title {
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            color: #6b7280;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .payslip-table {
            width: 100%;
            border-collapse: collapse;
            background: #ffffff;
        }

        .payslip-table thead tr {
            background: #e5e7eb;
        }

        .payslip-table thead th {
            padding: 8px 12px;
            text-align: left;
            font-size: 10px;
            font-weight: bold;
            color: #374151;
            border: 1px solid #d1d5db;
        }

        .payslip-table thead th.amount-col {
            text-align: right;
        }

        .payslip-table tbody tr {
            background: #ffffff;
        }

        .payslip-table tbody tr:nth-child(even) {
            background: #f9fafb;
        }

        .payslip-table tbody td {
            padding: 7px 12px;
            border: 1px solid #e5e7eb;
            color: #111827;
        }

        .payslip-table tbody td.amount-col {
            text-align: right;
            font-family: 'DejaVu Sans', monospace;
        }

        .payslip-table tfoot td {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            font-weight: bold;
            background: #f3f4f6;
        }

        .payslip-table tfoot td.amount-col {
            text-align: right;
            font-family: 'DejaVu Sans', monospace;
        }

        /* ── Net Pay box ─────────────────────────────────────────────────── */
        .net-pay-box {
            background: #d1d5db;
            border: 2px solid #6b7280;
            border-radius: 4px;
            padding: 14px 20px;
            margin-bottom: 24px;
            text-align: right;
        }

        .net-pay-box .net-label {
            font-size: 12px;
            font-weight: bold;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .net-pay-box .net-amount {
            font-size: 22px;
            font-weight: bold;
            color: #111827;
            margin-top: 4px;
        }

        .net-pay-box .net-subline {
            font-size: 9px;
            color: #6b7280;
            margin-top: 2px;
        }

        /* ── Summary row ─────────────────────────────────────────────────── */
        .summary-row {
            width: 100%;
            margin-bottom: 20px;
        }

        .summary-row td {
            width: 33.33%;
            padding: 0 6px;
            vertical-align: top;
        }

        .summary-row td:first-child {
            padding-left: 0;
        }

        .summary-row td:last-child {
            padding-right: 0;
        }

        .summary-card {
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 10px 14px;
            text-align: center;
            background: #f9fafb;
        }

        .summary-card .card-label {
            font-size: 9px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .summary-card .card-value {
            font-size: 14px;
            font-weight: bold;
            color: #111827;
            margin-top: 3px;
        }

        /* ── Footer ──────────────────────────────────────────────────────── */
        .footer {
            border-top: 1px solid #e5e7eb;
            padding-top: 12px;
            color: #9ca3af;
            font-size: 9px;
            text-align: center;
        }

        /* ── Utility ─────────────────────────────────────────────────────── */
        .mb-4  { margin-bottom: 4px; }
        .text-right { text-align: right; }
    </style>
</head>
<body>

    {{-- ── Header ──────────────────────────────────────────────────────────────
         BCEA §33(1)(a): the employer's name and address. This said "MkhayaHub" — the software
         vendor — on every payslip the system had ever issued, because the view was never passed an
         employer at all. --}}
    <div class="header">
        <div class="company-name">{{ $employer['name'] }}</div>
        @if (!empty($employer['trading_name']))
            <div style="font-size:10px;color:#555">trading as {{ $employer['trading_name'] }}</div>
        @endif
        @if (!empty($employer['address']))
            <div style="font-size:10px;color:#555">{{ $employer['address'] }}</div>
        @endif
        @if (!empty($employer['paye_ref']))
            <div style="font-size:10px;color:#555">
                PAYE ref: {{ $employer['paye_ref'] }}@if (!empty($employer['uif_ref'])) · UIF ref: {{ $employer['uif_ref'] }}@endif
            </div>
        @endif
        <div class="payslip-meta">
            <span>Payslip Ref: {{ strtoupper(substr($payslip->uuid, 0, 8)) }}</span>
            <span>Period: {{ \Carbon\Carbon::parse($payslip->period_start)->format('d M Y') }} &ndash; {{ \Carbon\Carbon::parse($payslip->period_end)->format('d M Y') }}</span>
        </div>
    </div>

    {{-- ── Employee Details ─────────────────────────────────────────────────── --}}
    <div class="employee-section">
        <div class="section-title">Employee Information</div>
        <table class="employee-grid" cellpadding="0" cellspacing="0">
            <tr>
                <td>
                    <span class="label">Employee Name:</span>
                    <span class="value">{{ $employee['name'] ?: $payslip->employee_name }}</span>
                </td>
                <td>
                    <span class="label">Employee No:</span>
                    <span class="value">{{ $employee['number'] ?: '—' }}</span>
                </td>
            </tr>
            {{-- BCEA §33(1)(b) asks for the occupation, not only the name. The identity numbers are
                 here because an employee reconciling this against an IRP5 needs them. --}}
            <tr>
                <td>
                    <span class="label">Occupation:</span>
                    <span class="value">{{ $employee['occupation'] ?: '—' }}</span>
                </td>
                <td>
                    <span class="label">Tax No:</span>
                    <span class="value">{{ $employee['tax_number'] ?: '—' }}</span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="label">Period Start:</span>
                    <span class="value">{{ \Carbon\Carbon::parse($payslip->period_start)->format('d M Y') }}</span>
                </td>
                <td>
                    <span class="label">Period End:</span>
                    <span class="value">{{ \Carbon\Carbon::parse($payslip->period_end)->format('d M Y') }}</span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="label">Pay Date:</span>
                    <span class="value">{{ \Carbon\Carbon::parse($pay_date)->format('d M Y') }}</span>
                </td>
                <td>
                    <span class="label">ID No:</span>
                    <span class="value">{{ $employee['id_number'] ?: '—' }}</span>
                </td>
            </tr>
        </table>
    </div>

    {{-- ── Earnings Table ───────────────────────────────────────────────────── --}}
    @php
        $earnings   = $payslip->earningsLines->filter(fn($l) => ! $l->line_type->isDeduction());
        // PAYE/UIF/Pension are rendered from the payslip columns; exclude them here so
        // they aren't double-counted when they also exist as explicit deduction lines.
        $statutory  = ['paye', 'uif', 'pension'];
        $deductions = $payslip->earningsLines->filter(fn($l) => $l->line_type->isDeduction() && ! in_array($l->line_type->value, $statutory, true));
    @endphp

    <div class="table-section">
        <div class="table-section-title">Earnings</div>
        <table class="payslip-table" cellpadding="0" cellspacing="0">
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="amount-col">Amount (ZAR)</th>
                </tr>
            </thead>
            <tbody>
                {{-- When no explicit earnings lines exist, the basic salary is the gross. --}}
                @if ($earnings->isEmpty())
                    <tr>
                        <td>Basic Salary</td>
                        <td class="amount-col">{{ number_format((float) ($payslip->basic_salary ?: $payslip->gross_pay), 2) }}</td>
                    </tr>
                @else
                    @foreach ($earnings as $line)
                        <tr>
                            <td>{{ $line->description }}</td>
                            <td class="amount-col">{{ number_format((float) $line->amount, 2) }}</td>
                        </tr>
                    @endforeach
                @endif
            </tbody>
            <tfoot>
                <tr>
                    <td>Gross Pay</td>
                    <td class="amount-col">{{ number_format((float) $payslip->gross_pay, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    {{-- ── Deductions Table ─────────────────────────────────────────────────── --}}
    <div class="table-section">
        <div class="table-section-title">Deductions</div>
        <table class="payslip-table" cellpadding="0" cellspacing="0">
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="amount-col">Amount (ZAR)</th>
                </tr>
            </thead>
            <tbody>
                {{-- Statutory deductions are stored as columns; always show them as lines. --}}
                @if ((float) $payslip->paye > 0)
                    <tr><td>PAYE (income tax)</td><td class="amount-col">{{ number_format((float) $payslip->paye, 2) }}</td></tr>
                @endif
                @if ((float) $payslip->uif > 0)
                    <tr><td>UIF</td><td class="amount-col">{{ number_format((float) $payslip->uif, 2) }}</td></tr>
                @endif
                @if ((float) $payslip->pension > 0)
                    <tr><td>Pension / Retirement</td><td class="amount-col">{{ number_format((float) $payslip->pension, 2) }}</td></tr>
                @endif
                {{-- Any other explicit deduction lines (loans, garnishees, union, etc.). --}}
                @foreach ($deductions as $line)
                    <tr>
                        <td>{{ $line->description }}</td>
                        <td class="amount-col">{{ number_format((float) $line->amount, 2) }}</td>
                    </tr>
                @endforeach
                @if ((float) $payslip->total_deductions <= 0 && $deductions->isEmpty())
                    <tr>
                        <td colspan="2" style="text-align:center; color:#9ca3af;">No deductions</td>
                    </tr>
                @endif
            </tbody>
            <tfoot>
                <tr>
                    <td>Total Deductions</td>
                    <td class="amount-col">{{ number_format((float) $payslip->total_deductions, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    {{-- ── Summary Cards ────────────────────────────────────────────────────── --}}
    <table class="summary-row" cellpadding="0" cellspacing="0">
        <tr>
            <td>
                <div class="summary-card">
                    <div class="card-label">PAYE</div>
                    <div class="card-value">{{ number_format((float) $payslip->paye, 2) }}</div>
                </div>
            </td>
            <td>
                <div class="summary-card">
                    <div class="card-label">UIF</div>
                    <div class="card-value">{{ number_format((float) $payslip->uif, 2) }}</div>
                </div>
            </td>
            <td>
                <div class="summary-card">
                    <div class="card-label">Pension</div>
                    <div class="card-value">{{ number_format((float) $payslip->pension, 2) }}</div>
                </div>
            </td>
        </tr>
    </table>

    {{-- ── Net Pay Box ──────────────────────────────────────────────────────── --}}
    <div class="net-pay-box">
        <div class="net-label">Net Pay</div>
        <div class="net-amount">ZAR {{ number_format((float) $payslip->net_pay, 2) }}</div>
        <div class="net-subline">
            Gross {{ number_format((float) $payslip->gross_pay, 2) }}
            &minus; Deductions {{ number_format((float) $payslip->total_deductions, 2) }}
        </div>
    </div>

    {{-- ── Year to date ──────────────────────────────────────────────────────
         Standard on every South African payslip and absent from this one. Counted March to
         February, because that is the year an IRP5 reconciles against — not the calendar year. --}}
    @if (!empty($ytd))
        <div class="employee-section" style="margin-top:14px">
            <div class="section-title">Year to date (since {{ \Carbon\Carbon::parse($ytd['since'])->format('d M Y') }})</div>
            <table class="employee-grid" cellpadding="0" cellspacing="0">
                <tr>
                    <td><span class="label">Gross:</span> <span class="value">{{ number_format($ytd['gross'], 2) }}</span></td>
                    <td><span class="label">PAYE:</span> <span class="value">{{ number_format($ytd['paye'], 2) }}</span></td>
                </tr>
                <tr>
                    <td><span class="label">UIF:</span> <span class="value">{{ number_format($ytd['uif'], 2) }}</span></td>
                    <td><span class="label">Pension:</span> <span class="value">{{ number_format($ytd['pension'], 2) }}</span></td>
                </tr>
                <tr>
                    <td><span class="label">Net paid:</span> <span class="value">{{ number_format($ytd['net'], 2) }}</span></td>
                    <td></td>
                </tr>
            </table>
        </div>
    @endif

    {{-- ── Part period ───────────────────────────────────────────────────────
         Shown whenever the pay is for less than the whole period. Somebody who is paid less than
         they expected should be told why on the document, not left to work it out or to ring
         payroll about it. --}}
    @if (!empty($pro_rata) && ($pro_rata['partial'] ?? false))
        <div class="section">
            <div class="section-title">Part period</div>
            <table class="kv">
                <tr>
                    <td class="k">Reason</td>
                    <td class="v">{{ $pro_rata['reason'] ?? 'Employed for part of this period.' }}</td>
                </tr>
                <tr>
                    <td class="k">Days paid</td>
                    <td class="v">
                        {{ $pro_rata['days_paid'] ?? 0 }} of {{ $pro_rata['days_in_period'] ?? 0 }}
                        {{ ($pro_rata['basis'] ?? 'working') === 'calendar' ? 'calendar' : 'working' }} days
                    </td>
                </tr>
            </table>
        </div>
    @endif

    {{-- ── Hours and rates — BCEA §33(1)(g) ──────────────────────────────────
         Required only "if relevant to the calculation of pay", so it is rendered when hours were
         captured and omitted entirely when they were not. Printing zeros for a salaried employee
         would be worse than saying nothing. --}}
    @if (!empty($hours) && ($hours['ordinary'] ?? 0) + ($hours['overtime'] ?? 0) + ($hours['sunday'] ?? 0) + ($hours['public_holiday'] ?? 0) > 0)
        <div class="employee-section" style="margin-top:14px">
            <div class="section-title">Hours and rates</div>
            <table class="employee-grid" cellpadding="0" cellspacing="0">
                <tr>
                    <td><span class="label">Rate:</span> <span class="value">{{ number_format($hours['rate'] ?? 0, 2) }}/hr</span></td>
                    <td><span class="label">Overtime rate:</span> <span class="value">{{ number_format($hours['overtime_rate'] ?? 0, 2) }}/hr</span></td>
                </tr>
                <tr>
                    <td><span class="label">Ordinary hours:</span> <span class="value">{{ number_format($hours['ordinary'] ?? 0, 2) }}</span></td>
                    <td><span class="label">Overtime hours:</span> <span class="value">{{ number_format($hours['overtime'] ?? 0, 2) }}</span></td>
                </tr>
                @if (($hours['sunday'] ?? 0) > 0 || ($hours['public_holiday'] ?? 0) > 0)
                    <tr>
                        <td><span class="label">Sunday hours:</span> <span class="value">{{ number_format($hours['sunday'] ?? 0, 2) }}</span></td>
                        <td><span class="label">Public holiday hours:</span> <span class="value">{{ number_format($hours['public_holiday'] ?? 0, 2) }}</span></td>
                    </tr>
                @endif
            </table>
        </div>
    @endif

    {{-- ── Leave balance ─────────────────────────────────────────────────────
         Shown only where leave is actually administered for this employee, so a payroll-only
         client does not get an empty box. --}}
    @if (!empty($leave))
        <div class="employee-section" style="margin-top:14px">
            <div class="section-title">Leave balance</div>
            <table class="employee-grid" cellpadding="0" cellspacing="0">
                @foreach ($leave as $row)
                    <tr>
                        <td><span class="label">{{ $row['type'] }}:</span> <span class="value">{{ number_format($row['available'], 2) }} days</span></td>
                        <td><span class="label">Taken:</span> <span class="value">{{ number_format($row['taken'], 2) }} days</span></td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    {{-- ── Footer ──────────────────────────────────────────────────────────── --}}
    <div class="footer">
        <p>Confidential &mdash; Internal Use Only</p>
        <p class="mb-4">Generated on {{ now()->format('d M Y') }}</p>
    </div>

</body>
</html>
