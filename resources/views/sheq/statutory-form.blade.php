{{--
  A statutory form, rendered for printing and handing over (SHEQ-REG-6).

  This is the artefact that physically leaves the building: a W.Cl.1/W.Cl.2 goes to the Compensation
  Commissioner and a section 24 notification to the Department of Employment and Labour. Until now
  the controller, the route and every field binding existed and this file did not, so each download
  threw "View [sheq.statutory-form] not found" — the one output the module exists to produce could
  not be produced.

  The properties below are what a reader depends on, and each is covered by a test:

  - **A draft cannot be mistaken for a submission.** A draft reaching the Commissioner looking
    final is acted on as though the employer had certified it.
  - **A blank field says it is blank.** An empty box reads as "nothing to report" to a claims
    assessor; it has to read as "we did not supply this", which is a different statement.
  - **The document names the pack version that produced it.** Classification rules change between
    versions, and "why was this reported as a section 24?" is only answerable months later if the
    page says what it was assessed against.
  - **An override is shown beside what was generated.** REG-6 requires overrides to be audited, and
    a printed form showing only the final value hides that from the one reader who cannot query the
    database.

  Only `form` and `company` are required. Everything else is derived, so a caller rendering the
  view directly gets the same document as the controller does.
--}}
@php
    $content = $content ?? ($form['content'] ?? []);
    /*
     * FINAL, not FINALISED.
     *
     * `StatutoryFormService::finalise()` writes 'FINAL'; this compared against 'FINALISED', so
     * `$isDraft` was true for every form ever produced — including completed ones. A finalised
     * statutory submission printed stamped "must not be submitted in this state", which is the
     * one thing this document exists not to say. The route, the controller, the field binding and
     * the template were all in place and the artefact could still not be used for its purpose.
     *
     * Accepting both spellings deliberately: the stored value is what matters, and a document
     * that silently reverts to DRAFT if somebody renames a status is a trap waiting to be stepped
     * in again.
     */
    $isDraft = ! in_array($form['status'] ?? 'DRAFT', ['FINAL', 'FINALISED'], true);
    $gapCount = (int) ($form['gap_count'] ?? 0);
    $stamp = $form['finalised_at'] ?? $form['generated_at'] ?? null;
    $renderedAt = $generatedAt ?? ($stamp
        ? \Illuminate\Support\Carbon::parse($stamp)->timezone(config('app.timezone'))->format('d M Y H:i')
        : now()->timezone(config('app.timezone'))->format('d M Y H:i'));
    $reference = $incidentReference ?? null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $content['title'] ?? ($form['title'] ?? $form['form_code']) }}</title>
    <style>
        @page { margin: 18mm 15mm 24mm 15mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #111; line-height: 1.45; }
        h1 { font-size: 14pt; margin: 0 0 2mm; }
        .instrument { font-size: 9pt; color: #444; margin: 0 0 4mm; }
        .employer { font-size: 9pt; margin: 0 0 4mm; }
        .banner { border: 1pt solid #a15c00; color: #a15c00; padding: 2mm 3mm; margin: 0 0 4mm;
                  font-size: 9pt; font-weight: bold; }
        .guidance { font-size: 8.5pt; color: #444; border-left: 2pt solid #999;
                    padding: 2mm 0 2mm 3mm; margin: 0 0 5mm; }
        h2 { font-size: 10.5pt; margin: 5mm 0 2mm; padding-bottom: 1mm; border-bottom: 0.5pt solid #bbb; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 1.6mm 2mm; vertical-align: top; border-bottom: 0.3pt solid #ddd; }
        td.label { width: 38%; color: #333; }
        td.value { width: 62%; }
        .not-supplied { color: #a15c00; font-weight: bold; font-size: 9pt; }
        .rule { display: block; border-bottom: 0.5pt solid #666; min-height: 4.5mm; margin-top: 1mm; }
        .rule-tall { min-height: 16mm; }
        .was { font-size: 7.5pt; color: #666; }
        .footer { position: fixed; bottom: -16mm; left: 0; right: 0; font-size: 7.5pt; color: #555;
                  border-top: 0.3pt solid #bbb; padding-top: 1.5mm; }
    </style>
</head>
<body>
    <h1>{{ $content['title'] ?? ($form['title'] ?? $form['form_code']) }}</h1>

    @if (! empty($content['instrument']))
        <p class="instrument">{{ $content['instrument'] }}</p>
    @endif

    @if (! empty($company))
        <p class="employer"><strong>Employer:</strong> {{ $company }}</p>
    @endif

    @if ($isDraft)
        {{-- Unmissable, and worded so that nobody has to interpret it. --}}
        <div class="banner">
            DRAFT — not ready for submission.
            This form has not been finalised and must not be submitted in this state.
        </div>
    @elseif ($gapCount > 0)
        <div class="banner">
            {{ $gapCount }} required field(s) were NOT SUPPLIED — not ready for submission
            until they are completed.
        </div>
    @endif

    @if (! $isDraft && ! empty($form['content_sha256']))
        {{--
            The fingerprint `finalise()` takes of exactly what was submitted, so a later dispute
            has an answer. That is worth nothing if the paper copy cannot be matched to it, which
            is why it belongs on the page and not only in the database.
        --}}
        <p class="instrument">
            <strong>Content fingerprint (SHA-256):</strong>
            <span style="font-family: DejaVu Sans Mono, monospace; font-size: 7.5pt">{{
                $form['content_sha256'] }}</span>
            @if (! empty($form['finalised_at']))
                · finalised {{ \Illuminate\Support\Carbon::parse($form['finalised_at'])
                    ->timezone(config('app.timezone'))->format('d M Y H:i') }}
            @endif
        </p>
    @endif

    @if (! empty($content['guidance']))
        <p class="guidance">{{ $content['guidance'] }}</p>
    @endif

    @foreach ($content['sections'] ?? [] as $section)
        <h2>{{ $section['title'] }}</h2>
        <table>
            @foreach ($section['fields'] ?? [] as $field)
                @php
                    $value = $field['value'] ?? null;
                    $hasValue = $value !== null && $value !== '';
                    $wasOverridden = ! empty($field['overridden'])
                        && ($field['generated'] ?? null) !== $value;
                @endphp
                <tr>
                    <td class="label">{{ $field['label'] }}@if (! empty($field['required'])) *@endif</td>
                    <td class="value">
                        @if ($hasValue)
                            {{ $value }}
                            @if ($wasOverridden)
                                <br><span class="was">Generated: {{ $field['generated'] ?: '—' }} — overridden</span>
                            @endif
                        @elseif (! empty($field['required']))
                            {{-- A required field with nothing behind it. Never an empty box. --}}
                            <span class="not-supplied">NOT SUPPLIED</span>
                            <span class="rule {{ ! empty($field['multiline']) ? 'rule-tall' : '' }}"></span>
                        @else
                            <span class="rule {{ ! empty($field['multiline']) ? 'rule-tall' : '' }}"></span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endforeach

    <div class="footer">
        {{ $form['form_code'] }}
        @if ($reference) · Incident {{ $reference }} @endif
        · Assessed under regulatory pack {{ $form['pack'] ?? 'unknown' }}
        · {{ $isDraft ? 'Drafted' : 'Finalised' }} {{ $renderedAt }}
        @if ($gapCount > 0) · {{ $gapCount }} field(s) NOT SUPPLIED @endif
    </div>
</body>
</html>
