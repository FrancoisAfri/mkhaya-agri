<?php

namespace App\Support\Mail;

/**
 * Lightweight IMAP client using PHP stream sockets.
 * Does not require the php-imap extension.
 */
class ImapClient
{
    private mixed $socket = null;
    private int $tag = 1;

    public function __construct(
        private readonly string $host,
        private readonly int    $port,
        private readonly string $encryption, // ssl | tls | none
        private readonly string $username,
        private readonly string $password,
        private readonly string $folder = 'INBOX',
    ) {}

    // ── Connection ────────────────────────────────────────────────────────────

    public function connect(): void
    {
        // Implicit SSL on 993 regardless of how encryption is stored; STARTTLS uses plain TCP first.
        $useSsl = strtolower($this->encryption) === 'ssl' || $this->port === 993;
        $scheme = $useSsl ? 'ssl://' : 'tcp://';
        $ctx    = stream_context_create([
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
                'SNI_enabled'      => true,
                'peer_name'        => $this->host,
            ],
        ]);

        // stream_socket_client reports SSL handshake failures as warnings (empty $errstr),
        // so trap the warning text to give an actionable reason instead of a blank error.
        $warning = '';
        set_error_handler(function ($no, $str) use (&$warning) {
            $warning = preg_replace('/^stream_socket_client\(\):\s*/', '', $str);
            return true;
        });
        try {
            $this->socket = stream_socket_client(
                "{$scheme}{$this->host}:{$this->port}",
                $errno,
                $errstr,
                15,
                STREAM_CLIENT_CONNECT,
                $ctx,
            );
        } finally {
            restore_error_handler();
        }

        if (!$this->socket) {
            $reason = $errstr ?: $warning;
            while ($ssl = openssl_error_string()) {
                $reason = trim(($reason ? $reason . '; ' : '') . $ssl);
            }
            if ($reason === '') {
                $reason = "could not connect (errno {$errno}) — check the host, port, firewall and SSL from the server";
            }
            throw new \RuntimeException("IMAP connect failed [{$this->host}:{$this->port}]: {$reason}");
        }

        stream_set_timeout($this->socket, 10);
        $this->read(); // server greeting

        if ($this->encryption === 'tls') {
            $this->cmd('STARTTLS');
            stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        }

        $this->cmd("LOGIN \"{$this->username}\" \"{$this->password}\"");
        $this->cmd("SELECT \"{$this->folder}\"");
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            @fwrite($this->socket, 'A' . $this->tag++ . " LOGOUT\r\n");
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    // ── Fetch unseen messages ─────────────────────────────────────────────────

    /**
     * Returns array of parsed email arrays for unread messages.
     */
    public function fetchUnseen(): array
    {
        $response = $this->cmd('SEARCH UNSEEN');
        preg_match('/\* SEARCH([\d\s]*)/i', $response, $m);
        $ids = array_filter(explode(' ', trim($m[1] ?? '')));

        if (empty($ids)) return [];

        $emails = [];
        foreach ($ids as $id) {
            $raw = $this->fetchRaw((int)$id);
            if ($raw) {
                $emails[] = $this->parseEmail($raw, (int)$id);
                // Mark as seen
                $this->cmd("STORE {$id} +FLAGS (\\Seen)");
            }
        }
        return $emails;
    }

    // ── Raw fetch ─────────────────────────────────────────────────────────────

    private function fetchRaw(int $id): ?string
    {
        $tag      = 'A' . $this->tag++;
        $command  = "{$tag} FETCH {$id} (BODY[])\r\n";
        $this->write($command);

        $raw = '';
        while (!feof($this->socket)) {
            $line = fgets($this->socket, 4096);
            if ($line === false) break;
            $raw .= $line;
            if (str_starts_with($line, $tag)) break;
        }
        return $raw ?: null;
    }

    // ── Email parser ──────────────────────────────────────────────────────────

