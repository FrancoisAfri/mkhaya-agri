<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Employment Equity return — EEA2 (workforce profile) and EEA4 (income differentials).
 *
 * A designated employer files these with the Department of Employment and Labour every year, and
 * EEA4 is an income report: headcount and total remuneration for every occupational level, split by
 * race and gender. Payroll is the only place that holds the remuneration half, which is why this
 * lives here rather than in HR.
 *
 * Two things this deliberately does NOT do:
 *
 * 1. It does not guess. The people record carries free-ish codes for race and occupational level
 *    that predate any EE requirement — six ethnicities where the Act names four, seven occupational
 *    levels where the Act names six, and no code at all for top management. Everything that cannot
 *    be placed with confidence lands in an `unclassified` row and is counted in `data_quality`,
 *    with the employees named, so the gap is fixed in the people record rather than papered over in
 *    the report. A return that silently drops people is worse than one that shows a hole.
 *
 * 2. It does not file anything. The Department's submission is a signed PDF on their own portal;
 *    what is produced here is the figures to enter and a CSV to keep with the file.
 */
class EmploymentEquityService
{
    /** The four population groups the Act names, plus foreign nationals, who are reported apart. */
    public const RACES = ['african', 'coloured', 'indian', 'white', 'foreign', 'unclassified'];

    public const GENDERS = ['male', 'female', 'unclassified'];

    /** EEA2 occupational levels, top to bottom. Order is the order on the form. */
    public const LEVELS = [
        'top_management',
        'senior_management',
        'professionally_qualified',
        'skilled_technical',
        'semi_skilled',
        'unskilled',
        'unclassified',
    ];

    /**
     * The people record's ethnicity ids, mapped onto the Act's groups by name rather than by id —
     * an installation that seeded the lookup in a different order would otherwise report Coloured
     * employees as White.
     */
    private const RACE_BY_NAME = [
        'african'   => 'african',
        'black'     => 'african',
        'coloured'  => 'coloured',
        'indian'    => 'indian',
        'asian'     => 'indian',
        'white'     => 'white',
        'caucasian' => 'white',
    ];

    /**
     * The people record's occupational levels, mapped onto the Act's.
     *
     * 'Professional' and 'Skilled' have no counterpart on the form and are placed at the level the
     * Act describes them at. There is no code for top management, so that row stays empty until one
     * exists — shown rather than hidden, because a return with no top management is a question the
     * Department asks.
     */
    private const LEVEL_BY_NAME = [
        'top management'     => 'top_management',
        'senior management'  => 'senior_management',
        'middle management'  => 'professionally_qualified',
        'professional'       => 'professionally_qualified',
        'junior management'  => 'skilled_technical',
        'skilled'            => 'skilled_technical',
        'semi skilled'       => 'semi_skilled',
        'semi-skilled'       => 'semi_skilled',
        'unskilled'          => 'unskilled',
    ];

    /** The people record's numeric occupational levels, as the HR screens label them. */
    private const LEVEL_LABELS = [
        1 => 'senior management', 2 => 'middle management', 3 => 'junior management',
        4 => 'semi skilled', 5 => 'unskilled', 6 => 'professional', 7 => 'skilled',
    ];

    /** 1 is Male and 2 is Female everywhere the codes are read. */
    private const GENDER_CODES = [1 => 'male', 2 => 'female'];

    public function __construct(private readonly CurrentCompany $companies) {}

    /**
     * The reporting year runs 1 September to 31 August, which is the window the Department's own
     * reporting period follows.
     */
    public function yearBounds(int $year): array
    {
        return [
            Carbon::create($year - 1, 9, 1)->startOfDay(),
            Carbon::create($year, 8, 31)->endOfDay(),
        ];
    }

    public function report(int $year, ?int $companyId = null): array
    {
        $companyId ??= $this->companies->id();
        [$from, $to] = $this->yearBounds($year);

        $people = $this->people($companyId);
        $pay = $this->remuneration($companyId, $from, $to);

        $profile = $this->emptyGrid();
        $income = $this->emptyGrid();
        $unclassified = ['race' => [], 'gender' => [], 'level' => [], 'citizenship' => []];
        $disabled = $this->emptyGrid();
        $foreign = 0;

        foreach ($people as $person) {
            $race = $this->raceOf($person);
            $gender = self::GENDER_CODES[(int) $person->gender] ?? 'unclassified';
            $level = $this->levelOf($person);

            $name = trim(($person->first_name ?? '') . ' ' . ($person->surname ?? '')) ?: ('#' . $person->id);
            if ($race === 'unclassified') {
                $unclassified['race'][] = $name;
            }
            if ($gender === 'unclassified') {
                $unclassified['gender'][] = $name;
            }
            if ($level === 'unclassified') {
                $unclassified['level'][] = $name;
            }
            if ($race === 'foreign') {
                $foreign++;
            }
            if (blank($person->citizenship)) {
                $unclassified['citizenship'][] = $name;
            }

            $profile[$level][$race][$gender]++;
            if ((int) ($person->disabled ?? 0) === 1) {
                $disabled[$level][$race][$gender]++;
            }

            // EEA4 asks for total remuneration, which is gross pay for the year — not basic salary,
            // and not net. An employee with no payslip in the window still counts in the headcount.
            $income[$level][$race][$gender] += (float) ($person->user_id === null ? 0 : ($pay[$person->user_id] ?? 0));
        }

        return [
            'year'         => $year,
            'period'       => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'headcount'    => $people->count(),
            'profile'      => $profile,
            'income'       => $income,
            'disabled'     => $disabled,
            'foreign'      => $foreign,
            'differential' => $this->differential($profile, $income),
            'data_quality' => [
                'missing_race'   => $unclassified['race'],
                'missing_gender' => $unclassified['gender'],
                'missing_level'  => $unclassified['level'],
                'missing_citizenship' => $unclassified['citizenship'],
                'complete'       => $unclassified['race'] === [] && $unclassified['gender'] === []
                    && $unclassified['level'] === [] && $unclassified['citizenship'] === [],
            ],
        ];
    }

