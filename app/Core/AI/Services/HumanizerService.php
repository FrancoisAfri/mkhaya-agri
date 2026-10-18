<?php

namespace App\Core\AI\Services;

use Illuminate\Support\Str;

/**
 * Takes the tells out of machine-written copy.
 *
 * Every article, ad, subject line and outreach message this platform produces comes out of a
 * language model, and models have a house style people now recognise on sight: em dashes
 * everywhere, "delve" and "leverage", three adjectives where one would do, and a closing paragraph
 * about an evolving landscape. A prospect who spots it stops reading, and a client who spots it in
 * work they paid for asks what they are paying for.
 *
 * Two passes, deliberately separate:
 *
 *   guidance()  goes into the system prompt, so the model avoids the patterns while writing.
 *               Cheapest place to fix this, and the only one that costs nothing.
 *   clean()     rewrites what came back anyway. Mechanical patterns only — curly quotes, em dash
 *               runs, "In order to", emoji bullets, chatbot pleasantries. Deterministic, so it
 *               never invents and never needs a second call.
 *   scan()      reports what is left for a person to judge: rule-of-three stacking, inflated
 *               significance, vague attribution. Flagged rather than rewritten, because fixing
 *               those properly means knowing what the writer meant.
 *
 * Based on Wikipedia's "Signs of AI writing", which is the most complete catalogue of these
 * patterns anybody has assembled.
 */
class HumanizerService
{
    /**
     * The instruction added to every content-writing prompt in the platform.
     *
     * Written as prohibitions with examples because "write naturally" produces exactly the same
     * output as no instruction at all.
     */
    public function guidance(): string
    {
        return <<<'TEXT'
        WRITING RULES — these override any style instruction above.

        Never use these words or phrases: delve, tapestry, testament, realm, landscape (figurative),
        navigate (figurative), foster, underscore, pivotal, crucial, vital role, robust, seamless,
        multifaceted, holistic, leverage (as a verb), utilize, embark, journey (figurative),
        resonate, elevate, unlock, harness, spearhead, game-changer, cutting-edge, state-of-the-art,
        in today's fast-paced world, in the ever-evolving, it is important to note, it is worth
        noting, moreover, furthermore, additionally (as a paragraph opener).

        Never write these constructions:
        - "not only ... but also", "it's not just X, it's Y" — say the thing directly.
        - "X serves as a", "X stands as a", "X represents a" — write "X is a".
        - Trailing "-ing" clauses that add nothing: "..., highlighting the importance of...",
          "..., ensuring...", "..., reflecting...". Cut them or make them their own sentence.
        - Three adjectives or three items in a row purely for rhythm. Two is usually right.
        - A closing paragraph about the future, the journey, or the broader significance.
        - "Experts say", "industry reports suggest", "many believe" with no named source.

        Punctuation and formatting:
        - No em dashes. Use a full stop, a comma, or brackets.
        - Straight quotes and apostrophes only.
        - No emoji.
        - Sentence case for headings, not Title Case.
        - Bold sparingly. Never start a list item with a bolded label followed by a colon.

        Voice:
        - Vary sentence length. Some short. Some that take longer to get where they are going.
        - Prefer the concrete: a number, a name, a date, an example.
        - Say "is" and "has" instead of dressing them up.
        - It is fine to have an opinion and to say something is difficult or uncertain.
        TEXT;
    }

    /** Whether the guidance should be applied at all. Off is a supported answer. */
    public function enabled(): bool
    {
        return (bool) config('ai.humanizer.enabled', true);
    }

    /** Appends the rules to a system prompt. */
    public function applyTo(string $systemPrompt): string
    {
        return $this->enabled()
            ? rtrim($systemPrompt) . "\n\n" . $this->guidance()
            : $systemPrompt;
    }

    // ── The deterministic pass ───────────────────────────────────────────────

