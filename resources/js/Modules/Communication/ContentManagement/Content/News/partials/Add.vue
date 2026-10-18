<script setup lang="ts">
import { ref, computed, watch, nextTick } from "vue";
import { useNewsStore } from "../store";
import { ContentType } from "../../utils/type";
import { usePage } from "@inertiajs/vue3";
import ProductDescriptionEditor from "@/@core/components/ProductDescriptionEditor.vue";

interface CompanyUnit {
    id: number;
    name: string;
    parent_id: number | null;
    type?: string | null;
}

interface Level {
    selectedId: number | null;
    children: CompanyUnit[];
}

const props = withDefaults(defineProps<{
    companyUnits?: CompanyUnit[];
}>(), {
    companyUnits: () => [],
});

const model = defineModel<boolean>();
const store = useNewsStore();
const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);

// ── Stepper ──────────────────────────────────────────────────────────────────
const currentStep = ref(0);

const iconsSteps = [
    { title: "Content", icon: "tabler-pencil" },
    { title: "Media & Icon", icon: "tabler-photo-video" },
    { title: "Organization", icon: "tabler-sitemap" },
    { title: "Dates", icon: "tabler-calendar-event" },
    { title: "Review & Publish", icon: "tabler-circle-check" },
];

// ── Form data ────────────────────────────────────────────────────────────────
const title = ref("");
const summary = ref("");
const icon = ref("");
const event_start = ref("");
const event_end = ref("");
const event_date = ref("");
const expired_at = ref("");
const meta_data = ref("");
const is_active = ref(true);
const imageFile = ref<File | null>(null);
const videoFile = ref<File | null>(null);
const imagePreview = ref<string | null>(null);
const videoPreview = ref<string | null>(null);

// ── Icon presets ─────────────────────────────────────────────────────────────
const iconPresets = [
    { label: "Bullhorn", value: "fa-solid fa-bullhorn" },
    { label: "Newspaper", value: "fa-solid fa-newspaper" },
    { label: "Calendar", value: "fa-solid fa-calendar" },
    { label: "Info", value: "fa-solid fa-circle-info" },
    { label: "Star", value: "fa-solid fa-star" },
    { label: "Fire", value: "fa-solid fa-fire" },
    { label: "Bell", value: "fa-solid fa-bell" },
    { label: "Tag", value: "fa-solid fa-tag" },
    { label: "Pin", value: "fa-solid fa-thumbtack" },
    { label: "Message", value: "fa-solid fa-message" },
];

// ── Company Units ────────────────────────────────────────────────────────────
const levels = ref<Level[]>([]);

const roots = computed(() =>
    props.companyUnits.filter((u) => u.parent_id === null),
);

const typeConfig: Record<string, { label: string; icon: string; mdiIcon: string; color: string; bg: string }> = {
    division: { label: "Division", icon: "tabler-building", mdiIcon: "tabler-building", color: "#6366f1", bg: "#eef2ff" },
    department: { label: "Department", icon: "tabler-sitemap", mdiIcon: "tabler-sitemap", color: "#0d9488", bg: "#f0fdfa" },
    team: { label: "Team", icon: "tabler-users", mdiIcon: "tabler-users", color: "#f59e0b", bg: "#fffbeb" },
    section: { label: "Section", icon: "tabler-layout-dashboard", mdiIcon: "tabler-layout-dashboard", color: "#ec4899", bg: "#fdf2f8" },
    unit: { label: "Unit", icon: "tabler-cube", mdiIcon: "tabler-cube", color: "#64748b", bg: "#f8fafc" },
};

function toDateInput(val: string | null): string {
    if (!val) return "";
    return val.slice(0, 10);
}

function toDatetimeLocalInput(val: string | null): string {
    if (!val) return "";
    return val.slice(0, 16).replace(" ", "T");
}

const getTypeConfig = (type: string | undefined) =>
    typeConfig[type ?? ""] ?? { label: type ?? "Unit", icon: "tabler-cube", mdiIcon: "tabler-cube", color: "#64748b", bg: "#f8fafc" };

const getLevelLabel = (level: Level): string =>
    getTypeConfig(level.children[0]?.type).label;

