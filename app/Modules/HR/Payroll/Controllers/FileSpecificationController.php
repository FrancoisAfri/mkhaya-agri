<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\FileSpecification;
use App\Modules\HR\Payroll\Models\FileSpecificationField;
use App\Modules\HR\Payroll\Services\FileSpecificationRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where somebody transcribes a vendor's file layout with the document open beside them.
 *
 * The whole point is that this is data entry rather than a code change. So the screen shows the
 * sample output as the layout is built — a fixed-width spec is almost impossible to get right
 * without seeing the line it produces, and the two ways it goes wrong (a gap, an overlap) are
 * invisible in a table of numbers and obvious the moment you look at the rendered line.
 */
class FileSpecificationController extends Controller
{
    public function __construct(private readonly FileSpecificationRenderer $renderer)
    {
    }

    public function index(Request $request): Response
    {
        $specs = FileSpecification::query()
            ->withCount('fields')
            ->when($request->input('code'), fn ($q, $code) => $q->where('code', $code))
            ->orderBy('code')
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->get()
            ->map(fn (FileSpecification $s) => [
                'uuid' => $s->uuid, 'code' => $s->code, 'name' => $s->name,
                'vendor' => $s->vendor, 'version' => $s->version,
                'effective_from' => $s->effective_from?->toDateString(),
                'effective_to' => $s->effective_to?->toDateString(),
                'format' => $s->format, 'is_active' => $s->is_active,
                'fields_count' => $s->fields_count,
                // A shipped layout is read-only here: editing it would change every tenant's file.
                'shared' => $s->isShared(),
            ]);

        return Inertia::render('Modules/HR/Payroll/FileSpecifications/Index', [
            'specifications' => $specs,
            'codes' => FileSpecification::query()->distinct()->orderBy('code')->pluck('code'),
            'filters' => $request->only(['code']),
        ]);
    }

