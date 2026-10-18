# Ribbon → Hub Navigation Architecture (reusable blueprint)

A portable recipe for collapsing a module's many nav "ribbons" into a few **role-tiered hub pages**
without rewriting any feature code — just rearranging navigation and gating it by access level.
Built for Mkhaya Hub; copy this into **mkhayaprop** (or any Laravel + Inertia/Vue app).

---

## 0. The core idea in one line

> A module shows **N ribbons** today. Replace them with **a Dashboard + a handful of hubs**. Each hub
> is a landing page of **feature cards**. Every hub, every card, and every route carries an **access
> tier**; a user only sees/reaches what their tier allows. Feature pages and routes are untouched.

Two layers of enforcement, from ONE config file per module:
1. **Visibility** — the hub page only renders cards whose tier ≤ the user's tier (nice UX).
2. **Enforcement** — one middleware blocks any route whose tier > the user's tier (real security,
   even if someone types the URL).

---

## 1. The tier ladder (the backbone)

Five integer tiers, low number = more people can see it:

| Tier | Const | Persona (generic) | HR persona |
|------|-------|-------------------|------------|
| 1 | READ | view-only | Employee self-service (own data) |
| 2 | WRITE | create/edit | Employee actions (apply, submit, clock) |
| 3 | MODIFY | approve / manage team | Manager |
| 4 | ADMIN | module admin / config | HR / Finance / L&D |
| 5 | SUPERUSER | system / most-sensitive | System admin, audit, payroll config |

The app already stores **one access level per user per module** (e.g. `security_module_access.access_level`).
The hub system reads that number. It does **not** invent new RBAC — it maps personas onto the ladder.

**Golden rule for sensitive modules (e.g. Payroll):** put the ONE self-service item (My Payslips) at
READ and floor everything else at ADMIN+ with a *guard* (see §4). Then a normal user/manager literally
cannot see or reach payroll runs, even by URL.

---

## 2. The formulas (the "math")

### 2a. `pathTier(path)` — required tier for a URL
Longest, **segment-aware** prefix match over a flat `path => tier` map; default READ if nothing matches.

```
pathTier(path):
    path = trim(path, '/')
    best = 0 ; bestLen = -1
    for (base, tier) in pathTierMap:            # base has query string stripped
        if path == base OR path startsWith base + '/':
            if length(base) > bestLen:
                bestLen = length(base) ; best = tier
    return best or READ
```
"Segment-aware" = match on `base` or `base + '/'`, never a bare substring (so `crm/deals` does not
match `crm/deals-archive`). Longest match wins, so a specific card overrides a broad guard.

### 2b. `pathTierMap` — build the flat map
```
map = {}
for d in direct:            map[strip(d.path)]  = d.tier            # Dashboard etc.
for hub in hubs:
    map[strip(hub.path)] = hub.tier
    for card in hub.cards:  map[strip(card.path)] = min(existing, card.tier)   # lowest wins on ties
for (base, tier) in guards:                                        # subtree floors (§4)
    if base not already in map: map[base] = tier
```

### 2c. Hub / card visibility (on the page)
```
hub is shown        ⇔ hub.tier ≤ userTier        (set hub.tier = min(card.tier) so it shows if ANY card shows)
card is shown        ⇔ card.tier ≤ userTier
```

### 2d. Access resolution — `level(user, moduleCode)`
```
if no user (console/CLI)                      → SUPERUSER   # commands must see everything
if user.is_platform_admin OR user owns company → SUPERUSER
level = security_module_access.access_level for (user, module)   # cache the module id
return level > 0 ? level : READ
```

### 2e. The middleware decision
```
required = ModuleHubs.pathTier(request.path)
level    = Access.level(request.user, moduleCode)
if level < required: 403 (json/inertia) or redirect(dashboard)
else: continue
```

### 2f. Nav ribbon list (what the header renders)
`navRibbons = [ ...direct , ...hubs ]` in display order, each as
`{ name, path, icon, access_level: tier }`. The header already shows a ribbon only when
`ribbon.access_level ≤ userLevel` — so the tiers double as nav filtering.

---

## 3. The pieces (7 components)

Build once as a mini-framework, then each module is just a config class.

| # | Component | Responsibility |
|---|-----------|----------------|
| 1 | `ModuleHubs` (abstract base) | consts READ..SUPERUSER; `navRibbons()`, `pathTier()`, `pathTierMap()`, `card()` helper. Subclass declares only `code()`, `direct()`, `hubs()`, optional `guards()`. |
| 2 | `XxxHubs extends ModuleHubs` | ONE per module: the whole design as data (hubs → cards → tiers → icons → colors + i18n keys). |
| 3 | `HubRegistry` | maps `module_code => XxxHubs::class`; `for($code)`, `all()`. |
| 4 | `HubAccess::level(user, code)` | §2d. Cache the module id per code. |
| 5 | `EnsureModuleTier` middleware | §2e. Registered as `module.tier`, applied once per route group as `module.tier:<code>`. |
| 6 | `ModuleHubController::show(module, group)` | resolve class from registry, filter cards by level, render the shared Hub page. Route defaults pass module+group. |
| 7 | `Hub.vue` (shared) | data-driven card grid: props `{module, group, title, subtitle, cards[]}`; each card → `t(title)/t(desc)`, icon, color, click → `router.visit(path)`. Empty state = "no access". |