const initLevels = () => {
    levels.value = roots.value.length ? [{ selectedId: null, children: roots.value }] : [];
};

const onLevelSelect = (levelIndex: number, selectedId: number | null) => {
    levels.value[levelIndex].selectedId = selectedId;
    levels.value = levels.value.slice(0, levelIndex + 1);
    if (selectedId !== null) {
        const children = props.companyUnits.filter((u) => u.parent_id === selectedId);
        if (children.length > 0) levels.value.push({ selectedId: null, children });
    }
};

const selectedCompanyUnitId = computed(() => {
    const ids = levels.value.map((l) => l.selectedId).filter((id): id is number => id !== null);
    return ids.length > 0 ? ids[ids.length - 1] : null;
});

const selectedUnitPath = computed(() => {
    return levels.value
        .filter((l) => l.selectedId !== null)
        .map((l) => l.children.find((c) => c.id === l.selectedId)?.name ?? "")
        .join(" > ");
});

watch(() => props.companyUnits, initLevels, { immediate: true });

// ── Media handlers ───────────────────────────────────────────────────────────
function handleImage(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    if (!["image/jpeg", "image/png", "image/webp"].includes(file.type)) { store.showSnack("Invalid image format", "error"); return; }
    if (file.size > 5 * 1024 * 1024) { store.showSnack("Image must not exceed 5MB", "error"); return; }
    imageFile.value = file; videoFile.value = null; videoPreview.value = null;
    imagePreview.value = URL.createObjectURL(file);
}

function handleVideo(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    if (!file.type.startsWith("video/")) { store.showSnack("Invalid video file", "error"); return; }
    if (file.size > 100 * 1024 * 1024) { store.showSnack("Video must not exceed 100MB", "error"); return; }
    videoFile.value = file; imageFile.value = null; imagePreview.value = null;
    videoPreview.value = URL.createObjectURL(file);
}

function removeMedia() {
    imageFile.value = null; videoFile.value = null;
    imagePreview.value = null; videoPreview.value = null;
}

const handleSummaryUpdate = (content: string) => { summary.value = content; };

// ── Steps validation ─────────────────────────────────────────────────────────
const canGoNext = computed(() => {
    if (currentStep.value === 0) return title.value.trim().length > 0;
    return true;
});

function nextStep() { if (currentStep.value < iconsSteps.length - 1) currentStep.value++; }
function prevStep() { if (currentStep.value > 0) currentStep.value--; }

// ── Reset ────────────────────────────────────────────────────────────────────
function reset() {
    title.value = ""; summary.value = ""; icon.value = "";
    event_start.value = ""; event_end.value = ""; event_date.value = "";
    expired_at.value = ""; meta_data.value = ""; is_active.value = true;
    imageFile.value = null; videoFile.value = null;
    imagePreview.value = null; videoPreview.value = null;
    currentStep.value = 0; initLevels();
}

watch(model, (open) => { if (!open) nextTick(() => reset()); });

// ── Submit ───────────────────────────────────────────────────────────────────
function submit() {
    if (!title.value.trim()) { store.showSnack("Title is required", "error"); return; }
    const form = new FormData();
    form.append("title", title.value);
    form.append("summary", summary.value);
    form.append("icon", icon.value);
    form.append("is_active", is_active.value ? "1" : "0");
    form.append("type", String(ContentType.News));
    if (event_start.value) form.append("event_start", event_start.value);
    if (event_end.value) form.append("event_end", event_end.value);
    if (event_date.value) form.append("event_date", event_date.value);
    if (expired_at.value) form.append("expired_at", expired_at.value);
    if (meta_data.value) form.append("meta_data", meta_data.value);
    if (selectedCompanyUnitId.value) form.append("company_unit_id", String(selectedCompanyUnitId.value));
    if (imageFile.value instanceof File) form.append("image", imageFile.value);
    if (videoFile.value instanceof File) form.append("video", videoFile.value);
    store.store(form);
    model.value = false;
    reset();
}
</script>

