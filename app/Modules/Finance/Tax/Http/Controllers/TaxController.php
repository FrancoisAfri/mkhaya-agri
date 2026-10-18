<?php

namespace App\Modules\Finance\Tax\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Tax\Exceptions\TaxControlException;
use App\Modules\Finance\Tax\Exceptions\TaxRuleException;
use App\Modules\Finance\Tax\Services\TaxAudit;
use App\Modules\Finance\Tax\Support\TaxPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Shared behaviour for the tax screens: permission checks that audit denials, safe errors, safe exports. */
abstract class TaxController extends Controller
{
    /** Abilities whose refusal is itself worth recording (§16: log denied privileged actions). */
    private const PRIVILEGED = [
        TaxPermissions::MANAGE_REGISTRATIONS, TaxPermissions::APPROVE_RULES, TaxPermissions::REOPEN_PERIODS,
        TaxPermissions::APPROVE, TaxPermissions::SIGN_OFF, TaxPermissions::ADMIN, TaxPermissions::PREPARE,
    ];

    protected function allow(string $ability, string $action = 'access'): void
    {
        if (Auth::user()?->can($ability)) {
            return;
        }
        if (in_array($ability, self::PRIVILEGED, true)) {
            app(TaxAudit::class)->denied($action, 'Permission', "Missing {$ability}", ['context' => ['ability' => $ability, 'path' => request()->path()]]);
        }
        abort(403, 'You do not have permission to do this.');
    }

    /** Run a controlled action; a refusal by a tax control comes back as a message, never a stack trace (§24). */
    protected function attempt(callable $action, string $success): RedirectResponse
    {
        try {
            $result = $action();
        } catch (TaxControlException|TaxRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', is_string($result) ? $result : $success);
    }

    /** @return array<int, array{id:int, name:string}> */
    protected function people(): array
    {
        $audit = app(TaxAudit::class);

        return \App\Core\User\Models\User::query()->with('person')->where('company_id', Auth::user()?->company_id)
            ->orderBy('email')->limit(500)->get()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $audit->nameOf($u)])->sortBy('name')->values()->all();
    }

    /**
     * CSV export with formula-injection protection (§20.3): a text cell starting with = + - @ or a
     * control character is prefixed, so a spreadsheet shows it rather than runs it. Numbers stay numbers.
     *
     * @param  list<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     */
    protected function csv(string $filename, array $headers, iterable $rows, array $meta = []): StreamedResponse
    {
        app(TaxAudit::class)->record('export.downloaded', 'Report', ['context' => ['file' => $filename] + $meta]);

        return response()->streamDownload(function () use ($headers, $rows, $meta) {
            $out = fopen('php://output', 'w');
            foreach ($meta as $k => $v) {
                fputcsv($out, [$this->cell((string) $k), $this->cell((string) $v)]);
            }
            if ($meta) {
                fputcsv($out, []);
            }
            fputcsv($out, array_map(fn ($h) => $this->cell($h), $headers));
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => $this->cell($v), $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    private function cell(mixed $v): mixed
    {
        if ($v === null || is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
            return $v;
        }
        $v = (string) $v;

        return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    }

    private ?\App\Modules\Finance\Tax\Support\TaxBusinesses $businesses = null;

    /** One instance per request, so the business list is read once however many rows name a business. */
    protected function businesses(): \App\Modules\Finance\Tax\Support\TaxBusinesses
    {
        return $this->businesses ??= app(\App\Modules\Finance\Tax\Support\TaxBusinesses::class);
    }

    protected function entityName(?int $id): ?string
    {
        return $this->businesses()->name($id);
    }

    /** The businesses tax is kept for, and the one the list is filtered to (if any). */
    protected function businessProps(?int $selected = null): array
    {
        $b = $this->businesses();

        return ['businesses' => $b->options(), 'business' => $selected, 'accountingEnabled' => $b->accountingEnabled()];
    }

    protected function businessFilter(): ?int
    {
        return $this->businesses()->filter(request()->query('business'));
    }

    /** Validation rule: one of this company's businesses (never another tenant's id). */
    protected function businessRule(): \Illuminate\Validation\Rules\In
    {
        return \Illuminate\Validation\Rule::in($this->businesses()->ids());
    }
}
