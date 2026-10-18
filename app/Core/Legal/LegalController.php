<?php

namespace App\Core\Legal;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public legal pages: /privacy and /data-deletion.
 *
 * Unauthenticated on purpose, and that is the whole requirement. Facebook and LinkedIn fetch the
 * privacy policy URL with a crawler that has no session, and Facebook will not let an app leave
 * development mode until it answers. A policy behind a login is the same to them as no policy — so
 * these two routes sit outside the auth middleware, read nothing from the request, and write nothing.
 */
class LegalController extends Controller
{
    public function privacy(LegalDocuments $documents): Response
    {
        return Inertia::render('Pages/Legal/Document', [
            'kind'          => 'privacy',
            'operator'      => $documents->operator(),
            'effectiveDate' => $documents->effectiveDate(),
            'sections'      => $documents->privacy(),
        ]);
    }

    public function dataDeletion(LegalDocuments $documents): Response
    {
        return Inertia::render('Pages/Legal/Document', [
            'kind'          => 'deletion',
            'operator'      => $documents->operator(),
            'effectiveDate' => $documents->effectiveDate(),
            'sections'      => $documents->dataDeletion(),
        ]);
    }
}
