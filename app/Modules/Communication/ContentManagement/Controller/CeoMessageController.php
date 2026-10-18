<?php

namespace App\Modules\Communication\ContentManagement\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Communication\ContentManagement\Requests\CeoMessageRequest;
use App\Modules\Communication\ContentManagement\Services\CeoMessageService;

class CeoMessageController extends Controller
{
    protected $ceoMessageService;
    public function __construct(CeoMessageService $ceoMessageService)
    {
        $this->ceoMessageService = $ceoMessageService;
    }

  public function store(CeoMessageRequest $request)
{
    $this->ceoMessageService->storeCeoMessage($request);

    return redirect()->back()->with('success', 'CEO message published successfully!');
}

    public function update(CeoMessageRequest $request, $id)
    {
        $message = $this->ceoMessageService->updateCeoMessage($request, $id);

        return redirect()->back()
            ->with('success', 'Message updated successfully!');
    }
}
