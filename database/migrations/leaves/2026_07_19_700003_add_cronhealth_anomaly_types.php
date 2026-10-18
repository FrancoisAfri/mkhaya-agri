<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Weekly cron-health + attendance cross-check anomaly types (no-show, accrual/sick cron
 * did not run, recent overdraw). Extend the leave_anomaly_flags anomaly_type CHECK.
 */
return new class extends Migration
{
    private string $constraint = 'leave_anomaly_flags_anomaly_type_check';

    private array $types = [
        'monday_friday_abuse', 'holiday_abuse', 'excessive_sick', 'negative_balance',
        'burnout_risk', 'understaffing_risk', 'pattern_detected', 'concurrent_abuse',
        'policy_violation', 'no_leave_profile', 'missing_accrual', 'balance_mismatch',
        'missing_birthdate', 'missing_join_date', 'no_sick_leave_setup', 'overtime_not_credited',
        // new
        'unauthorised_absence', 'stale_accrual', 'sick_cycle_overdue', 'recent_negative_txn',
    ];

    public function up(): void
    {
        $this->replaceCheck($this->types);
    }

    public function down(): void
    {
        $this->replaceCheck(array_slice($this->types, 0, 16));
    }

    private function replaceCheck(array $values): void
    {
        $list = implode(', ', array_map(fn ($v) => "'" . $v . "'", $values));
        DB::statement("ALTER TABLE leave_anomaly_flags DROP CONSTRAINT IF EXISTS {$this->constraint}");
        DB::statement("ALTER TABLE leave_anomaly_flags ADD CONSTRAINT {$this->constraint} CHECK (anomaly_type IN ({$list}))");
    }
};