    private function parseEmail(string $raw, int $seqId): array
    {
        // Strip IMAP FETCH wrapper lines
        $lines  = explode("\n", $raw);
        $inBody = false;
        $mail   = '';
        foreach ($lines as $line) {
            if (!$inBody && preg_match('/^\* \d+ FETCH/i', $line)) {
                $inBody = true;
                continue;
            }
            if ($inBody) $mail .= $line . "\n";
        }

        /*
         * One line ending, decided here, and every split below is on "\n\n".
         *
         * The split used to be on "\r\n\r\n" with a fallback guarded by `if (! $headerPart)` —
         * which can never be true. `explode` with a limit of 2 returns the *whole string* as the
         * first element when the separator is absent, so a server sending bare LF produced
         * $headerPart = the entire message and $bodyPart = ''. The fallback sat there looking like
         * a safety net and could not fire.
         *
         * That is the empty-ticket bug, and it is also the wrong-sender bug: with the whole message
         * handed to parseHeaders, every `From:` line inside a forwarded body was read as a header.
         */
        $mail = str_replace(["\r\n", "\r"], "\n", $mail);

        [$headerPart, $bodyPart] = array_pad(explode("\n\n", $mail, 2), 2, '');

        $headers = $this->parseHeaders($headerPart);

        return [
            'seq_id'      => $seqId,
            'message_id'  => $this->headerVal($headers, 'message-id'),
            'subject'     => $this->decodeHeader($this->headerVal($headers, 'subject') ?? '(No Subject)'),
            'from_email'  => $this->extractEmail($this->headerVal($headers, 'from') ?? ''),
            'from_name'   => $this->extractName($this->headerVal($headers, 'from') ?? ''),
            'reply_to'    => $this->extractEmail($this->headerVal($headers, 'reply-to') ?? $this->headerVal($headers, 'from') ?? ''),
            'in_reply_to' => $this->headerVal($headers, 'in-reply-to'),
            'references'  => $this->headerVal($headers, 'references'),
            'date'        => $this->headerVal($headers, 'date'),
            'body'        => $this->extractBody($bodyPart, $headers),
            /*
             * Every header, for whatever has to judge this message later.
             *
             * Bulk mail identifies itself: `List-Unsubscribe`, `Precedence: bulk` and
             * `Auto-Submitted` are what a sender is obliged to set on marketing and automated mail,
             * and `X-Spam-*` is what the receiving server already decided. Those are far better
             * evidence than reading the words, and they are free — they were parsed a moment ago
             * and then thrown away.
             */
            'headers'     => $headers,
        ];
    }

    /**
     * The header block, and only the header block.
     *
     * Two rules that were missing, both of which let body text be read as headers:
     *
     *   **Stop at the blank line.** A header block ends there, by definition. Without the stop this
     *   parsed every colon-bearing line it was handed — so on a forwarded email, the quoted
     *   "From: ... Sent: ... To: ... Subject: ..." block became headers.
     *
     *   **First occurrence wins.** A message has one From. When something malformed carries two,
     *   the first is the envelope's own and the later ones are quoted material. Last-wins meant the
     *   sender on the ticket was whichever `From:`-shaped line appeared furthest down the message —
     *   typically an address from an older layer of the forward chain, belonging to nobody involved.
     */
    private function parseHeaders(string $raw): array
    {
        $headers = [];

        // Unfold: a header continued on the next line begins with whitespace.
        $raw = preg_replace("/\n([ \t])/", '$1', str_replace(["\r\n", "\r"], "\n", $raw));

        foreach (explode("\n", $raw) as $line) {
            if (trim($line) === '') {
                break;
            }

            if (! str_contains($line, ':')) {
                continue;
            }

            [$key, $val] = explode(':', $line, 2);
            $key = strtolower(trim($key));

            if (! array_key_exists($key, $headers)) {
                $headers[$key] = trim($val);
            }
        }

        return $headers;
    }

    private function headerVal(array $headers, string $key): ?string
    {
        return $headers[$key] ?? null;
    }

    private function decodeHeader(string $encoded): string
    {
        return mb_decode_mimeheader($encoded);
    }

