<?php

namespace App\Modules\HR\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Hr\Services\HRPersonService;
use Illuminate\Http\Request;

class HRPersonController extends Controller
{
    public function __construct(
        protected HRPersonService $service
    ) {}

    public function index(): \Illuminate\Http\JsonResponse
    {
        return response()->json(
            $this->service->list()
        );
    }

    public function store(Request $request)
    {
        $person = $this->service->create(
            $request->validated()
        );

        return response()->json($person, 201);
    }

    public function show(HRPerson $hrPerson)
    {
        return response()->json($hrPerson);
    }

    public function update(Request $request, HRPerson $hrPerson)
    {
        $updated = $this->service->update($hrPerson, $request->validated());

        return response()->json($updated);
    }
}

