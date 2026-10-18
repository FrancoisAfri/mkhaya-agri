import { createI18n } from "vue-i18n"
import { watch } from "vue"

import en from "@/utils/locales/en.json"
import { financeLocales } from "@/utils/locales/finance"
import { accountingLocales } from "@/utils/locales/accounting"
import { bureauLocales } from "@/utils/locales/bureau"
import { dashboardHome } from "@/utils/locales/dashboard"
import { navNames } from "@/utils/locales/nav"
import modEn from "@/utils/locales/modules/en.json"

/*
 * ── One language in the bundle, the rest on request ─────────────────────────
 *
 * Every locale used to be imported at the top of this file and merged before the app started.
 * That is 24.5 MB of JSON arriving through one entry point, so Rollup put it in a single ~17 MB
 * chunk: a build that needed an 8 GB heap, a PWA plugin that had to be told not to read its own
 * output, and every user downloading twelve languages to read one.
 *
 * English stays bundled. It is `fallbackLocale`, so every screen needs it immediately — and it is
 * the base the seven newer SA languages are composed on top of. Keeping it static is also what
 * makes this change safe to deploy: if a language chunk fails to arrive for any reason — a bad
 * network, a stale cache, a chunk that did not upload — vue-i18n falls back to English rather than
 * rendering raw keys.
 *
 * The small TypeScript vocabularies (finance, accounting, bureau, dashboard, nav) stay static too.
 * Together they are a few hundred kilobytes and they are needed to compose every locale.
 */

/*
 * Which languages this install ships is discovered, not declared.
 *
 * `import.meta.glob` is resolved at build time against the files that are actually on disk, so the
 * set of languages follows the locale files themselves. Delete them and the app offers English;
 * put them back and rebuild and they return, with no code to edit — which is the whole point, and
 * the reason the list is not a hand-maintained array any more.
 *
 * The paths are relative rather than aliased because the glob has to be statically analysable:
 * Rollup can only split each language into its own chunk when it can see the path literally.
 */
const moduleGlob = import.meta.glob('./utils/locales/modules/*.json') as Record<string, () => Promise<any>>
const legacyGlob = import.meta.glob('./utils/locales/*.json') as Record<string, () => Promise<any>>

/** `./utils/locales/modules/nso.json` -> `nso`, and the one file whose name is not its code. */
const codeOf = (path: string): string => {
    const name = path.split('/').pop()!.replace(/\.json$/, '')

    return name === 'zulu' ? 'zu' : name
}

const byCode = (glob: Record<string, () => Promise<any>>): Record<string, () => Promise<any>> =>
    Object.fromEntries(
        Object.entries(glob)
            .map(([path, loader]) => [codeOf(path), loader])
            // English is bundled, never fetched.
            .filter(([code]) => code !== 'en'),
    )

// The base file for the languages that have one of their own. The others are composed on English.
const baseLoaders = byCode(legacyGlob)
// The per-module page translations: the bulk of what each language weighs.
const moduleLoaders = byCode(moduleGlob)

// Merge the finance vocabulary + accounting UI strings into each locale. New SA
// languages use English as their base and layer their translations on top;
// untranslated keys fall back to English via fallbackLocale.
const fin = (lang: string) => financeLocales[lang]?.fin ?? {}
const acc = (lang: string) => accountingLocales[lang]?.acc ?? {}
const withFin = (base: any, lang: string) => ({ ...base, fin: fin(lang), acc: acc(lang) })

// Deep-merge a partial home_page override (SA-language dashboard drafts) onto the
// English base so translated keys win and any omitted key inherits English.
const deepMerge = (base: any, over: any): any => {
    if (!over) return base
    const out: any = { ...base }
    for (const k of Object.keys(over)) {
        out[k] = over[k] && typeof over[k] === "object" && !Array.isArray(over[k])
            ? deepMerge(base?.[k] ?? {}, over[k])
            : over[k]
    }
    return out
}
// Leave/Payroll Bureau screen text. English is the base and any key a language omits falls back to
// it, so a partial translation reads as English rather than as a raw key.
//
// Applied separately from withFin rather than folded into it: French is handed the *English*
// finance vocabulary on purpose (withFin(fr, 'en')), and riding along on that argument silently
// gave French the English bureau screens too.
const bur = (lang: string) => deepMerge(bureauLocales.en.bureau, bureauLocales[lang]?.bureau ?? {})
const withBureau = (base: any, lang: string) => ({ ...base, bureau: bur(lang) })

