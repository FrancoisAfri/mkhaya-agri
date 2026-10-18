<?php

namespace App\Core\Messaging\Services;

use Illuminate\Support\Str;

/**
 * Puts the recipient's details into a message, whatever notation the writer used.
 *
 * Both modules only ever substituted `{{first_name}}`. Nobody writing an email types that — they
 * type `[Name]`, because that is what every email template anybody has ever seen uses. So a
 * perfectly good letter went out to prospects reading "Good Day [Name]", and the CRM's version was
 * worse: an unrecognised token was replaced with an empty string, so the same letter went out as
 * "Good Day " and nothing anywhere said a word about it.
 *
 * Two things follow from that. The resolver accepts the notations people actually use, and it
 * reports what it could not fill in — because a merge failure is not a formatting preference, it
 * is a message that should not be sent.
 */
class MergeFields
{
    /**
     * The names a writer might reasonably use for the same thing.
     *
     * Keyed by what it resolves to. Matching is case-insensitive and ignores spaces, underscores
     * and hyphens, so "First Name", "first_name" and "firstname" are one token.
     */
    private const ALIASES = [
        'first_name' => ['name', 'firstname', 'first', 'fname', 'givenname', 'contactname', 'recipientname'],
        'last_name'  => ['lastname', 'surname', 'lname', 'familyname'],
        'full_name'  => ['fullname', 'contactfullname'],
        'company'    => ['company', 'companyname', 'organisation', 'organization', 'business', 'account'],
        'email'      => ['email', 'emailaddress'],
        'phone'      => ['phone', 'telephone', 'mobile', 'cell'],
        'title'      => ['title', 'jobtitle', 'position', 'role'],
        // rep_name is what the CRM's sequence templates have always signed off with, and every
        // seeded cadence uses it. Without it here the token survives the merge and the prospect
        // receives an email signed "{{rep_name}}".
        'sender'     => ['sender', 'sendername', 'fromname', 'myname', 'repname', 'rep', 'salesrep', 'accountmanager'],
        'sender_company' => ['sendercompany', 'ourcompany'],
    ];

    /**
     * Every placeholder notation seen in real templates.
     *
     * Square brackets first because that is what a person writing by hand uses, and it was the one
     * notation neither module handled.
     */
    private const PATTERNS = [
        '/\[\s*([A-Za-z][A-Za-z _-]{0,40}?)\s*\]/',      // [Name], [First Name]
        '/\{\{\s*([A-Za-z][A-Za-z _-]{0,40}?)\s*\}\}/',  // {{first_name}}
        '/\{\s*([A-Za-z][A-Za-z _-]{0,40}?)\s*\}/',      // {Name}
        '/%\s*([A-Za-z][A-Za-z _-]{0,40}?)\s*%/',        // %NAME%
    ];

    /**
     * @param  array  $data  first_name, last_name, company, email, … whatever is known
     * @return array{text: string, unresolved: string[]}
     */
    public function apply(string $template, array $data): array
    {
        $values = $this->normalise($data);
        $unresolved = [];

        $text = $template;
        foreach (self::PATTERNS as $pattern) {
            $text = preg_replace_callback($pattern, function ($m) use ($values, &$unresolved) {
                $key = $this->canonical($m[1]);
                $value = $key !== null ? ($values[$key] ?? '') : '';

                if ($value === '') {
                    // Left in place, not blanked. A visible [Name] tells somebody the merge failed;
                    // an empty string produces "Good Day " and nobody ever notices.
                    $unresolved[] = trim($m[1]);

                    return $m[0];
                }

                return $value;
            }, $text) ?? $text;
        }

        return ['text' => $text, 'unresolved' => array_values(array_unique($unresolved))];
    }

    /** The usual case: just the text, with whatever could be filled in filled in. */
    public function render(string $template, array $data): string
    {
        return $this->apply($template, $data)['text'];
    }