    public function show(FileSpecification $specification): Response
    {
        $sample = $this->sample();

        return Inertia::render('Modules/HR/Payroll/FileSpecifications/Edit', [
            'specification' => [
                'uuid' => $specification->uuid, 'code' => $specification->code,
                'name' => $specification->name, 'vendor' => $specification->vendor,
                'version' => $specification->version,
                'effective_from' => $specification->effective_from?->toDateString(),
                'effective_to' => $specification->effective_to?->toDateString(),
                'format' => $specification->format, 'delimiter' => $specification->delimiter,
                'line_ending' => $specification->line_ending, 'pad_to_length' => $specification->pad_to_length,
                'is_active' => $specification->is_active, 'notes' => $specification->notes,
                'shared' => $specification->isShared(),
            ],
            'fields' => $specification->fields()->get()->map(fn (FileSpecificationField $f) => [
                'uuid' => $f->uuid, 'record_type' => $f->record_type, 'sequence' => $f->sequence,
                'name' => $f->name, 'label' => $f->label, 'source' => $f->source,
                'position' => $f->position, 'length' => $f->length, 'type' => $f->type,
                'decimals' => $f->decimals, 'date_format' => $f->date_format,
                'pad_char' => $f->pad_char, 'pad_side' => $f->pad_side,
                'uppercase' => $f->uppercase, 'required' => $f->required,
                'default_value' => $f->default_value,
            ]),
            // Seeing the line is the whole point: a gap or an overlap is invisible in a table of
            // numbers and obvious the moment the rendered output is in front of you.
            'preview' => $this->renderer->render($specification->load('fields'), $sample['context'], $sample['rows']),
            'problems' => $this->renderer->validateSpecification($specification),
            'tokens' => FileSpecificationRenderer::availableTokens($sample['context'], $sample['rows'][0] ?? []),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, creating: true);

        $spec = FileSpecification::create($data + ['created_by' => Auth::id()]);

        return redirect()->route('payroll.file-specs.show', $spec->uuid)
            ->with('success', 'Layout created. Add its fields from the vendor document.');
    }

    public function update(Request $request, FileSpecification $specification): RedirectResponse
    {
        $this->refuseSharedEdit($specification);

        $specification->update($this->validated($request));

        return back()->with('success', 'Layout updated.');
    }

    /**
     * Start the next version from this one.
     *
     * How a vendor revision is handled: copy, change what moved, and set the date it takes effect.
     * The old version stays exactly as it was, so a file reprinted for an old period still comes out
     * in the format that was current then.
     */
    public function duplicate(Request $request, FileSpecification $specification): RedirectResponse
    {
        $data = $request->validate([
            'effective_from' => ['required', 'date'],
        ]);

        $copy = $specification->replicate(['uuid', 'created_at', 'updated_at']);
        $copy->uuid = null;
        $copy->company_id = app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id();
        $copy->version = ((int) FileSpecification::where('code', $specification->code)->max('version')) + 1;
        $copy->effective_from = $data['effective_from'];
        $copy->effective_to = null;
        $copy->created_by = Auth::id();
        $copy->save();

        foreach ($specification->fields()->get() as $field) {
            $new = $field->replicate(['uuid', 'created_at', 'updated_at']);
            $new->uuid = null;
            $new->file_specification_id = $copy->id;
            $new->company_id = $copy->company_id;
            $new->save();
        }

        // The version it replaces stops the day before the new one starts, so no date is covered by
        // two versions at once — which would make "which layout applies" a matter of sort order.
        if (blank($specification->effective_to)) {
            $specification->update([
                'effective_to' => \Illuminate\Support\Carbon::parse($data['effective_from'])->subDay(),
            ]);
        }

        return redirect()->route('payroll.file-specs.show', $copy->uuid)
            ->with('success', "Version {$copy->version} started from version {$specification->version}.");
    }

    public function storeField(Request $request, FileSpecification $specification): RedirectResponse
    {
        $this->refuseSharedEdit($specification);

        $data = $this->validatedField($request);

        $data['sequence'] ??= ((int) $specification->fields()
            ->where('record_type', $data['record_type'])
            ->max('sequence')) + 1;

        $specification->fields()->create($data);

        return back()->with('success', 'Field added.');
    }

    public function updateField(Request $request, FileSpecification $specification, FileSpecificationField $field): RedirectResponse
    {
        $this->refuseSharedEdit($specification);
        abort_unless($field->file_specification_id === $specification->id, 404);

        $field->update($this->validatedField($request, creating: false));

        return back()->with('success', 'Field updated.');
    }

    public function destroyField(FileSpecification $specification, FileSpecificationField $field): RedirectResponse
    {
        $this->refuseSharedEdit($specification);
        abort_unless($field->file_specification_id === $specification->id, 404);

        $field->delete();

        return back()->with('success', 'Field removed.');
    }

    /**
     * A layout shipped with the product belongs to every tenant.
     *
     * Editing one here would change everybody's file, so the answer is to copy it — which is what
     * `duplicate` does, and what the screen offers instead of a save button.
     */
    private function refuseSharedEdit(FileSpecification $specification): void
    {
        abort_if(
            $specification->isShared(),
            403,
            'This layout ships with the product and is shared by every tenant. Start a version of your own from it instead.',
        );
    }

    private function validated(Request $request, bool $creating = false): array
    {
        return $request->validate([
            'code'           => [$creating ? 'required' : 'sometimes', 'string', 'max:60'],
            'name'           => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'vendor'         => ['nullable', 'string', 'max:60'],
            'version'        => ['nullable', 'integer', 'min:1', 'max:999'],
            'effective_from' => [$creating ? 'required' : 'sometimes', 'date'],
            'effective_to'   => ['nullable', 'date', 'after_or_equal:effective_from'],
            'format'         => ['nullable', 'in:fixed,delimited'],
            'delimiter'      => ['nullable', 'string', 'max:4'],
            'line_ending'    => ['nullable', 'in:crlf,lf,cr'],
            'pad_to_length'  => ['boolean'],
            'is_active'      => ['boolean'],
            'notes'          => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function validatedField(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'record_type'   => [$creating ? 'required' : 'sometimes', 'in:header,detail,trailer'],
            'sequence'      => ['nullable', 'integer', 'min:1', 'max:999'],
            'name'          => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'label'         => ['nullable', 'string', 'max:255'],
            'source'        => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'position'      => ['nullable', 'integer', 'min:1', 'max:9999'],
            'length'        => ['nullable', 'integer', 'min:1', 'max:9999'],
            'type'          => ['nullable', 'in:text,number,cents,date'],
            'decimals'      => ['nullable', 'integer', 'min:0', 'max:6'],
            'date_format'   => ['nullable', 'string', 'max:20'],
            'pad_char'      => ['nullable', 'string', 'max:2'],
            'pad_side'      => ['nullable', 'in:left,right'],
            'uppercase'     => ['boolean'],
            'required'      => ['boolean'],
            'default_value' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * Something to render the preview against.
     *
     * Deliberately invented rather than read from a real run: a layout is usually transcribed before
     * the first file is ever produced, and a preview that only works once you have live payroll data
     * is a preview nobody sees when they need it.
     */
    private function sample(): array
    {
        return [
            'context' => [
                'company' => [
                    'name' => 'Sample Client (Pty) Ltd',
                    'account_number' => '62000000001',
                    'branch_code' => '250655',
                    'paye_reference' => '7100000001',
                ],
                'run' => [
                    'name' => 'Monthly 1 Mar 2026 – 31 Mar 2026',
                    'period_start' => '2026-03-01',
                    'period_end' => '2026-03-31',
                    'pay_date' => '2026-03-25',
                    'total_net' => 27500.00,
                ],
            ],
            'rows' => [
                [
                    'employee' => [
                        'number' => 'EMP0001', 'name' => 'Thandi Mokoena',
                        'id_number' => '9001010000088', 'tax_number' => '1234567890',
                        'account_number' => '62123456789', 'branch_code' => '250655',
                        'net_pay' => 15000.00,
                    ],
                ],
                [
                    'employee' => [
                        'number' => 'EMP0002', 'name' => 'Sipho Ndlovu',
                        'id_number' => '8805050000085', 'tax_number' => '9876543210',
                        'account_number' => '17987654321', 'branch_code' => '470010',
                        'net_pay' => 12500.00,
                    ],
                ],
            ],
        ];
    }
}
