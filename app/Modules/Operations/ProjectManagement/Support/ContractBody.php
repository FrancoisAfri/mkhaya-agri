<?php

namespace App\Modules\Operations\ProjectManagement\Support;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Makes a contract body safe to render.
 *
 * Contract bodies are HTML: templates are written in a rich text editor, and drafting copies that
 * markup onto the contract. Putting it on a screen therefore means `v-html`, and `v-html` over text
 * that anybody with template rights composed — and that has had a contractor's own name substituted
 * into it — is a stored-XSS hole unless something stands in between.
 *
 * This is that something, and it is an allowlist rather than a blocklist: parse the markup, keep
 * the handful of tags an agreement is actually written with, and drop every attribute that is not
 * named here. Anything unrecognised loses its tags and keeps its text, so a mangled template reads
 * as plain prose instead of vanishing.
 *
 * No dependency: the platform has no HTML purifier, and one sanitiser used in one place is a
 * smaller thing to own than a package used once.
 */
final class ContractBody
{
    /** What an agreement is written with. Deliberately short. */
    private const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'div', 'span',
        'strong', 'b', 'em', 'i', 'u', 's',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
        'a',
    ];

    /**
     * Attributes kept, by tag. Everything absent from here goes — which is how `style`, `class`,
     * every `on*` handler and the editor's own data attributes are dealt with without naming them.
     */
    private const ALLOWED_ATTRIBUTES = [
        'a'  => ['href', 'title'],
        'th' => ['colspan', 'rowspan'],
        'td' => ['colspan', 'rowspan'],
    ];

    /** Schemes a link may use. `javascript:` and `data:` are absent on purpose. */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * Tags that take their contents with them.
     *
     * Everything else unrecognised is unwrapped so its text survives, which is right for markup an
     * author meant as prose. It is wrong for these: unwrapping `<script>` leaves the source code
     * sitting in the middle of the agreement as visible text. It was never readable content.
     */
    private const DROPPED_WITH_CONTENTS = [
        'script', 'style', 'noscript', 'template', 'iframe', 'object', 'embed',
        'svg', 'math', 'form', 'input', 'button', 'select', 'textarea',
        'link', 'meta', 'base', 'head', 'title',
    ];

    public static function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $document = new DOMDocument();

        // A rich text editor emits a fragment, and libxml would otherwise guess at a document
        // around it and warn about every tag it does not know. The wrapper is unwrapped below.
        $previous = libxml_use_internal_errors(true);

        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"?><div id="pm-contract-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            // Unparseable markup is shown as the text it contains rather than not shown at all.
            return trim(strip_tags($html)) ?: null;
        }

        $root = $document->getElementById('pm-contract-root');

        if (! $root instanceof DOMElement) {
            return trim(strip_tags($html)) ?: null;
        }

        self::stripDisallowed($document);
        self::clean($root);
        self::wrapTables($document, $root);

        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        return trim($out) ?: null;
    }

    /**
     * Put each table in a scrollable box of its own.
     *
     * A rate schedule can be wider than the column the contract is read in, and the alternative to
     * this is making the table itself `display: block` — which is what a first attempt did, and it
     * collapses every cell into a run of prose. The signature block at the foot of an agreement is
     * a table, so that regression landed squarely on the part people actually sign.
     *
     * Wrapping happens here rather than in CSS because the sanitiser emits a bare `<table>`: there
     * is no element to hang the overflow on until one is made.
     */
    private static function wrapTables(DOMDocument $document, DOMElement $root): void
    {
        $xpath = new DOMXPath($document);

        /** @var iterable<DOMNode> $tables */
        $tables = $xpath->query('.//table', $root);

        foreach (iterator_to_array($tables) as $table) {
            $parent = $table->parentNode;

            if ($parent === null) {
                continue;
            }

            $wrapper = $document->createElement('div');
            $wrapper->setAttribute('class', 'contract-table');

            $parent->replaceChild($wrapper, $table);
            $wrapper->appendChild($table);
        }
    }

    /**
     * Comments and processing instructions, gone before anything else looks at the tree.
     *
     * A conditional comment is markup some browsers still act on, and neither carries text worth
     * keeping.
     */
    private static function stripDisallowed(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        /** @var iterable<DOMNode> $nodes */
        $nodes = $xpath->query('//comment() | //processing-instruction()');

        foreach (iterator_to_array($nodes) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /** Walk the tree, unwrapping tags that are not allowed and stripping attributes that are not. */
    private static function clean(DOMElement $element): void
    {
        // Snapshot: unwrapping mutates the live child list underneath the loop.
        foreach (iterator_to_array($element->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::DROPPED_WITH_CONTENTS, true)) {
                $child->parentNode?->removeChild($child);

                continue;
            }

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                self::clean($child);
                self::unwrap($child);

                continue;
            }

            self::stripAttributes($child, $tag);
            self::clean($child);
        }
    }

    private static function stripAttributes(DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];

        /** @var list<DOMAttr> $attributes */
        $attributes = iterator_to_array($element->attributes);

        foreach ($attributes as $attribute) {
            $name = strtolower($attribute->nodeName);

            if (! in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            if ($name === 'href' && ! self::isSafeUrl($attribute->nodeValue ?? '')) {
                $element->removeAttribute($attribute->nodeName);
            }
        }

        // A link that survived keeps its destination but stops being able to reach back into the
        // page that opened it.
        if ($tag === 'a' && $element->hasAttribute('href')) {
            $element->setAttribute('rel', 'noopener noreferrer nofollow');
            $element->setAttribute('target', '_blank');
        }
    }

    /**
     * Relative links are fine; absolute ones must use a scheme we named.
     *
     * The value is unpicked with a plain prefix test rather than parse_url, because parse_url is
     * lenient about the whitespace and control characters that `java\tscript:` relies on.
     */
    private static function isSafeUrl(string $url): bool
    {
        $normalised = strtolower(preg_replace('/[\s\x00-\x1F\x7F]+/', '', $url) ?? '');

        if ($normalised === '') {
            return false;
        }

        // No scheme at all — a relative path or a fragment.
        if (! preg_match('/^([a-z][a-z0-9+.\-]*):/', $normalised, $matches)) {
            return true;
        }

        return in_array($matches[1], self::ALLOWED_SCHEMES, true);
    }

    /** Replace an element with its children, keeping the text an author wrote. */
    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }
}
