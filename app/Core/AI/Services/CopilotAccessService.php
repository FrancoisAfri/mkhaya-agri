<?php

namespace App\Core\AI\Services;

use Illuminate\Support\Facades\DB;

class CopilotAccessService
{
    /**
     * Logical bot category => keywords matched (case-insensitive) against
     * security_modules.name OR security_modules.code_name. A user is granted
     * access to a category if they have access_level > 0 on at least one
     * matching active module.
     */
    private const CATEGORY_KEYWORDS = [
        'employee'    => ['employee', 'hr', 'people', 'staff', 'workforce', 'personnel', 'human resource'],
        'leave'       => ['leave', 'attendance', 'time off', 'absence', 'pto'],
        'asset'       => ['asset', 'equipment', 'inventory', 'hardware', 'license'],
        'payroll'     => ['payroll', 'salary', 'compensation', 'payslip'],
        'recruitment' => ['recruit', 'hiring', 'vacancy', 'candidate', 'applicant'],
        'fleet'       => ['fleet', 'vehicle', 'driver', 'transport'],
        'performance' => ['performance', 'appraisal', 'review', 'kpi'],
    ];

    /** Human-friendly labels used in the denial message. */
    private const CATEGORY_LABELS = [
        'employee'    => 'Employee / HR',
        'leave'       => 'Leave & Attendance',
        'asset'       => 'Assets',
        'payroll'     => 'Payroll',
        'recruitment' => 'Recruitment',
        'fleet'       => 'Fleet',
        'performance' => 'Performance',
    ];

    /**
     * @return array<string, bool> e.g. ['employee' => true, 'leave' => false, ...]
     */
    public function getCategoryAccess(int $userId): array
    {
        $accessibleNames = $this->accessibleModuleStrings($userId);

        $result = [];
        foreach (self::CATEGORY_KEYWORDS as $category => $keywords) {
            $result[$category] = $this->matchesAny($accessibleNames, $keywords);
        }

        return $result;
    }

    public function hasAccess(int $userId, string $category): bool
    {
        return $this->getCategoryAccess($userId)[$category] ?? false;
    }

    public function label(string $category): string
    {
        return self::CATEGORY_LABELS[$category] ?? ucfirst($category);
    }

    /**
     * Lower-cased "name|code_name" haystacks for every active module the user
     * has access_level > 0 on.
     *
     * @return string[]
     */
    private function accessibleModuleStrings(int $userId): array
    {
        return DB::table('security_modules')
            ->join('security_module_access', 'security_modules.id', '=', 'security_module_access.module_id')
            ->where('security_module_access.user_id', $userId)
            ->where('security_module_access.access_level', '>', 0)
            ->where('security_modules.active', 1)
            ->pluck(DB::raw("LOWER(COALESCE(security_modules.name,'') || '|' || COALESCE(security_modules.code_name,''))"))
            ->all();
    }

    private function matchesAny(array $haystacks, array $keywords): bool
    {
        foreach ($haystacks as $haystack) {
            foreach ($keywords as $kw) {
                if (str_contains($haystack, strtolower($kw))) {
                    return true;
                }
            }
        }
        return false;
    }
}
