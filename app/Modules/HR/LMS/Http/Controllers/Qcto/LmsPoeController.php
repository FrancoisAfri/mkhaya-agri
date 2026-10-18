<?php

namespace App\Modules\HR\LMS\Http\Controllers\Qcto;

use App\Core\User\Trait\FileStorage;
use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\LmsCertificate;
use App\Modules\HR\LMS\Models\Qcto\LmsPoeEvidence;
use App\Modules\HR\LMS\Models\Qcto\LmsPoePortfolio;
use App\Modules\HR\LMS\Models\Qcto\LmsPoeSection;
use App\Modules\HR\LMS\Models\Qcto\LmsRegisteredPerson;
use App\Modules\HR\LMS\Models\Qcto\LmsUnitStandard;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LmsPoeController extends Controller
{
    use FileStorage;

    public function __construct()
    {
        // Extend the allowed MIME types beyond the trait's defaults to cover
        // the full range of PoE evidence document types.
        $this->allowedMimes = [
            'image/jpeg', 'image/png', 'image/webp', 'image/gif',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.ms-powerpoint',
            'text/plain',
            'application/zip',
            'application/x-zip-compressed',
        ];
    }

    // ── Learner view of their own PoE ──────────────────────────────────────────

    public function myPortfolios()
    {
        $portfolios = LmsPoePortfolio::where('user_id', auth()->id())
            ->with(['course', 'assessor', 'moderator'])
            ->withCount('sections')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Modules/LMS/Poe', [
            'portfolios' => $portfolios,
        ]);
    }

    public function showPortfolio(LmsPoePortfolio $portfolio)
    {
        abort_unless($portfolio->user_id === auth()->id() || $this->isAdmin(), 403);

        $portfolio->load(['sections.evidence', 'sections.unitStandard', 'assessor', 'moderator', 'course']);

        return Inertia::render('Modules/LMS/PoeDetail', [
            'portfolio'     => $portfolio,
            'unitStandards' => LmsUnitStandard::orderBy('title')->get(['id', 'saqa_id', 'title', 'nqf_level', 'credits']),
        ]);
    }

    public function createPortfolio(Request $request)
    {
        $data = $request->validate([
            'course_id'                => 'nullable|exists:lms_courses,id',
            'enrollment_id'            => 'nullable|exists:lms_enrollments,id',
            'learnership_enrolment_id' => 'nullable|exists:lms_learnership_enrolments,id',
            'title'                    => 'required|string|max:200',
        ]);

        $data['user_id']      = auth()->id();
        $data['hr_person_id'] = auth()->user()?->person?->id;
        $data['created_by']   = auth()->id();

        $portfolio = LmsPoePortfolio::create($data);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($portfolio)
            ->withProperties(['title' => $portfolio->title])
            ->log('Learner created PoE portfolio "' . $portfolio->title . '".');

        return redirect()->route('lms.poe.show', $portfolio)->with('success', 'Portfolio created.');
    }

    public function declare(LmsPoePortfolio $portfolio)
    {
        abort_unless($portfolio->user_id === auth()->id(), 403);

        $portfolio->update([
            'learner_declared' => true,
            'declared_at'      => now(),
        ]);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($portfolio)
            ->log('Learner signed authenticity declaration for portfolio "' . $portfolio->title . '".');

        return back()->with('success', 'Declaration recorded.');
    }

    public function submit(LmsPoePortfolio $portfolio)
    {
        abort_unless($portfolio->user_id === auth()->id(), 403);
        abort_unless($portfolio->learner_declared, 422, 'Please complete your declaration first.');

        $portfolio->update([
            'status'       => 'submitted',
            'submitted_at' => now(),
        ]);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($portfolio)
            ->withProperties(['submitted_at' => now()->toDateTimeString()])
            ->log('Learner submitted portfolio "' . $portfolio->title . '" for assessment.');

        return back()->with('success', 'Portfolio submitted for assessment.');
    }

    // ── Sections ───────────────────────────────────────────────────────────────

    public function storeSection(Request $request, LmsPoePortfolio $portfolio)
    {
        abort_unless($portfolio->user_id === auth()->id() || $this->isAdmin(), 403);

        $data = $request->validate([
            'unit_standard_id' => 'nullable|exists:lms_unit_standards,id',
            'title'            => 'required|string|max:200',
            'description'      => 'nullable|string',
            'outcome_number'   => 'nullable|string|max:20',
            'sort_order'       => 'nullable|integer|min:0',
        ]);

        if (!empty($data['unit_standard_id'])) {
            $us = LmsUnitStandard::find($data['unit_standard_id']);
            if ($us && $us->isExpired()) {
                return back()->withErrors([
                    'unit_standard_id' => "Unit standard \"{$us->title}\" (SAQA: {$us->saqa_id}) expired on {$us->expires_at->toDateString()} and may not be used in new PoE sections.",
                ]);
            }
        }

        $data['poe_portfolio_id'] = $portfolio->id;
        LmsPoeSection::create($data);

        return back()->with('success', 'Section added.');
    }

    public function updateSection(Request $request, LmsPoeSection $section)
    {
        abort_unless($section->portfolio->user_id === auth()->id() || $this->isAdmin(), 403);

        $data = $request->validate([
            'title'               => 'required|string|max:200',
            'description'         => 'nullable|string',
            'outcome_number'      => 'nullable|string|max:20',
            'status'              => 'nullable|in:draft,submitted,reviewed,approved,returned',
            'assessment_decision' => 'nullable|in:competent,not_yet_competent',
            'assessor_notes'      => 'nullable|string',
        ]);

        $previousStatus = $section->status ?? 'draft';
        $newStatus      = $data['status'] ?? $previousStatus;

        if ($previousStatus !== $newStatus) {
            $section->logRevision($previousStatus, $newStatus, $data['assessor_notes'] ?? null);
        }

        $section->update($data);
        return back()->with('success', 'Section updated.');
    }

    public function destroySection(LmsPoeSection $section)
    {
        abort_unless($section->portfolio->user_id === auth()->id() || $this->isAdmin(), 403);
        $section->delete();
        return back()->with('success', 'Section deleted.');
    }

    // ── Evidence ───────────────────────────────────────────────────────────────

    public function uploadEvidence(Request $request, LmsPoeSection $section)
    {
        abort_unless($section->portfolio->user_id === auth()->id() || $this->isAdmin(), 403);

        $request->validate([
            'title'         => 'required|string|max:200',
            'description'   => 'nullable|string',
            'evidence_type' => 'required|in:document,image,video,link,observation,testimonial,product,report',
            'file'          => 'nullable|file|max:20480',
            'url'           => 'nullable|url|max:500',
        ]);

        $fileData  = null;
        $fileName  = null;
        $mimeType  = null;
        $sizeBytes = null;

        if ($request->hasFile('file')) {
            try {
                $stored = $this->saveFileToDatabase($request->file('file'));
                $fileData  = $stored['data'];
                $fileName  = $stored['original_name'];
                $mimeType  = $stored['mime'];
                $sizeBytes = $stored['size'];
            } catch (\InvalidArgumentException $e) {
                return back()->withErrors(['file' => $e->getMessage()]);
            }
        }

        // Detect if we're replacing an existing evidence item by title+type (versioning)
        $existing = LmsPoeEvidence::where('poe_section_id', $section->id)
            ->where('title', $request->title)
            ->where('evidence_type', $request->evidence_type)
            ->whereNull('previous_version_id')
            ->latest()
            ->first();

        $evidence = new LmsPoeEvidence([
            'poe_section_id'      => $section->id,
            'title'               => $request->title,
            'description'         => $request->description,
            'evidence_type'       => $request->evidence_type,
            'file_name'           => $fileName,
            'mime_type'           => $mimeType,
            'size_bytes'          => $sizeBytes,
            'url'                 => $request->url,
            'uploaded_by'         => auth()->id(),
            'version'             => $existing ? ($existing->version + 1) : 1,
            'previous_version_id' => $existing?->id,
        ]);

        // Evidence can also be a URL rather than an upload, so there are not always bytes.
        if ($fileData !== null) {
            $evidence->writeBytes(base64_decode($fileData), pathinfo((string) $fileName, PATHINFO_EXTENSION));
        }

        $evidence->save();

        activity()
            ->causedBy(auth()->user())
            ->performedOn($evidence)
            ->withProperties([
                'section'       => $section->title,
                'evidence_type' => $evidence->evidence_type,
                'file_name'     => $fileName,
            ])
            ->log('Evidence "' . $evidence->title . '" uploaded to section "' . $section->title . '".');

        return back()->with('success', 'Evidence uploaded.');
    }

    public function downloadEvidence(LmsPoeEvidence $evidence)
    {
        abort_unless(
            $evidence->section->portfolio->user_id === auth()->id() || $this->isAdmin(),
            403
        );
        abort_unless($evidence->hasBytes() && $evidence->file_name, 404);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($evidence)
            ->log('Evidence "' . $evidence->title . '" downloaded.');

        return $evidence->fileResponse($evidence->file_name, $evidence->mime_type);
    }

    public function destroyEvidence(LmsPoeEvidence $evidence)
    {
        abort_unless($evidence->section->portfolio->user_id === auth()->id() || $this->isAdmin(), 403);

        $title   = $evidence->title;
        $section = $evidence->section->title;

        $evidence->delete();

        activity()
            ->causedBy(auth()->user())
            ->log("Evidence \"{$title}\" removed from section \"{$section}\".");

        return back()->with('success', 'Evidence removed.');
    }

    // ── Admin assessment ───────────────────────────────────────────────────────

    public function adminIndex()
    {
        $portfolios = LmsPoePortfolio::with(['user', 'course', 'assessor'])
            ->orderBy('status')
            ->orderByDesc('submitted_at')
            ->get();

        $assessors  = LmsRegisteredPerson::assessors()->active()->get(['id', 'first_name', 'last_name']);
        $moderators = LmsRegisteredPerson::moderators()->active()->get(['id', 'first_name', 'last_name']);

        return Inertia::render('Modules/LMS/Admin/PoeAdmin', [
            'portfolios' => $portfolios,
            'assessors'  => $assessors,
            'moderators' => $moderators,
        ]);
    }

    public function assignAssessor(Request $request, LmsPoePortfolio $portfolio)
    {
        $data = $request->validate([
            'assessor_id'  => 'required|exists:lms_registered_persons,id',
            'moderator_id' => 'nullable|exists:lms_registered_persons,id',
        ]);

        // QCTO: assessor registration must be current.
        $assessor = LmsRegisteredPerson::find($data['assessor_id']);
        if ($assessor && $assessor->isExpired()) {
            return back()->withErrors([
                'assessor_id' => "Assessor \"{$assessor->full_name}\" (reg. {$assessor->registration_number}) SETA registration expired on {$assessor->expires_at->toDateString()}. Only assessors with a current registration may sign off PoE assessments.",
            ]);
        }

        // QCTO/SAQA Moderation Policy: assessor and moderator must be different persons.
        if (!empty($data['moderator_id']) && $data['moderator_id'] == $data['assessor_id']) {
            return back()->withErrors([
                'moderator_id' => 'The moderator must be a different registered person from the assessor (QCTO separation-of-roles requirement).',
            ]);
        }

        // QCTO: moderator registration must be current.
        if (!empty($data['moderator_id'])) {
            $moderator = LmsRegisteredPerson::find($data['moderator_id']);
            if ($moderator && $moderator->isExpired()) {
                return back()->withErrors([
                    'moderator_id' => "Moderator \"{$moderator->full_name}\" (reg. {$moderator->registration_number}) SETA registration expired on {$moderator->expires_at->toDateString()}.",
                ]);
            }
        }

        $portfolio->update(array_merge($data, ['status' => 'under_review']));

        activity()
            ->causedBy(auth()->user())
            ->performedOn($portfolio)
            ->withProperties(['assessor_id' => $data['assessor_id'], 'moderator_id' => $data['moderator_id'] ?? null])
            ->log('Assessor assigned to portfolio "' . $portfolio->title . '".');

        return back()->with('success', 'Assessor assigned.');
    }

    public function assess(Request $request, LmsPoePortfolio $portfolio)
    {
        $data = $request->validate([
            'assessment_decision' => 'required|in:competent,not_yet_competent,insufficient_evidence',
            'assessor_feedback'   => 'nullable|string',
        ]);

        $portfolio->update(array_merge($data, [
            'assessed_at' => now(),
            'status'      => $data['assessment_decision'] === 'competent' ? 'approved' : 'returned',
        ]));

        activity()
            ->causedBy(auth()->user())
            ->performedOn($portfolio)
            ->withProperties(['decision' => $data['assessment_decision'], 'feedback' => $data['assessor_feedback']])
            ->log('Assessment decision "' . $data['assessment_decision'] . '" recorded for portfolio "' . $portfolio->title . '".');

        return back()->with('success', 'Assessment recorded.');
    }

    public function moderate(Request $request, LmsPoePortfolio $portfolio)
    {
        $data = $request->validate([
            'moderation_decision' => 'required|in:agreed,different',
            'moderator_feedback'  => 'nullable|string',
        ]);

        $portfolio->update(array_merge($data, [
            'moderated_at' => now(),
            'status'       => $data['moderation_decision'] === 'agreed' ? 'certified' : 'returned',
        ]));

        if ($data['moderation_decision'] === 'agreed') {
            $portfolio->loadMissing('course');
            $certNumber = strtoupper('POE-' . str_pad($portfolio->id, 6, '0', STR_PAD_LEFT));

            // Idempotent: only create if no certificate for this portfolio exists yet.
            if (!LmsCertificate::where('certificate_number', $certNumber)->exists()) {
                LmsCertificate::create([
                    'enrollment_id'      => $portfolio->enrollment_id,
                    'hr_person_id'       => $portfolio->hr_person_id,
                    'poe_portfolio_id'   => $portfolio->id,
                    'nqf_level'          => $portfolio->course?->nqf_level,
                    'saqa_qual_id'       => $portfolio->course?->saqa_id,
                    'issued_at'          => now(),
                    'issued_by'          => auth()->id(),
                    'certificate_number' => $certNumber,
                ]);
            }
        }

        activity()
            ->causedBy(auth()->user())
            ->performedOn($portfolio)
            ->withProperties(['decision' => $data['moderation_decision']])
            ->log('Moderation decision "' . $data['moderation_decision'] . '" recorded for portfolio "' . $portfolio->title . '".');

        return back()->with('success', 'Moderation recorded.');
    }

    private function isAdmin(): bool
    {
        $role = strtolower(auth()->user()?->role ?? '');
        return in_array($role, ['super admin', 'administrator', 'hr', 'lms_admin']);
    }
}
