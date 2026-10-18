<script lang="ts" setup>
import { ref, onMounted, nextTick, computed, watch, onUnmounted } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import CopilotWidget from "@/Components/AI/CopilotWidget.vue";
import NotificationModal from "./partials/OpenModal.vue";
import CeoMessageModal from "./partials/CeoMessageModal.vue";
import ContentModal from "./partials/ContentModal.vue";
import SpotlightModal from "./partials/SpotlightModal.vue";
import CustomizeQuickAccessModal from "./partials/CustomizeQuickAccessModal.vue";
import { useDashboardStore } from "./Store/Store";
import type { Content, EmployeeSpotlight, Ribbon } from "./Store/type";
import { useReactionStore } from "./Store/ReactionStore";
import { useI18n } from "vue-i18n";
import "./css/style.css";

const { t } = useI18n()

const props = defineProps<{
    message: string;
    user: any;
    approvalAlert: { show: boolean; message: string; pendingCount: number } | null;
    profileAlert: { show: boolean; message: string; completion: number } | null;
    news?: Content[];
    events?: Content[];
    announcements?: Content[];
    internalNotices?: Content[];
    spotlights?: EmployeeSpotlight[];
    staffAnniversaries?: any[];
    staffLeaves?: any[];
    ceoMessage?: any;
    reactionsSummary?: Record<string, { like_count: number; user_liked: boolean; reaction_id: number | null }>;
    accessibleRibbons?: Ribbon[];
    userQuickAccess?: Ribbon[];
    capabilities?: { content_management?: boolean; resources?: boolean };
    moduleWidgets?: { code: string; name: string; icon: string; color: string; link: string; metrics: { label: string; value: number | string; format?: string }[] }[];
}>();

function formatCurrency(v: number | string): string {
    const n = typeof v === "number" ? v : parseFloat(String(v)) || 0;
    return "R " + n.toLocaleString("en-ZA", { maximumFractionDigits: 0 });
}
const WIDGET_COLORS: Record<string, string> = {
    indigo: "99,102,241", teal: "20,184,166", blue: "59,130,246",
    orange: "249,115,22", purple: "168,85,247", cyan: "6,182,212", green: "34,197,94",
    emerald: "16,185,129", sky: "14,165,233", amber: "245,158,11", violet: "139,92,246",
    rose: "244,63,94", pink: "236,72,153", lime: "132,204,22", slate: "100,116,139", red: "239,68,68",
};
function colorRgb(name: string): string {
    return WIDGET_COLORS[name] ?? "99,102,241";
}

// Which dashboard sections the company's plan/active modules allow. The greeting
// hero is always shown; everything else is gated. Absent prop (e.g. platform
// admin) → everything on.
const caps = computed(() => ({
    content_management: props.capabilities?.content_management ?? true,
    resources: props.capabilities?.resources ?? true,
}));

// Company's accessible modules (already plan-filtered server-side) — used to
// render a module launcher for plans without the content dashboard, so the page
// stays full and useful instead of near-empty.
const nav = usePage();
const workspaceCategories = computed<any[]>(() => ((nav.props as any).navigation ?? []) as any[]);

// A module is only navigable if it has at least one ACCESSIBLE ribbon with a real
// path. We must NOT fall back to the module's own `path` (e.g. `/finance/executive`)
// because those base paths frequently have no route and 404 — the real landing page
// is always the first accessible ribbon (e.g. `/finance/executive/dashboard`).
function moduleRibbonsOf(mod: any): any[] {
    // Eloquent serialises the `moduleRibbons` relation as snake_case `module_ribbons`
    // in the Inertia JSON — reading the camelCase key gave `undefined`, which is why
    // every box fell through to the routeless module base path and 404'd.
    return (mod?.module_ribbons ?? mod?.moduleRibbons ?? []) as any[];
}
function firstRibbonPath(mod: any): string | null {
    const first = moduleRibbonsOf(mod).find((r) => r?.ribbon_path) ?? null;
    return first?.ribbon_path ? String(first.ribbon_path) : null;
}
// Drop modules with no navigable ribbon so the grid never renders a dead/404 box.
const navigableWorkspace = computed<any[]>(() => workspaceCategories.value
    .map((cat: any) => ({ ...cat, modules: (cat.modules ?? []).filter((m: any) => firstRibbonPath(m)) }))
    .filter((cat: any) => cat.modules.length > 0));
const showWorkspace = computed(() => !caps.value.content_management && navigableWorkspace.value.length > 0);
function moduleLink(mod: any): string {
    const path = firstRibbonPath(mod);
    if (!path) return '#';
    return path.startsWith('/') ? path : `/${path}`;
}
function moduleIcon(mod: any): string {
    const first = moduleRibbonsOf(mod)[0];
    const fa = first?.font_awesome || mod?.font_awesome || mod?.icon || 'fa-cube';
    return String(fa).startsWith('fa-') ? String(fa) : `fa-${fa}`;
}

const userRibbonIds = computed(() => (props.userQuickAccess ?? []).map((r) => r.id));

const store = useDashboardStore();
watch(() => props, (p) => store.hydrate({ news: p.news, events: p.events, announcements: p.announcements, internalNotices: p.internalNotices, spotlights: p.spotlights, ceoMessage: p.ceoMessage }), { immediate: true, deep: true });

const reactionStore = useReactionStore();
watch(() => props.reactionsSummary, (summary) => { if (summary) reactionStore.hydrate(summary); }, { immediate: true });

const ceoModalOpen = ref(false);
const selectedContent = ref<Content | null>(null);
const contentModalOpen = ref(false);
const selectedSpotlight = ref<EmployeeSpotlight | null>(null);
const spotlightModalOpen = ref(false);

function openCeoModal() { ceoModalOpen.value = true; }
function openContentModal(i: any) { selectedContent.value = i; contentModalOpen.value = true; }
function openSpotlightModal(s: EmployeeSpotlight) { selectedSpotlight.value = s; spotlightModalOpen.value = true; }

