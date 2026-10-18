<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentGeneration;
use App\Modules\Operations\DocumentManagement\Models\DocumentTemplate;
use App\Modules\Operations\DocumentManagement\Models\DocumentVersion;
use App\Modules\Operations\DocumentManagement\Support\DocumentRelations;
use App\Modules\Operations\DocumentManagement\Support\MergeFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turning a template and a record into a document.
 *
 * The generated file is a PDF and the document is a document like any other — versioned,
 * permissioned, searchable, linked to the record it was generated about. Nothing downstream needs
 * to know it was generated rather than uploaded, which is what stops this becoming a second kind of
 * document with its own rules.
 */
class GenerationService
{
    /**
     * Shows what would be produced, without producing it.
     *
     * The requirement asks for extraction to be confirmed before saving; the same courtesy applies
     * here for the opposite reason — a contract generated with an empty salary line is worse than
     * one that was never generated, and the preview is where somebody sees the gap.
     *
     * @return array{html: string, values: array<string,string>, missing: list<string>, unknown: list<string>}
     */
    public function preview(DocumentTemplate $template, ?Model $subject): array
    {
        $resolved = MergeFields::resolve($template->subject_relation, $subject);

        return [
            'html' => MergeFields::fill((string) $template->body, $resolved['values']),
            'values' => $resolved['values'],
            // Fields the record could not answer — a real gap in the data.
            'missing' => $resolved['missing'],
            // Fields the template asks for that do not exist at all — usually a typo.
            'unknown' => $template->unknownFields(),
        ];
    }

    /**
     * Generates the document.
     *
     * @return array{document: ?Document, error: ?string}
     */
    public function generate(DocumentTemplate $template, ?Model $subject, array $options = []): array
    {
        if (blank($template->body)) {
            return ['document' => null, 'error' => __('document_management.templates.empty_body')];
        }

        $preview = $this->preview($template, $subject);

        /*
         * A missing field stops the generation unless somebody says otherwise.
         *
         * The default is to refuse, because the failure it prevents — a signed contract with a
         * blank where the salary should be — is worse than the inconvenience of being asked. The
         * override exists because sometimes the gap is genuinely fine, and refusing forever would
         * push people back to typing the document by hand.
         */
        if ($preview['missing'] !== [] && ! ($options['allow_missing'] ?? false)) {
            return ['document' => null, 'error' => __('document_management.templates.missing_fields', [
                'fields' => implode(', ', $preview['missing']),
            ])];
        }

        return DB::transaction(function () use ($template, $subject, $preview, $options) {
            $title = $this->titleFor($template, $preview['values'], $options);
            $binary = $this->render($preview['html'], $title);

            $document = Document::create([
                'title' => $title,
                'description' => $options['description'] ?? null,
                'folder_id' => $options['folder_id'] ?? $template->folder_id,
                'category_id' => $template->category_id,
                'status' => Document::STATUS_ACTIVE,
                // Generated, not approved. A document that appeared already published would skip
                // whatever review the organisation put in place for that kind of document.
                'approval_status' => Document::APPROVAL_DRAFT,
                'version_count' => 1,
                'document_type' => $template->name,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
                'owner_id' => Auth::id(),
            ]);

            $version = $this->writeVersion($document, $binary, $title);

            $document->forceFill([
                'current_version_id' => $version->id,
            ])->save();

            // Linked to whatever it was generated about, so it appears on that record straight away
            // rather than needing to be filed by hand.
            if ($subject) {
                $document->linkTo($subject, DocumentRelations::forModel($subject) ?? $template->subject_relation);
            }

            DocumentGeneration::create([
                'template_id' => $template->id,
                'document_id' => $document->id,
                'subject_type' => $subject ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'merge_values' => $preview['values'],
                'missing_fields' => $preview['missing'],
                'generated_by' => Auth::id(),
            ]);

            app(DocumentService::class)->record($document, DocumentEvent::GENERATED, $version, [
                'template' => $template->name,
                'missing' => $preview['missing'],
            ]);

            return ['document' => $document->fresh(), 'error' => null];
        });
    }

    /**
     * The generated file.
     *
     * dompdf rather than a headless browser: it is already a dependency, it needs no binary on the
     * host, and a template is HTML we sanitised on the way in rather than an arbitrary page.
     */
    private function render(string $html, string $title): string
    {
        $document = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($this->wrap($html, $title));

        return $document->setPaper('a4')->output();
    }

    /** Page furniture around the template body, so a generated document looks like a document. */
    private function wrap(string $html, string $title): string
    {
        $safeTitle = e($title);

        return <<<HTML
        <!DOCTYPE html>
        <html><head><meta charset="utf-8"><title>{$safeTitle}</title>
        <style>
            /* Serif for the body: these are printed and signed, and a contract set in a UI font
               reads like a web page rather than an agreement. */
            body { font-family: DejaVu Serif, Georgia, serif; font-size: 11pt; line-height: 1.55; color: #1a1a1a; }
            h1, h2, h3 { font-family: DejaVu Sans, Helvetica, sans-serif; line-height: 1.25; }
            h1 { font-size: 16pt; } h2 { font-size: 13pt; } h3 { font-size: 11.5pt; }
            table { width: 100%; border-collapse: collapse; margin: 0.6rem 0; }
            th, td { border: 1px solid #bbb; padding: 5px 7px; text-align: left; vertical-align: top; }
            th { background: #f2f2f2; }
            /* Signature blocks and clause headings must not be orphaned across a page break. */
            h1, h2, h3, tr { page-break-inside: avoid; }
        </style></head>
        <body>{$html}</body></html>
        HTML;
    }

    private function writeVersion(Document $document, string $binary, string $title): DocumentVersion
    {
        $storedName = Str::uuid() . '.pdf';
        $path = 'documents/' . $document->uuid . '/' . $storedName;

        Storage::disk('documents')->put($path, $binary);

        return DocumentVersion::create([
            'document_id' => $document->id,
            'version' => 1,
            'major' => 1,
            'minor' => 0,
            'original_name' => Str::of($title)->replaceMatches('/[^\w.\- ]+/u', '_')->limit(200, '')->trim()->value() . '.pdf',
            'stored_name' => $storedName,
            'disk' => 'documents',
            'path' => $path,
            'extension' => 'pdf',
            'mime' => 'application/pdf',
            'size' => strlen($binary),
            'checksum' => hash('sha256', $binary),
            'change_note' => __('document_management.templates.generated_note'),
            'uploaded_by' => Auth::id(),
        ]);
    }

    /**
     * What the generated document is called.
     *
     * The pattern takes merge fields too, so a contract is filed as "Employment contract — J
     * Dlamini" rather than as the third copy of the same name.
     *
     * @param  array<string, string>  $values
     */
    private function titleFor(DocumentTemplate $template, array $values, array $options): string
    {
        if (filled($options['title'] ?? null)) {
            return (string) $options['title'];
        }

        $pattern = $template->filename_pattern ?: $template->name;
        $filled = trim(strip_tags(MergeFields::fill($pattern, $values)));

        // A pattern whose fields all came back empty would leave the placeholders in the filename.
        return $filled !== '' && ! str_contains($filled, '{{') ? $filled : $template->name;
    }
}