<template>
    <VDialog v-model="model" max-width="800" persistent scrollable>
        <VCard elevation="0">

            <!-- Header -->
            <div
                class="d-flex align-center justify-space-between px-6 py-4 flex-shrink-0"
                style="border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity))"
            >
                <div class="d-flex align-center gap-3">
                    <VAvatar color="primary" variant="tonal" size="40">
                        <VIcon icon="tabler-news" size="20" />
                    </VAvatar>
                    <div>
                        <p class="text-body-1 font-weight-semibold mb-0">Add News</p>
                        <p class="text-caption text-medium-emphasis mb-0">Create a new news article</p>
                    </div>
                </div>
                <VBtn icon="tabler-x" variant="text" size="small" @click="model = false" />
            </div>

            <!-- Stepper -->
            <VCardText class="pa-6 pb-0">
                <div class="custom-stepper">
                    <div
                        v-for="(step, index) in iconsSteps"
                        :key="index"
                        class="stepper-step"
                        :class="{
                            'stepper-step--active': index === currentStep,
                            'stepper-step--completed': index < currentStep,
                        }"
                        @click="currentStep = index"
                    >
                        <div
                            class="stepper-icon"
                            :class="{
                                'stepper-icon--active': index === currentStep,
                                'stepper-icon--completed': index < currentStep,
                            }"
                        >
                            <VIcon :icon="step.icon" size="16" />
                        </div>
                        <span class="stepper-title">{{ step.title }}</span>
                        <div
                            v-if="index < iconsSteps.length - 1"
                            class="stepper-line"
                            :class="{ 'stepper-line--completed': index < currentStep }"
                        />
                    </div>
                </div>
            </VCardText>

            <VDivider />

            <!-- Content -->
            <VCardText class="pa-6" style="overflow-y: auto; max-height: 50vh">
                <VWindow v-model="currentStep" class="disable-tab-transition">

                    <!-- Step 1: Content -->
                    <VWindowItem>
                        <VRow>
                            <VCol cols="12">
                                <h6 class="text-h6 font-weight-medium">Content Information</h6>
                                <p class="mb-4">Enter the main content details</p>
                            </VCol>
                            <VCol cols="12">
                                <VTextField
                                    v-model="title"
                                    label="Title *"
                                    variant="outlined"
                                    density="compact"
                                    placeholder="Enter news title"
                                    prepend-inner-icon="tabler-text-size"
                                    :error-messages="errors.title"
                                />
                            </VCol>
                            <VCol cols="12">
                                <p class="text-caption text-medium-emphasis mb-2">Summary</p>
                                <ProductDescriptionEditor
                                    :model-value="summary"
                                    @update:model-value="handleSummaryUpdate"
                                />
                                <p v-if="errors.summary" class="text-caption text-error mt-1">{{ errors.summary }}</p>
                            </VCol>
                        </VRow>
                    </VWindowItem>

                    <!-- Step 2: Media & Icon -->
                    <VWindowItem>
                        <VRow>
                            <VCol cols="12">
                                <h6 class="text-h6 font-weight-medium">Media & Icon</h6>
                                <p class="mb-4">Add an image/video and choose an icon</p>
                            </VCol>

                            <VCol cols="12">
                                <VSelect
                                    v-model="icon"
                                    :items="iconPresets"
                                    item-title="label"
                                    item-value="value"
                                    label="Icon"
                                    variant="outlined"
                                    density="compact"
                                    prepend-inner-icon="tabler-shapes"
                                    clearable
                                    placeholder="Select an icon"
                                >
                                    <template #item="{ item, props: itemProps }">
                                        <VListItem v-bind="itemProps" :title="item.raw.label">
                                            <template #prepend>
                                                <i :class="item.raw.value" style="font-size: 16px; color: #6366f1" />
                                            </template>
                                        </VListItem>
                                    </template>
                                    <template #selection="{ item }">
                                        <i :class="item.raw.value" style="font-size: 16px; color: #6366f1" class="me-2" />
                                        {{ item.raw.label }}
                                    </template>
                                </VSelect>
                            </VCol>

                            <VCol cols="12" class="mt-4">
                                <p class="text-overline text-medium-emphasis mb-3">Media (Image or Video)</p>
                                <VRow v-if="!imagePreview && !videoPreview">
                                    <VCol cols="6">
                                        <label class="drop-zone">
                                            <div class="text-center">
                                                <VIcon icon="tabler-photo" size="28" class="mb-2 opacity-30" />
                                                <p class="text-caption text-medium-emphasis mb-0">Image (JPG · PNG · WEBP · 5 MB)</p>
                                            </div>
                                            <input type="file" accept=".jpg,.jpeg,.png,.webp" class="sr-only" @change="handleImage" />
                                        </label>
                                    </VCol>
                                    <VCol cols="6">
                                        <label class="drop-zone">
                                            <div class="text-center">
                                                <VIcon icon="tabler-movie" size="28" class="mb-2 opacity-30" />
                                                <p class="text-caption text-medium-emphasis mb-0">Video (MP4 · MOV · 100 MB)</p>
                                            </div>
                                            <input type="file" accept="video/*" class="sr-only" @change="handleVideo" />
                                        </label>
                                    </VCol>
                                </VRow>

                                <VRow v-if="imagePreview">
                                    <VCol cols="12">
                                        <div class="media-preview">
                                            <img :src="imagePreview" alt="Preview" />
                                            <div class="media-actions">
                                                <label>
                                                    <VBtn size="small" color="white" variant="flat" class="me-2">
                                                        <VIcon icon="tabler-refresh" size="14" class="me-1" />Change
                                                    </VBtn>
                                                    <input type="file" accept=".jpg,.jpeg,.png,.webp" class="sr-only" @change="handleImage" />
                                                </label>
                                                <VBtn size="small" color="error" variant="flat" @click="removeMedia">
                                                    <VIcon icon="tabler-trash" size="14" class="me-1" />Remove
                                                </VBtn>
                                            </div>
                                        </div>
                                    </VCol>
                                </VRow>

                                <VRow v-if="videoPreview">
                                    <VCol cols="12">
                                        <div class="media-preview">
                                            <video :src="videoPreview" controls />
                                            <div class="media-actions">
                                                <label>
                                                    <VBtn size="small" color="white" variant="flat" class="me-2">
                                                        <VIcon icon="tabler-refresh" size="14" class="me-1" />Change
                                                    </VBtn>
                                                    <input type="file" accept="video/*" class="sr-only" @change="handleVideo" />
                                                </label>
                                                <VBtn size="small" color="error" variant="flat" @click="removeMedia">
                                                    <VIcon icon="tabler-trash" size="14" class="me-1" />Remove
                                                </VBtn>
                                            </div>
                                        </div>
                                    </VCol>
                                </VRow>
                            </VCol>
                        </VRow>
                    </VWindowItem>

                    <!-- Step 3: Organization -->
                    <VWindowItem>
                        <VRow>
                            <VCol cols="12">
                                <h6 class="text-h6 font-weight-medium">Organization</h6>
                                <p class="mb-4">Target a specific unit or leave empty for all</p>
                            </VCol>
                            <VCol cols="12">
                                <template v-if="levels.length > 0">
                                    <div v-for="(level, index) in levels" :key="index" class="mb-3">
                                        <div class="d-flex align-center gap-2 mb-2">
                                            <VIcon
                                                v-if="index > 0"
                                                icon="tabler-corner-down-right"
                                                size="12"
                                                color="secondary"
                                                class="ms-1"
                                            />
                                            <div class="level-badge" :style="{
                                                background: getTypeConfig(level.children[0]?.type).bg,
                                                color: getTypeConfig(level.children[0]?.type).color,
                                            }">
                                                <VIcon :icon="getTypeConfig(level.children[0]?.type).icon" size="12" />
                                                {{ getLevelLabel(level) }}
                                            </div>
                                        </div>
                                        <VSelect
                                            :model-value="level.selectedId"
                                            :items="level.children"
                                            item-title="name"
                                            item-value="id"
                                            :label="`Select ${getLevelLabel(level)}`"
                                            :prepend-inner-icon="getTypeConfig(level.children[0]?.type).mdiIcon"
                                            variant="outlined"
                                            density="compact"
                                            clearable
                                            @update:model-value="onLevelSelect(index, $event)"
                                        />
                                    </div>
                                </template>
                                <p v-else class="text-caption text-medium-emphasis text-center py-2">
                                    No organization units available
                                </p>
                            </VCol>
                        </VRow>
                    </VWindowItem>

                    <!-- Step 4: Dates -->
                    <VWindowItem>
                        <VRow>
                            <VCol cols="12">
                                <h6 class="text-h6 font-weight-medium">Dates</h6>
                                <p class="mb-4">Optional scheduling and expiration</p>
                            </VCol>
                            <VCol cols="12" md="6">
                                <VTextField v-model="event_date" label="Event Date" type="date" variant="outlined"
                                    density="compact" prepend-inner-icon="tabler-calendar" :error-messages="errors.event_date" />
                            </VCol>
                            <VCol cols="12" md="6">
                                <VTextField v-model="expired_at" label="Expiration Date" type="datetime-local"
                                    variant="outlined" density="compact" prepend-inner-icon="tabler-calendar-clock"
                                    :error-messages="errors.expired_at" />
                            </VCol>
                            <VCol cols="12" md="6">
                                <VTextField v-model="event_start" label="Event Start Time" type="time" variant="outlined"
                                    density="compact" prepend-inner-icon="tabler-clock-play" :error-messages="errors.event_start" />
                            </VCol>
                            <VCol cols="12" md="6">
                                <VTextField v-model="event_end" label="Event End Time" type="time" variant="outlined"
                                    density="compact" prepend-inner-icon="tabler-clock-stop" :error-messages="errors.event_end" />
                            </VCol>
                            <VCol cols="12">
                                <VSwitch v-model="is_active" label="Active" color="primary" density="compact" hide-details inset />
                            </VCol>
                        </VRow>
                    </VWindowItem>

                    <!-- Step 5: Review & Publish -->
                    <VWindowItem>
                        <VRow>
                            <VCol cols="12">
                                <h6 class="text-h6 font-weight-medium">Review & Publish</h6>
                                <p class="mb-4">Verify all information before publishing</p>
                            </VCol>
                            <VCol cols="12">
                                <div class="review-section">
                                    <h6 class="text-subtitle-2 font-weight-bold mb-3">Content</h6>
                                    <div class="review-item">
                                        <span class="review-label">Title</span>
                                        <span class="review-value">{{ title || "—" }}</span>
                                    </div>
                                    <div v-if="icon" class="review-item">
                                        <span class="review-label">Icon</span>
                                        <span class="review-value">
                                            <i :class="icon" style="color: #6366f1" class="me-1" />
                                        </span>
                                    </div>
                                    <div class="review-item">
                                        <span class="review-label">Summary</span>
                                        <span class="review-value" v-html="summary || '—'" />
                                    </div>

                                    <VDivider class="my-3" />

                                    <h6 class="text-subtitle-2 font-weight-bold mb-3">Media</h6>
                                    <div v-if="imagePreview" class="review-media">
                                        <img :src="imagePreview" alt="Preview" class="review-media-img" />
                                    </div>
                                    <div v-else-if="videoPreview" class="review-media">
                                        <video :src="videoPreview" class="review-media-video" />
                                    </div>
                                    <p v-else class="text-caption text-medium-emphasis">No media attached</p>

                                    <VDivider class="my-3" />

                                    <h6 class="text-subtitle-2 font-weight-bold mb-3">Organization</h6>
                                    <p v-if="selectedUnitPath" class="text-body-2 d-flex align-center gap-2">
                                        <VIcon icon="tabler-sitemap" size="14" color="primary" />{{ selectedUnitPath }}
                                    </p>
                                    <p v-else class="text-caption text-medium-emphasis">All units (no restriction)</p>

                                    <VDivider class="my-3" />

                                    <h6 class="text-subtitle-2 font-weight-bold mb-3">Dates</h6>
                                    <div class="review-item">
                                        <span class="review-label">Event Date</span>
                                        <span class="review-value">{{ event_date || "—" }}</span>
                                    </div>
                                    <div class="review-item">
                                        <span class="review-label">Event Time</span>
                                        <span class="review-value">
                                            {{ event_start ? `${event_start}${event_end ? ` → ${event_end}` : ''}` : '—' }}
                                        </span>
                                    </div>
                                    <div class="review-item">
                                        <span class="review-label">Expires</span>
                                        <span class="review-value">{{ expired_at || "—" }}</span>
                                    </div>

                                    <VDivider class="my-3" />

                                    <h6 class="text-subtitle-2 font-weight-bold mb-3">Status</h6>
                                    <VChip :color="is_active ? 'success' : 'default'" size="small" variant="tonal">
                                        {{ is_active ? 'Active' : 'Inactive' }}
                                    </VChip>
                                </div>
                            </VCol>
                        </VRow>
                    </VWindowItem>
                </VWindow>
            </VCardText>

            <!-- Footer -->
            <div
                class="d-flex flex-wrap gap-4 justify-space-between pa-6 flex-shrink-0"
                style="border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity))"
            >
                <VBtn variant="tonal" color="secondary" :disabled="currentStep === 0" @click="prevStep">
                    <VIcon icon="tabler-arrow-left" start class="flip-in-rtl" />Previous
                </VBtn>

                <VBtn
                    v-if="iconsSteps.length - 1 === currentStep"
                    color="success"
                    :loading="store.isLoading"
                    @click="submit"
                >
                    <template #loader>
                        <VProgressCircular indeterminate size="18" width="2" color="white" class="me-2" />
                        <span>Saving…</span>
                    </template>
                    <VIcon icon="tabler-device-floppy" size="16" class="me-2" />Publish News
                </VBtn>

                <VBtn v-else color="primary" :disabled="!canGoNext" @click="nextStep">
                    Next<VIcon icon="tabler-arrow-right" end class="flip-in-rtl" />
                </VBtn>
            </div>
        </VCard>
    </VDialog>
