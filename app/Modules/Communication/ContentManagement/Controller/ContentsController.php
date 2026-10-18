<?php

namespace App\Modules\Communication\ContentManagement\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Communication\ContentManagement\Models\Content;
use App\Modules\Communication\ContentManagement\Requests\ContentsRequest;
use App\Modules\Communication\ContentManagement\Services\ContentsService;


class ContentsController extends Controller
{
    public function __construct(
        protected ContentsService $contentService,

    ) {}


    /*
    |--------------------------------------------------------------------------
    | STORE
    |--------------------------------------------------------------------------
    */

    public function store(ContentsRequest $request)
    {
        $content = $this->contentService->store(
            $request->validated()
        );

        return back()->with('success', 'Content created successfully');
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    public function update(ContentsRequest $request,int $id)
    {
        $this->contentService->update(
            $request->validated(),
            $id
        );

        return back()->with('success', 'Content updated successfully');
    }
       public function destroy(int $id)
    {
        $content=Content::findOrFail($id);

        $content->delete();

        return back()->with('success', 'Content deleted successfully');
    }

}
