<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records every authenticated state-changing request (POST/PUT/PATCH/DELETE)
 * in the Spatie activity_log so the /settings/audits page captures actions that
 * aren't tied to a single model save (merges, reopens, exports, bulk ops, etc.).
 *
 * Model-level changes are still logged separately by LogsActivity; this is the
 * safety net that guarantees the *action* itself is always recorded.
 */
class AuditRequests
{
    /** Write verbs we audit. */
    private const VERBS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** Route-name fragments to skip (high-frequency, low-value noise). */
    private const SKIP_FRAGMENTS = [
        'notifications.read', 'presence', 'heartbeat', 'visitors.track',
        'typing', 'livechat.', 'visitor.', 'broadcasting',
    ];

    /** Request keys never to store in properties. */
    private const REDACT = ['password', 'password_confirmation', 'current_password',
        'token', '_token', 'otp', 'pin', 'secret', 'card_number', 'cvv'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $this->record($request, $response);
        } catch (\Throwable $e) {
            // Auditing must never break the request.
        }

        return $response;
    }

    private function record(Request $request, Response $response): void
    {
        if (! in_array($request->method(), self::VERBS, true)) {
            return;
        }
        if (! Auth::check()) {
            return;
        }

        $routeName = optional($request->route())->getName() ?? $request->path();

        foreach (self::SKIP_FRAGMENTS as $frag) {
            if (str_contains((string) $routeName, $frag)) {
                return;
            }
        }

        // Only audit successful or redirect responses (skip validation 4xx/5xx noise).
        $status = $response->getStatusCode();
        if ($status >= 400) {
            return;
        }

        $logName = $this->logNameFor((string) $routeName, $request->path());

        activity($logName)
            ->causedBy(Auth::user())
            ->withProperties([
                'action'   => $routeName,
                'method'   => $request->method(),
                'path'     => '/' . ltrim($request->path(), '/'),
                'ip'       => $request->ip(),
                'route_params' => $this->routeParams($request),
                'input'    => $this->safeInput($request),
                'status'   => $status,
            ])
            ->log($this->describe($request, (string) $routeName));
    }

    /** Derive a friendly log_name (group) from the route/path prefix. */
    private function logNameFor(string $routeName, string $path): string
    {
        return match (true) {
            str_contains($routeName, 'contract') => 'Contract Management',
            str_contains($routeName, 'supplier') => 'Supplier Management',
            str_contains($routeName, 'helpdesk') => 'HelpDesk',
            str_contains($routeName, 'performance') => 'Performance',
            str_contains($routeName, 'governance')  => 'Governance',
            str_contains($routeName, 'leave')       => 'Leave',
            str_contains($routeName, 'fleet')       => 'Fleet',
            str_contains($routeName, 'asset')       => 'Assets',
            str_contains($routeName, 'payroll')     => 'Payroll',
            str_contains($routeName, 'recruit')     => 'Recruitment',
            str_contains($routeName, 'offboarding') => 'Offboarding',
            str_contains($routeName, 'lms') || str_contains($routeName, 'learning') => 'LMS',
            str_contains($routeName, 'esign')       => 'E-Sign',
            str_contains($routeName, 'disciplin')   => 'Disciplinary',
            str_contains($routeName, 'polic')       => 'Policies',
            default => 'Activity',
        };
    }

    private function describe(Request $request, string $routeName): string
    {
        $verb = match ($request->method()) {
            'POST'   => 'Created/submitted',
            'PUT', 'PATCH' => 'Updated',
            'DELETE' => 'Deleted',
            default  => 'Action',
        };

        return "{$verb}: {$routeName}";
    }

    private function routeParams(Request $request): array
    {
        $route = $request->route();
        if (! $route) {
            return [];
        }
        $params = [];
        foreach ($route->parameters() as $k => $v) {
            $params[$k] = is_object($v) ? (method_exists($v, 'getKey') ? $v->getKey() : (string) $v) : $v;
        }
        return $params;
    }

    private function safeInput(Request $request): array
    {
        $input = $this->redact($request->except(self::REDACT));

        // Trim large/binary payloads.
        array_walk_recursive($input, function (&$v) {
            if (is_string($v) && strlen($v) > 500) {
                $v = substr($v, 0, 500) . '…';
            }
        });

        return array_slice($input, 0, 40, true);
    }

    /**
     * Secrets nested anywhere in the input -- `credentials[password]`, `settings[api_key]` -- are
     * redacted too; `except()` only reaches the top level, so a nested secret used to be stored.
     */
    private function redact(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_string($key) && (in_array(strtolower($key), self::REDACT, true)
                || preg_match('/password|secret|token|api[_-]?key|credential|authori[sz]ation|private[_-]?key/i', $key))) {
                $input[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $input[$key] = $this->redact($value);
            }
        }

        return $input;
    }
}