const customizeOpen = ref(false);
const availableRibbons = computed(() => props.accessibleRibbons ?? []);
const displayableQuickAccess = computed(() => (props.userQuickAccess ?? []).filter((r) => r.active).map((r) => ({ id: r.id, name: (r as any).pivot?.name ?? r.ribbon_name ?? "Untitled", icon: r.font_awesome || "link", link: r.ribbon_path || "#" })));
const onQuickAccessUpdated = () => { router.reload({ only: ["userQuickAccess", "accessibleRibbons"] }); };

const heroSlide = ref(0);
let heroTimer: ReturnType<typeof setInterval> | null = null;

const ceoImageUrl = computed<string>(() => {
    const m = store.ceoMessage; const DEFAULT = "https://res.cloudinary.com/dftdt9jbc/image/upload/q_auto:low,t_Article/v1386214311/rfactu/social/non-salaries/3.jpeg";
    if (!m) return DEFAULT; if (m.image_url) return m.image_url;
    const img = m.assetsFile?.image ?? m.asset?.image; if (!img) return DEFAULT;
    if (typeof img === "string") return img;
    if (Array.isArray(img) && img[0]?.data) return `data:${img[0].mime};base64,${img[0].data}`;
    return DEFAULT;
});

const ceoVideoUrl = computed<string | null>(() => {
    const m = store.ceoMessage; if (!m) return null; if (m.video_url) return m.video_url;
    const vid = m.assetsFile?.video ?? m.asset?.video; if (!vid) return null;
    try { const p = typeof vid === "string" ? JSON.parse(vid) : vid; return p.path ? `/storage/${p.path}` : null; }
    catch { return null; }
});

const heroHasSlider = computed(() => !!ceoVideoUrl.value);

function startHeroAuto() { if (!heroHasSlider.value) return; heroTimer = setInterval(() => { heroSlide.value = heroSlide.value === 0 ? 1 : 0; }, 10000); }
function stopHeroAuto() { if (heroTimer) { clearInterval(heroTimer); heroTimer = null; } }
function heroGoTo(idx: number) { stopHeroAuto(); heroSlide.value = idx; }

onMounted(() => startHeroAuto());
onUnmounted(() => stopHeroAuto());

function getPersonPhotoUrl(person: any): string | null {
    if (!person?.profile_pic) return null; const raw = person.profile_pic;
    if (typeof raw === 'string') { if (raw.startsWith('data:')) return raw; try { const obj = JSON.parse(raw); if (obj?.data && obj?.mime) return `data:${obj.mime};base64,${obj.data}`; if (obj?.path) return obj.path.startsWith('/') ? obj.path : `/storage/${obj.path}`; } catch { if (raw.startsWith('/')) return raw; if (raw.includes('http')) return raw; return `/storage/${raw}`; } }
    if (typeof raw === 'object' && raw !== null) { if (raw.data && raw.mime) return `data:${raw.mime};base64,${raw.data}`; if (raw.path) return raw.path.startsWith('/') ? raw.path : `/storage/${raw.path}`; }
    return null;
}

function getImageUrl(item: Content | EmployeeSpotlight): string | null {
    const kind = (item as any)._kind; const personPhoto = getPersonPhotoUrl((item as any).person);
    if (personPhoto) return personPhoto;
    if (kind === 'Staff Anniversary') return "https://cdn.dribbble.com/userupload/44213166/file/original-2074251b8c6193e6ba3ac6c833bfb864.png?resize=752x&vertical=center";
    if (kind === 'Staff Leave') return "https://cdn.dribbble.com/userupload/14830114/file/original-0863c6a13d0bf69db5adc4887b2b958d.jpg?resize=752x&vertical=center";
    const DEFAULT = 'https://plus.unsplash.com/premium_photo-1661777211390-125ba8631767?q=80&w=687&auto=format&fit=crop&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D';
    const img = item.asset?.image; if (!img) return DEFAULT;
    if (typeof img === 'string') return img;
    const arr = img as any; if (Array.isArray(arr) && arr[0]?.data) return `data:${arr[0].mime};base64,${arr[0].data}`;
    return DEFAULT;
}

function getVideoUrl(item: Content): string | null {
    const vid = (item.asset as any)?.video; if (!vid) return null;
    try { const p = typeof vid === "string" ? JSON.parse(vid) : vid; return p.path ? `/storage/${p.path}` : null; }
    catch { return null; }
}

const displayName = computed(() => {
    if (!props.user) return "there"; const first = props.user.first_name?.trim(); const last = props.user.surname?.trim();
    if (first && last) return `${first} ${last}`; if (first) return first;
    return props.user.name?.split(" ")[0] ?? "there";
});

const greetingText = computed(() => {
    const h = new Date().getHours();
    if (h < 12) return t('home_page.greeting.good_morning');
    if (h < 17) return t('home_page.greeting.good_afternoon');
    return t('home_page.greeting.good_evening');
});

const showExtra = ref(false);
const activeFilter = ref<"news" | "events" | "announcements" | "internal-notice" | "anniversaries" | "leaves">("news");
const searchQuery = ref("");
const isLoaded = ref(false);

const resources = ref([
    { name: "Forms", icon: "fa-file-alt", color: "teal", link: "/cms/forms" },
    { name: "Corporate Branding", icon: "fa-palette", color: "olive", link: "/cms/corporate-branding" },
    { name: "Employee Handbook", icon: "fa-book-open", color: "dark", link: "/cms/employee-handbook" },
    { name: "NDAs & Legal", icon: "fa-scale-balanced", color: "red", link: "/cms/nda-legal" },
    { name: "Health & Safety", icon: "fa-kit-medical", color: "green", link: "/cms/health-safety" },
    { name: "Templates", icon: "fa-file", color: "purple", link: "/cms/templates" },
    { name: "Training Material", icon: "fa-graduation-cap", color: "orange", link: "/cms/training-material" },
    { name: "Security Policy", icon: "fa-lock", color: "slate", extra: true },
    { name: "Sops", icon: "fa-gears", color: "teal", link: "/cms/sops", extra: true },
    { name: "Downloads", icon: "fa-upload", color: "dark", link: "/cms/downloads", extra: true },
]);

