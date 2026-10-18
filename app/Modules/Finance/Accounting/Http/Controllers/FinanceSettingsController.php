<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Core\Storage\StoredImage;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinSetting;
use App\Modules\Finance\Accounting\Services\CurrentEntity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinanceSettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Modules/Finance/Accounting/DocumentSettings', [
            'settings' => $this->forTheForm(FinSetting::current()),

            /*
             * Whose letterhead this is, said on the page.
             *
             * A screen that edits one business's name, VAT number and bank account while showing
             * no sign of which business that is cannot be checked by the person using it — the
             * first sign of editing the wrong one is a client's invoice carrying another
             * business's banking details.
             */
            'business' => app(CurrentEntity::class)->get()?->name,
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_name'        => 'nullable|string|max:255',
            'trading_name'        => 'nullable|string|max:255',
            'address'             => 'nullable|string|max:1000',
            'email'               => 'nullable|email|max:255',
            'phone'               => 'nullable|string|max:40',
            'website'             => 'nullable|string|max:255',
            'vat_number'          => 'nullable|string|max:40',
            'registration_number' => 'nullable|string|max:60',
            /*
             * No vendor number here.
             *
             * It lived on this page and printed on the letterhead of every document the tenant
             * issues, which is what a registration number is. A vendor number is the supplier
             * account a *client* opened for you on their own purchasing system, so there is one per
             * client and a single shared value is wrong on every invoice but the first. It is
             * captured on the customer and carried onto the invoice and the quote instead.
             */
            'logo'                => 'nullable|string|max:3000000', // base64 data URI
            'bank_name'           => 'nullable|string|max:80',
            'bank_account_name'   => 'nullable|string|max:120',
            'bank_account_number' => 'nullable|string|max:40',
            'branch_code'         => 'nullable|string|max:20',
            'swift'               => 'nullable|string|max:20',
            'default_message'     => 'nullable|string|max:2000',
            'default_terms'       => 'nullable|string|max:8000',
            'reminders_enabled'   => 'boolean',
            'reminder_days'       => 'nullable|array',
            'reminder_days.*'     => 'integer|min:1|max:365',
            'show_payment_qr'     => 'boolean',
            'snapscan_merchant'   => 'nullable|string|max:80',
        ]);

        $data = $this->settleTheLogo($data);

        $settings = FinSetting::current();
        $settings->fill($data)->save();

        return back()->with('success', 'Company details saved.');
    }

    /**
     * The settings a business shows on its letterhead, with the logo as something an <img> can use.
     *
     * The logo moved to the shared disk, leaving its location in `logo_path` and the column empty.
     * This method kept sending the column, so the page asked for a logo, got null, and drew an
     * empty box over an image that was sitting on disk the whole time. The field keeps its name
     * because `<img src>` takes a URL as happily as a data URI — the browser does not care, and
     * neither does the form that posts it back.
     */
    private function forTheForm(FinSetting $settings): array
    {
        return array_merge($settings->only([
            'company_name', 'trading_name', 'address', 'email', 'phone', 'website',
            'vat_number', 'registration_number', 'bank_name', 'bank_account_name',
            'bank_account_number', 'branch_code', 'swift', 'default_message', 'default_terms',
            'reminders_enabled', 'reminder_days', 'show_payment_qr', 'snapscan_merchant',
        ]), [
            'logo' => StoredImage::url($settings->logo_path, $settings->logo),
        ]);
    }

    /**
     * Decide what the save should do with the logo, which is three different intentions in one box.
     *
     * A new upload arrives as a data URI: the bytes go to the disk and we record where. What went
     * wrong before is that `logo_path` was not fillable, so the location was dropped on the way to
     * the row while the column it replaced was set to null in the same breath — the image was
     * written to disk and immediately forgotten, which is why a business could fill in its whole
     * letterhead and still show no logo.
     *
     * A form that was opened and saved without touching the logo posts back the URL it was given.
     * That is not a new image and not a removal, so neither column is touched. Writing the URL into
     * the column would leave the document renderer holding a link it has to fetch over HTTP, from
     * behind this application's own auth, at the moment it is drawing a PDF.
     *
     * Empty means the user pressed Remove, and that is the one case where both are cleared.
     */
    private function settleTheLogo(array $data): array
    {
        if (! array_key_exists('logo', $data)) {
            return $data;
        }

        [, $storedAt] = StoredImage::fromDataUri($data['logo'], 'branding/finance');

        if ($storedAt !== null) {
            $data['logo'] = null;
            $data['logo_path'] = $storedAt;

            return $data;
        }

        if (blank($data['logo'])) {
            $data['logo_path'] = null;

            return $data;
        }

        unset($data['logo']);

        return $data;
    }
}
