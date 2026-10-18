<script setup>
import { ref, onMounted, onUnmounted, nextTick } from "vue";
import { Link, useForm, router } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import { ribbonLabelKeys, ribbonSlug } from "@/utils/locales/nav";
import { SUPPORTED_LOCALES } from "@/i18n";

const page = usePage();
const form = useForm({});
const { t, te, locale } = useI18n();

// Ribbon labels resolve to the page's own title key, so the top nav and the
// heading it leads to read the same in every language (see utils/locales/nav.ts).
function tRibbon(ribbon) {
    const key = ribbonLabelKeys[ribbonSlug(ribbon.ribbon_path)];
    return key && te(key) ? t(key) : ribbon.ribbon_name;
}

/* ── Sticky scroll ── */
const isScrolled = ref(false);
const isHidden = ref(false);
let lastScrollY = 0;

function handleScroll() {
    const currentY = window.scrollY;
    isScrolled.value = currentY > 10;
    isHidden.value = currentY > lastScrollY && currentY > 80;
    lastScrollY = currentY;
}

/* ── Mobile ── */
const mobileOpen = ref(false);
const openMobileDropdown = ref(null);
const openMobileModule = ref(null);

function toggleNav() {
    mobileOpen.value = !mobileOpen.value;
}
function toggleMobileDropdown(key) {
    openMobileDropdown.value = openMobileDropdown.value === key ? null : key;
    openMobileModule.value = null;
}
function toggleMobileModule(key) {
    openMobileModule.value = openMobileModule.value === key ? null : key;
}

/* ── Desktop dropdowns ── */
const openDesktopDropdown = ref(null);
const openDesktopModule = ref(null);

function toggleDesktopDropdown(key, e) {
    e.stopPropagation();
    if (openDesktopDropdown.value === key) {
        openDesktopDropdown.value = null;
        return;
    }
    openDesktopDropdown.value = key;
    openDesktopModule.value = null;
    // Anchor the (teleported, fixed) dropdown under the trigger so the
    // horizontally-scrolling nav bar never clips it.
    const rect = e.currentTarget.getBoundingClientRect();
    dropdownPos.value = { top: rect.bottom + 6, left: rect.left };
}

/* ── Horizontal category-bar scrolling (slide instead of wrapping) ── */
const navScrollRef = ref(null);
const dropdownPos = ref({ top: 0, left: 0 });
const canScrollLeft = ref(false);
const canScrollRight = ref(false);

function updateNavArrows() {
    const el = navScrollRef.value;
    if (!el) return;
    canScrollLeft.value = el.scrollLeft > 4;
    canScrollRight.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 4;
}

function scrollNav(dir) {
    const el = navScrollRef.value;
    if (!el) return;
    el.scrollBy({ left: dir * Math.max(220, el.clientWidth * 0.6), behavior: "smooth" });
    // Any open dropdown would drift from its trigger while scrolling.
    closeDesktopDropdowns();
}

function setHoveredModule(key) {
    openDesktopModule.value = key;
}

function clearHoveredModule() {
    openDesktopModule.value = null;
}

function closeDesktopDropdowns() {
    openDesktopDropdown.value = null;
    openDesktopModule.value = null;
}

/* ── User menu ── */
const userMenuOpen = ref(false);
const langMenuOpen = ref(false);
const userMenuRef = ref(null);
const currentLang = ref(localStorage.getItem("lang") || "en");

const languages = [
    { code: "en", label: "English",    flag: "🇬🇧" },
    { code: "zu", label: "isiZulu",    flag: "🇿🇦" },
    { code: "af", label: "Afrikaans",  flag: "🇿🇦" },
    { code: "tn", label: "Setswana",   flag: "🇿🇦" },
    { code: "fr", label: "Français",   flag: "🇫🇷" },
];