    /**
     * The income differential the EEA4 exists to expose: average remuneration at the top level
     * against the bottom, and the widest gap between two groups doing the same level of work.
     */
    public function differential(array $profile, array $income): array
    {
        $averagePerLevel = [];
        foreach (self::LEVELS as $level) {
            $heads = 0;
            $paid = 0.0;
            foreach (self::RACES as $race) {
                foreach (self::GENDERS as $gender) {
                    $heads += $profile[$level][$race][$gender];
                    $paid += $income[$level][$race][$gender];
                }
            }
            $averagePerLevel[$level] = $heads > 0 ? round($paid / $heads, 2) : null;
        }

        $widest = null;
        foreach (self::LEVELS as $level) {
            $cells = [];
            foreach (self::RACES as $race) {
                foreach (self::GENDERS as $gender) {
                    $heads = $profile[$level][$race][$gender];
                    if ($heads > 0 && $income[$level][$race][$gender] > 0) {
                        $cells[$race . ':' . $gender] = $income[$level][$race][$gender] / $heads;
                    }
                }
            }

            // One group at a level cannot be compared with anything, so it is not a gap.
            if (count($cells) < 2) {
                continue;
            }

            $high = max($cells);
            $low = min($cells);
            $gap = $low > 0 ? round(($high - $low) / $low * 100, 1) : null;
            if ($gap !== null && ($widest === null || $gap > $widest['gap_percent'])) {
                $widest = [
                    'level'       => $level,
                    'highest'     => array_search($high, $cells, true),
                    'lowest'      => array_search($low, $cells, true),
                    'gap_percent' => $gap,
                ];
            }
        }

        return ['average_per_level' => $averagePerLevel, 'widest_gap' => $widest];
    }

    public function toCsv(array $report): string
    {
        $out = "Occupational level,Race,Gender,Headcount,Total remuneration,Average remuneration\r\n";
        foreach (self::LEVELS as $level) {
            foreach (self::RACES as $race) {
                foreach (self::GENDERS as $gender) {
                    $heads = $report['profile'][$level][$race][$gender];
                    if ($heads === 0) {
                        continue;
                    }
                    $paid = $report['income'][$level][$race][$gender];
                    $out .= implode(',', [
                        $level, $race, $gender, $heads,
                        number_format($paid, 2, '.', ''),
                        number_format($paid / $heads, 2, '.', ''),
                    ]) . "\r\n";
                }
            }
        }

        return $out;
    }

    // ── Reading the people and the pay ───────────────────────────────────────

    private function people(int $companyId)
    {
        return DB::table('hr_people as p')
            ->leftJoin('ethnicities as e', 'e.id', '=', 'p.ethnicity')
            ->where('p.company_id', $companyId)
            // Status 1 is employed. A blank status is read as employed too: the field arrived after
            // most of these records did, and leaving those people out would understate the workforce.
            ->where(fn ($q) => $q->where('p.status', 1)->orWhereNull('p.status'))
            ->select('p.id', 'p.user_id', 'p.first_name', 'p.surname', 'p.gender', 'p.disabled',
                'p.citizenship', 'p.occupational_level', 'e.value as ethnicity_name')
            ->get();
    }

    /** Gross pay for the window, per user, from finalised payslips. */
    private function remuneration(int $companyId, Carbon $from, Carbon $to): array
    {
        return DB::table('payslips')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereDate('period_end', '>=', $from->toDateString())
            ->whereDate('period_end', '<=', $to->toDateString())
            ->groupBy('employee_id')
            ->selectRaw('employee_id, sum(gross_pay) as total')
            ->pluck('total', 'employee_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    private function raceOf(object $person): string
    {
        // Permanent resident, work permit and other are all "not a South African citizen", which is
        // the question the form asks — foreign nationals are their own line rather than a population
        // group. A blank citizenship is not read as foreign; it is counted as a gap instead, because
        // most records predate the field and reading them all as foreign would be a fabrication.
        if (in_array((int) ($person->citizenship ?? 0), [2, 3, 4], true)) {
            return 'foreign';
        }

        $name = strtolower(trim((string) ($person->ethnicity_name ?? '')));

        return self::RACE_BY_NAME[$name] ?? 'unclassified';
    }

    private function levelOf(object $person): string
    {
        $label = self::LEVEL_LABELS[(int) ($person->occupational_level ?? 0)] ?? null;

        return $label === null ? 'unclassified' : (self::LEVEL_BY_NAME[$label] ?? 'unclassified');
    }

    private function emptyGrid(): array
    {
        $grid = [];
        foreach (self::LEVELS as $level) {
            foreach (self::RACES as $race) {
                foreach (self::GENDERS as $gender) {
                    $grid[$level][$race][$gender] = 0;
                }
            }
        }

        return $grid;
    }
}
