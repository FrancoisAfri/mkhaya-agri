<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Services\CrmAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reusable AI translation endpoint — used to translate complaints, survey
 * feedback and customer comms (e.g. to/from South Africa's 11 official languages).
 */
class CrmTranslateController extends Controller
{
    public function translate(Request $request, CrmAiService $ai): JsonResponse
    {
        $data = $request->validate([
            'text'     => 'required|string|max:12000',
            'language' => 'required|string|max:40',
        ]);

        $result = $ai->translate($data['text'], $data['language']);

        return response()->json($result, ($result['available'] ?? false) && ! isset($result['error']) ? 200 : 422);
    }
}