const spotlightTypeConfig: Record<number, { label: string; icon: string; color: string }> = {
    1: { label: t('home_page.spotlights.award'), icon: "fa-trophy", color: "#FF9F43" },
    2: { label: t('home_page.spotlights.anniversary'), icon: "fa-cake-candles", color: "#00CFE8" },
    3: { label: t('home_page.spotlights.competition'), icon: "fa-medal", color: "#28C76F" },
};
function getSpotlightCfg(type: number) { return spotlightTypeConfig[type] ?? { label: "Spotlight", icon: "fa-star", color: "#aaa" }; }

const contentTypeConfig: Record<string, { label: string; icon: string; bgGradient: string; textColor: string }> = {
    News: { label: t('home_page.news_events.filter_news'), icon: "fa-newspaper", bgGradient: "from-blue-500 to-blue-700", textColor: "text-blue-600" },
    Event: { label: t('home_page.news_events.filter_events'), icon: "fa-calendar-check", bgGradient: "from-emerald-500 to-teal-600", textColor: "text-emerald-600" },
    Announcement: { label: t('home_page.news_events.filter_announcements'), icon: "fa-bullhorn", bgGradient: "from-amber-400 to-orange-600", textColor: "text-amber-600" },
    "Internal Notice": { label: t('home_page.news_events.filter_internal_notice'), icon: "fa-info-circle", bgGradient: "from-purple-500 to-purple-700", textColor: "text-purple-600" },
    "Staff Anniversary": { label: t('home_page.spotlights.anniversary'), icon: "fa-cake-candles", bgGradient: "from-pink-500 to-rose-600", textColor: "text-pink-600" },
    "Staff Leave": { label: t('home_page.news_events.filter_leaves'), icon: "fa-umbrella-beach", bgGradient: "from-cyan-500 to-blue-600", textColor: "text-cyan-600" },
};
function getContentTypeCfg(kind: string) { return contentTypeConfig[kind] ?? { label: kind, icon: "fa-file-alt", bgGradient: "from-gray-500 to-gray-700", textColor: "text-gray-600" }; }

function authorName(item: Content | EmployeeSpotlight): string {
    const u = (item as any).author ?? (item as any).employee; if (!u) return t('home_page.spotlights.unknown');
    const p = u.person; if (p) return [p.first_name, p.surname].filter(Boolean).join(" ") || u.email;
    return u.email || t('home_page.spotlights.unknown');
}
function authorInitial(item: Content | EmployeeSpotlight): string {
    const u = (item as any).author ?? (item as any).employee; const p = u?.person;
    return (p?.first_name?.[0] ?? u?.email?.[0] ?? "?").toUpperCase();
}
function formatDate(d: string | null): string { if (!d) return "—"; return new Date(d).toLocaleDateString("en-GB", { day: "numeric", month: "long", year: "numeric" }); }
function formatShortDate(d: string): { day: string; month: string } { const dt = new Date(d); return { day: dt.getDate().toString().padStart(2, "0"), month: dt.toLocaleString("en", { month: "short" }).toUpperCase() }; }
function formatTime(start: string | null, end: string | null): string { if (!start) return ""; return end ? `${start.slice(0, 5)} – ${end.slice(0, 5)}` : start.slice(0, 5); }
function stripHtml(html: string | null, max = 120): string { if (!html) return ""; return html.replace(/<[^>]*>/g, "").replace(/&nbsp;/g, " ").replace(/&amp;/g, "&").trim().substring(0, max); }
function limitWords(text: string | null | undefined, max = 20): string { if (!text) return ""; const words = text.replace(/<[^>]*>/g, "").trim().split(/\s+/); return words.length <= max ? words.join(" ") : words.slice(0, max).join(" ") + "…"; }
function formatMonth(val: string) { if (!val) return "—"; return new Date(val).toLocaleDateString("en-US", { day: "2-digit", month: "long", year: "numeric" }); }

const feedItems = computed(() => {
    const all: any[] = [];
    if (activeFilter.value === "news") store.news?.forEach((n) => all.push({ ...n, _kind: "News" }));
    if (activeFilter.value === "announcements") store.announcements?.forEach((a) => all.push({ ...a, _kind: "Announcement" }));
    if (activeFilter.value === "internal-notice") store.internalNotices?.forEach((ni) => all.push({ ...ni, _kind: "Internal Notice" }));
    if (activeFilter.value === "leaves") (props.staffLeaves ?? []).forEach((leave) => all.push({ ...leave, _kind: "Staff Leave" }));
    if (activeFilter.value === "anniversaries") (props.staffAnniversaries ?? []).forEach((ann) => all.push({ ...ann, _kind: "Staff Anniversary" }));
    return all.sort((a, b) => new Date(b.created_at || b.event_date || b.start_date).getTime() - new Date(a.created_at || a.event_date || a.start_date).getTime()).slice(0, 4);
});

const upcomingEvents = computed(() => {
    const today = new Date(); today.setHours(0, 0, 0, 0);
    return (store.events ?? []).filter((e) => { if (!e.event_date) return false; const d = new Date(e.event_date); d.setHours(0, 0, 0, 0); return d >= today; })
        .sort((a, b) => { if (!a.event_date) return 1; if (!b.event_date) return -1; return new Date(a.event_date).getTime() - new Date(b.event_date).getTime(); });
});

const allUpcomingContents = computed(() => {
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const allContents: any[] = [...(store.events ?? []).map((e) => ({ ...e, _kind: "Event" })), ...(store.announcements ?? []).map((a) => ({ ...a, _kind: "Announcement" })), ...(store.internalNotices ?? []).map((n) => ({ ...n, _kind: "Internal Notice" }))];
    return allContents.filter((item) => { const dateToCheck = item.event_date || item.expired_at; if (!dateToCheck) return false; const d = new Date(dateToCheck); d.setHours(0, 0, 0, 0); return d >= today; })
        .sort((a, b) => new Date(a.event_date || a.expired_at).getTime() - new Date(b.event_date || b.expired_at).getTime()).slice(0, 5);
});

