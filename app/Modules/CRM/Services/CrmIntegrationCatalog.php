<?php

namespace App\Modules\CRM\Services;

/**
 * Catalogue of every external provider the CRM can connect to, grouped by
 * category. Each provider declares the credential fields it needs; some email
 * providers ship SMTP presets so the user only has to drop in a username and
 * password / API key and it works.
 */
class CrmIntegrationCatalog
{
    /** @return array<string, array<string, array>> category => provider => meta */
    public static function all(): array
    {
        return [
            'email'    => self::email(),
            'voip'     => self::voip(),
            'whatsapp' => self::whatsapp(),
            'sms'      => self::sms(),
            'meeting'  => self::meeting(),
        ];
    }

    public static function categoryLabels(): array
    {
        return [
            'email'    => 'Email Sending Providers',
            'voip'     => 'VoIP & Telephony',
            'whatsapp' => 'WhatsApp Business',
            'sms'      => 'SMS / Text Messaging',
            'meeting'  => 'Video Meetings (recording)',
        ];
    }

    public static function categoryIcons(): array
    {
        return [
            'email'    => 'tabler-mail',
            'voip'     => 'tabler-phone',
            'whatsapp' => 'tabler-brand-whatsapp',
            'sms'      => 'tabler-message',
            'meeting'  => 'tabler-video',
        ];
    }

