<?php

namespace App\Support\Hubs;

/**
 * Reusable base for a module's navigation "hubs" (the nav-reduction pattern first shipped for
 * Content Marketing, now generalised for CRM, Helpdesk and any future module). A module collapses
 * its many feature ribbons into a handful of hub pages; each hub shows role-filtered feature cards.
 *
 * A subclass declares only three things — code() (the module's code_name), direct() (non-hub
 * ribbons, e.g. the Dashboard), and hubs() (the groups + their cards). Everything else (the nav
 * ribbon list, the path→tier map, prefix matching) is derived here so behaviour stays identical
 * across modules.
 *
 * Access tiers mirror the app's ribbon tiers: READ=1 … SUPERUSER=5. A hub — and each card inside
 * it — only appears for users whose module access level is high enough (see HubAccess), and the
 * EnsureModuleTier middleware enforces the same tier per route so the separation is real, not
 * just visual. Card labels resolve to i18n keys "<code>.cards.<key>.title/.subtitle"; hub labels
 * to "<code>.hubs.<group>.title/.subtitle".
 */
abstract class ModuleHubs
{
    public const READ = 1;

    public const WRITE = 2;

    public const MODIFY = 3;

    public const ADMIN = 4;

    public const SUPERUSER = 5;

    /** The module's code_name (e.g. "crm", "help_desk"). */
    abstract public static function code(): string;

    /**
     * Direct (non-hub) ribbons — typically just the Dashboard. Each entry:
     * ['key','name','path','icon','tier'].
     */
    abstract public static function direct(): array;

    /**
     * Hub groups → feature cards. Keyed by group slug. Each hub:
     * ['name','path','icon','tier','title','subtitle','cards'=>[card,...]].
     * Build cards with static::card(...).
     */
    abstract public static function hubs(): array;

    /**
     * Optional base-path => tier guards for defense-in-depth. These add tier floors for whole URL
     * subtrees so a route that is NOT an exact card still gets gated (e.g. `payroll` => ADMIN means
     * any payroll/* URL requires ADMIN, while the more specific `payroll/my-payslips` card keeps its
     * own READ tier via the longest-prefix rule). Critical for sensitive modules like Payroll where
     * an un-carded admin sub-route must never fall back to READ.
     *
     * @return array<string,int>
     */
    public static function guards(): array
    {
        return [];
    }

    /** The nav ribbons (direct + hubs), in display order — shape the seeder/migration consume. */
    public static function navRibbons(): array
    {
        $out = [];
        foreach (static::direct() as $d) {
            $out[] = ['name' => $d['name'], 'path' => $d['path'], 'icon' => $d['icon'], 'access_level' => $d['tier']];
        }
        foreach (static::hubs() as $h) {
            $out[] = ['name' => $h['name'], 'path' => $h['path'], 'icon' => $h['icon'], 'access_level' => $h['tier']];
        }

        return $out;
    }

    /** Required access tier for a request path (longest, segment-aware prefix wins). */
    public static function pathTier(string $path): int
    {
        $path = trim($path, '/');
        $best = 0;
        $bestLen = -1;
        foreach (static::pathTierMap() as $base => $tier) {
            if ($path === $base || str_starts_with($path, $base . '/')) {
                $len = strlen($base);
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $best = $tier;
                }
            }
        }

