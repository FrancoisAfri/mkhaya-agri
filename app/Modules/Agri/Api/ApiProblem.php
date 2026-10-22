<?php

namespace App\Modules\Agri\Api;

use App\Modules\Operations\ProjectManagement\Support\Api\ProblemDetails;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * The farm API's one error shape: RFC 9457 problem details (`type`, `title`, `status`, `detail`,
 * `instance`, and `errors` for a validation failure), the same as the platform's other APIs, so an
 * integrator writes one error handler.
 */
final class ApiProblem
{
    public static function make(int $status, string $title, ?string $detail = null, ?Request $request = null, array $extensions = [], ?string $type = null): Response
    {
        return ProblemDetails::make($status, $title, $detail, $request, $extensions, $type);
    }

    public static function fromThrowable(Throwable $e, Request $request): Response
    {
        return ProblemDetails::fromThrowable($e, $request);
    }
}