    private static function meeting(): array
    {
        return [
            'zoom' => [
                'label'       => 'Zoom',
                'logo'        => 'tabler-brand-zoom',
                'description' => 'Host and record sales meetings through Zoom (Server-to-Server OAuth app). Recordings can be pulled in for AI transcription.',
                'fields'      => [
                    ['key' => 'account_id',    'label' => 'Account ID',    'type' => 'text',     'placeholder' => 'Zoom account ID'],
                    ['key' => 'client_id',     'label' => 'Client ID',     'type' => 'text',     'placeholder' => 'OAuth client ID'],
                    ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'placeholder' => 'OAuth client secret', 'secret' => true],
                ],
            ],
            'ms_teams' => [
                'label'       => 'Microsoft Teams',
                'logo'        => 'tabler-brand-teams',
                'description' => 'Create and record meetings via Microsoft Teams / Graph (Azure app registration).',
                'fields'      => [
                    ['key' => 'tenant_id',     'label' => 'Tenant ID',     'type' => 'text',     'placeholder' => 'Azure AD tenant ID'],
                    ['key' => 'client_id',     'label' => 'Client ID',     'type' => 'text',     'placeholder' => 'App (client) ID'],
                    ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'placeholder' => 'Client secret value', 'secret' => true],
                ],
            ],
            'google_meet' => [
                'label'       => 'Google Meet',
                'logo'        => 'tabler-brand-google',
                'description' => 'Schedule Google Meet meetings via the Google Calendar API (service account / OAuth).',
                'fields'      => [
                    ['key' => 'client_id',     'label' => 'Client ID',     'type' => 'text',     'placeholder' => 'Google OAuth client ID'],
                    ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'placeholder' => 'Google OAuth client secret', 'secret' => true],
                ],
            ],
        ];
    }

    /** Find one provider's meta (with its category) or null. */
    public static function find(string $provider): ?array
    {
        foreach (self::all() as $category => $providers) {
            if (isset($providers[$provider])) {
                return ['category' => $category] + $providers[$provider];
            }
        }
        return null;
    }

    private static function smtpFields(string $host, int $port, string $enc, string $userPlaceholder, string $passLabel, string $passPlaceholder): array
    {
        return [
            ['key' => 'host',       'label' => 'SMTP Host',     'type' => 'text',     'placeholder' => $host,            'default' => $host],
            ['key' => 'port',       'label' => 'SMTP Port',     'type' => 'text',     'placeholder' => (string) $port,   'default' => (string) $port],
            ['key' => 'encryption', 'label' => 'Encryption',    'type' => 'select',   'placeholder' => $enc,             'default' => $enc, 'options' => ['tls', 'ssl', 'none']],
            ['key' => 'username',   'label' => 'Username',      'type' => 'text',     'placeholder' => $userPlaceholder],
            ['key' => 'password',   'label' => $passLabel,      'type' => 'password', 'placeholder' => $passPlaceholder, 'secret' => true],
            ['key' => 'from_email', 'label' => 'From Address',  'type' => 'text',     'placeholder' => 'sales@yourcompany.com'],
            ['key' => 'from_name',  'label' => 'From Name',     'type' => 'text',     'placeholder' => 'Your Company Sales'],
        ];
    }

    private static function email(): array
    {
        return [
            'smtp' => [
                'label'       => 'Custom SMTP Server',
                'logo'        => 'tabler-server-cog',
                'description' => 'Connect any mail server that speaks SMTP — your own hosting, cPanel, Exchange or a private relay.',
                'fields'      => self::smtpFields('mail.yourcompany.com', 587, 'tls', 'sales@yourcompany.com', 'Password', 'Mailbox password'),
            ],
            'gmail' => [
                'label'       => 'Gmail / Google Workspace',
                'logo'        => 'tabler-brand-google',
                'description' => 'Send through Gmail or Google Workspace using an App Password (2-step verification required).',
                'fields'      => self::smtpFields('smtp.gmail.com', 587, 'tls', 'you@gmail.com', 'App Password', '16-character Google app password'),
            ],
            'outlook' => [
                'label'       => 'Microsoft 365 / Outlook',
                'logo'        => 'tabler-brand-windows',
                'description' => 'Send through Microsoft 365, Outlook.com or Exchange Online over SMTP AUTH.',
                'fields'      => self::smtpFields('smtp.office365.com', 587, 'tls', 'you@yourcompany.com', 'Password', 'Account / app password'),
            ],
            'sendgrid' => [
                'label'       => 'Twilio SendGrid',
                'logo'        => 'tabler-send',
                'description' => 'High-volume transactional & marketing email via SendGrid SMTP relay.',
                'fields'      => self::smtpFields('smtp.sendgrid.net', 587, 'tls', 'apikey', 'API Key', 'SG.xxxxxxxx (username stays "apikey")'),
            ],
            'mailgun' => [
                'label'       => 'Mailgun',
                'logo'        => 'tabler-mail-fast',
                'description' => 'Developer-friendly email delivery and analytics via Mailgun SMTP.',
                'fields'      => self::smtpFields('smtp.mailgun.org', 587, 'tls', 'postmaster@mg.yourcompany.com', 'SMTP Password', 'Mailgun SMTP password'),
            ],
            'ses' => [
                'label'       => 'Amazon SES',
                'logo'        => 'tabler-brand-aws',
                'description' => 'Scalable, low-cost email through Amazon Simple Email Service SMTP interface.',
                'fields'      => self::smtpFields('email-smtp.us-east-1.amazonaws.com', 587, 'tls', 'SES SMTP username', 'SMTP Password', 'SES SMTP password'),
            ],
            'postmark' => [
                'label'       => 'Postmark',
                'logo'        => 'tabler-stamp',
                'description' => 'Fast, reliable transactional email with great deliverability via Postmark SMTP.',
                'fields'      => self::smtpFields('smtp.postmarkapp.com', 587, 'tls', 'Postmark Server Token', 'Server Token', 'Use server token as both user & pass'),
            ],
            'brevo' => [
                'label'       => 'Brevo (Sendinblue)',
                'logo'        => 'tabler-mail-heart',
                'description' => 'Marketing and transactional email + automation via Brevo SMTP relay.',
                'fields'      => self::smtpFields('smtp-relay.brevo.com', 587, 'tls', 'you@yourcompany.com', 'SMTP Key', 'Brevo SMTP key'),
            ],
            'mailjet' => [
                'label'       => 'Mailjet',
                'logo'        => 'tabler-plane-departure',
                'description' => 'Send and track email via Mailjet SMTP (API key + secret as user/pass).',
                'fields'      => self::smtpFields('in-v3.mailjet.com', 587, 'tls', 'Mailjet API Key', 'Secret Key', 'Mailjet secret key'),
            ],
        ];
    }

    private static function voip(): array
    {
        return [
            'twilio_voice' => [
                'label'       => 'Twilio Voice',
                'logo'        => 'tabler-phone-outgoing',
                'description' => 'Click-to-call, programmable voice and call recording through Twilio.',
                'fields'      => [
                    ['key' => 'account_sid', 'label' => 'Account SID',   'type' => 'text',     'placeholder' => 'ACxxxxxxxxxxxxxxxx'],
                    ['key' => 'auth_token',  'label' => 'Auth Token',    'type' => 'password', 'placeholder' => 'Twilio auth token', 'secret' => true],
                    ['key' => 'from_number', 'label' => 'Caller ID',     'type' => 'text',     'placeholder' => '+27105348787'],
                    ['key' => 'agent_number','label' => 'Your Phone (for click-to-call)', 'type' => 'text', 'placeholder' => '+27… rings you, then the customer'],
                ],
            ],
            'vonage' => [
                'label'       => 'Vonage (Nexmo)',
                'logo'        => 'tabler-phone-ringing',
                'description' => 'Programmable voice calls and recordings via the Vonage Voice API.',
                'fields'      => [
                    ['key' => 'api_key',     'label' => 'API Key',       'type' => 'text',     'placeholder' => 'Vonage API key'],
                    ['key' => 'api_secret',  'label' => 'API Secret',    'type' => 'password', 'placeholder' => 'Vonage API secret', 'secret' => true],
                    ['key' => 'from_number', 'label' => 'Caller ID',     'type' => 'text',     'placeholder' => '+27105348787'],
                ],
            ],
            'sip' => [
                'label'       => 'SIP Trunk / Zoiper',
                'logo'        => 'tabler-router',
                'description' => 'Connect any SIP PBX or softphone (e.g. Zoiper). Enter the SIP domain and account used by your phones.',
                'fields'      => [
                    ['key' => 'sip_domain',   'label' => 'SIP Domain / Server', 'type' => 'text',     'placeholder' => '196.43.213.56'],
                    ['key' => 'sip_username',  'label' => 'SIP Username',       'type' => 'text',     'placeholder' => '27105348787101'],
                    ['key' => 'sip_password',  'label' => 'SIP Password',       'type' => 'password', 'placeholder' => 'SIP account password', 'secret' => true],
                    ['key' => 'sip_port',      'label' => 'Port',               'type' => 'text',     'placeholder' => '5060', 'default' => '5060'],
                ],
            ],
            'telnyx' => [
                'label'       => 'Telnyx',
                'logo'        => 'tabler-phone-plus',
                'description' => 'Carrier-grade voice, SIP trunking and call control via Telnyx.',
                'fields'      => [
                    ['key' => 'api_key',     'label' => 'API Key',       'type' => 'password', 'placeholder' => 'KEYxxxxxxxx', 'secret' => true],
                    ['key' => 'from_number', 'label' => 'Caller ID',     'type' => 'text',     'placeholder' => '+27105348787'],
                ],
            ],
        ];
    }

    private static function whatsapp(): array
    {
        return [
            'meta_whatsapp' => [
                'label'       => 'WhatsApp Cloud API (Meta)',
                'logo'        => 'tabler-brand-whatsapp',
                'description' => 'Official WhatsApp Business Platform — send template & session messages directly through Meta.',
                'fields'      => [
                    ['key' => 'token',           'label' => 'Permanent Access Token', 'type' => 'password', 'placeholder' => 'EAAxxxxxxxx', 'secret' => true],
                    ['key' => 'phone_number_id', 'label' => 'Phone Number ID',        'type' => 'text',     'placeholder' => '1234567890'],
                    ['key' => 'business_id',     'label' => 'Business Account ID',    'type' => 'text',     'placeholder' => 'WhatsApp Business Account ID'],
                    ['key' => 'base_url',        'label' => 'Graph API Base URL',     'type' => 'text',     'placeholder' => 'https://graph.facebook.com/v21.0', 'default' => 'https://graph.facebook.com/v21.0'],
                ],
            ],
            'twilio_whatsapp' => [
                'label'       => 'Twilio WhatsApp',
                'logo'        => 'tabler-brand-whatsapp',
                'description' => 'Send WhatsApp messages through Twilio’s WhatsApp Business sender.',
                'fields'      => [
                    ['key' => 'account_sid', 'label' => 'Account SID',  'type' => 'text',     'placeholder' => 'ACxxxxxxxx'],
                    ['key' => 'auth_token',  'label' => 'Auth Token',   'type' => 'password', 'placeholder' => 'Twilio auth token', 'secret' => true],
                    ['key' => 'from_number', 'label' => 'WhatsApp From','type' => 'text',     'placeholder' => 'whatsapp:+14155238886'],
                ],
            ],
            '360dialog' => [
                'label'       => '360dialog',
                'logo'        => 'tabler-brand-whatsapp',
                'description' => 'WhatsApp Business API provider popular in EMEA — connect via API key.',
                'fields'      => [
                    ['key' => 'api_key',  'label' => 'API Key',  'type' => 'password', 'placeholder' => '360dialog API key', 'secret' => true],
                    ['key' => 'base_url', 'label' => 'Base URL', 'type' => 'text',     'placeholder' => 'https://waba-v2.360dialog.io', 'default' => 'https://waba-v2.360dialog.io'],
                ],
            ],
        ];
    }

    private static function sms(): array
    {
        return [
            'twilio_sms' => [
                'label'       => 'Twilio SMS',
                'logo'        => 'tabler-message-2',
                'description' => 'Global SMS delivery with delivery receipts via Twilio.',
                'fields'      => [
                    ['key' => 'account_sid', 'label' => 'Account SID',  'type' => 'text',     'placeholder' => 'ACxxxxxxxx'],
                    ['key' => 'auth_token',  'label' => 'Auth Token',   'type' => 'password', 'placeholder' => 'Twilio auth token', 'secret' => true],
                    ['key' => 'from_number', 'label' => 'Sender Number','type' => 'text',     'placeholder' => '+27105348787'],
                ],
            ],
            'vonage_sms' => [
                'label'       => 'Vonage SMS',
                'logo'        => 'tabler-message-2',
                'description' => 'Reliable global SMS via the Vonage Messaging API.',
                'fields'      => [
                    ['key' => 'api_key',    'label' => 'API Key',    'type' => 'text',     'placeholder' => 'Vonage API key'],
                    ['key' => 'api_secret', 'label' => 'API Secret', 'type' => 'password', 'placeholder' => 'Vonage API secret', 'secret' => true],
                    ['key' => 'from',       'label' => 'Sender ID',  'type' => 'text',     'placeholder' => 'Company or +27...'],
                ],
            ],
            'clickatell' => [
                'label'       => 'Clickatell (SA)',
                'logo'        => 'tabler-message-2',
                'description' => 'South-African favourite for bulk and transactional SMS via Clickatell.',
                'fields'      => [
                    ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'placeholder' => 'Clickatell API key', 'secret' => true],
                    ['key' => 'from',    'label' => 'Sender ID', 'type' => 'text',   'placeholder' => 'Company name or short code'],
                ],
            ],
            'bulksms' => [
                'label'       => 'BulkSMS (SA)',
                'logo'        => 'tabler-message-2',
                'description' => 'Send SMS across South Africa and beyond through BulkSMS.com.',
                'fields'      => [
                    ['key' => 'username', 'label' => 'Token ID / Username', 'type' => 'text',     'placeholder' => 'BulkSMS token ID'],
                    ['key' => 'password', 'label' => 'Token Secret',        'type' => 'password', 'placeholder' => 'BulkSMS token secret', 'secret' => true],
                ],
            ],
        ];
    }
}
