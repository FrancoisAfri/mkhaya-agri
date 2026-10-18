<?php

use App\Modules\HR\Payroll\Controllers\Bureau\BureauPayslipPortalController;
use Illuminate\Support\Facades\Route;

/**
 * A bureau employee's own payslips, reached by the link the bureau sends them.
 *
 * Public by necessity: these people have no Hub account and never will. The token in the
 * URL is the credential, and the PDF behind it is still password-locked with the
 * employee's own payslip key, so a leaked link alone opens nothing readable.
 *
 * Kept in its own file, outside the authenticated group in web.php, because that is the
 * only way to be sure it stays public. Declared inside the module's own route file it
 * silently inherited `auth`, and the whole feature redirected to a login page.
 */
Route::middleware(['web', 'module.active:payroll_bureau'])
    ->prefix('payroll/bureau/payslip-portal')
    ->name('payroll.bureau.portal.')
    ->group(function () {
        Route::get('/{token}', [BureauPayslipPortalController::class, 'show'])
            ->middleware('throttle:60,1')
            ->name('show');

        Route::get('/{token}/payslips/{payslip}', [BureauPayslipPortalController::class, 'download'])
            ->middleware('throttle:60,1')
            ->name('download');
    });