const filteredResources = computed(() => resources.value.filter((r) => { const matchSearch = r.name.toLowerCase().includes(searchQuery.value.toLowerCase()); const matchVis = showExtra.value || !(r as any).extra; return matchSearch && matchVis; }));

function goTo(link: string | undefined) { if (link) window.location.href = link; }

const currentMonth = ref(new Date());
const showCalendar = ref(false);

const eventsPerDay = computed(() => {
    const map: Record<string, Content[]> = {};
    [...(store.news ?? []), ...(store.events ?? []), ...(store.announcements ?? []), ...(store.internalNotices ?? [])].forEach((e) => { const dateToUse = e.event_date || e.expired_at; if (dateToUse) { const key = dateToUse.split("T")[0]; if (!map[key]) map[key] = []; map[key].push(e); } });
    return map;
});

const calendarDays = computed(() => {
    const year = currentMonth.value.getFullYear(); const month = currentMonth.value.getMonth();
    const firstDay = new Date(year, month, 1); const lastDay = new Date(year, month + 1, 0);
    const startDate = new Date(firstDay); startDate.setDate(startDate.getDate() - firstDay.getDay());
    const days = []; let current = new Date(startDate);
    while (current <= lastDay || days.length % 7 !== 0) { const dateStr = current.toISOString().split("T")[0]; days.push({ date: new Date(current), dateStr, isCurrentMonth: current.getMonth() === month, hasEvents: !!eventsPerDay.value[dateStr], eventsCount: eventsPerDay.value[dateStr]?.length ?? 0 }); current.setDate(current.getDate() + 1); if (current.getMonth() !== month && days.length % 7 === 0) break; }
    return days;
});

function goToPrevMonth() { currentMonth.value = new Date(currentMonth.value.getFullYear(), currentMonth.value.getMonth() - 1, 1); }
function goToNextMonth() { currentMonth.value = new Date(currentMonth.value.getFullYear(), currentMonth.value.getMonth() + 1, 1); }

function scrollSpotlights(dir: number) { document.getElementById("spotlightsGrid")?.scrollBy({ left: dir * 320, behavior: "smooth" }); }

function getContentReaction(id: number) { const state = reactionStore.getState(`content_${id}`); return { like_count: 0, user_liked: false, reaction_id: null, comment_count: 0, ...state }; }
function toggleContentLike(e: MouseEvent, id: number) { e.stopPropagation(); reactionStore.toggleLike(id, null); }

const ceoText = computed(() => { const m = store.ceoMessage; if (!m) return null; return { title: m.title ?? t('home_page.hero.ceo_title'), message: limitWords(m.message ?? m.summary ?? m.description ?? "", 20) }; });

onMounted(async () => {
    await nextTick(); isLoaded.value = true;
    const canvas = document.getElementById("particles-hero") as HTMLCanvasElement | null; if (!canvas) return; const section = canvas.parentElement; if (!section) return; const ctx = canvas.getContext("2d"); if (!ctx) return;
    let W: number, H: number; let particles: any[] = []; const mouse = { x: -9999, y: -9999 };
    const CFG = { count: 70, minR: 1, maxR: 2.8, speed: 0.3, linkDist: 120, linkOpacity: 0.12, colors: ["#2d9e8e", "#5bc2b3", "#f0b429", "rgba(255,255,255,0.8)"], mouseForce: 90 };
    function resize() { W = canvas!.width = section!.offsetWidth; H = canvas!.height = section!.offsetHeight; }
    function rp() { const c = CFG.colors[Math.floor(Math.random() * CFG.colors.length)]; return { x: Math.random() * W, y: Math.random() * H, vx: (Math.random() - 0.5) * CFG.speed * 2, vy: (Math.random() - 0.5) * CFG.speed * 2, r: CFG.minR + Math.random() * (CFG.maxR - CFG.minR), color: c, alpha: 0.4 + Math.random() * 0.5 }; }
    function init() { resize(); particles = Array.from({ length: CFG.count }, rp); }
    function draw() { ctx!.clearRect(0, 0, W, H); particles.forEach((p) => { const dx = p.x - mouse.x, dy = p.y - mouse.y; const dist = Math.sqrt(dx * dx + dy * dy); if (dist < CFG.mouseForce) { const f = (CFG.mouseForce - dist) / CFG.mouseForce; p.vx += (dx / dist) * f * 0.5; p.vy += (dy / dist) * f * 0.5; } const spd = Math.sqrt(p.vx * p.vx + p.vy * p.vy); if (spd > CFG.speed * 3) { p.vx *= 0.93; p.vy *= 0.93; } p.x += p.vx; p.y += p.vy; if (p.x < 0) p.x = W; if (p.x > W) p.x = 0; if (p.y < 0) p.y = H; if (p.y > H) p.y = 0; ctx!.beginPath(); ctx!.arc(p.x, p.y, p.r, 0, Math.PI * 2); ctx!.fillStyle = p.color; ctx!.globalAlpha = p.alpha; ctx!.fill(); ctx!.globalAlpha = 1; }); for (let i = 0; i < particles.length; i++) for (let j = i + 1; j < particles.length; j++) { const a = particles[i], b = particles[j]; const dx = a.x - b.x, dy = a.y - b.y; const d = Math.sqrt(dx * dx + dy * dy); if (d < CFG.linkDist) { ctx!.beginPath(); ctx!.moveTo(a.x, a.y); ctx!.lineTo(b.x, b.y); ctx!.strokeStyle = "#5bc2b3"; ctx!.globalAlpha = CFG.linkOpacity * (1 - d / CFG.linkDist); ctx!.lineWidth = 0.6; ctx!.stroke(); ctx!.globalAlpha = 1; } } requestAnimationFrame(draw); }
    section.addEventListener("mousemove", (e) => { const r = canvas!.getBoundingClientRect(); mouse.x = e.clientX - r.left; mouse.y = e.clientY - r.top; });
    section.addEventListener("mouseleave", () => { mouse.x = -9999; mouse.y = -9999; });
    window.addEventListener("resize", resize); init(); draw();
});
</script>

