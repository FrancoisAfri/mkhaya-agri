<?php

namespace Tests\Feature\Finance;

use Tests\TestCase;

/**
 * A migration must not write through an Eloquent model.
 *
 * This has now broken the build twice, the same way both times, and it is invisible until somebody
 * builds a database from scratch — which developers rarely do and a new customer always does.
 *
 * A migration runs against the schema as it stood on its own date. A model describes the schema as
 * it is today. The two agree right up until somebody adds a column to that table: from then on the
 * model writes a column the old migration's table does not have yet, and every fresh install dies
 * partway through `migrate` with "column does not exist" — pointing at a migration written months
 * earlier that has nothing to do with the change.
 *
 * It cost a 300-test cascade to find the first time (`FinAccount`, when entity_id arrived in Phase
 * 2a) and again the second (`FinAccountMappingSeeder`, called from its own migration, in 2b).
 *
 * Reading a constant off a model class is fine, and common: those do not touch the database.
 */
class MigrationsDoNotUseEloquentModelsTest extends TestCase
{
    /**
     * The calls that turn a model into a write.
     *
     * Static calls on the class only, deliberately. `->update(` and `->save(` are how the query
     * builder writes too, and a migration that reads a constant off a model while writing through
     * `DB::table(...)->update(...)` is doing exactly the right thing — flagging it would train
     * people to ignore this test. Both breakages so far were static calls, which is the shape this
     * catches without guessing.
     */
    private const WRITE_CALLS = [
        '::create(', '::insert(', '::updateOrCreate(', '::firstOrCreate(', '::firstOrNew(',
        '::upsert(', '::forceCreate(', '::createOrFirst(',
    ];

    public function test_no_migration_writes_through_a_model(): void
    {
        $offenders = [];

        foreach ($this->migrationFiles() as $path) {
            $source = file_get_contents($path);

            foreach ($this->modelsImported($source) as $model) {
                foreach (self::WRITE_CALLS as $call) {
                    if (str_contains($source, $model.$call)) {
                        $offenders[] = basename($path).' — '.$model.' '.trim($call, '(');
                        continue 2;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['A migration writes through an Eloquent model. Use the query builder instead:', ''],
            array_unique($offenders),
            ['', 'The model describes today\'s schema; the migration runs against its own. They',
             'diverge the moment anybody adds a column, and every fresh install then fails to',
             'migrate — see this test\'s docblock.'],
        )));
    }

    /**
     * Seeders are migrations too, when a migration calls one.
     *
     * `FinAccountMappingSeeder` is run from the migration that creates its table, so it inherits
     * exactly the same constraint — and it is easy to miss, because the seeder itself looks like
     * an ordinary seeder.
     */
    public function test_no_seeder_called_from_a_migration_writes_through_a_model(): void
    {
        $called = [];

        foreach ($this->migrationFiles() as $path) {
            preg_match_all('/new (\w+Seeder)\(\)/', file_get_contents($path), $matches);
            $called = array_merge($called, $matches[1]);
        }

        $offenders = [];

        foreach (array_unique($called) as $seeder) {
            $file = base_path("database/seeders/{$seeder}.php");

            if (! is_file($file)) {
                continue;
            }

            $source = file_get_contents($file);

            foreach ($this->modelsImported($source) as $model) {
                foreach (self::WRITE_CALLS as $call) {
                    if (str_contains($source, $model.$call)) {
                        $offenders[] = "{$seeder} (called from a migration) — {$model} ".trim($call, '(');
                        continue 2;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['A seeder run from a migration writes through an Eloquent model:', ''],
            array_unique($offenders),
            ['', 'It carries the migration\'s constraint. Name the columns with the query builder.'],
        )));
    }

    /** @return list<string> */
    private function migrationFiles(): array
    {
        $files = [];

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('database/migrations'))
        );

        foreach ($walk as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The model class names a file imports.
     *
     * Only App models — an import of Illuminate's own Model base class, or of a value object, is
     * not what this is looking for.
     *
     * @return list<string>
     */
    private function modelsImported(string $source): array
    {
        preg_match_all('/^use (App\\\\[\w\\\\]*Models\\\\(\w+));$/m', $source, $matches);

        return array_values(array_unique($matches[2]));
    }
}
