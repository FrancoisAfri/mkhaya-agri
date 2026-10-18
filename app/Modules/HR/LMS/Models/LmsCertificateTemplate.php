<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LmsCertificateTemplate extends Model
{
    use SoftDeletes;

    protected $table = 'lms_certificate_templates';

    protected $fillable = [
        'name', 'description', 'course_id',
        'orientation', 'background_color', 'accent_color',
        'background_image', 'logo_url',
        'title_text', 'subtitle_text', 'body_html',
        'signatory_1_name', 'signatory_1_title', 'signatory_1_signature',
        'signatory_2_name', 'signatory_2_title', 'signatory_2_signature',
        'requires_understanding_score', 'min_understanding_score',
        'is_active', 'created_by',
    ];

    protected $casts = [
        'requires_understanding_score' => 'boolean',
        'is_active'                    => 'boolean',
        'min_understanding_score'      => 'integer',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(LmsCertification::class, 'template_id');
    }

    /**
     * Render the body_html replacing placeholders with actual values.
     */
    public function render(array $vars): string
    {
        $html = $this->body_html ?? '';
        foreach ($vars as $key => $value) {
            $html = str_replace("{{{$key}}}", (string) $value, $html);
        }
        return $html;
    }
}