<template>
    <section class="hero-section">
        <canvas id="particles-hero"></canvas>
        <div class="hero-content">
            <div class="hero-greeting" :class="{ 'animate-in': isLoaded }">
                <span class="greeting-wave">👋</span>
                <div>
                    <p class="greeting-label">{{ greetingText }}</p>
                    <h1 class="greeting-name">{{ t('home_page.greeting.welcome_back', { name: displayName }) }}</h1>
                </div>
            </div>

            <div v-if="caps.content_management" class="hero-grid" :class="{ 'animate-in': isLoaded }" style="background:#fff;padding:1rem;border-radius:0 15px 0 0">
                <div class="hero-card ceo-card">
                    <div class="ceo-badge"><i class="fa fa-bullhorn"></i><span>{{ t('home_page.hero.featured') }}</span></div>
                    <h2 class="ceo-title text-blue-950">{{ ceoText?.title ?? t('home_page.hero.ceo_title') }}</h2>
                    <p class="ceo-text text-black">{{ ceoText?.message ?? t('home_page.hero.ceo_fallback') }}</p>
                    <button class="ceo-btn" @click="openCeoModal">{{ t('home_page.hero.read_more') }} <i class="fa fa-arrow-right ml-2 text-xs"></i></button>
                </div>

                <div class="hero-card spotlight-img-card cursor-pointer hero-ceo-media" @click="openCeoModal">
                    <template v-if="heroHasSlider">
                        <div class="hero-slide" :class="{ active: heroSlide === 0 }"><img :src="ceoImageUrl" alt="CEO visual" class="hero-media-img" /></div>
                        <div class="hero-slide" :class="{ active: heroSlide === 1 }"><video :src="ceoVideoUrl!" muted loop playsinline preload="metadata" class="hero-media-vid"></video></div>
                        <div class="hero-dots"><button :class="['hero-dot', { active: heroSlide === 0 }]" @click.stop="heroGoTo(0)" aria-label="Image"><i class="fa fa-image"></i></button><button :class="['hero-dot', { active: heroSlide === 1 }]" @click.stop="heroGoTo(1)" aria-label="Video"><i class="fa fa-play"></i></button></div>
                        <div class="hero-progress"><div class="hero-progress-fill" :key="heroSlide"></div></div>
                    </template>
                    <template v-else-if="ceoVideoUrl"><video :src="ceoVideoUrl" muted loop playsinline preload="metadata" class="hero-media-vid solo"></video><div class="hero-media-icon-tag"><i class="fa fa-play mr-1"></i>{{ t('home_page.hero.video_tag') }}</div></template>
                    <template v-else><img :src="ceoImageUrl" alt="CEO visual" class="hero-media-img solo" /></template>
                    <div class="spotlight-img-overlay"><span class="spotlight-img-badge"><i class="fa fa-bullhorn mr-1"></i>{{ ceoText?.title ?? t('home_page.hero.ceo_title') }}</span><span class="spotlight-view-hint"><i class="fa fa-expand mr-1"></i>{{ t('home_page.hero.read_full_message') }}</span></div>
                </div>

                <div class="hero-card news-feed-card">
                    <div class="news-feed-header text-blue-950"><h3><i class="fa fa-newspaper mr-2"></i>{{ t('home_page.news_events.title') }}</h3></div>
                    <div class="news-feed-list">
                        <template v-if="(store.news ?? []).length > 0">
                            <div class="news-feed-item clickable-item" v-for="item in (store.news ?? []).slice(0, 3)" :key="item.id" @click="openContentModal(item)">
                                <div :class="`news-feed-icon bg-gradient-to-br ${getContentTypeCfg('News').bgGradient}`"><i :class="`fa ${getContentTypeCfg('News').icon} text-white`"></i></div>
                                <div class="news-feed-content"><span class="news-feed-tag">{{ getContentTypeCfg("News").label }}</span><p class="news-feed-title text-blue-800">{{ item.title }}</p><p class="news-feed-desc text-black">{{ limitWords(stripHtml(item.summary), 5) }}</p></div>
                                <i class="fa fa-chevron-right news-feed-arrow"></i>
                            </div>
                        </template>
                        <template v-else-if="(store.announcements ?? []).length > 0">
                            <div class="news-feed-item clickable-item" v-for="item in (store.announcements ?? []).slice(0, 3)" :key="item.id" @click="openContentModal(item)">
                                <div :class="`news-feed-icon bg-gradient-to-br ${getContentTypeCfg('Announcement').bgGradient}`"><i :class="`fa ${getContentTypeCfg('Announcement').icon} text-white`"></i></div>
                                <div class="news-feed-content"><span class="news-feed-tag">{{ getContentTypeCfg("Announcement").label }}</span><p class="news-feed-title text-blue-800">{{ item.title }}</p><p class="news-feed-desc text-black">{{ limitWords(stripHtml(item.summary), 5) }}</p></div>
                                <i class="fa fa-chevron-right news-feed-arrow"></i>
                            </div>
                        </template>
                        <template v-else>
                            <div class="news-feed-item" v-for="(item, i) in [{ icon: 'fa-house', gradient: 'from-blue-500 to-blue-700', tag: 'Policy', title: 'Changes to Flex Work are Coming in November', desc: 'Lorem ipsum dolor sit amet consectetur.' }, { icon: 'fa-person-walking', gradient: 'from-emerald-400 to-teal-600', tag: 'Wellness', title: 'See how you can get more done with less', desc: 'Lorem ipsum dolor sit amet consectetur.' }, { icon: 'fa-laptop', gradient: 'from-violet-500 to-purple-700', tag: 'Productivity', title: 'New tools rolling out across teams', desc: 'Lorem ipsum dolor sit amet consectetur.' }]" :key="i">
                                <div :class="`news-feed-icon bg-gradient-to-br ${item.gradient}`"><i :class="`fa ${item.icon} text-white`"></i></div>
                                <div class="news-feed-content"><span class="news-feed-tag">{{ item.tag }}</span><p class="news-feed-title text-blue-800">{{ item.title }}</p><p class="news-feed-desc text-black">{{ item.desc }}</p></div>
                            </div>
                        </template>
                    </div>
                    <button class="news-feed-more">{{ t('home_page.news_events.title') }} <i class="fa fa-arrow-right ml-1 text-xs"></i></button>
                </div>
            </div>
        </div>
    </section>

    <div class="quick-links-bar">
        <div class="quick-links-inner">
            <button class="quick-link-item add-link" @click="customizeOpen = true"><div class="ql-icon dashed"><i class="fa fa-plus"></i></div><span>{{ t('home_page.quick_access.customize') }}</span></button>
            <div class="ql-divider"></div>
            <template v-if="displayableQuickAccess.length > 0">
                <a v-for="item in displayableQuickAccess" :key="item.id" :href="item.link" class="quick-link-item" :title="item.name"><div class="ql-icon bg-blue-50"><i :class="`fa fa-${item.icon} text-blue-600`"></i></div><span>{{ item.name }}</span></a>
            </template>
            <template v-else><span class="text-caption text-medium-emphasis ps-4"><i class="fa fa-plus me-2"></i>{{ t('home_page.quick_access.add_shortcuts') }}</span></template>
        </div>
    </div>

    <!-- Module widgets — one KPI card per active module (adapts to the plan). -->
    <section v-if="(props.moduleWidgets?.length ?? 0) > 0" class="section-container">
        <div class="section-header"><div><h2 class="section-title"><i class="fa fa-chart-simple mr-2 text-indigo-600"></i>Your modules</h2><p class="section-subtitle">A live snapshot of the modules active on your plan</p></div></div>
        <div class="module-widget-grid">
            <a v-for="w in props.moduleWidgets" :key="w.code" :href="w.link" class="module-widget-card" :style="{ '--mw': colorRgb(w.color) }">
                <div class="mw-head">
                    <span class="mw-icon"><i :class="`fa ${w.icon}`"></i></span>
                    <span class="mw-name">{{ w.name }}</span>
                    <i class="fa fa-arrow-right mw-arrow"></i>
                </div>
                <div class="mw-metrics">
                    <div v-for="(m, i) in w.metrics" :key="i" class="mw-metric">
                        <div class="mw-value">{{ m.format === 'currency' ? formatCurrency(m.value) : m.value }}</div>
                        <div class="mw-label">{{ m.label }}</div>
                    </div>
                </div>
            </a>
        </div>
    </section>

    <!-- Module launcher — shown for plans without the content dashboard, so the
         page adapts to the company's active modules and stays beautiful. -->
    <section v-if="showWorkspace" class="section-container">
        <div class="section-header"><div><h2 class="section-title"><i class="fa fa-grip mr-2 text-indigo-600"></i>Your workspace</h2><p class="section-subtitle">Jump straight into the modules included in your plan</p></div></div>
        <div class="workspace-grid">
            <template v-for="cat in navigableWorkspace" :key="cat.id">
                <a v-for="mod in cat.modules" :key="mod.id" :href="moduleLink(mod)" class="workspace-card">
                    <div class="workspace-icon"><i :class="`fa ${moduleIcon(mod)}`"></i></div>
                    <div class="workspace-meta"><span class="workspace-name">{{ mod.name }}</span><span class="workspace-cat">{{ cat.name }}</span></div>
                    <i class="fa fa-arrow-right workspace-arrow"></i>
                </a>
            </template>
        </div>
    </section>

    <section v-if="caps.resources" class="section-container">
        <div class="section-header"><div><h2 class="section-title"><i class="fa fa-folder-open mr-2 text-teal-600"></i>{{ t('home_page.top_resources.title') }}</h2><p class="section-subtitle">{{ t('home_page.top_resources.subtitle') }}</p></div></div>
        <div class="resource-search"><i class="fa fa-magnifying-glass"></i><input v-model="searchQuery" type="text" :placeholder="t('home_page.top_resources.search_placeholder')" /><kbd v-if="!searchQuery" class="search-kbd">⌘K</kbd></div>
        <div class="resources-grid"><TransitionGroup name="resource"><button v-for="resource in filteredResources" :key="resource.name" :class="`resource-card resource-${resource.color}`" @click="goTo(String(resource.link))"><div class="resource-icon-wrap"><i :class="`fa ${resource.icon}`"></i></div><span class="resource-name">{{ resource.name }}</span><i class="fa fa-arrow-right resource-arrow"></i></button></TransitionGroup></div>
        <button @click="showExtra = !showExtra" class="toggle-more-btn"><i :class="`fa ${showExtra ? 'fa-chevron-up' : 'fa-chevron-down'} mr-2`"></i>{{ showExtra ? t('home_page.top_resources.show_less') : t('home_page.top_resources.show_more') }}</button>
    </section>

    <section v-if="caps.content_management" class="section-container">
        <div class="section-header"><div><h2 class="section-title"><i class="fa fa-newspaper mr-2 text-blue-600"></i>{{ t('home_page.news_events.title') }}</h2><p class="section-subtitle">{{ t('home_page.news_events.subtitle') }}</p></div></div>
        <div class="news-events-layout">
            <div class="articles-section">
                <div class="filter-bar">
                    <button v-for="(f, key) in { news: t('home_page.news_events.filter_news'), announcements: t('home_page.news_events.filter_announcements'), events: t('home_page.news_events.filter_events'), 'internal-notice': t('home_page.news_events.filter_internal_notice'), anniversaries: t('home_page.news_events.filter_anniversaries'), leaves: t('home_page.news_events.filter_leaves') }" :key="key" :class="['filter-chip', { active: activeFilter === key }]" @click="activeFilter = key as any">{{ f }}</button>
                </div>
                <div class="articles-scroll-wrap" v-if="activeFilter !== 'events'">
                    <template v-if="feedItems.length > 0">
                        <div class="articles-grid">
                            <article class="article-card clickable-card" v-for="item in feedItems.slice(0, 4)" :key="item.id" @click="openContentModal(item)">
                                <div class="article-img-wrap">
                                    <template v-if="item._kind !== 'Staff Anniversary' && item._kind !== 'Staff Leave' && getVideoUrl(item)"><video :src="getVideoUrl(item)!" muted loop playsinline preload="none" class="article-video-thumb"></video><div class="article-img-overlay"></div><span class="article-media-play"><i class="fa fa-play"></i></span></template>
                                    <template v-else><img :src="getImageUrl(item)" :alt="item.title" /><div class="article-img-overlay"></div></template>
                                    <span :class="`article-type-badge ${getContentTypeCfg(item._kind).textColor}`"><i :class="`fa ${getContentTypeCfg(item._kind).icon} mr-1 text-xs`"></i>{{ getContentTypeCfg(item._kind).label }}</span>
                                </div>
                                <div class="article-body">
                                    <div class="article-meta" v-if="item._kind !== 'Staff Anniversary' && item._kind !== 'Staff Leave'"><div class="article-avatar">{{ authorInitial(item) }}</div><div><p class="article-author">{{ authorName(item) }}</p><p class="article-date">{{ formatDate(item.expired_at ?? item.event_date ?? item.created_at) }}</p></div></div>
                                    <div class="article-meta" v-else><div class="article-avatar">{{ item.person?.initial }}</div><div><p class="article-author">{{ item.person?.first_name }} {{ item.person?.surname }}</p><p class="article-date">{{ item._kind === 'Staff Leave' ? formatDate(item.start_date) : formatDate(item.event_date) }}</p></div></div>
                                    <h3 class="article-title">{{ item.title }}</h3>
                                    <p v-if="item.summary" class="article-excerpt">{{ limitWords(stripHtml(item.summary), 5) }}</p>
                                    <div class="article-actions" v-if="item._kind !== 'Staff Anniversary' && item._kind !== 'Staff Leave'">
                                        <button @click.stop="openContentModal(item)"><i class="fa-regular fa-comment mr-1"></i><span v-if="getContentReaction(item.id).comment_count > 0" class="comment-count-badge-inline">{{ getContentReaction(item.id).comment_count }}</span></button>
                                        <button @click.stop="toggleContentLike($event, item.id)" :class="{ 'liked-inline': getContentReaction(item.id).user_liked }"><i :class="getContentReaction(item.id).user_liked ? 'fa fa-heart mr-1' : 'fa-regular fa-heart mr-1'"></i>{{ getContentReaction(item.id).like_count || 0 }}</button>
                                        <button class="ml-auto" @click.stop><i class="fa-regular fa-bookmark"></i></button>
                                        <span class="article-read-more">{{ t('home_page.news_events.read_more') }} <i class="fa fa-arrow-right ml-1 text-xs"></i></span>
                                    </div>
                                </div>
                            </article>
                        </div>
                    </template>
                    <div v-else class="events-empty"><i class="fa-regular fa-calendar-xmark"></i><p>{{ t('home_page.news_events.no_content') }}</p></div>
                </div>
                <div class="articles-scroll-wrap" v-if="activeFilter === 'events'">
                    <template v-if="upcomingEvents.length > 0">
                        <div class="articles-grid">
                            <article class="article-card clickable-card" v-for="event in upcomingEvents.slice(0, 4)" :key="event.id" @click="openContentModal(event)">
                                <div class="article-img-wrap">
                                    <template v-if="getVideoUrl(event)"><video :src="getVideoUrl(event)!" muted loop playsinline preload="none" class="article-video-thumb"></video><div class="article-img-overlay"></div><span class="article-media-play"><i class="fa fa-play"></i></span></template>
                                    <template v-else><img :src="getImageUrl(event)" :alt="event.title" /><div class="article-img-overlay"></div></template>
                                    <span :class="`article-type-badge ${getContentTypeCfg('Event').textColor}`"><i :class="`fa ${getContentTypeCfg('Event').icon} mr-1 text-xs`"></i>{{ getContentTypeCfg("Event").label }}</span>
                                </div>
                                <div class="article-body">
                                    <div class="article-meta"><div class="event-date-mini" v-if="event.event_date"><span class="event-mini-day">{{ formatShortDate(event.event_date).day }}</span><span class="event-mini-month">{{ formatShortDate(event.event_date).month }}</span></div><div><p class="article-author">{{ event.companyUnit?.name ?? t('home_page.news_events.company_event') }}</p><p class="article-date" v-if="event.event_start"><i class="fa-regular fa-clock mr-1"></i>{{ formatTime(event.event_start, event.event_end) }}</p></div></div>
                                    <h3 class="article-title">{{ event.title }}</h3>
                                    <p v-if="event.summary" class="article-excerpt">{{ limitWords(stripHtml(event.summary), 5) }}</p>
                                </div>
                            </article>
                        </div>
                    </template>
                    <div v-else class="events-empty"><i class="fa-regular fa-calendar-xmark"></i><p>{{ t('home_page.news_events.no_upcoming_events') }}</p></div>
                </div>
            </div>

            <aside class="events-aside">
                <div class="events-aside-header"><h3><i class="fa" :class="showCalendar ? 'fa-calendar-days' : 'fa-fire'"></i><span class="ml-2">{{ showCalendar ? t('home_page.news_events.calendar') : t('home_page.news_events.upcoming_events') }}</span></h3><button class="cal-toggle-btn" @click="showCalendar = !showCalendar" :title="showCalendar ? 'Show Contents' : 'Show Calendar'"><i class="fa" :class="showCalendar ? 'fa-list' : 'fa-calendar'"></i></button></div>
                <template v-if="showCalendar">
                    <div class="calendar-header"><button class="cal-nav-btn" @click="goToPrevMonth"><i class="fa fa-chevron-left"></i></button><h3 class="cal-title">{{ currentMonth.toLocaleDateString("en-US", { month: "long", year: "numeric" }) }}</h3><button class="cal-nav-btn" @click="goToNextMonth"><i class="fa fa-chevron-right"></i></button></div>
                    <div class="calendar-grid"><div class="cal-day-header" v-for="d in ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']" :key="d">{{ d }}</div><div v-for="day in calendarDays" :key="day.dateStr" :class="['cal-day', { 'not-current': !day.isCurrentMonth, 'has-events': day.hasEvents }]"><div class="cal-day-num">{{ day.date.getDate() }}</div><div v-if="day.hasEvents" class="cal-events-indicator"><span class="cal-event-dot"></span><span v-if="day.eventsCount > 1" class="cal-event-count">+{{ day.eventsCount - 1 }}</span></div></div></div>
                </template>
                <template v-else>
                    <div class="events-list-scroll">
                        <template v-if="allUpcomingContents.length > 0">
                            <div v-for="item in allUpcomingContents" :key="item.id" class="event-list-item clickable-event" :class="{ active: selectedContent?.id === item.id }" @click="openContentModal(item)">
                                <div class="event-item-date"><span class="event-item-day">{{ formatShortDate(item.event_date || item.expired_at || new Date().toISOString().split("T")[0]).day }}</span><span class="event-item-month">{{ formatShortDate(item.event_date || item.expired_at || new Date().toISOString().split("T")[0]).month }}</span></div>
                                <div class="event-item-content"><p class="event-item-title">{{ item.title }}</p><p v-if="item.summary" class="event-item-summary text-xs">{{ limitWords(stripHtml(item.summary), 5) }}</p><p v-if="item.event_start" class="event-item-time"><i class="fa-regular fa-clock mr-1"></i>{{ formatTime(item.event_start, item.event_end) }}</p></div>
                            </div>
                        </template>
                        <template v-else><div style="text-align:center;padding:1.5rem .5rem;color:#94a3b8"><i class="fa-regular fa-calendar-xmark" style="font-size:1.5rem;display:block;margin-bottom:.5rem"></i>{{ t('home_page.news_events.no_upcoming_contents') }}</div></template>
                    </div>
                </template>
            </aside>
        </div>
    </section>

    <section v-if="caps.content_management" class="section-container section-last">
        <div class="section-header"><div><h2 class="section-title"><i class="fa fa-users mr-2 text-amber-500"></i>{{ t('home_page.spotlights.title') }}</h2><p class="section-subtitle">{{ t('home_page.spotlights.subtitle') }}</p></div><div class="spotlight-arrows"><button class="arrow-btn" @click="scrollSpotlights(-1)"><i class="fa fa-chevron-left"></i></button><button class="arrow-btn" @click="scrollSpotlights(1)"><i class="fa fa-chevron-right"></i></button></div></div>
        <div class="spotlights-track" id="spotlightsGrid" v-if="(store.spotlights ?? []).length > 0">
            <div class="spot-card clickable-spot" v-for="spot in store.spotlights ?? []" :key="spot.id" @click="openSpotlightModal(spot)">
                <img :src="getImageUrl(spot)" :alt="spot.title" />
                <div class="spot-overlay">
                    <span class="spot-tag" :style="{ background: getSpotlightCfg(spot.type).color + '22', color: getSpotlightCfg(spot.type).color, border: `1px solid ${getSpotlightCfg(spot.type).color}44` }"><i :class="`fa ${getSpotlightCfg(spot.type).icon} mr-1`"></i>{{ getSpotlightCfg(spot.type).label }}</span>
                    <p class="spot-employee"><i class="fa fa-user mr-1 opacity-70"></i>{{ spot.employee?.person ? [spot.employee.person.first_name, spot.employee.person.surname].filter(Boolean).join(" ") : (spot.employee?.email ?? "—") }}</p>
                    <p class="spot-text">{{ spot.title }}</p>
                    <p class="spot-month"><i class="fa-regular fa-calendar mr-1 opacity-70"></i>{{ formatMonth(spot.month) }}</p>
                    <div class="spot-click-hint"><i class="fa fa-expand mr-1"></i>{{ t('home_page.spotlights.view_details') }}</div>
                </div>
            </div>
        </div>
        <div class="spotlights-track" id="spotlightsGrid" v-else>
            <div class="spot-card" v-for="(s, i) in [{ img: 'https://images.unsplash.com/photo-1556742049-0cfed4f6a45d?w=600&q=80', icon: 'fa-medal', text: t('home_page.spotlight_defaults.title1'), tag: t('home_page.spotlight_defaults.tag1') }, { img: 'https://images.unsplash.com/photo-1573496799652-408c2ac9fe98?w=600&q=80', icon: 'fa-chart-line', text: t('home_page.spotlight_defaults.title2'), tag: t('home_page.spotlight_defaults.tag2') }, { img: 'https://images.unsplash.com/photo-1551836022-d5d88e9218df?w=600&q=80', icon: 'fa-trophy', text: t('home_page.spotlight_defaults.title3'), tag: t('home_page.spotlight_defaults.tag3') }]" :key="i">
                <img :src="s.img" :alt="s.text" />
                <div class="spot-overlay"><span class="spot-tag"><i :class="`fa ${s.icon} mr-1`"></i>{{ s.tag }}</span><p class="spot-text">{{ s.text }}</p></div>
            </div>
        </div>
    </section>

    <CeoMessageModal :ceoMessage="store.ceoMessage" :isOpen="ceoModalOpen" @close="ceoModalOpen = false" />
    <ContentModal :item="selectedContent" :isOpen="contentModalOpen" :getAnniversaryImageUrl="() => 'https://cdn.dribbble.com/userupload/44213166/file/original-2074251b8c6193e6ba3ac6c833bfb864.png?resize=752x&vertical=center'" :getLeaveImageUrl="() => 'https://cdn.dribbble.com/userupload/14830114/file/original-0863c6a13d0bf69db5adc4887b2b958d.jpg?resize=752x&vertical=center'" @close="contentModalOpen = false" />
    <SpotlightModal :spotlight="selectedSpotlight" :isOpen="spotlightModalOpen" @close="spotlightModalOpen = false" />
    <CustomizeQuickAccessModal v-model="customizeOpen" :accessible-ribbons="availableRibbons" :user-ribbon-ids="userRibbonIds" :user-quick-access="props.userQuickAccess ?? []" @updated="onQuickAccessUpdated" />
    <CopilotWidget />
    <NotificationModal :approvalAlert="approvalAlert" :profileAlert="profileAlert" />
</template>
