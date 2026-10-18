<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 0; }
        .wrapper { max-width: 600px; margin: 40px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .header { background: #2E7D32; color: #fff; padding: 28px 32px; }
        .header h1 { margin: 0; font-size: 22px; }
        .body { padding: 32px; color: #333; font-size: 15px; line-height: 1.7; }
        .ticket-box { background: #F1F8E9; border-left: 4px solid #2E7D32; border-radius: 4px; padding: 16px 20px; margin: 20px 0; }
        .ticket-box dt { font-weight: bold; color: #555; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
        .ticket-box dd { margin: 2px 0 12px 0; font-size: 15px; }
        .notes-box { background: #FAFAFA; border: 1px solid #E0E0E0; border-radius: 4px; padding: 14px 18px; margin-top: 16px; font-size: 14px; color: #444; white-space: pre-line; }
        .footer { padding: 20px 32px; background: #f9f9f9; color: #888; font-size: 12px; text-align: center; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        <h1>&#10003; Ticket Resolved</h1>
    </div>
    <div class="body">
        @php
            $name = $ticket->requester_name ?: ($ticket->requester?->person?->first_name ?? 'there');
        @endphp

        <p>Hi <strong>{{ $name }}</strong>,</p>
        <p>We are pleased to let you know that your support request has been marked as <strong>resolved</strong>.</p>

        <dl class="ticket-box">
            <dt>Ticket Number</dt>
            <dd>{{ $ticket->ticket_number }}</dd>

            <dt>Subject</dt>
            <dd>{{ $ticket->subject }}</dd>

            @if($ticket->resolved_at)
                <dt>Resolved On</dt>
                <dd>{{ $ticket->resolved_at->format('d M Y, H:i') }}</dd>
            @endif
        </dl>

        @if($ticket->resolution_notes)
            <p><strong>Resolution notes:</strong></p>
            <div class="notes-box">{{ $ticket->resolution_notes }}</div>
        @endif

        <p style="margin-top:24px;">If your issue is not fully resolved, please reply to this email or open a new ticket and reference <strong>{{ $ticket->ticket_number }}</strong>.</p>
        <p>Thank you for reaching out.<br><strong>Support Team</strong></p>
    </div>
    <div class="footer">
        This is an automated notification. Please do not reply directly to this email.
    </div>
</div>
</body>
</html>
