<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An employee number is the key a client's own records join on, and the key the Leave Bureau
 * importer matches people by. It has to be unique within a client, and the database has to be the
 * thing that says so.
 *
 * Until now it was a plain nullable string with no index at all. The generator picked its candidate
 * with count() + 1 and checked for a clash in PHP, which two concurrent adds both pass before
 * either inserts; and a client who typed their own number could collide with one the sequence
 * issued later. Both produce two people with the same number, which is not visible until payroll or
 * a leave import quietly attaches something to the wrong one.
 *
 * Existing data is repaired before the index goes on, because a client that already has duplicates
 * could not have the constraint applied otherwise.
 */
return new class extends Migration
{
    private const INDEX = 'bureau_employees_company_number_unique';

    public function up(): void
    {
        // Blanks first. Postgres treats every NULL as distinct so the index would accept them, but
        // a NULL is unusable as a join key — and every one of these is a row imported back when the
        // template did not carry the column.
        $this->fillBlanks();
        $this->renumberDuplicates();

        Schema::table('bureau_employees', function (Blueprint $table) {
            $table->unique(['bureau_company_id', 'employee_number'], self::INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('bureau_employees', function (Blueprint $table) {
            $table->dropUnique(self::INDEX);
        });
    }

    /** Issue a number to everyone who never got one. */
    private function fillBlanks(): void
    {
        $rows = DB::table('bureau_employees')
            ->select('id', 'bureau_company_id')
            ->where(fn ($q) => $q->whereNull('employee_number')->orWhere('employee_number', ''))
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            DB::table('bureau_employees')
                ->where('id', $row->id)
                ->update(['employee_number' => $this->nextNumber($row->bureau_company_id)]);
        }
    }

    /**
     * Where a number is held twice, the earliest row keeps it.
     *
     * Whoever was created first is the one the client's own records and any existing payslips are
     * most likely to mean, so renumbering the later copies is the change that breaks least.
     */
    private function renumberDuplicates(): void
    {
        $duplicates = DB::table('bureau_employees')
            ->select('bureau_company_id', 'employee_number')
            ->whereNotNull('employee_number')
            ->groupBy('bureau_company_id', 'employee_number')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $laterHolders = DB::table('bureau_employees')
                ->where('bureau_company_id', $duplicate->bureau_company_id)
                ->where('employee_number', $duplicate->employee_number)
                ->orderBy('id')
                ->pluck('id')
                ->skip(1);

            foreach ($laterHolders as $id) {
                DB::table('bureau_employees')
                    ->where('id', $id)
                    ->update(['employee_number' => $this->nextNumber($duplicate->bureau_company_id)]);
            }
        }
    }

    /**
     * The next free EMPnnnn for a client, read from the highest one already issued.
     *
     * Deliberately re-read per row: this loop is assigning numbers as it goes, so a value cached
     * before it started would hand the same one out twice.
     */
    private function nextNumber(int $bureauCompanyId): string
    {
        $highest = 0;

        $issued = DB::table('bureau_employees')
            ->where('bureau_company_id', $bureauCompanyId)
            ->whereNotNull('employee_number')
            ->pluck('employee_number');

        foreach ($issued as $number) {
            if (preg_match('/^EMP(\d+)$/i', (string) $number, $matches)) {
                $highest = max($highest, (int) $matches[1]);
            }
        }

        return 'EMP' . str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
    }
};
