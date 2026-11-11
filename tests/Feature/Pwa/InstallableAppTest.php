<?php

namespace Tests\Feature\Pwa;

use Tests\TestCase;

/**
 * Every user can install Mkhaya Agri on a phone or laptop and open it like an app: a manifest, the icons,
 * a service worker that never keeps a page (so a deploy is never hidden behind yesterday's copy),
 * an offline notice, and an "Install app" button in the top bar.
 */
class InstallableAppTest extends TestCase
{
    public function test_the_manifest_opens_the_whole_app_full_screen(): void
    {
        $m = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);

        $this->assertSame(['Mkhaya Agri', '/', 'standalone'], [$m['name'], $m['scope'], $m['display']]);
        $this->assertStringStartsWith('/?', $m['start_url']);
        foreach ($m['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
            [$w, $h] = getimagesize(public_path(ltrim($icon['src'], '/')));
            $this->assertSame($icon['sizes'], "{$w}x{$h}", $icon['src']);
        }
        $this->assertContains('maskable', array_column($m['icons'], 'purpose'));
    }

    public function test_every_page_links_it_and_registers_the_worker(): void
    {
        $head = file_get_contents(resource_path('views/app.blade.php'));
        $this->assertStringContainsString('<link rel="manifest" href="/manifest.webmanifest">', $head);
        $this->assertStringContainsString("navigator.serviceWorker.register('/sw.js')", $head);
        $this->assertStringContainsString('beforeinstallprompt', $head);
    }

    public function test_the_worker_keeps_only_hashed_build_files_and_the_offline_notice(): void
    {
        $sw = file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString('const CACHEABLE = [/^\/build\//, /^\/icons\//, /^\/images\//, /^\/favicon\./];', $sw);
        $this->assertStringContainsString("if (request.method !== 'GET') return;", $sw);
        // It clears only its own caches: other workers on the site (contractor, farm, till) keep theirs.
        $this->assertStringContainsString("name.startsWith('mkhaya-app-')", $sw);
        $this->assertFileExists(public_path('offline.html'));
    }

    public function test_the_top_bar_offers_install_app(): void
    {
        $this->assertStringContainsString('<InstallAppButton', file_get_contents(resource_path('js/Core/Layouts/MainLayout.vue')));
        $this->assertFileExists(resource_path('js/Components/Shared/InstallAppButton.vue'));
    }
}