    private function extractEmail(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $m)) return trim($m[1]);
        return trim($from);
    }

    private function extractName(string $from): string
    {
        if (preg_match('/^"?([^"<]+)"?\s*</', $from, $m)) {
            return trim($m[1], " \t\"");
        }
        return '';
    }

    /**
     * The readable text of a message, whatever shape it arrived in.
     *
     * What was here looked for one `Content-Type: text/plain` part and gave up otherwise. Three
     * things went wrong with that, and together they are why a forwarded Outlook email produced a
     * ticket with no content:
     *
     *   **HTML-only mail has no text/plain part.** Most mail sent from Outlook is
     *   multipart/alternative carrying text/html, or html with no alternative at all. Finding no
     *   plain part, the old code fell through and ran strip_tags over the raw multipart source —
     *   boundaries, part headers, base64 blobs and all.
     *
     *   **Parts were split on "\n\n".** MIME uses CRLF, so the part's own headers were never
     *   separated from its body and the decode returned an empty string.
     *
     *   **Nesting was not handled.** multipart/mixed wrapping multipart/alternative — an email with
     *   a signature image, which is to say most business email — hid the text one level down.
     *
     * So this walks the tree: prefer text/plain, fall back to text/html, and never descend into
     * something a sender marked as an attachment.
     */
    private function extractBody(string $body, array $headers): string
    {
        $text = $this->partText($body, $headers);

        return trim($text ?? '');
    }

    /**
     * One MIME part, recursively.
     *
     * @param  array<string, string>  $headers  that part's own headers
     */
    private function partText(string $body, array $headers, int $depth = 0): ?string
    {
        // A malformed or hostile message must not be able to recurse without end.
        if ($depth > 8) {
            return null;
        }

        // Lower-cased for comparing the type; the original is kept because a MIME boundary is
        // case-sensitive, and folding it is enough to make every part of a message disappear.
        $rawType = $headers['content-type'] ?? 'text/plain';
        $contentType = strtolower($rawType);

        // An attachment is not the message, however readable its bytes happen to be.
        if (str_contains(strtolower($headers['content-disposition'] ?? ''), 'attachment')) {
            return null;
        }

        if (str_contains($contentType, 'multipart')) {
            return $this->multipartText($body, $rawType, $depth);
        }

        $decoded = $this->decodePart($body, $headers);

        if (str_contains($contentType, 'text/html')) {
            return $this->htmlToText($decoded);
        }

        // text/plain, and anything unlabelled — mail with no Content-Type is plain text by default.
        if ($contentType === '' || str_contains($contentType, 'text/')) {
            return $decoded;
        }

        return null;
    }

    /**
     * The best readable part of a multipart body.
     *
     * Plain text is preferred where a sender offered both, because it is what they wrote rather
     * than what their client generated. HTML is kept as the fallback rather than discarded, which
     * is the whole difference between a ticket with the message on it and a ticket with nothing.
     */
    private function multipartText(string $body, string $contentType, int $depth): ?string
    {
        if (! preg_match('/boundary="?([^";\s]+)"?/i', $contentType, $m)) {
            return null;
        }

        $boundary = $m[1];
        $plain = null;
        $html = null;

        foreach (explode('--' . $boundary, $body) as $chunk) {
            $chunk = ltrim($chunk, "\n");

            if ($chunk === '' || str_starts_with($chunk, '--')) {
                continue; // the closing delimiter
            }

            // Each part carries its own headers, separated from its body by a blank line.
            [$partHeaders, $partBody] = array_pad(explode("\n\n", $chunk, 2), 2, '');

            $ph = $this->parseHeaders($partHeaders);
            $text = $this->partText($partBody, $ph, $depth + 1);

            if ($text === null || trim($text) === '') {
                continue;
            }

            $type = strtolower($ph['content-type'] ?? '');

            if (str_contains($type, 'text/html')) {
                $html ??= $text;
            } else {
                $plain ??= $text;
            }
        }

        return $plain ?? $html;
    }

    /** @param array<string, string> $headers */
    private function decodePart(string $body, array $headers): string
    {
        $decoded = match (strtolower(trim($headers['content-transfer-encoding'] ?? ''))) {
            'base64'           => (string) base64_decode($body, false),
            'quoted-printable' => quoted_printable_decode($body),
            default            => $body,
        };

        return $this->toUtf8($decoded, $headers['content-type'] ?? '');
    }

    /** Mail is not always UTF-8; a Latin-1 part read as UTF-8 loses every accented character. */
    private function toUtf8(string $text, string $contentType): string
    {
        if (! preg_match('/charset="?([^";\s]+)"?/i', $contentType, $m)) {
            return $text;
        }

        $charset = strtoupper(trim($m[1]));

        if ($charset === 'UTF-8' || $charset === '' || ! function_exists('mb_convert_encoding')) {
            return $text;
        }

        $converted = @mb_convert_encoding($text, 'UTF-8', $charset);

        return $converted === false ? $text : $converted;
    }

    /**
     * HTML to something a person can read in a ticket.
     *
     * Block ends become newlines before the tags come off, or every paragraph of a forwarded chain
     * runs into the next as one unbroken line. Style and script contents are dropped rather than
     * stripped, because `strip_tags` removes the tags and keeps what is between them — which is how
     * a stylesheet ends up as the body of a ticket.
     */
    private function htmlToText(string $html): string
    {
        $html = preg_replace('#<(head|style|script|title)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('/<!--.*?-->/s', ' ', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/div|/tr|/li|/h[1-6])\b[^>]*>#i', "\n", $html) ?? $html;

        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);

        // Non-breaking spaces are not whitespace to \s, and table-based mail is full of them.
        $text = str_replace(["\xC2\xA0", "\u{200B}"], ' ', $text);

        // Collapse runs of blank lines, but keep the paragraph breaks that make a forward readable.
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    // ── Low-level command ─────────────────────────────────────────────────────

    private function cmd(string $command): string
    {
        $tag  = 'A' . $this->tag++;
        $this->write("{$tag} {$command}\r\n");
        return $this->read($tag);
    }

    /**
     * Write to the socket, turning a dropped/shut-down SSL connection into a clean
     * exception instead of a raw "fwrite(): SSL operation failed … protocol is
     * shutdown" warning that would abort the whole mailbox poll.
     */
    private function write(string $data): void
    {
        if (! $this->socket || feof($this->socket)) {
            throw new \RuntimeException('IMAP connection closed by the server.');
        }

        $warning = '';
        set_error_handler(function ($no, $str) use (&$warning) {
            $warning = preg_replace('/^fwrite\(\):\s*/', '', $str);
            return true;
        });
        try {
            $written = fwrite($this->socket, $data);
        } finally {
            restore_error_handler();
        }

        if ($written === false || $written === 0) {
            throw new \RuntimeException('IMAP write failed' . ($warning ? ": {$warning}" : ' — connection lost.'));
        }
    }

    private function read(string $until = ''): string
    {
        $buf = '';
        while (!feof($this->socket)) {
            $line = fgets($this->socket, 4096);
            if ($line === false) break;
            $buf .= $line;
            if ($until && str_starts_with($line, $until)) break;
            if (!$until) break;
        }
        return $buf;
    }
}
