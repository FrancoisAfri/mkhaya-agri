<?php

namespace Tests\Feature\Notifications;

use App\Shared\Listeners\MirrorEmailToNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * The preview line under a notification's title.
 *
 * Every outgoing email is mirrored into the bell, and the preview was produced with `strip_tags()`
 * alone. That removes the *tags* and keeps everything between them — including the contents of
 * `<style>` and `<title>`, which are not tags to be stripped but text to be discarded.
 *
 * Every templated email on this platform carries a stylesheet in its head, so the notification for
 * a document waiting to be signed read:
 *
 *     MkhayaHub @media only screen and (max-width: 600px) { .inner-body {…
 *
 * and ran out of characters there. The one thing a preview exists to show — what the message says —
 * was always past the limit.
 */
class EmailPreviewReadsAsAMessageTest extends TestCase
{
    use DatabaseTransactions;

    /** The listener's own extraction, which is the thing under test. */
    private function preview(string $html): string
    {
        $email = (new Email())->to('someone@example.test')->subject('Please Sign')->html($html);

        $method = new \ReflectionMethod(MirrorEmailToNotification::class, 'bodyPreview');
        $method->setAccessible(true);

        return $method->invoke(new MirrorEmailToNotification(), new MessageSending($email));
    }

    /** A templated email, shaped the way the ones that produced the report are shaped. */
    private function templated(string $body): string
    {
        return <<<HTML
        <!doctype html>
        <html>
        <head>
            <title>MkhayaHub</title>
            <meta charset="utf-8">
            <style>
                @media only screen and (max-width: 600px) {
                    .inner-body { width: 100% !important; }
                    .footer { width: 100% !important; }
                }
            </style>
        </head>
        <body><div class="inner-body">{$body}</div></body>
        </html>
        HTML;
    }

    // ── The reported preview ─────────────────────────────────────────────────

    public function test_a_stylesheet_never_reaches_the_preview(): void
    {
        $preview = $this->preview($this->templated('<p>You have a document waiting for signature.</p>'));

        $this->assertStringNotContainsString('@media', $preview);
        $this->assertStringNotContainsString('max-width', $preview);
        $this->assertStringNotContainsString('inner-body', $preview);
    }

    public function test_the_page_title_is_not_the_message(): void
    {
        $preview = $this->preview($this->templated('<p>You have a document waiting for signature.</p>'));

        $this->assertStringStartsNotWith('MkhayaHub', $preview);
    }

    /** And what remains is the thing somebody wanted to read. */
    public function test_the_message_itself_survives(): void
    {
        $preview = $this->preview($this->templated('<p>You have a document waiting for signature.</p>'));

        $this->assertSame('You have a document waiting for signature.', $preview);
    }

    // ── Reading as prose ─────────────────────────────────────────────────────

    /** Blocks end at a word boundary, or the last word of one line joins the first of the next. */
    public function test_lines_do_not_run_into_each_other(): void
    {
        $preview = $this->preview($this->templated('<p>Hello Francois</p><p>Please sign the SLA.</p>'));

        $this->assertSame('Hello Francois Please sign the SLA.', $preview);
    }

    /** `&amp;` and `&nbsp;` are what an HTML body is full of, and neither is a word. */
    public function test_entities_are_decoded(): void
    {
        $preview = $this->preview($this->templated('<p>Mkhaya&nbsp;Prop&nbsp;&amp;&nbsp;Osizweni SLA</p>'));

        $this->assertSame('Mkhaya Prop & Osizweni SLA', $preview);
    }

    /** Outlook conditional comments are a wall of markup that reads as text. */
    public function test_conditional_comments_are_dropped(): void
    {
        $preview = $this->preview($this->templated(
            '<!--[if mso]><table role="presentation"><tr><td><![endif]--><p>The real message.</p>',
        ));

        $this->assertSame('The real message.', $preview);
    }

    /** A style block in the body, which templated mail often emits, is still not text. */
    public function test_a_style_block_outside_the_head_is_also_dropped(): void
    {
        $preview = $this->preview('<body><style>.x { color: red; }</style><p>Only this.</p></body>');

        $this->assertSame('Only this.', $preview);
    }

    // ── Nothing tag-shaped survives, however it was encoded ──────────────────

    /**
     * The preview is stored and read back by whatever comes along; it must contain no markup.
     *
     * Nothing renders it as HTML today — the bell interpolates it, which escapes — but "safe
     * because of how it happens to be displayed" is a property that quietly stops being true the
     * first time somebody reaches for v-html. Decoding before stripping, rather than after, means
     * an escaped tag is a tag by the time the strip sees it.
     */
    public function test_an_escaped_tag_does_not_become_a_real_one(): void
    {
        $preview = $this->preview($this->templated(
            '<p>Reply to &lt;script&gt;alert(1)&lt;/script&gt; today.</p>',
        ));

        // The markup is what must not survive. The words between the tags are inert text in a
        // preview and stay — stripping content rather than tags would be a different job, and one
        // that would quietly eat legitimate messages.
        $this->assertStringNotContainsString('<script', $preview);
        $this->assertStringNotContainsString('</script', $preview);
        $this->assertSame('Reply to alert(1) today.', $preview);
    }

    /** Double-encoded is the same answer: still no live tag in the stored text. */
    public function test_a_double_encoded_tag_is_not_unwrapped_into_one(): void
    {
        $preview = $this->preview($this->templated('<p>&amp;lt;img onerror=x&amp;gt;</p>'));

        $this->assertStringNotContainsString('<img', $preview);
    }

    // ── What must not change ─────────────────────────────────────────────────

    /** A plain-text email has no markup to remove and must come through untouched. */
    public function test_a_plain_text_email_is_unaffected(): void
    {
        $email = (new Email())->to('someone@example.test')->subject('Plain')
            ->text('Your leave request was approved.');

        $method = new \ReflectionMethod(MirrorEmailToNotification::class, 'bodyPreview');
        $method->setAccessible(true);

        $this->assertSame(
            'Your leave request was approved.',
            $method->invoke(new MirrorEmailToNotification(), new MessageSending($email)),
        );
    }

    /** Still a preview, not the whole letter. */
    public function test_it_is_still_truncated(): void
    {
        $preview = $this->preview($this->templated('<p>' . str_repeat('word ', 100) . '</p>'));

        // `Str::limit` counts the text and then appends its ellipsis, so the result is a little
        // over the limit by design. What matters is that it is bounded and visibly cut.
        $this->assertStringEndsWith('...', $preview);
        $this->assertLessThan(200, mb_strlen($preview));
    }

    /** An email with nothing in it must not throw on the way past. */
    public function test_an_empty_body_is_handled(): void
    {
        $this->assertSame('', $this->preview(''));
    }
}