    /**
     * Tidies a greeting whose name could not be resolved.
     *
     * "Good day [Name]," reads as broken and "Good day ," reads as careless. Falling back to
     * "Good day," is the only one of the three a person would have written themselves.
     */
    public function tidyGreeting(string $text): string
    {
        // A placeholder still sitting next to a greeting, with or without punctuation after it.
        $text = preg_replace(
            '/\b(good day|good morning|good afternoon|hi|hello|dear|hey)\b[ \t]*(\[[^\]]{0,40}\]|\{\{[^}]{0,40}\}\}|\{[^}]{0,40}\})\s*([,.!]?)/i',
            '$1$3',
            $text,
        ) ?? $text;

        // "Good day ," left by an older template that blanked the token instead of keeping it.
        return preg_replace('/\b(good day|good morning|good afternoon|hi|hello|dear|hey)[ \t]+([,.!])/i', '$1$2', $text) ?? $text;
    }

    /**
     * Everything known about a recipient, in the shape this resolver expects.
     *
     * Takes the record each module already has rather than making the caller assemble one, so the
     * two modules cannot drift apart on what "the recipient's name" means.
     */
    public function forRecipient(?string $name, ?string $company = null, ?string $email = null, array $extra = []): array
    {
        $name = trim((string) $name);
        $first = $name !== '' ? Str::before($name, ' ') : '';
        $last = $name !== '' && str_contains($name, ' ') ? trim(Str::after($name, ' ')) : '';

        return array_filter(array_merge([
            'first_name' => $first,
            'last_name'  => $last,
            'full_name'  => $name,
            'company'    => trim((string) $company),
            'email'      => trim((string) $email),
        ], $extra), fn ($v) => $v !== null);
    }

    /** @return array<string,string> */
    private function normalise(array $data): array
    {
        $values = [];
        $exact = [];

        foreach ($data as $key => $value) {
            $canonical = $this->canonical((string) $key);
            if ($canonical === null || blank($value)) {
                continue;
            }

            // An exactly-named key beats one that only matched an alias, and is never overwritten
            // by one. Without this, a record carrying both `company` (the recipient's) and
            // `company_name` (ours) resolved both to the same slot in array order — so whichever
            // came last won, and `{{company}}` in a prospecting email rendered *our* company name
            // to the prospect. Wrong, plausible, and impossible to spot in a test send.
            $isExact = str_replace('_', '', strtolower((string) $key)) === str_replace('_', '', $canonical);

            if (isset($values[$canonical]) && ! $isExact) {
                continue;
            }
            if (isset($exact[$canonical]) && ! $isExact) {
                continue;
            }

            $values[$canonical] = (string) $value;
            if ($isExact) {
                $exact[$canonical] = true;
            }
        }

        // A template asking for the full name when only a first name is known should still work.
        if (empty($values['full_name']) && ! empty($values['first_name'])) {
            $values['full_name'] = trim($values['first_name'] . ' ' . ($values['last_name'] ?? ''));
        }
        if (empty($values['first_name']) && ! empty($values['full_name'])) {
            $values['first_name'] = Str::before($values['full_name'], ' ');
        }

        return $values;
    }

    /** The canonical field a written token refers to, or null if it is not one we know. */
    private function canonical(string $token): ?string
    {
        $flat = strtolower(preg_replace('/[\s_\-]+/', '', $token) ?? $token);

        foreach (self::ALIASES as $canonical => $aliases) {
            if ($flat === str_replace('_', '', $canonical) || in_array($flat, $aliases, true)) {
                return $canonical;
            }
        }

        return null;
    }

    /**
     * Placeholders a template still contains that nothing can fill.
     *
     * For checking a template before a campaign goes out rather than after — the difference
     * between catching it once and apologising to a list.
     *
     * @return string[]
     */
    public function unresolvedIn(string $template, array $sampleData = []): array
    {
        return $this->apply($template, $sampleData !== [] ? $sampleData : [
            'first_name' => 'Sample', 'last_name' => 'Person',
            'company' => 'Sample Company', 'email' => 'sample@example.com',
        ])['unresolved'];
    }
}