// English base with a language's dashboard translations layered on top.
const withDash = (lang: string) => ({ ...en, home_page: deepMerge((en as any).home_page, dashboardHome[lang]) })
// Add the nav category/module name translations for a language (undefined for
// English → MainLayout falls back to the DB name).
const withNav = (base: any, lang: string) => ({ ...base, nav: navNames[lang] })
// Deep-merge a language's per-module page translations over the locale base so
// the translated namespaces win and anything untranslated inherits English.
const withModules = (base: any, modules: any) => deepMerge(base, modules ?? {})

/*
 * What this install can actually serve: English, plus every language whose files are present.
 *
 * Derived rather than listed, so the language picker, the server middleware and the stored
 * preference all agree with what was shipped. A hard-coded list would offer languages that are not
 * there, and the only symptom would be a screen of English with a flag beside it.
 */
export const SUPPORTED_LOCALES: readonly string[] = [
    'en',
    ...Object.keys({ ...moduleLoaders, ...baseLoaders }).sort(),
]
export type SupportedLocale = string

export function storedLocale(): SupportedLocale {
    const s = typeof window !== "undefined" ? window.localStorage.getItem("lang") : null
    const locale = (s && SUPPORTED_LOCALES.includes(s) ? s : null) ?? "en"

    return locale as SupportedLocale
}

export const i18n = createI18n({
    legacy:         false,
    locale:         storedLocale(),
    fallbackLocale: "en",
    messages: {
        // The only language in the bundle. The rest are registered by loadLocale().
        en: withBureau(withModules(withNav(withFin(en, 'en'), 'en'), modEn), 'en'),
    },
})

/** Languages already fetched and registered, so a second request costs nothing. */
const loaded = new Set<string>(["en"])
/** In-flight requests, so two switchers racing produce one download. */
const pending = new Map<string, Promise<void>>()

/**
 * Fetch a language and register it.
 *
 * Safe to call for anything: an unknown or already-present language resolves immediately, and a
 * failed download is reported and swallowed — the screen then renders in English, which is what
 * `fallbackLocale` is for and is a far better outcome than a page of raw keys.
 */
export function loadLocale(lang: string): Promise<void> {
    if (loaded.has(lang) || !SUPPORTED_LOCALES.includes(lang)) {
        return Promise.resolve()
    }

    const existing = pending.get(lang)
    if (existing) return existing

    const job = (async () => {
        const [baseModule, pageModule] = await Promise.all([
            baseLoaders[lang]?.() ?? Promise.resolve(null),
            moduleLoaders[lang]?.() ?? Promise.resolve(null),
        ])

        // A language with no base file of its own is composed on the English one, exactly as before.
        const base = baseModule ? (baseModule.default ?? baseModule) : withDash(lang)
        const modules = pageModule ? (pageModule.default ?? pageModule) : {}

        // French takes the ENGLISH finance vocabulary on purpose — the same deliberate exception the
        // static version carried.
        const finLang = lang === 'fr' ? 'en' : lang

        i18n.global.setLocaleMessage(
            lang,
            withBureau(withModules(withNav(withFin(base, finLang), lang), modules), lang) as any,
        )
        loaded.add(lang)
    })()
        .catch(err => {
            // Reported, not thrown. The caller is usually a click handler or the boot sequence, and
            // neither should fail because a translation did not arrive.
            console.error(`[i18n] could not load "${lang}" — falling back to English`, err)
        })
        .finally(() => pending.delete(lang))

    pending.set(lang, job)

    return job
}

/*
 * A net under every language switcher in the app.
 *
 * There are several — the header, the nav bar actions, the bureau portal — and more will be added.
 * Rather than requiring each to remember to await, anything that sets the locale triggers the
 * fetch here. A switcher that does await gets a clean change; one that does not shows English for
 * the moment the chunk is in flight, which is the fallback behaving exactly as intended.
 *
 * This is the property that makes the change safe: missing a switcher degrades the result, it does
 * not break it.
 */
watch(
    () => (i18n.global.locale as any).value as string,
    lang => { void loadLocale(lang) },
    { immediate: true },
)