    /** Straight swaps: the pattern, and what it becomes. */
    private const REPLACEMENTS = [
        // Filler that always shortens without changing the meaning.
        '/\bin order to\b/i'                      => 'to',
        '/\bdue to the fact that\b/i'             => 'because',
        '/\bin the event that\b/i'                => 'if',
        '/\bat this point in time\b/i'            => 'now',
        '/\bfor the purpose of\b/i'               => 'for',
        '/\bin spite of the fact that\b/i'        => 'although',
        '/\bhas the ability to\b/i'               => 'can',
        '/\bit is important to note that\b/i'     => '',
        '/\bit is worth noting that\b/i'          => '',
        '/\bit should be noted that\b/i'          => '',
        '/\bneedless to say,?\s*/i'               => '',
        // Copula avoidance.
        '/\bserves as an?\b/i'                    => 'is a',
        '/\bstands as an?\b/i'                    => 'is a',
        // Overused vocabulary with a plain equivalent.
        '/\bdelve into\b/i'                       => 'look at',
        '/\bleverage(d|s)?\b/i'                   => 'use',
        '/\butilis(e|ed|es|ing)\b/i'              => 'use',
        '/\butiliz(e|ed|es|ing)\b/i'              => 'use',
        '/\bin today\'s fast-paced world,?\s*/i'  => '',
        '/\bin the ever-evolving\b/i'             => 'in the changing',
        // Chatbot correspondence that got pasted in with the content.
        '/^\s*(certainly|of course|absolutely)[!,.]\s*/im' => '',
        '/\bI hope this helps[!.]?\s*/i'          => '',
        '/\bLet me know if you.{0,40}[!.?]\s*/i'  => '',
        '/\bHere(\'s| is) (a|the) .{0,30}you requested[:.]?\s*/i' => '',
        // Knowledge-cutoff disclaimers.
        '/\bas of my last (training )?update,?\s*/i' => '',
        '/\bbased on available information,?\s*/i'   => '',
    ];

    /**
     * Cleans the mechanical patterns.
     *
     * Only things that can be fixed without knowing what the writer meant. An em dash becomes a
     * comma or a full stop by position; "delve into" becomes "look at"; a curly quote becomes a
     * straight one. Anything requiring judgement is left for scan() to report.
     */
    public function clean(?string $text): string
    {
        $text = (string) $text;
        if ($text === '' || ! $this->enabled()) {
            return $text;
        }

        /*
         * Never touch structured data. Straightening a curly quote inside a JSON string value
         * inserts an unescaped double quote, which ends the string and makes the whole payload
         * unparseable — so the caller silently gets nothing back at all. Prose is what this is for;
         * a JSON transport carrying prose is cleaned field by field after it is decoded.
         */
        if ($this->looksStructured($text)) {
            return $text;
        }

        foreach (self::REPLACEMENTS as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        $text = $this->straightenQuotes($text);
        $text = $this->replaceEmDashes($text);
        $text = $this->stripEmoji($text);
        $text = $this->unboldListLabels($text);
        $text = $this->sentenceCaseHeadings($text);

        // Collapse the double spaces and stray leading punctuation the deletions leave behind.
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;
        $text = preg_replace('/(^|\n)[ \t]*([,;:])\s*/', '$1', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        // A sentence that lost its opening clause often starts lower case now. Trimmed first, or
        // the space the deletion left behind sits between the anchor and the letter.
        $text = trim($text);
        $text = preg_replace_callback('/(^|[.!?]\s+|\n)\s*([a-z])/u',
            fn ($m) => $m[1] . mb_strtoupper($m[2]), $text) ?? $text;

        return trim($text);
    }

    /**
     * A payload that parses as JSON, or opens like one.
     *
     * The parse is the real test; the cheap prefix check exists because a truncated or fenced
     * response will not parse and must still be left alone.
     */
    public function looksStructured(string $text): bool
    {
        $trimmed = ltrim($text);
        $opener = substr($trimmed, 0, 1);

        if ($opener !== '{' && $opener !== '[' && ! str_starts_with($trimmed, '```json')) {
            return false;
        }

        return true;
    }

    private function straightenQuotes(string $text): string
    {
        return strtr($text, [
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'",
            "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{2033}" => '"',
            "\u{2026}" => '...',
        ]);
    }

    /**
     * An em dash becomes a comma mid-sentence and a full stop where it is joining two clauses that
     * can stand alone. A pair used as brackets becomes brackets.
     */
    private function replaceEmDashes(string $text): string
    {
        // Paired first, so a genuine parenthetical is not turned into two stray commas.
        $text = preg_replace('/\s*[—–]\s*([^—–\n]{3,60}?)\s*[—–]\s*/u', ' ($1) ', $text) ?? $text;

        // A remaining dash before a capital letter is usually joining two sentences.
        $text = preg_replace('/\s*[—–]\s*(?=[A-Z])/u', '. ', $text) ?? $text;

        return preg_replace('/\s*[—–]\s*/u', ', ', $text) ?? $text;
    }

    private function stripEmoji(string $text): string
    {
        $stripped = preg_replace(
            '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}]/u',
            '', $text,
        ) ?? $text;

        // Leaves "- item" rather than "-  item" where the emoji was the bullet. Only collapses a
        // run of two or more: matching a single space eats the one every bullet already has.
        return preg_replace('/(^|\n)([-*])[ \t]{2,}/', '$1$2 ', $stripped) ?? $stripped;
    }

    /**
     * "**Speed:** it is fast" becomes "Speed: it is fast".
     *
     * Both spellings occur — the colon inside the bold and the colon after it — and matching only
     * one leaves half of them in place.
     */
    private function unboldListLabels(string $text): string
    {
        $text = preg_replace('/(^|\n)(\s*[-*]\s+)\*\*([^*\n]{2,60}?):\*\*/u', '$1$2$3:', $text) ?? $text;

        return preg_replace('/(^|\n)(\s*[-*]\s+)\*\*([^*\n]{2,60}?)\*\*\s*:/u', '$1$2$3:', $text) ?? $text;
    }

    /**
     * Words that may be lowered inside a heading. Everything else keeps its capital.
     *
     * The previous rule lowered any word shaped like a capitalised ordinary word, which renamed
     * Cape Town to "Cape town" and the Department of Labour to "the department of labour". On a
     * platform where half the headings name a city, a province or a government department, getting
     * Title Case slightly wrong is far cheaper than getting a place name wrong.
     */
    private const FUNCTION_WORDS = [
        'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'from', 'in', 'into', 'nor', 'of', 'off',
        'on', 'onto', 'or', 'over', 'per', 'the', 'to', 'up', 'via', 'with', 'within', 'without',
        'your', 'yours', 'our', 'their', 'its', 'is', 'are', 'was', 'were', 'be', 'been',
    ];

    /** Title Case headings become sentence case, without renaming anything. */
    private function sentenceCaseHeadings(string $text): string
    {
        return preg_replace_callback('/(^|\n)(#{1,6}\s+)(.+)/u', function ($m) {
            $words = preg_split('/\s+/', trim($m[3])) ?: [];
            if (count($words) < 4) {
                return $m[0];
            }

            $capitalised = 0;
            foreach ($words as $word) {
                if (preg_match('/^[A-Z][a-z]/', $word)) {
                    $capitalised++;
                }
            }

            // Only intervene where most words are capitalised, which is Title Case rather than a
            // sentence that happens to contain names.
            if ($capitalised < max(3, (int) ceil(count($words) * 0.7))) {
                return $m[0];
            }

            $fixed = array_map(function ($word, $i) {
                if ($i === 0) {
                    return $word;
                }

                // A capital that is not a function word is a name until proven otherwise. This
                // gets a few real Title Case words wrong and never renames a city.
                $bare = mb_strtolower(trim($word, ".,:;!?'\"()"));

                return in_array($bare, self::FUNCTION_WORDS, true) ? mb_strtolower($word) : $word;
            }, $words, array_keys($words));

            return $m[1] . $m[2] . implode(' ', $fixed);
        }, $text) ?? $text;
    }

    /**
     * Cleans the prose fields of a decoded payload, leaving its structure and its keys alone.
     *
     * This is how a JSON response gets humanized: after decoding, on the values that are actually
     * text. Short values that look like a slug, a URL, a code or a date are skipped — those are
     * identifiers, and rewriting "leverage" inside one breaks whatever reads it.
     *
     * @param  string[]  $only  restrict to these keys; empty means every string value
     */
    public function cleanFields(array $payload, array $only = []): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->cleanFields($value, $only);
                continue;
            }

            if (! is_string($value) || ($only !== [] && ! in_array((string) $key, $only, true))) {
                continue;
            }

            if ($this->isIdentifier((string) $key, $value)) {
                continue;
            }

            $payload[$key] = $this->clean($value);
        }

