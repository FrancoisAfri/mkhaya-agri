<?php

namespace Tests\Feature\DocumentManagement;

use Tests\TestCase;

/**
 * Nothing in this module destroys something on a single click.
 *
 * Ten of them did. Deleting a group, a category, a template, a quota, a document, a share, a
 * folder grant and a group membership all fired straight from the button, because the confirmation
 * was a separate thing somebody had to remember to add and eight people in a row did not.
 *
 * A convention nobody checks is a convention that decays, so this reads the screens the way the
 * browser does and refuses the pattern rather than trusting the next author to remember. It is the
 * same shape as PageComponentsResolveTest: cheap, static, and it fails on the commit that
 * introduces the problem rather than in front of somebody who has just lost a folder.
 */
class DestructiveActionsAreConfirmedTest extends TestCase
{
    /**
     * Calls that end something.
     *
     * `router.delete` is the obvious one. The others are destructive through a POST — revoking
     * access and emptying the bin do not stop being irreversible because of the verb they use.
     */
    private const DESTRUCTIVE = [
        'router.delete(',
        ".destroy'",
        ".purge'",
        ".revoke'",
        ".empty'",
        "'document-management.groups.members.remove'",
    ];

    /**
     * Screens that ask in their own words rather than through the shared component.
     *
     * Both raise a dialog and both name what is about to be lost; they are listed here because a
     * closable chip and an icon button have nowhere to hang an activator slot, not because they
     * are exempt from asking.
     */
    private const CONFIRMS_ITS_OWN_WAY = [
        'FolderTree.vue',
        'RecycleBin.vue',
        'Groups.vue',
    ];

    public function test_no_screen_destroys_anything_straight_from_a_click(): void
    {
        $offenders = [];

        foreach ($this->componentFiles() as $file) {
            $source = (string) file_get_contents($file);

            if (! $this->isDestructive($source)) {
                continue;
            }

            $name = basename($file);

            if (in_array($name, self::CONFIRMS_ITS_OWN_WAY, true)) {
                // Still has to ask — just not through the shared component.
                if (! str_contains($source, 'VDialog')) {
                    $offenders[] = "{$name} destroys something and raises no dialog at all";
                }

                continue;
            }

            if (! str_contains($source, 'ConfirmAction')) {
                $offenders[] = "{$name} destroys something without a confirmation";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These screens can destroy something without asking first:\n  " . implode("\n  ", $offenders),
        );
    }

    public function test_a_destructive_call_is_never_written_inline_on_a_click(): void
    {
        /*
         * The specific shape every one of the ten took: `@click="router.delete(...)"`. Even on a
         * screen that has a confirmation for something else, an inline call like this bypasses it —
         * which is exactly how a screen ends up half-guarded and looking finished.
         */
        $offenders = [];

        foreach ($this->componentFiles() as $file) {
            $source = (string) file_get_contents($file);

            preg_match_all('/@click(?:\.\w+)*="([^"]*)"/', $source, $matches);

            foreach ($matches[1] as $handler) {
                if ($this->isDestructive($handler)) {
                    $offenders[] = basename($file) . ': @click="' . trim(preg_replace('/\s+/', ' ', $handler)) . '"';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These destroy something inline on a click, so no confirmation can stand between:\n  "
                . implode("\n  ", $offenders),
        );
    }

    public function test_the_guard_is_actually_looking_at_something(): void
    {
        // A pattern list that matched nothing would let the whole test pass silently.
        $seen = 0;

        foreach ($this->componentFiles() as $file) {
            if ($this->isDestructive((string) file_get_contents($file))) {
                $seen++;
            }
        }

        $this->assertGreaterThan(5, $seen, 'The destructive-call patterns no longer match this module.');
    }

    private function isDestructive(string $source): bool
    {
        foreach (self::DESTRUCTIVE as $needle) {
            if (str_contains($source, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function componentFiles(): array
    {
        $files = [];

        foreach (['Pages', 'Components'] as $directory) {
            $path = resource_path('js/Modules/DocumentManagement/' . $directory);

            foreach (glob($path . '/*.vue') ?: [] as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }
}
