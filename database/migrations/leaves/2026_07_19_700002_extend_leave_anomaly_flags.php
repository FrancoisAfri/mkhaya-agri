<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The integrity scanner adds account-setup / ledger anomaly types; the weekly digest
 * needs a re-alert counter. Extend the anomaly_type CHECK constraint + add alert columns.
 */
return new class extends Migration
{
    private string $constraint = 'leave_anomaly_flags_anomaly_type_check';

    private array $types = [
        'monday_friday_abuse', 'holiday_abuse', 'excessive_sick', 'negative_balance',
        'burnout_risk', 'understaffing_risk', 'pattern_detected', 'concurrent_abuse',
        'policy_violation',
        // new
        'no_leave_profile', 'missing_accrual', 'balance_mismatch', 'missing_birthdate',
        'missing_join_date', 'no_sick_leave_setup', 'overtime_not_credited',
    ];

    public function up(): void
    {
        $this->replaceCheck($this->types);

        Schema::table('leave_anomaly_flags', function (Blueprint $table) {
            if (! Schema::hasColumn('leave_anomaly_flags', 'alert_count')) {
                $table->unsignedInteger('alert_count')->default(0)->after('status');
            }
            if (! Schema::hasColumn('leave_anomaly_flags', 'last_alerted_at')) {
                $table->timestamp('last_alerted_at')->nullable()->after('alert_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('leave_anomaly_flags', function (Blueprint $table) {
            $table->dropColumn(['alert_count', 'last_alerted_at']);
        });
        $this->replaceCheck(array_slice($this->types, 0, 9));
    }

    private function replaceCheck(array $values): void
    {
        $list = implode(', ', array_map(fn ($v) => "'" . $v . "'", $values));
        DB::statement("ALTER TABLE leave_anomaly_flags DROP CONSTRAINT IF EXISTS {$this->constraint}");
        DB::statement("ALTER TABLE leave_anomaly_flags ADD CONSTRAINT {$this->constraint} CHECK (anomaly_type IN ({$list}))");
    }
};
