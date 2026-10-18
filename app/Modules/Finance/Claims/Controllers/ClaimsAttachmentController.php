<?php

namespace App\Modules\Finance\Claims\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Claims\Models\ClaimAttachment;
use App\Modules\Finance\Claims\Traits\ClaimsAuthTrait;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class ClaimsAttachmentController extends Controller
{
    use ClaimsAuthTrait;

    public function download(string $uuid): Response
    {
        $attachment = ClaimAttachment::where('uuid', $uuid)->firstOrFail();

        // Only the employee, admins, or approvers may download
        $claim = $attachment->claim;
        abort_unless(
            $claim->employee_id === Auth::id() || $this->isClaimsApprover(),
            403
        );

        return $attachment->fileResponse($attachment->original_name, $attachment->mime);
    }
}
