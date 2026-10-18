<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Contracts\EmployeeDirectory;
use App\Modules\Operations\Sheq\Regulatory\PackManifest;
use App\Modules\Operations\Sheq\Security\SpecialInformation;
use App\Modules\Operations\Sheq\Support\Timestamps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Generating statutory forms (SHEQ-REG-6).
 *
 * The layout comes from the regulatory pack, never from this class. That is the whole design: a
 * practitioner who finds a wrong label or a missing box corrects the pack, ships a new version, and
 * every form generated afterwards is right — without a deploy, and with the correction versioned
 * alongside the rules that classified the incident (SHEQ-REG-2).
 *
 * Two behaviours matter more than the rendering:
 *
 * - **Gaps are shown, not guessed.** A field the records cannot fill is reported as a gap and left
 *   blank. Inventing a plausible value for a form that goes to the Compensation Commissioner is the
 *   worst thing this code could do; a blank box a human must fill is the honest outcome.
 * - **An override never erases what the system proposed.** Both values are kept, with who changed
 *   it and why, because an audit that cannot show the original is not an audit.
 */
class StatutoryFormService
{
    public function __construct(
        private readonly EmployeeDirectory $employees,
        private readonly SpecialInformation $special,
    ) {}

    /**
     * Build a form for an incident and store the instance.
     *
     * @return array<string,mixed>
     */
    public function generate(int $companyId, int $incidentId, string $formCode, ?int $userId = null): array
    {
        $incident = DB::table('sheq_incidents')
            ->where('company_id', $companyId)->where('id', $incidentId)
            ->whereNull('deleted_at')->first();

        if ($incident === null) {
            throw new RuntimeException('Incident not found.');
        }

        $pack = $this->packFor($incident);
        $definition = $pack->manifest['forms'][$formCode] ?? null;

        if ($definition === null) {
            throw new RuntimeException(
                "Pack {$pack->packId}@{$pack->version} defines no form '{$formCode}'."
            );
        }

        $context = $this->context($companyId, $incident, $pack);
        $sections = [];
        $gaps = 0;

        foreach ($definition['sections'] ?? [] as $section) {
            $fields = [];

            foreach ($section['fields'] ?? [] as $field) {
                $value = $this->resolve($field['source'] ?? null, $context, $field['format'] ?? null);

                // A required field the records cannot fill is what "gaps highlighted" means.
                $isGap = ($value === null || $value === '') && ! empty($field['required']);

                if ($isGap) {
                    $gaps++;
                }

                $fields[] = [
                    'key' => $field['key'],
                    'label' => $field['label'],
                    'value' => $value,
                    'generated' => $value,
                    'source' => $field['source'] ?? null,
                    'required' => (bool) ($field['required'] ?? false),
                    'multiline' => (bool) ($field['multiline'] ?? false),
                    'is_gap' => $isGap,
                    'overridden' => false,
                ];
            }

            $sections[] = ['title' => $section['title'] ?? '', 'fields' => $fields];
        }

        $content = [
            'title' => $definition['title'] ?? $formCode,
            'instrument' => $definition['instrument'] ?? null,
            'guidance' => $definition['guidance'] ?? null,
            'sections' => $sections,
        ];

        $uuid = (string) Str::uuid();

        $id = DB::table('sheq_statutory_form_instances')->insertGetId([
            'uuid' => $uuid,
            'company_id' => $companyId,
            'form_code' => $formCode,
            'subject_type' => 'INCIDENT',
            'subject_id' => $incidentId,
            'regulatory_pack_id' => $pack->packId,
            'regulatory_pack_version' => $pack->version,
            'title' => $content['title'],
            'content' => json_encode($content),
            'gap_count' => $gaps,
            'status' => 'DRAFT',
            'generated_at' => Timestamps::utc(Carbon::now()),
            'generated_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId, 'actor_id' => $userId, 'actor_type' => 'USER',
            'action' => 'statutory_form.generated',
            'subject_type' => 'StatutoryForm', 'subject_id' => $id,
            'after' => json_encode([
                'form_code' => $formCode, 'incident' => $incident->reference,
                'gaps' => $gaps, 'pack' => $pack->packId.'@'.$pack->version,
            ]),
        ]);

        return $this->view($companyId, $uuid);
    }

