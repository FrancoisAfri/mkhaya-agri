<?php

namespace App\Modules\Operations\Sheq\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * An incident cannot be classified because no rulebook is in force for this site.
 *
 * Refusing is right — an incident recorded against no pack has no statutory classification and no
 * notification deadlines, which is worse than a refused submission. Refusing with a stack trace is
 * not: in production a reporter filled in the form, pressed submit, and got a 500. What they had
 * typed was gone, and nothing told them that a regulatory pack has to be activated first.
 *
 * So the refusal renders itself, and it distinguishes the two ways of having no pack, because they
 * are fixed by different people:
 *
 *   - versions are installed but none is activated — a tenant administrator can do this on the
 *     Regulatory Packs screen;
 *   - nothing is installed at all — `sheq:packs:install` has never been run on this deployment, and
 *     no screen can do it, so telling a tenant to "activate one" sends them to an empty page.
 *
 * Nothing runs that install command automatically, so the second state is the likely one on a
 * fresh deployment.
 */
class NoActiveRegulatoryPack extends RuntimeException
{
    public function __construct(public readonly ?int $siteId = null, ?bool $anyInstalled = null)
    {
        parent::__construct(self::reason($anyInstalled ?? self::anyVersionInstalled()));
    }

    /** Whether this deployment holds any pack version at all, activated or not. */
    public static function anyVersionInstalled(): bool
    {
        return DB::table('sheq_regulatory_pack_versions')->exists();
    }

    /**
     * Phrased for whoever is reading it, which is usually not the person who can fix it.
     *
     * Static so the report form can show the same sentence before anything is typed, rather than
     * two descriptions of one problem that drift apart.
     */
    public static function reason(?bool $anyInstalled = null): string
    {
        $anyInstalled ??= self::anyVersionInstalled();

        if (! $anyInstalled) {
            return 'No regulatory pack has been installed on this system, so an incident reported '
                .'now would have no statutory classification or notification deadlines. The packs '
                .'have to be installed on the server before incidents can be reported — ask your '
                .'system administrator to run the SHEQ regulatory pack installation.';
        }

        return 'No regulatory pack is active for this site, so an incident reported now would have '
            .'no statutory classification or notification deadlines. An administrator needs to '
            .'activate one under SHEQ → Regulatory Packs before incidents can be reported.';
    }

    public function render(Request $request): mixed
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'code' => 'SHEQ_NO_ACTIVE_PACK',
                'site_id' => $this->siteId,
            ], 409);
        }

        // Back to the form with the input intact. The reason sits on `site_id` because that is the
        // field it is about, and on a company-wide gap it is the nearest thing to one.
        return back()
            ->withInput()
            ->withErrors(['site_id' => $this->getMessage()]);
    }
}