</template>

<style scoped>
.custom-stepper { display: flex; align-items: center; justify-content: center; gap: 0; }
.stepper-step { display: flex; align-items: center; cursor: pointer; position: relative; }
.stepper-icon { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: rgba(var(--v-border-color), 0.15); color: rgba(var(--v-theme-on-surface), 0.4); transition: all 0.3s ease; flex-shrink: 0; }
.stepper-icon--active { background: rgb(var(--v-theme-primary)); color: #fff; box-shadow: 0 2px 8px rgba(var(--v-theme-primary), 0.3); }
.stepper-icon--completed { background: rgb(var(--v-theme-primary)); color: #fff; }
.stepper-title { display: none; }
.stepper-line { width: 50px; height: 2px; background: rgba(var(--v-border-color), 0.3); margin: 0 4px; transition: background 0.3s ease; }
.stepper-line--completed { background: rgb(var(--v-theme-primary)); }

.drop-zone { display: flex; align-items: center; justify-content: center; aspect-ratio: 16/9; border: 2px dashed rgba(var(--v-border-color), 0.4); border-radius: 8px; cursor: pointer; transition: border-color 0.2s; }
.drop-zone:hover { border-color: rgb(var(--v-theme-primary)); }

.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); }

.media-preview { position: relative; aspect-ratio: 16/9; border-radius: 8px; overflow: hidden; background: #000; }
.media-preview img, .media-preview video { width: 100%; height: 100%; object-fit: contain; }
.media-actions { position: absolute; bottom: 0.5rem; right: 0.5rem; display: flex; gap: 0.5rem; }

.level-badge { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; text-transform: capitalize; letter-spacing: 0.3px; }

.review-section { padding: 8px 0; }
.review-item { display: flex; justify-content: space-between; align-items: flex-start; padding: 8px 0; gap: 16px; }
.review-label { font-size: 12px; font-weight: 600; color: rgba(var(--v-theme-on-surface), 0.6); min-width: 100px; text-transform: uppercase; letter-spacing: 0.4px; }
.review-value { font-size: 14px; text-align: right; word-break: break-word; }
.review-media { border-radius: 8px; overflow: hidden; max-height: 200px; }
.review-media-img, .review-media-video { width: 100%; max-height: 200px; object-fit: contain; background: #f5f5f5; }
</style>