    /**
     * Override a field (SHEQ-REG-6: "users MUST be able to override any field and the override MUST
     * be audited").
     *
     * The generated value stays on the field beside the override, so the form can always show both.
     */
    public function override(int $companyId, string $formUuid, string $fieldKey, ?string $value,
        ?string $reason = null, ?int $userId = null): array
    {
        return DB::transaction(function () use ($companyId, $formUuid, $fieldKey, $value, $reason, $userId) {
            $instance = $this->find($companyId, $formUuid);

            if ($instance->status === 'FINAL') {
                // A submitted form is a record of what was sent. Changing it would rewrite history.
                throw new RuntimeException(
                    'This form has been finalised. Generate a new one rather than editing what was submitted.'
                );
            }

            $content = json_decode((string) $instance->content, true);
            $generated = null;
            $found = false;

            foreach ($content['sections'] as $s => $section) {
                foreach ($section['fields'] as $f => $field) {
                    if ($field['key'] !== $fieldKey) {
                        continue;
                    }

                    $generated = $field['generated'];
                    $content['sections'][$s]['fields'][$f]['value'] = $value;
                    $content['sections'][$s]['fields'][$f]['overridden'] = true;
                    // Filling a gap by hand closes it — that is the point of allowing the override.
                    $content['sections'][$s]['fields'][$f]['is_gap'] =
                        ($value === null || $value === '') && $field['required'];
                    $found = true;
                }
            }

            if (! $found) {
                throw new RuntimeException("This form has no field '{$fieldKey}'.");
            }

            DB::table('sheq_statutory_form_overrides')->insert([
                'company_id' => $companyId,
                'form_instance_id' => $instance->id,
                'field_key' => $fieldKey,
                'generated_value' => $generated,
                'override_value' => $value,
                'reason' => $reason,
                'overridden_by' => $userId,
                'overridden_at' => Timestamps::utc(Carbon::now()),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('sheq_statutory_form_instances')->where('id', $instance->id)->update([
                'content' => json_encode($content),
                'gap_count' => $this->countGaps($content),
                'override_count' => DB::table('sheq_statutory_form_overrides')
                    ->where('form_instance_id', $instance->id)->count(),
                'updated_at' => now(),
            ]);

            DB::table('sheq_audit_log')->insert([
                'company_id' => $companyId, 'actor_id' => $userId, 'actor_type' => 'USER',
                'action' => 'statutory_form.field_overridden',
                'subject_type' => 'StatutoryForm', 'subject_id' => $instance->id,
                'before' => json_encode([$fieldKey => $generated]),
                'after' => json_encode([$fieldKey => $value, 'reason' => $reason]),
            ]);

            return $this->view($companyId, $formUuid);
        });
    }

    /**
     * Finalise: this is the version that was submitted.
     *
     * Refused while a required field is still blank. A W.Cl.2 short of the injured person's name is
     * not a form the Commissioner will accept, and letting it be marked submitted would leave the
     * obligation looking discharged when it is not.
     */
    public function finalise(int $companyId, string $formUuid, ?int $userId = null): array
    {
        return DB::transaction(function () use ($companyId, $formUuid, $userId) {
            $instance = $this->find($companyId, $formUuid);
            $content = json_decode((string) $instance->content, true);
            $gaps = $this->gapLabels($content);

            if ($gaps !== []) {
                throw new RuntimeException(
                    'This form is not complete. Still blank: '.implode(', ', $gaps)
                );
            }

            DB::table('sheq_statutory_form_instances')->where('id', $instance->id)->update([
                'status' => 'FINAL',
                'finalised_at' => Timestamps::utc(Carbon::now()),
                'finalised_by' => $userId,
                // Fingerprints exactly what was submitted, so a later dispute has an answer.
                'content_sha256' => hash('sha256', (string) $instance->content),
                'updated_at' => now(),
            ]);

            DB::table('sheq_audit_log')->insert([
                'company_id' => $companyId, 'actor_id' => $userId, 'actor_type' => 'USER',
                'action' => 'statutory_form.finalised',
                'subject_type' => 'StatutoryForm', 'subject_id' => $instance->id,
                'after' => json_encode([
                    'form_code' => $instance->form_code,
                    'sha256' => hash('sha256', (string) $instance->content),
                ]),
            ]);

            return $this->view($companyId, $formUuid);
        });
    }

    /** @return array<string,mixed> */
    public function view(int $companyId, string $formUuid): array
    {
        $instance = $this->find($companyId, $formUuid);
        $content = json_decode((string) $instance->content, true);

        return [
            'uuid' => $instance->uuid,
            'form_code' => $instance->form_code,
            'title' => $instance->title,
            'status' => $instance->status,
            'content' => $content,
            'gap_count' => (int) $instance->gap_count,
            'gaps' => $this->gapLabels($content),
            'override_count' => (int) $instance->override_count,
            'pack' => $instance->regulatory_pack_id.'@'.$instance->regulatory_pack_version,
            'generated_at' => $instance->generated_at,
            'finalised_at' => $instance->finalised_at,
            /*
             * The fingerprint taken at finalisation (REG-FIX-3). It was computed and stored so a
             * later dispute has an answer, and then never read — which makes it an answer nobody
             * can reach. The PDF prints it, so the paper copy somebody posts to a regulator can
             * be matched back to the record years later.
             */
            'content_sha256' => $instance->content_sha256,
            'overrides' => DB::table('sheq_statutory_form_overrides')
                ->where('company_id', $companyId)->where('form_instance_id', $instance->id)
                ->orderBy('id')
                ->get(['field_key', 'generated_value', 'override_value', 'reason', 'overridden_at'])
                ->map(fn ($o) => (array) $o)->all(),
        ];
    }

    /** Forms already generated for an incident. @return list<array<string,mixed>> */
    public function forIncident(int $companyId, int $incidentId): array
    {
        return DB::table('sheq_statutory_form_instances')
            ->where('company_id', $companyId)
            ->where('subject_type', 'INCIDENT')->where('subject_id', $incidentId)
            ->orderByDesc('id')
            ->get(['uuid', 'form_code', 'title', 'status', 'gap_count', 'override_count', 'generated_at'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /** Which forms the pack says this incident needs, and whether each has been generated. */
    public function requiredFor(int $companyId, int $incidentId): array
    {
        $incident = DB::table('sheq_incidents')
            ->where('company_id', $companyId)->where('id', $incidentId)->first();

        if ($incident === null) {
            return [];
        }

        $required = json_decode((string) $incident->required_forms, true) ?: [];
        $existing = collect($this->forIncident($companyId, $incidentId))->keyBy('form_code');
        $pack = $this->packFor($incident);

        return array_map(fn ($code) => [
            'form_code' => $code,
            'title' => $pack->manifest['forms'][$code]['title'] ?? $code,
            // A pack that names a form it does not define is a pack bug, and saying so beats a
            // button that fails when pressed.
            'defined' => isset($pack->manifest['forms'][$code]),
            'generated' => $existing->has($code),
            'status' => $existing->get($code)['status'] ?? null,
            'uuid' => $existing->get($code)['uuid'] ?? null,
        ], $required);
    }

    /**
     * Everything a form may address. Assembled once so a field's `source` is a lookup rather than a
     * query — a form with forty fields would otherwise be forty round trips.
     */
    private function context(int $companyId, object $incident, PackManifest $pack): array
    {
        $company = DB::table('companies')->where('id', $companyId)->first();
        $site = $incident->site_id === null ? null
            : DB::table('sheq_sites')->where('id', $incident->site_id)->first();

        // The injured person, not merely the first involved: a witness's name on a W.Cl.2 would be
        // a false statement to the Commissioner.
        $person = DB::table('sheq_incident_people')
            ->where('company_id', $companyId)->where('incident_id', $incident->id)
            ->whereIn('role', ['INJURED', 'AFFECTED'])
            ->orderBy('id')->first();

        $hr = $person && $person->hr_person_id ? $this->employees->find((int) $person->hr_person_id) : null;

        // The employer's regulatory identifiers. Absent settings are not an error — the fields
        // simply report as gaps, which is what tells somebody to go and fill them in.
        $settings = DB::table('sheq_settings')->where('company_id', $companyId)->first();

        return [
            'incident' => (array) $incident,
            'company' => $company === null ? [] : (array) $company,
            'settings' => $settings === null ? [] : (array) $settings,
            'site' => $site === null ? [] : (array) $site,
            'person' => [
                'full_name' => $hr['full_name'] ?? $person?->external_name,
                'job_title' => $hr['job_title'] ?? null,
                'employee_number' => $hr['employee_number'] ?? null,
                'engaged_on' => $hr['engaged_on'] ?? null,
                // The WCL-1/WCL-2 forms require the injury description, so the encrypted values
                // are revealed for the submission the law compels.
                'injury_nature' => $this->special->reveal($person?->injury_nature),
                'body_part' => $this->special->reveal($person?->body_part),
            ],
            'pack' => ['label' => $pack->packId.'@'.$pack->version, 'id' => $pack->packId],
        ];
    }

    private function resolve(?string $source, array $context, ?string $format): ?string
    {
        if ($source === null) {
            return null;
        }

        [$root, $key] = array_pad(explode('.', $source, 2), 2, null);
        $value = $context[$root][$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return match ($format) {
            'date' => Carbon::parse($value)->toDateString(),
            'time' => Carbon::parse($value)->format('H:i'),
            'yesno' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No',
            default => (string) $value,
        };
    }

    /** @return list<string> the labels a person still has to fill in */
    private function gapLabels(array $content): array
    {
        $gaps = [];

        foreach ($content['sections'] ?? [] as $section) {
            foreach ($section['fields'] as $field) {
                if (! empty($field['is_gap'])) {
                    $gaps[] = $field['label'];
                }
            }
        }

        return $gaps;
    }

    private function countGaps(array $content): int
    {
        return count($this->gapLabels($content));
    }

    /** The pack version effective when the incident occurred, not today's (SHEQ-REG-2). */
    private function packFor(object $incident): PackManifest
    {
        $row = DB::table('sheq_regulatory_pack_versions as v')
            ->join('sheq_regulatory_packs as p', 'p.id', '=', 'v.pack_id')
            ->where('p.pack_id', $incident->regulatory_pack_id)
            ->where('v.version', $incident->regulatory_pack_version)
            ->first(['p.pack_id', 'v.version', 'v.manifest']);

        if ($row === null) {
            throw new RuntimeException(
                'The regulatory pack version this incident was classified against is no longer installed.'
            );
        }

        return new PackManifest($row->pack_id, $row->version, json_decode($row->manifest, true) ?: []);
    }

    private function find(int $companyId, string $uuid): object
    {
        $instance = DB::table('sheq_statutory_form_instances')
            ->where('company_id', $companyId)->where('uuid', $uuid)->first();

        if ($instance === null) {
            throw new RuntimeException('Form not found.');
        }

        return $instance;
    }
}