        return $best ?: self::READ;
    }

    /**
     * The nav ribbon that owns a request path, or null when the path belongs to another module.
     *
     * A hub card (e.g. "disciplinary/corrective-actions") is not a ribbon of its own, so the nav
     * has nothing to match on and the sidebar branch would collapse. Mapping the card back to its
     * hub ("disciplinary/cases-hub") lets the menu keep the right ribbon highlighted while the
     * user works inside a hub. Longest, segment-aware prefix wins, so a card beats its hub.
     */
    public static function ribbonForPath(string $path): ?string
    {
        return static::ribbonMatchForPath($path)['ribbon'] ?? null;
    }

    /**
     * As ribbonForPath(), but also reports how much of the path was matched, so a caller comparing
     * several modules can pick the most specific one. Two modules can legitimately publish the same
     * page (settings/user-access is a ribbon of both Reports and User Management).
     *
     * @return array{ribbon:string,base:string}|null
     */
    public static function ribbonMatchForPath(string $path): ?array
    {
        $path = trim($path, '/');
        $best = null;
        $bestLen = -1;

        foreach (static::ribbonPathMap() as $base => $ribbon) {
            if ($path === $base || str_starts_with($path, $base . '/')) {
                $len = strlen($base);
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $best = ['ribbon' => $ribbon, 'base' => $base];
                }
            }
        }

        return $best;
    }

    /** Flat path => owning nav ribbon path map (direct pages, hubs and their cards). */
    public static function ribbonPathMap(): array
    {
        $map = [];
        foreach (static::direct() as $d) {
            $map[self::stripQuery($d['path'])] = self::stripQuery($d['path']);
        }
        foreach (static::hubs() as $hub) {
            $hubPath = self::stripQuery($hub['path']);
            $map[$hubPath] = $hubPath;
            foreach ($hub['cards'] as $card) {
                // First hub wins if two hubs list the same card, mirroring nav display order.
                $map[self::stripQuery($card['path'])] ??= $hubPath;
            }
        }

        return $map;
    }

    /** Flat path => tier map for every hub + card + direct page (query strings stripped). */
    public static function pathTierMap(): array
    {
        $map = [];
        foreach (static::direct() as $d) {
            $map[self::stripQuery($d['path'])] = $d['tier'];
        }
        foreach (static::hubs() as $hub) {
            $map[self::stripQuery($hub['path'])] = $hub['tier'];
            foreach ($hub['cards'] as $card) {
                // Keep the lowest tier when two cards share a base path (e.g. settings sub-tabs).
                $key = self::stripQuery($card['path']);
                $map[$key] = isset($map[$key]) ? min($map[$key], $card['tier']) : $card['tier'];
            }
        }
        // Subtree guards fill in only where no explicit card already sets the tier, so a card's
        // own (often lower) tier always wins over a broader guard via the longest-prefix rule.
        foreach (static::guards() as $base => $tier) {
            $base = self::stripQuery($base);
            if (! isset($map[$base])) {
                $map[$base] = $tier;
            }
        }

        return $map;
    }

    /**
     * Build a feature card. $path may carry a query string for the link; tiers strip it.
     *
     * $gate is for the handful of pages whose controller asks a policy something the tier does not
     * answer — an admin who may configure the module but may not, say, verify an audit chain. The
     * tier alone would card that page and the policy would then refuse it, which reads as a broken
     * link rather than as a boundary. Pass ['ability' => …, 'subject' => …] and the hub hides the
     * card for anyone the same policy would turn away. Omit it and nothing changes: tier decides.
     *
     * $titleKey / $descKey point the card at wording that already exists — normally the page's own
     * heading. A card added for a page that is already built and already translated then needs no
     * new strings in twelve languages, and its label cannot drift from the heading it opens. Same
     * reasoning as ribbonLabelKeys in nav.ts. Omit them for the usual `<module>.cards.<key>.*`.
     */
    protected static function card(
        string $key,
        string $path,
        string $icon,
        int $tier,
        string $color,
        ?array $gate = null,
        ?string $titleKey = null,
        ?string $descKey = null,
    ): array {
        return [
            'key'   => $key,
            'path'  => $path,
            'icon'  => $icon,
            'tier'  => $tier,
            'color' => $color,
            'gate'  => $gate,
            'title' => $titleKey ?? static::code() . '.cards.' . $key . '.title',
            'desc'  => $descKey ?? static::code() . '.cards.' . $key . '.subtitle',
        ];
    }

    /**
     * Fold hubs into one another so a module keeps to at most five sidebar ribbons.
     *
     * $plan maps a surviving hub to what it absorbs: ['insights' => ['absorb' => ['configure'],
     * 'name' => 'Insights & Settings']]. The absorbed hubs' cards follow the survivor's own, the
     * survivor's tier drops to the lowest of them (each card still carries its own tier, so nobody
     * sees a card they could not open before), and the absorbed hubs disappear — their landing
     * routes with them, since every module registers its landings from hubs().
     */
    protected static function consolidate(array $hubs, array $plan): array
    {
        foreach ($plan as $target => $spec) {
            foreach ($spec['absorb'] ?? [] as $from) {
                $hubs[$target]['cards'] = array_merge($hubs[$target]['cards'], $hubs[$from]['cards']);
                $hubs[$target]['tier'] = min($hubs[$target]['tier'], $hubs[$from]['tier']);
                unset($hubs[$from]);
            }
            foreach (['name', 'icon'] as $k) {
                if (isset($spec[$k])) {
                    $hubs[$target][$k] = $spec[$k];
                }
            }
        }

        return $hubs;
    }

    private static function stripQuery(string $path): string
    {
        return trim(explode('?', $path, 2)[0], '/');
    }
}