/*
 * Offered only where the language was actually shipped.
 *
 * The labels below are the full table and stay that way: `SUPPORTED_LOCALES` is derived from the
 * locale files present at build time, so removing a language removes it from this menu, and putting
 * its files back brings it straight back. A hard-coded menu would offer a flag that produces a
 * screen of English.
 */
const availableLanguages = computed(() => languages.filter(l => SUPPORTED_LOCALES.includes(l.code)));

function switchLanguage(code) {
    currentLang.value = code;
    locale.value       = code;
    localStorage.setItem("lang", code);
    langMenuOpen.value = false;
    userMenuOpen.value = false;

    // Persist to server so the preference survives across devices
    fetch("/user/locale", {
        method: "PATCH",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? "",
        },
        body: JSON.stringify({ locale: code }),
    }).catch(() => { /* silently ignore if not logged in */ });
}

function handleClickOutside(e) {
    if (userMenuRef.value && !userMenuRef.value.contains(e.target)) {
        userMenuOpen.value = false;
        langMenuOpen.value = false;
    }
    closeDesktopDropdowns();
}

function submitLogout() {
    form.post(route("auth.logout"), {
        onSuccess: () => window.location.replace("/login"),
    });
}

function lockScreen() {
    router.visit("/lock");
}

/* ── Nav items ── */
const navItems = computed(() => {
    return (page.props.navigation ?? []).map((category) => ({
        key: category.id,
        label: category.name,
        icon: category.icon,
        modules: (category.modules ?? []).map((module) => ({
            key: module.id,
            label: module.name,
            icon: module.font_awesome,
            ribbons: (module.module_ribbons ?? []).map((ribbon) => ({
                icon: ribbon.font_awesome,
                label: tRibbon(ribbon),
                href: ribbon.ribbon_path,
            })),
        })),
    }));
});

onMounted(() => {
    locale.value = currentLang.value;
    window.addEventListener("scroll", handleScroll, { passive: true });
    window.addEventListener("resize", updateNavArrows);
    document.addEventListener("click", handleClickOutside);
    nextTick(updateNavArrows);
});

onUnmounted(() => {
    window.removeEventListener("scroll", handleScroll);
    window.removeEventListener("resize", updateNavArrows);
    document.removeEventListener("click", handleClickOutside);
});

const profileCompletion = computed(() => page.props.profile_completion || 0);

// White-label: when the tenant has it active, the header shows THEIR company name and
// logo (resolveBranding() only populates these when white_label is on), else the default.
const systemLogo = computed(
    () => (page.props as any).branding?.system_logo || "/images/logo_default.png",
);
const systemName = computed(
    () => (page.props as any).branding?.system_name || "MKHAYA HUB",
);

const radius = 16;
const circumference = 2 * Math.PI * radius;

const offset = computed(() => {
    return circumference - (profileCompletion.value / 100) * circumference;
});

const strokeColor = computed(() => {
    if (profileCompletion.value < 30) return '#ef4444'
    if (profileCompletion.value < 80) return '#f59e0b'
    return '#10b981'
})
</script>

