<?php

namespace App\Modules\Agri\Support;

use App\Modules\Tenancy\Services\CompanyBranding;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Farm emails in the company's own name where it is white-labelled.
 *
 * Laravel's standard notification email says the product's name in its header, sign-off and
 * footer, and the sender's name is the product's too. For a white-labelled company each of those
 * becomes the company's name, so nothing its people, growers or auditors receive says "Mkhaya";
 * otherwise the email is exactly as it was.
 */
final class AgriMail
{
    public static function brand(MailMessage $mail, ?int $companyId): MailMessage
    {
        $b = app(CompanyBranding::class)->for($companyId, withLogoData: false);
        if (! $b['white_label']) {
            return $mail;
        }

        return $mail->from((string) config('mail.from.address'), $b['name'])
            ->salutation(UiLabels::get('agri.mail.regards', ['name' => $b['name']]))
            ->markdown('agri.mail.branded', ['brandName' => $b['name'], 'brandLinkHint' => UiLabels::get('agri.mail.link_hint')]);
    }
}
