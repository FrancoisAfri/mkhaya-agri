<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 0; }
        .wrapper { max-width: 600px; margin: 40px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .header { background: #1565C0; color: #fff; padding: 28px 32px; }
        .header h1 { margin: 0; font-size: 22px; }
        .body { padding: 32px; color: #333; font-size: 15px; line-height: 1.7; }
        .reply-box { background: #FAFAFA; border-left: 4px solid #1565C0; border-radius: 4px; padding: 16px 20px; margin: 20px 0; font-size: 15px; color: #333; white-space: pre-line; }
        .meta { color: #777; font-size: 13px; margin: 0 0 16px 0; }
        .footer { padding: 20px 32px; background: #f9f9f9; color: #888; font-size: 12px; text-align: center; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        <h1>New reply on your ticket</h1>
    </div>
    <div class="body">
        @php
            $name = $ticket->requester_name ?: ($ticket->requester?->person?->first_name ?? 'there');
            $author = $comment->user?->person
                ? trim($comment->user->person->first_name . ' ' . $comment->user->person->surname)
                : 'Support';
        @endphp

        <p>Hi <strong>{{ $name }}</strong>,</p>
        <p class="meta">
            {{ $author }} replied to <strong>{{ $ticket->ticket_number }}</strong> — {{ $ticket->subject }}
        </p>

        <div class="reply-box">{{ $comment->body }}</div>

        <p style="margin-top:24px;">
            You can reply directly to this email and your message will be added to the same ticket.
        </p>
        <p>Thank you,<br><strong>Support Team</strong></p>
    </div>
    <div class="footer">
        Please keep <strong>{{ $ticket->ticket_number }}</strong> in the subject line so your reply reaches the right ticket.
    </div>
</div>
</body>
</html>