**Nav (hub) icons** use the header's icon set (Tabler in Mkhaya); **card icons** use the page's set (MDI).
Keep them distinct.

---

## 4. Guards (defense-in-depth for sensitive subtrees)

A route under an admin subtree that isn't an exact card would otherwise default to READ. Fix with a
**subtree floor**:

```
PayrollHubs::guards() = ['payroll' => ADMIN]        # every payroll/* needs ADMIN…
PayrollHubs::direct() = My Payslips @ 'payroll/my-payslips' @ READ   # …except this longer, exact match
```
Because `pathTier` picks the **longest** prefix, `payroll/my-payslips` stays READ while
`payroll/anything-else` floors at ADMIN. Use guards for `payroll`, `lms/admin`, `<mod>/setup`, etc.
Guards only fill gaps — an explicit card's (usually lower) tier always wins.

---

## 5. The card() helper + i18n convention

```
card(key, path, icon, tier, color) => {
    key, path, icon, tier, color,
    title: "<code>.cards.<key>.title",
    desc:  "<code>.cards.<key>.subtitle",
}
```
Hub labels → `"<code>.hubs.<group>.title|subtitle"`. Reuse a feature's EXISTING i18n keys where they
exist so you translate less. Add a shared `hub_ui.open` / `hub_ui.no_access`.

**Translate everything** (Mkhaya = 12 languages). Author `en` first, then fan out translation to the
other languages (one subagent per language writing a JSON file, then merge). Validate every locale has
every key before building.

---

## 6. Persisting it: seeder + migration + sync command

Ribbons live in a DB table (`security_module_ribbons`) and drive the nav. Three ways to apply:

**Seeder (fresh installs):** the module's `ribbons` array becomes `XxxHubs::navRibbons()`.

**Migration (existing installs) — the consolidation algorithm:**
```
moduleId = lookup(code); if none: return
keepNames = []
for (i, r) in navRibbons():                     # upsert the hub ribbons
    keepNames += r.name
    upsert ribbon by (moduleId, r.name) → {path, icon, access_level, sort=i+1, active}
cardToHub = { strip(card.path) => hub.path  for each card }   # where retired features now live
for old in ribbons(moduleId) where name NOT IN keepNames:      # retire everything else…
    hub = cardToHub[strip(old.path)] ?? dashboard
    re-point old's how-tos / children to the hub ribbon        # BEFORE deleting (FK cascade!)
    delete old
```
Key correctness points: **retain by NAME not path** (so a legacy ribbon sharing a hub's path is still
retired — cleans duplicates/typos), and **re-point dependent rows (how-tos) before deleting** because
FK cascade would otherwise wipe them.

**Sync command (live reload, on demand):** `hubs:sync {module?} {--prune=1}` runs the same
consolidation idempotently for one or all registered modules (+ a `--prune=0` to add hubs without
deleting legacy ribbons). This is your "reload module on the live site" button.

---

## 7. Wiring routes

```
Route::middleware(['web','auth','module.active:<code>','module.tier:<code>'])
    ->prefix('<code>')->name('<code>.')->group(function () {
        Route::get('/', [Dashboard::class,'index'])->name('dashboard');
        foreach (['create'=>'create', ...hubSlugs...] as $path => $group)
            Route::get("/{$path}", [ModuleHubController::class,'show'])
                ->defaults('module','<code>')->defaults('group',$group)->name("hub.$group");
        // …all existing feature routes unchanged…
    });
```
Give hub paths a distinct slug (e.g. `assets/register-hub`) when a feature route already owns the plain
name (`assets/register`). Add `module.tier` ONCE to the group, not per route.

---

## 8. Testing checklist (do all of it before go-live)

1. **Path tiers**: assert `pathTier()` for representative + deep sub-paths (`crm/deals/5/advise`).
2. **Guards**: an un-carded sensitive path (`payroll/whatever`) floors correctly; the READ exception
   (`payroll/my-payslips`) stays READ.
3. **Middleware E2E**: a fake low-tier user is **blocked (403)** from every admin action and **allowed**
   self-service (this is the real go-live proof).
4. **Per-role matrix**: for tiers 1..5, print the visible nav ribbons + visible cards per hub; eyeball
   the role separation.
5. **Route resolution**: every hub path + every card path resolves to a real GET route; no hub-path
   collisions with feature routes.
6. **Controller render**: each hub renders with correct card counts + i18n keys.
7. **Migration**: ribbon counts drop as expected, **0 orphaned how-tos**, duplicates gone.
8. **i18n**: every locale has every new key; frontend build passes.

---

## 9. Recipe to reuse in mkhayaprop (next week)

1. Copy the 7 components (§3) + the shared `Hub.vue`. Register `module.tier` middleware alias.
2. For each module: read its current ribbons + real routes, decide hubs + persona→tier mapping
   (§1), write `XxxHubs`, register it in `HubRegistry`.
3. Add guards for sensitive subtrees (§4).
4. Wire routes (§7), point the seeder at `navRibbons()`, write ONE consolidation migration (§6),
   add the `hubs:sync` command.
5. Add + translate i18n (§5).
6. Run the §8 checklist. Ship.

**Rules of thumb:** 5–8 hubs per module max; hub.tier = min(card tiers); sensitive module → only the
self-service item at READ + a guard; never delete a ribbon before re-pointing its dependents; keep the
whole design in the config class so a tier change is a one-line edit.