<template>
    <nav
        class="navbar px-4 sm:px-6 lg:px-8 py-3 transition-all duration-300"
        :class="[
            isScrolled
                ? 'fixed top-0 left-0 right-0 z-[999] bg-white/95 backdrop-blur-md shadow-lg border-b border-gray-100'
                : 'relative bg-white',
            isHidden ? '-translate-y-full' : 'translate-y-0',
        ]"
    >
        <!-- Barre scroll -->
        <div
            v-if="isScrolled"
            class="absolute bottom-0 left-0 h-[2px] bg-gradient-to-r from-teal-500 to-blue-600 transition-all duration-300"
            :style="{
                width: `${(window?.scrollY / (document?.body?.scrollHeight - window?.innerHeight)) * 100 || 0}%`,
            }"
        />

        <div class="flex items-center justify-between w-full">
            <div class="flex w-full gap-10">
                <!-- Logo -->
                <Link class="flex items-center gap-2 flex-shrink-0" href="/">
                    <img
                        :src="systemLogo"
                        :alt="systemName"
                        class="object-contain transition-all duration-300"
                        :class="isScrolled ? 'w-8 h-8' : 'w-10 h-10'"
                    />
                    <span
                        class="brand font-bold tracking-wide hidden sm:block transition-all duration-300"
                        :class="isScrolled ? 'text-base' : 'text-lg'"
                        style="color: #020d31"
                        >{{ systemName }}</span
                    >
                </Link>

                <!-- ══ Desktop Nav (single row, slides left/right) ══ -->
                <div class="hidden lg:flex items-center flex-1 min-w-0">
                    <button
                        v-show="canScrollLeft"
                        type="button"
                        class="nav-arrow mr-1"
                        aria-label="Scroll left"
                        @click="scrollNav(-1)"
                    >
                        <i class="fa-solid fa-house mr-1" /> {{ t("home") }}
                    <!-- </Link> -->
                        <i class="fa-solid fa-chevron-left" />
                    </button>

                    <div
                        ref="navScrollRef"
                        class="nav-scroll flex items-center gap-6 xl:gap-10 flex-1 min-w-0 overflow-x-auto flex-nowrap py-1"
                        @scroll="updateNavArrows"
                    >
                        <Link
                            href="/"
                            class="nav-link font-semibold flex-shrink-0"
                            style="color: #020d31"
                        >
                            <i class="fa-solid fa-house mr-1" /> Home
                        </Link>

                        <!-- Catégories -->
                        <div
                            v-for="item in navItems"
                            :key="item.key"
                            class="relative flex-shrink-0"
                        >
                        <!-- Trigger catégorie -->
                        <span
                            class="nav-link cursor-pointer flex items-center gap-1"
                            @click="toggleDesktopDropdown(item.key, $event)"
                        >
                            <i :class="`fa-solid fa-${item.icon} mr-1`" />
                            {{ item.label }}
                            <i
                                class="fa-solid fa-chevron-down text-[10px] ml-1 transition-transform duration-200"
                                :class="{
                                    'rotate-180':
                                        openDesktopDropdown === item.key,
                                }"
                            />
                        </span>

                        <!-- Dropdown catégorie (liste modules) — teleported so the
                             scrolling nav bar never clips it -->
                        <Teleport to="body">
                        <transition
                            enter-active-class="transition ease-out duration-200"
                            enter-from-class="opacity-0 translate-y-2 scale-95"
                            enter-to-class="opacity-100 translate-y-0 scale-100"
                            leave-active-class="transition ease-in duration-150"
                            leave-from-class="opacity-100 translate-y-0 scale-100"
                            leave-to-class="opacity-0 translate-y-2 scale-95"
                        >
                            <div
                                v-show="openDesktopDropdown === item.key"
                                class="fixed z-[200] bg-white rounded-lg shadow-lg border border-gray-100 py-1 min-w-[275px]"
                                :style="{ top: dropdownPos.top + 'px', left: dropdownPos.left + 'px' }"
                                @click.stop
                            >
                                <!-- ✅ Chaque ligne = un module qui ouvre le dropright -->
                                <div
                                    v-for="mod in item.modules"
                                    :key="mod.key"
                                    class="relative group"
                                    @mouseenter="
                                        setHoveredModule(
                                            `${item.key}-${mod.key}`,
                                        )
                                    "
                                    @mouseleave="clearHoveredModule"
                                >
                                    <!-- Module row -->
                                    <div
                                        class="flex items-center justify-between px-4 py-2.5 cursor-pointer hover:bg-gray-50 transition-colors rounded-lg mx-1"
                                        :class="{
                                            'bg-indigo-50':
                                                openDesktopModule ===
                                                `${item.key}-${mod.key}`,
                                        }"
                                    >
                                        <span
                                            class="flex items-center gap-2 text-sm font-medium text-gray-700"
                                        >
                                            <i
                                                :class="`fa-solid fa-${mod.icon} text-indigo-400 w-4`"
                                            />
                                            {{ mod.label }}
                                        </span>
                                        <i
                                            v-if="mod.ribbons.length"
                                            class="fa-solid fa-chevron-right text-[10px] text-gray-400 ml-3"
                                        />
                                    </div>

                                    <!-- ✅ Dropright ribbons (au hover du module) -->
                                    <transition
                                        enter-active-class="transition ease-out duration-150"
                                        enter-from-class="opacity-0 translate-x-2 scale-95"
                                        enter-to-class="opacity-100 translate-x-0 scale-100"
                                        leave-active-class="transition ease-in duration-100"
                                        leave-from-class="opacity-100 translate-x-0 scale-100"
                                        leave-to-class="opacity-0 translate-x-2 scale-95"
                                    >
                                        <div
                                            v-show="
                                                openDesktopModule ===
                                                    `${item.key}-${mod.key}` &&
                                                mod.ribbons.length
                                            "
                                            class="absolute left-full top-0 ml-1 z-50 bg-white rounded-xl shadow-lg border border-gray-100 py-1 min-w-[200px]"
                                            @mouseenter="
                                                setHoveredModule(
                                                    `${item.key}-${mod.key}`,
                                                )
                                            "
                                            @mouseleave="clearHoveredModule"
                                        >
                                            <!-- Header module dans le dropright -->
                                            <div
                                                class="px-4 py-2 border-b border-gray-50 mb-1"
                                            >
                                                <p
                                                    class="text-[11px] font-bold text-gray-400 uppercase tracking-wider flex items-center gap-1.5"
                                                >
                                                    <i
                                                        :class="`fa-solid fa-${mod.icon} text-indigo-400`"
                                                    />
                                                    {{ mod.label }}
                                                </p>
                                            </div>

                                            <!-- Ribbons -->
                                            <Link
                                                v-for="ribbon in mod.ribbons"
                                                :key="ribbon.label"
                                                :href="'/' + ribbon.href"
                                                class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 transition-colors rounded-lg mx-1"
                                                @click="closeDesktopDropdowns"
                                            >
                                                <i
                                                    :class="`fa-solid fa-${ribbon.icon} w-4 text-indigo-400`"
                                                />
                                                {{ ribbon.label }}
                                            </Link>

                                            <!-- Empty -->
                                            <div
                                                v-if="!mod.ribbons.length"
                                                class="px-4 py-3 text-xs text-gray-400 italic"
                                            >
                                                No pages available
                                            </div>
                                        </div>
                                    </transition>
                                </div>

                                <!-- Catégorie sans modules -->
                                <div
                                    v-if="!item.modules.length"
                                    class="px-4 py-3 text-xs text-gray-400 italic"
                                >
                                    No modules available
                                </div>
                            </div>
                        </transition>
                        </Teleport>
                        </div>
                    </div>

                    <button
                        v-show="canScrollRight"
                        type="button"
                        class="nav-arrow ml-1"
                        aria-label="Scroll right"
                        @click="scrollNav(1)"
                    >
                        <i class="fa-solid fa-chevron-right" />
                    </button>
                </div>
            </div>
            <!-- ══ Droite : User + Hamburger ══ -->
            <div class="flex items-center gap-3">
                <!-- User menu -->
                <div class="relative" ref="userMenuRef">
                    <button
                        class="flex items-center gap-2 px-2 py-1.5 rounded-xl hover:bg-gray-100 transition-colors duration-200"
                        @click="userMenuOpen = !userMenuOpen"
                    >
                        <div
                            class="w-8 h-8 rounded-full bg-gray-300 flex items-center justify-center flex-shrink-0 overflow-hidden"
                        >
                            <div
                                class="relative w-9 h-9 flex items-center justify-center"
                            >
                                <!-- SVG cercle -->
                                <svg class="absolute w-10 h-10 -rotate-90">
                                    <!-- background -->
                                    <circle
                                        cx="20"
                                        cy="20"
                                        r="16"
                                        stroke="#e5e7eb"
                                        stroke-width="3"
                                        fill="transparent"
                                    />

                                    <!-- progress -->
                                    <circle
                                        cx="20"
                                        cy="20"
                                        r="16"
                                        :stroke="strokeColor"
                                        stroke-width="3"
                                        fill="transparent"
                                        stroke-linecap="round"
                                        :stroke-dasharray="circumference"
                                        :stroke-dashoffset="offset"
                                        class="transition-all duration-500"
                                    />
                                </svg>

                                <!-- Avatar -->
                                <div
                                    class="w-8 h-8 rounded-full bg-gray-300 flex items-center justify-center overflow-hidden"
                                >
                                    <img
                                        v-if="$page.props.auth.user?.avatar"
                                        :src="$page.props.auth.user?.avatar"
                                        class="w-full h-full object-cover"
                                    />
                                    <i
                                        v-else
                                        class="fa-solid fa-user text-gray-500 text-sm"
                                    />
                                </div>
                            </div>
                        </div>
                        <span
                            class="hidden sm:block text-sm font-semibold text-gray-700 max-w-[120px] truncate"
                        >
                            {{ $page.props.auth.user?.name }}
                        </span>
                        <i
                            class="fa-solid fa-chevron-down text-[10px] text-gray-400 transition-transform duration-200"
                            :class="{ 'rotate-180': userMenuOpen }"
                        />
                    </button>

                    <transition
                        enter-active-class="transition ease-out duration-150"
                        enter-from-class="opacity-0 translate-y-1 scale-95"
                        enter-to-class="opacity-100 translate-y-0 scale-100"
                        leave-active-class="transition ease-in duration-100"
                        leave-from-class="opacity-100 translate-y-0 scale-100"
                        leave-to-class="opacity-0 translate-y-1 scale-95"
                    >
                        <div
                            v-show="userMenuOpen"
                            class="absolute right-0 mt-2 w-56 bg-white rounded-xl shadow-lg border border-gray-100 py-1 z-50"
                        >
                            <div class="px-4 py-3 border-b border-gray-100">
                                <p
                                    class="text-sm font-semibold text-gray-800 truncate"
                                >
                                    {{ $page.props.auth.user?.name }}
                                </p>
                                <p class="text-xs text-gray-400 truncate">
                                    {{ $page.props.auth.user?.email }}
                                </p>
                            </div>

                            <Link
                                href="/profile"
                                class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 transition-colors"
                                @click="userMenuOpen = false"
                            >
                                <i
                                    class="fa-solid fa-circle-user w-4 text-gray-400"
                                />
                                {{ t("my_profile") }}
                            </Link>

                            <button
                                @click="lockScreen"
                                class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 transition-colors"
                            >
                                <i class="fa-solid fa-lock w-4 text-gray-400" />
                                Lock
                            </button>

                            <button
                                @click="langMenuOpen = !langMenuOpen"
                                class="w-full flex items-center justify-between gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 transition-colors"
                            >
                                <span class="flex items-center gap-3">
                                    <i class="fa-solid fa-globe w-4 text-gray-400" />
                                    {{ t("language") }}
                                </span>
                                <span class="flex items-center gap-1">
                                    <span class="text-xs text-gray-400 uppercase font-mono">{{ currentLang }}</span>
                                    <i
                                        class="fa-solid fa-chevron-right text-[10px] text-gray-400 transition-transform duration-200"
                                        :class="{ 'rotate-90': langMenuOpen }"
                                    />
                                </span>
                            </button>

                            <transition
                                enter-active-class="transition ease-out duration-150"
                                enter-from-class="opacity-0 -translate-y-1"
                                enter-to-class="opacity-100 translate-y-0"
                                leave-active-class="transition ease-in duration-100"
                                leave-from-class="opacity-100 translate-y-0"
                                leave-to-class="opacity-0 -translate-y-1"
                            >
                                <div
                                    v-show="langMenuOpen"
                                    class="bg-gray-50 border-t border-b border-gray-100"
                                >
                                    <button
                                        v-for="lang in availableLanguages"
                                        :key="lang.code"
                                        @click="switchLanguage(lang.code)"
                                        class="w-full flex items-center gap-3 px-6 py-2 text-sm hover:bg-gray-100 transition-colors"
                                        :class="
                                            currentLang === lang.code
                                                ? 'text-blue-600 font-semibold'
                                                : 'text-gray-600'
                                        "
                                    >
                                        <span class="text-base">{{ lang.flag }}</span>
                                        {{ lang.label }}
                                        <i
                                            v-if="currentLang === lang.code"
                                            class="fa-solid fa-check ml-auto text-blue-500 text-xs"
                                        />
                                    </button>
                                </div>
                            </transition>

                            <div class="border-t border-gray-100 mt-1">
                                <button
                                    @click="submitLogout"
                                    class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-red-500 hover:bg-red-50 transition-colors"
                                >
                                    <i
                                        class="fa-solid fa-arrow-right-from-bracket w-4"
                                    />
                                    {{ t("logout") }}
                                </button>
                            </div>
                        </div>
                    </transition>
                </div>

                <!-- Hamburger -->
                <button
                    class="lg:hidden w-9 h-9 flex items-center justify-center rounded-lg hover:bg-gray-100 transition-colors"
                    @click="toggleNav"
                >
                    <i
                        class="fa-solid transition-all duration-200"
                        :class="mobileOpen ? 'fa-xmark text-lg' : 'fa-bars'"
                    />
                </button>
            </div>
        </div>

        <!-- ══ Mobile Menu ══ -->
        <transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-2"
        >
            <div
                v-show="mobileOpen"
                class="lg:hidden mt-2 pb-2 border-t border-gray-100 pt-2"
            >
                <div class="nav-mobile-item">
                    <Link
                        href="/"
                        class="nav-link font-semibold block"
                        style="color: #020d31"
                    >
                        <i class="fa-solid fa-house mr-2" /> Home
                    </Link>
                </div>

                <div
                    v-for="item in navItems"
                    :key="item.key"
                    class="nav-mobile-item"
                >
                    <span
                        class="nav-link flex justify-between items-center w-full cursor-pointer"
                        @click="toggleMobileDropdown(item.key)"
                    >
                        <span>
                            <i :class="`fa-solid fa-${item.icon} mr-2`" />
                            {{ item.label }}d
                        </span>
                        <i
                            class="fa-solid fa-chevron-down text-[10px] transition-transform duration-200"
                            :class="{
                                'rotate-180': openMobileDropdown === item.key,
                            }"
                        />
                    </span>

                    <!-- Modules mobile -->
                    <transition
                        enter-active-class="transition ease-out duration-200"
                        enter-from-class="opacity-0 -translate-y-1"
                        enter-to-class="opacity-100 translate-y-0"
                        leave-active-class="transition ease-in duration-150"
                        leave-from-class="opacity-100 translate-y-0"
                        leave-to-class="opacity-0 -translate-y-1"
                    >
                        <div
                            v-show="openMobileDropdown === item.key"
                            class="mt-1 ml-3 border-l-2 border-indigo-100 pl-3"
                        >
                            <div v-for="mod in item.modules" :key="mod.key">
                                <!-- ✅ Module trigger pour ribbons -->
                                <div
                                    class="flex items-center justify-between py-2 px-2 cursor-pointer rounded-lg hover:bg-gray-50 transition-colors"
                                    @click="
                                        toggleMobileModule(
                                            `${item.key}-${mod.key}`,
                                        )
                                    "
                                >
                                    <span
                                        class="flex items-center gap-2 text-sm font-medium text-gray-700"
                                    >
                                        <i
                                            :class="`fa-solid fa-${mod.icon} text-indigo-400 w-4`"
                                        />
                                        {{ mod.label }}
                                    </span>
                                    <i
                                        v-if="mod.ribbons.length"
                                        class="fa-solid fa-chevron-down text-[10px] text-gray-400 transition-transform duration-200"
                                        :class="{
                                            'rotate-180':
                                                openMobileModule ===
                                                `${item.key}-${mod.key}`,
                                        }"
                                    />
                                </div>

                                <transition
                                    enter-active-class="transition ease-out duration-150"
                                    enter-from-class="opacity-0 -translate-y-1"
                                    enter-to-class="opacity-100 translate-y-0"
                                    leave-active-class="transition ease-in duration-100"
                                    leave-from-class="opacity-100 translate-y-0"
                                    leave-to-class="opacity-0 -translate-y-1"
                                >
                                    <div
                                        v-show="
                                            openMobileModule ===
                                            `${item.key}-${mod.key}`
                                        "
                                        class="ml-4 border-l border-indigo-50 pl-3 mb-1"
                                    >
                                        <Link
                                            v-for="ribbon in mod.ribbons"
                                            :key="ribbon.label"
                                            :href="ribbon.href"
                                            class="flex items-center gap-2 py-2 px-2 text-sm text-gray-600 hover:text-indigo-700 hover:bg-indigo-50 rounded-lg transition-colors"
                                            @click="mobileOpen = false"
                                        >
                                            <i
                                                :class="`fa-solid fa-${ribbon.icon} text-indigo-300 w-4`"
                                            />
                                            {{ ribbon.label }}
                                        </Link>

                                        <div
                                            v-if="!mod.ribbons.length"
                                            class="py-2 px-2 text-xs text-gray-400 italic"
                                        >
                                            No pages
                                        </div>
                                    </div>
                                </transition>
                            </div>
                        </div>
                    </transition>
                </div>

                <!-- User mobile -->
                <div class="nav-mobile-item border-t border-gray-100 mt-2 pt-2">
                    <div class="flex items-center gap-3 px-2 py-2">
                        <div
                            class="w-9 h-9 rounded-full bg-gray-300 flex items-center justify-center overflow-hidden"
                        >
                            <i class="fa-solid fa-user text-gray-500" />
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-gray-800">
                                {{ $page.props.auth.user?.name }}
                            </p>
                            <p class="text-xs text-gray-400">
                                {{ $page.props.auth.user?.email }}
                            </p>
                        </div>
                    </div>
                    <Link
                        href="/profile"
                        class="dropdown-item"
                        @click="mobileOpen = false"
                    >
                        <i class="fa-solid fa-circle-user w-4" />
                        {{ t("my_profile") }}
                    </Link>
                    <button
                        @click="lockScreen"
                        class="dropdown-item w-full text-left"
                    >
                        <i class="fa-solid fa-lock w-4" />
                        Lock
                    </button>
                    <button
                        @click="submitLogout"
                        class="dropdown-item text-red-500 w-full text-left"
                    >
                        <i class="fa-solid fa-arrow-right-from-bracket w-4" />
                        {{ t("logout") }}
                    </button>
                </div>
            </div>
        </transition>
    </nav>

    <div v-if="isScrolled" class="h-[64px]" />
</template>

<style scoped>
/* Category bar scrolls horizontally instead of wrapping to a second row */
.nav-scroll {
    scroll-behavior: smooth;
    scrollbar-width: none;      /* Firefox */
    -ms-overflow-style: none;   /* IE/Edge */
}
.nav-scroll::-webkit-scrollbar {
    display: none;              /* Chrome/Safari */
}
.nav-arrow {
    flex: 0 0 auto;
    width: 28px;
    height: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    color: #020d31;
    background: rgba(2, 13, 49, 0.06);
    transition: background 0.2s;
}
.nav-arrow:hover {
    background: rgba(2, 13, 49, 0.14);
}
</style>
