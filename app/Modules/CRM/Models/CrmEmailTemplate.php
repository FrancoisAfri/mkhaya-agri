<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CrmEmailTemplate extends Model
{
    protected $table = 'crm_email_templates';

    /** URLs reference the uuid, never the sequential id. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = ['uuid', 'name', 'key', 'category', 'subject', 'body', 'active', 'created_by'];

    protected $casts = ['active' => 'boolean'];

    public const CATEGORIES = ['lead', 'deal', 'complaint', 'campaign', 'general'];

    /**
     * Merge tags available to author templates with.
     *
     * `sender_company` is our company; `company` is the recipient's. `company_name` used to be
     * offered here for the first of those and could never have worked: MergeFields canonicalises
     * "company name" to the RECIPIENT's company, so a template reading "I'm Ann from
     * {{company_name}}" told the prospect we worked for them. It resolved, it read naturally, and
     * it was wrong — the kind of mistake a test send does not reveal, because the tester knows
     * which company is which.
     */
    public const MERGE_TAGS = [
        'first_name', 'last_name', 'full_name', 'company', 'email', 'phone',
        'rep_name', 'sender_company', 'subject',
    ];

    /** Fetch an active system template by its stable key. */
    public static function byKey(string $key): ?self
    {
        return static::where('key', $key)->where('active', true)->first();
    }

    /** Replace {{merge_tags}} in a string with values (missing tags → ''). */
    /**
     * Fills a template with the recipient's details.
     *
     * This used to handle `{{token}}` only, and replaced anything it did not recognise with an
     * empty string — so a letter written with `[Name]`, which is what people actually type, went
     * out either as a literal `[Name]` or as "Good Day " with nothing said about it.
     *
     * The shared resolver takes every notation in use, leaves a placeholder it cannot fill visible
     * rather than blanking it, and tidies a greeting left without its name.
     */
    public static function merge(string $text, array $data): string
    {
        $merge = app(\App\Core\Messaging\Services\MergeFields::class);

        return $merge->tidyGreeting($merge->render($text, $data));
    }

    /** The same merge, with the placeholders it could not fill reported rather than hidden. */
    public static function mergeChecked(string $text, array $data): array
    {
        return app(\App\Core\Messaging\Services\MergeFields::class)->apply($text, $data);
    }

    /** Render this template's subject + body against the given data. */
    public function render(array $data): array
    {
        return [
            'subject' => self::merge($this->subject, $data),
            'body'    => self::merge($this->body, $data),
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $t) {
            $t->uuid ??= (string) Str::uuid();
        });
    }
}