        return $payload;
    }

    /** A slug, a URL, a colour, a date, a code — anything that is read by a machine. */
    private function isIdentifier(string $key, string $value): bool
    {
        if (preg_match('/(^|_)(slug|url|href|link|id|uuid|code|type|status|colour|color|locale|lang|date|at)$/i', $key)) {
            return true;
        }

        $trimmed = trim($value);

        return $trimmed === ''
            || str_starts_with($trimmed, 'http')
            || (! str_contains($trimmed, ' ') && mb_strlen($trimmed) < 40);
    }


    // ── The reporting pass ───────────────────────────────────────────────────

    /** Patterns that need a person: what to look for, and what to say about it. */
    private const TELLS = [
        'inflated_significance' => [
            '/\b(a testament to|marking a (pivotal|key|significant) (moment|turning point)|shaping the|underscor\w+ (the |its )?(importance|significance)|reflects? (a )?broader|evolving landscape|indelible mark|deeply rooted|plays? an? (vital|crucial|pivotal|key) role)\b/i',
            'Inflated significance. Say what happened; let the reader decide whether it matters.',
        ],
        'promotional' => [
            '/\b(nestled|boasts a|vibrant|breathtaking|must-visit|renowned|stunning|rich (cultural|history|heritage)|state-of-the-art|cutting-edge|game-chang\w+)\b/i',
            'Promotional language. This reads as a brochure rather than as information.',
        ],
        'superficial_ing' => [
            '/,\s+(highlighting|underscoring|emphasizing|emphasising|showcasing|reflecting|symbolizing|symbolising|ensuring|fostering|cultivating|contributing to)\b/i',
            'A trailing "-ing" clause that adds no information. Cut it, or make it a sentence.',
        ],
        'negative_parallelism' => [
            '/\b(not only\b.{0,80}?\bbut also|it\'s not just\b.{0,60}?\bit\'s|isn\'t just\b.{0,60}?\bit\'s)\b/i',
            'Negative parallelism ("not only... but also"). Say the thing directly.',
        ],
        'vague_attribution' => [
            '/\b(experts? (say|believe|argue|agree)|industry reports?|observers have|many believe|some critics argue|studies show)\b/i',
            'Vague attribution. Name the source or drop the claim.',
        ],
        'ai_vocabulary' => [
            '/\b(delve|tapestry|realm|multifaceted|holistic|robust|seamless|pivotal|foster(ing|s)?|spearhead\w*|resonate\w*|embark)\b/i',
            'Vocabulary that reads as machine-written.',
        ],
        'generic_conclusion' => [
            '/\b(the future looks bright|exciting times (lie )?ahead|a step in the right direction|continues? (its|their) journey|remains? to be seen)\b/i',
            'A generic upbeat ending. End on something specific or stop a paragraph earlier.',
        ],
        'sycophancy' => [
            '/\b(great question|you\'re absolutely right|excellent point|happy to help)\b/i',
            'Chatbot pleasantry left in the copy.',
        ],
    ];

    /**
     * @return array{score:int, findings:array<int,array{key:string,note:string,count:int,examples:string[]}>, stats:array}
     */
    public function scan(?string $text): array
    {
        $text = (string) $text;
        $findings = [];

        foreach (self::TELLS as $key => [$pattern, $note]) {
            if (preg_match_all($pattern, $text, $matches)) {
                $findings[] = [
                    'key'      => $key,
                    'note'     => $note,
                    'count'    => count($matches[0]),
                    'examples' => array_slice(array_unique($matches[0]), 0, 3),
                ];
            }
        }

        $stats = [
            'em_dashes'      => preg_match_all('/[—–]/u', $text),
            'curly_quotes'   => preg_match_all('/[\x{2018}\x{2019}\x{201C}\x{201D}]/u', $text),
            'emoji'          => preg_match_all('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $text),
            'rule_of_three'  => preg_match_all('/\b\w+,\s+\w+,?\s+and\s+\w+\b/u', $text),
            'sentence_variance' => $this->sentenceVariance($text),
        ];

        // Every finding costs; the mechanical counts cost less because clean() removes them.
        // Eight for the pattern being present at all, three per repeat. Copy carrying six
        // different tells is worse than copy carrying one of them six times, and the scale should
        // say so.
        $penalty = collect($findings)->sum(fn ($f) => min(22, 8 + $f['count'] * 3))
            + min(10, (int) $stats['em_dashes'] * 2)
            + min(6, (int) $stats['curly_quotes'])
            + min(8, (int) $stats['emoji'] * 4)
            + ($stats['sentence_variance'] !== null && $stats['sentence_variance'] < 4 ? 8 : 0);

        return [
            'score'    => max(0, 100 - (int) $penalty),
            'findings' => $findings,
            'stats'    => $stats,
        ];
    }

    /**
     * Standard deviation of sentence length.
     *
     * Uniform sentence length is the tell that survives every vocabulary fix, and it is the one
     * thing here that no word list catches.
     */
    private function sentenceVariance(string $text): ?float
    {
        $sentences = array_values(array_filter(
            preg_split('/(?<=[.!?])\s+/u', strip_tags($text)) ?: [],
            fn ($s) => str_word_count($s) > 1,
        ));

        if (count($sentences) < 4) {
            return null;
        }

        $lengths = array_map(fn ($s) => str_word_count($s), $sentences);
        $mean = array_sum($lengths) / count($lengths);
        $variance = array_sum(array_map(fn ($l) => ($l - $mean) ** 2, $lengths)) / count($lengths);

        return round(sqrt($variance), 1);
    }

    /** Clean, then report what is left. The usual entry point. */
    public function process(?string $text): array
    {
        $cleaned = $this->clean($text);

        return ['text' => $cleaned, 'report' => $this->scan($cleaned)];
    }

    /** Short label for a score, for anywhere it is shown next to a number. */
    public function verdict(int $score): string
    {
        return match (true) {
            $score >= 85 => 'Reads as human-written.',
            $score >= 65 => 'Mostly clean, a few tells left.',
            $score >= 40 => 'Recognisably machine-written. Worth an edit.',
            default      => 'Reads as machine-written. Rewrite before this goes out.',
        };
    }
}
