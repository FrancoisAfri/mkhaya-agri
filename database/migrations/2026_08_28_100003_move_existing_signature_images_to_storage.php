<?php

use App\Core\Storage\StoredImage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves signatures already drawn into the shared disk.
 *
 * A migration rather than a console command because these are not base64 file blobs — they are
 * data URIs posted by the signing pad — so `storage:consolidate` cannot read them, and there is no
 * reason to make an operator run a one-off by hand for something that has to happen exactly once.
 *
 * Each row is written and verified before its column is cleared, and a row that fails either step
 * is left exactly as it was: a signature is evidence that someone agreed to something, and losing
 * one is worse than the duplication continuing.
 */
return new class extends Migration
{
    /** table => [image column, path column, folder] */
    private const TABLES = [
        'pm_contract_signatures' => ['signature_data', 'signature_path', 'project-management/signatures'],
        'esign_signers'          => ['signature_data', 'signature_path', 'esign/signatures'],
    ];

    public function up(): void
    {
        // No tenant context: this has to see every row, not one company's.
        DB::statement("SELECT set_config('app.company_id', '', false)");

        foreach (self::TABLES as $table => [$column, $pathColumn, $folder]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $pathColumn)) {
                continue;
            }

            DB::table($table)
                ->whereNotNull($column)
                ->whereNull($pathColumn)
                ->orderBy('id')
                ->chunkById(50, function ($rows) use ($table, $column, $pathColumn, $folder) {
                    foreach ($rows as $row) {
                        $this->move($table, $column, $pathColumn, $folder, $row);
                    }
                });
        }
    }

    private function move(string $table, string $column, string $pathColumn, string $folder, object $row): void
    {
        $value = (string) $row->{$column};

        // A typed signature is a name, not an image. It belongs in the column and stays there.
        if (! str_starts_with($value, 'data:image')) {
            return;
        }

        [$remaining, $path] = StoredImage::fromDataUri($value, $folder);

        if ($path === null) {
            return;
        }

        // Read it back before letting go of the original.
        $stored = StoredImage::bytes($path);
        $expected = base64_decode((string) preg_replace('#^data:[^;]+;base64,#', '', $value), true) ?: '';

        if ($stored === null || $stored !== $expected) {
            return;
        }

        DB::table($table)->where('id', $row->id)->update([
            $pathColumn => $path,
            $column => $remaining,
        ]);
    }

    public function down(): void
    {
        // Deliberately not reversed. Putting the images back into their columns would mean reading
        // every file and re-encoding it, and the forward path stays readable either way — the
        // accessor falls back to the column, so nothing depends on this being undone.
    }
};
