<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Approved overtime now credits annual leave. The ledger's transaction_type CHECK
 * constraint must allow the new 'overtime_credit' value.
 */
return new class extends Migration
{
    private string $constraint = 'leave_balance_transactions_transaction_type_check';

    private array $types = [
        'opening_balance', 'allocation', 'manual_adjustment', 'request_reservation',
        'request_release', 'request_consumption', 'payout', 'carryover', 'forfeiture',
        'correction', 'overtime_credit',
    ];

    public function up(): void
    {
        $this->replaceCheck($this->types);
    }

    public function down(): void
    {
        $this->replaceCheck(array_values(array_diff($this->types, ['overtime_credit'])));
    }

    private function replaceCheck(array $values): void
    {
        $list = implode(', ', array_map(fn ($v) => "'" . $v . "'", $values));
        DB::statement("ALTER TABLE leave_balance_transactions DROP CONSTRAINT IF EXISTS {$this->constraint}");
        DB::statement("ALTER TABLE leave_balance_transactions ADD CONSTRAINT {$this->constraint} CHECK (transaction_type IN ({$list}))");
    }
};
