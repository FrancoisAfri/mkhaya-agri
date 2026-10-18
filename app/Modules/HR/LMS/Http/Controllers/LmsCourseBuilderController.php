<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\LmsCourse;
use App\Modules\HR\LMS\Models\Qcto\LmsSeta;
use App\Modules\HR\LMS\Models\LmsCourseModule;
use App\Modules\HR\LMS\Models\LmsCourseMaterial;
use App\Modules\HR\LMS\Models\LmsCoursePrerequisite;
use App\Modules\HR\LMS\Models\LmsLesson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class LmsCourseBuilderController extends Controller
{
    // ── Builder page ─────────────────────────────────────────────────────────

    public function show(LmsCourse $course): Response
    {
        $course->load([
            'modules.lessons.materials',
            'prerequisites.prerequisiteCourse',
            'category',
        ]);

        $allPublishedCourses = LmsCourse::where('id', '!=', $course->id)
            ->orderBy('title')
            ->get(['id', 'title', 'type']);

        return Inertia::render('Modules/LMS/Admin/CourseBuilder', [
            'course' => [
                'id'                 => $course->id,
                'uuid'               => $course->uuid,
                'title'              => $course->title,
                'description'        => $course->description,
                'type'               => $course->type,
                'duration_mins'      => $course->duration_mins,
                'pass_score'         => $course->pass_score,
                'expires_after_days' => $course->expires_after_days,
                'is_mandatory'       => $course->is_mandatory,
                'is_published'       => $course->is_published,
                'category'                     => $course->category
                    ? ['id' => $course->category->id, 'name' => $course->category->name]
                    : null,
                'nqf_level'                    => $course->nqf_level,
                'credits'                      => $course->credits,
                'saqa_id'                      => $course->saqa_id,
                'unit_standard_code'           => $course->unit_standard_code,
                'ofo_code'                     => $course->ofo_code,
                'seta_id'                      => $course->seta_id,
                'seta_accreditation_number'    => $course->seta_accreditation_number,
                'seta_accreditation_expires_at'=> $course->seta_accreditation_expires_at?->toDateString(),
                'qualification_component'      => $course->qualification_component,
                'is_qcto_registered'           => $course->is_qcto_registered,
                'is_accredited'                => $course->is_accredited,
                'modules' => $course->modules->map(fn ($m) => [
                    'id'          => $m->id,
                    'title'       => $m->title,
                    'description' => $m->description,
                    'sort_order'  => $m->sort_order,
                    'lessons'     => $m->lessons->map(fn ($l) => [
                        'id'           => $l->id,
                        'title'        => $l->title,
                        'type'         => $l->type,
                        'content_body' => $l->content_body,
                        'external_url' => $l->external_url,
                        'duration_mins'=> $l->duration_mins,
                        'sort_order'   => $l->sort_order,
                        'is_preview'   => $l->is_preview,
                        'materials'    => $l->materials->map(fn ($mat) => [
                            'id'         => $mat->id,
                            'title'      => $mat->title,
                            'type'       => $mat->type,
                            'file_name'  => $mat->file_name,
                            'mime_type'  => $mat->mime_type,
                            'size_mb'    => $mat->size_mb,
                            'url'        => $mat->url,
                            'sort_order' => $mat->sort_order,
                        ]),
                    ]),
                ]),
                'prerequisites' => $course->prerequisites->map(fn ($p) => [
                    'id'    => $p->id,
                    'course'=> [
                        'id'    => $p->prerequisiteCourse->id,
                        'title' => $p->prerequisiteCourse->title,
                        'type'  => $p->prerequisiteCourse->type,
                    ],
                ]),
            ],
            'availableCourses' => $allPublishedCourses->values(),
            'setas'            => LmsSeta::orderBy('acronym')->get(['id', 'name', 'acronym']),
        ]);
    }

    // ── Module CRUD ──────────────────────────────────────────────────────────

    public function storeModule(Request $request, LmsCourse $course): RedirectResponse
    {
        $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $course->modules()->create([
            'title'       => $request->title,
            'description' => $request->description,
            'sort_order'  => $course->modules()->max('sort_order') + 1,
        ]);

        return redirect()->back()->with('success', 'Module created.');
    }

    public function updateModule(Request $request, LmsCourse $course, LmsCourseModule $module): RedirectResponse
    {
        abort_unless($module->course_id === $course->id, 404);

        $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $module->update($request->only('title', 'description'));

        return redirect()->back()->with('success', 'Module updated.');
    }

    public function destroyModule(LmsCourse $course, LmsCourseModule $module): RedirectResponse
    {
        abort_unless($module->course_id === $course->id, 404);
        $module->delete();

        return redirect()->back()->with('success', 'Module deleted.');
    }

    public function reorderModules(Request $request, LmsCourse $course): RedirectResponse
    {
        $request->validate([
            'order'   => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        foreach ($request->order as $index => $moduleId) {
            LmsCourseModule::where('id', $moduleId)
                ->where('course_id', $course->id)
                ->update(['sort_order' => $index]);
        }

        return redirect()->back()->with('success', 'Modules reordered.');
    }

    // ── Lesson CRUD ──────────────────────────────────────────────────────────

    public function storeLesson(Request $request, LmsCourse $course, LmsCourseModule $module): RedirectResponse
    {
        abort_unless($module->course_id === $course->id, 404);

        $request->validate([
            'title'        => ['required', 'string', 'max:255'],
            'type'         => ['required', 'in:video,pdf,document,presentation,text,link'],
            'content_body' => ['nullable', 'string'],
            'external_url' => ['nullable', 'string', 'max:1000'],
            'duration_mins'=> ['nullable', 'integer', 'min:0'],
            'is_preview'   => ['boolean'],
        ]);

        $module->lessons()->create([
            'course_id'    => $course->id,
            'title'        => $request->title,
            'type'         => $request->type,
            'content_body' => $request->content_body,
            'external_url' => $request->external_url,
            'duration_mins'=> $request->duration_mins ?? 0,
            'sort_order'   => $module->lessons()->max('sort_order') + 1,
            'is_preview'   => $request->boolean('is_preview'),
        ]);

        return redirect()->back()->with('success', 'Lesson created.');
    }

    public function updateLesson(Request $request, LmsCourse $course, LmsLesson $lesson): RedirectResponse
    {
        abort_unless($lesson->course_id === $course->id, 404);

        $request->validate([
            'title'        => ['required', 'string', 'max:255'],
            'type'         => ['required', 'in:video,pdf,document,presentation,text,link'],
            'content_body' => ['nullable', 'string'],
            'external_url' => ['nullable', 'string', 'max:1000'],
            'duration_mins'=> ['nullable', 'integer', 'min:0'],
            'is_preview'   => ['boolean'],
        ]);

        $lesson->update([
            'title'        => $request->title,
            'type'         => $request->type,
            'content_body' => $request->content_body,
            'external_url' => $request->external_url,
            'duration_mins'=> $request->duration_mins ?? 0,
            'is_preview'   => $request->boolean('is_preview'),
        ]);

        return redirect()->back()->with('success', 'Lesson updated.');
    }

    public function destroyLesson(LmsCourse $course, LmsLesson $lesson): RedirectResponse
    {
        abort_unless($lesson->course_id === $course->id, 404);

        foreach ($lesson->materials as $material) {
            if ($material->disk !== 'database') {
                Storage::disk($material->disk)->delete($material->path);
            }
        }

        $lesson->delete();

        return redirect()->back()->with('success', 'Lesson deleted.');
    }

    public function reorderLessons(Request $request, LmsCourse $course, LmsCourseModule $module): RedirectResponse
    {
        abort_unless($module->course_id === $course->id, 404);

        $request->validate([
            'order'   => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        foreach ($request->order as $index => $lessonId) {
            LmsLesson::where('id', $lessonId)
                ->where('module_id', $module->id)
                ->update(['sort_order' => $index]);
        }

        return redirect()->back()->with('success', 'Lessons reordered.');
    }

    // ── Material upload ──────────────────────────────────────────────────────

    public function uploadMaterial(Request $request, LmsCourse $course, LmsLesson $lesson): RedirectResponse
    {
        abort_unless($lesson->course_id === $course->id, 404);

        $request->validate([
            'file'  => ['required', 'file', 'max:512000'], // 500 MB
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');
        $mime = $file->getMimeType() ?? 'application/octet-stream';
        $type = LmsCourseMaterial::typeFromMime($mime);

        if ($type === 'video') {
            $dir  = "lms/courses/{$course->id}/lessons/{$lesson->id}";
            $path = Storage::disk('local')->putFile($dir, $file);
            $disk = 'local';
        } else {
            $path = LmsCourseMaterial::encodeForDatabase($file);
            $disk = 'database';
        }

        $lesson->materials()->create([
            'course_id'   => $course->id,
            'title'       => $request->title ?: $file->getClientOriginalName(),
            'type'        => $type,
            'disk'        => $disk,
            'path'        => $path,
            'file_name'   => $file->getClientOriginalName(),
            'mime_type'   => $mime,
            'size_bytes'  => $file->getSize(),
            'sort_order'  => $lesson->materials()->max('sort_order') + 1,
            'uploaded_by' => Auth::id(),
        ]);

        return redirect()->back()->with('success', 'File uploaded successfully.');
    }

    public function destroyMaterial(LmsCourse $course, LmsLesson $lesson, LmsCourseMaterial $material): RedirectResponse
    {
        abort_unless($material->lesson_id === $lesson->id && $material->course_id === $course->id, 404);

        if ($material->disk !== 'database') {
            Storage::disk($material->disk)->delete($material->path);
        }
        $material->delete();

        return redirect()->back()->with('success', 'Material deleted.');
    }

    // ── Prerequisites ────────────────────────────────────────────────────────

    public function storePrerequisite(Request $request, LmsCourse $course): RedirectResponse
    {
        $request->validate([
            'prerequisite_course_id' => [
                'required',
                'integer',
                'exists:lms_courses,id',
                function ($attr, $value, $fail) use ($course) {
                    if ((int) $value === $course->id) {
                        $fail('A course cannot be its own prerequisite.');
                    }
                },
            ],
        ]);

        LmsCoursePrerequisite::firstOrCreate([
            'course_id'              => $course->id,
            'prerequisite_course_id' => $request->prerequisite_course_id,
        ]);

        return redirect()->back()->with('success', 'Prerequisite added.');
    }

    public function destroyPrerequisite(LmsCourse $course, LmsCoursePrerequisite $prerequisite): RedirectResponse
    {
        abort_unless($prerequisite->course_id === $course->id, 404);
        $prerequisite->delete();

        return redirect()->back()->with('success', 'Prerequisite removed.');
    }

    // ── Course settings ──────────────────────────────────────────────────────

    public function updateSettings(Request $request, LmsCourse $course): RedirectResponse
    {
        $request->validate([
            'title'              => ['required', 'string', 'max:255'],
            'description'        => ['nullable', 'string'],
            'type'               => ['required', 'in:video,document,quiz,scorm,external'],
            'duration_mins'      => ['nullable', 'integer', 'min:0'],
            'pass_score'         => ['nullable', 'integer', 'min:0', 'max:100'],
            'expires_after_days' => ['nullable', 'integer', 'min:1'],
            'is_mandatory'       => ['boolean'],
            'is_published'       => ['boolean'],
            // QCTO / NQF / SETA
            'nqf_level'                    => ['nullable', 'integer', 'min:1', 'max:10'],
            'credits'                      => ['nullable', 'numeric', 'min:0'],
            'saqa_id'                      => ['nullable', 'string', 'max:20'],
            'unit_standard_code'           => ['nullable', 'string', 'max:40'],
            'ofo_code'                     => ['nullable', 'string', 'max:7', 'regex:/^\d{7}$/'],
            'seta_id'                      => ['nullable', 'exists:lms_setas,id'],
            'seta_accreditation_number'    => ['nullable', 'string', 'max:80'],
            'seta_accreditation_expires_at'=> ['nullable', 'date'],
            'qualification_component'      => ['nullable', 'in:knowledge,practical,work_experience'],
            'is_qcto_registered'           => ['boolean'],
            'is_accredited'                => ['boolean'],
        ]);

        // QCTO: cannot publish an accredited course whose SETA accreditation has expired.
        if ($request->boolean('is_published')
            && $request->boolean('is_accredited')
            && $request->filled('seta_accreditation_expires_at')
            && now()->gt($request->date('seta_accreditation_expires_at'))
        ) {
            return back()->withErrors([
                'is_published' => 'This course cannot be published — its SETA accreditation expired on ' . $request->date('seta_accreditation_expires_at')->toDateString() . '. Renew accreditation before publishing.',
            ]);
        }

        $course->update([
            'title'              => $request->title,
            'description'        => $request->description,
            'type'               => $request->type,
            'duration_mins'      => $request->duration_mins,
            'pass_score'         => $request->pass_score,
            'expires_after_days' => $request->expires_after_days,
            'is_mandatory'       => $request->boolean('is_mandatory'),
            'is_published'       => $request->boolean('is_published'),
            // QCTO / NQF / SETA
            'nqf_level'                    => $request->nqf_level,
            'credits'                      => $request->credits,
            'saqa_id'                      => $request->saqa_id,
            'unit_standard_code'           => $request->unit_standard_code,
            'ofo_code'                     => $request->ofo_code,
            'seta_id'                      => $request->seta_id,
            'seta_accreditation_number'    => $request->seta_accreditation_number,
            'seta_accreditation_expires_at'=> $request->seta_accreditation_expires_at,
            'qualification_component'      => $request->qualification_component,
            'is_qcto_registered'           => $request->boolean('is_qcto_registered'),
            'is_accredited'                => $request->boolean('is_accredited'),
        ]);

        return redirect()->back()->with('success', 'Course settings saved.');
    }
}
