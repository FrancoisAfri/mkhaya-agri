<script setup lang="ts">
import { watch, nextTick, computed, ref } from "vue";
import { usePage } from "@inertiajs/vue3";
import ProductDescriptionEditor from "@/@core/components/ProductDescriptionEditor.vue";
import { useI18n } from 'vue-i18n'
import {
    useContentForm,
    getTypeConfig,
    getLevelLabel,
    iconPresets,
    toDateInput,
    toDatetimeLocalInput,
    type CompanyUnit,
} from "./Usecontentform";

import "./style.css";

const { t } = useI18n()

const props = withDefaults(
    defineProps<{
        companyUnits?: CompanyUnit[];
        mode: "add" | "edit";
        contentType: number;
        headerTitle: string;
        headerSubtitle: string;
        headerIcon: string;
        submitLabel: string;
        submitColor?: string;
        submitIcon?: string;
        editing?: Record<string, any> | null;
        isLoading?: boolean;
    }>(),
    {
        companyUnits: () => [],
        submitColor: "success",
        submitIcon: "fa-solid fa-floppy-disk",
        editing: null,
        isLoading: false,
    },
);

const emit = defineEmits<{
    (e: "submit", form: FormData): void;
}>();

const model = defineModel<boolean>();
const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string>);

function localSnack(msg: string, _: string) {
    console.warn(msg);
}

const f = useContentForm(() => props.companyUnits, localSnack);

const {
    title,
    summary,
    icon,
    event_start,
    event_end,
    event_date,
    expired_at,
    is_active,
    imageFile,
    videoFile,
    imagePreview,
    videoPreview,
    currentStep,
    levels,
    selectedCompanyUnitId,
    selectedUnitPath,
    onLevelSelect,
    handleImage,
    handleVideo,
    removeMedia,
    iconsSteps: rawIconsSteps,
    canGoNext,
    nextStep,
    prevStep,
    reset,
    initLevels,
    buildPathToUnit,
    buildLevelsFromPath,
} = f;

const iconsSteps = computed(() => [
  { ...rawIconsSteps[0], title: t('content_form.steps.content') },
  { ...rawIconsSteps[1], title: t('content_form.steps.media') },
  { ...rawIconsSteps[2], title: t('content_form.steps.organization') },
  { ...rawIconsSteps[3], title: t('content_form.steps.dates') },
  { ...rawIconsSteps[4], title: t('content_form.steps.review') },
])

watch(
    () => props.editing,
    (val) => {
        if (!val || props.mode !== "edit") return;

        title.value = val.title ?? "";
        summary.value = val.summary ?? "";
        icon.value = val.icon ?? "";
        is_active.value = val.is_active ?? true;
        event_date.value = toDateInput(val.event_date ?? null);
        expired_at.value = toDatetimeLocalInput(val.expired_at ?? null);
        event_start.value = val.event_start ? val.event_start.slice(0, 5) : "";
        event_end.value = val.event_end ? val.event_end.slice(0, 5) : "";

        const img = val.asset?.image as any;
        imagePreview.value =
            Array.isArray(img) && img[0]?.data
                ? `data:${img[0].mime};base64,${img[0].data}`
                : null;

        const vid = val.asset?.video;
        if (vid) {
            try {
                const parsed = typeof vid === "string" ? JSON.parse(vid) : vid;
                videoPreview.value = parsed.path
                    ? `/storage/${parsed.path}`
                    : null;
            } catch {
                videoPreview.value = null;
            }
        } else {
            videoPreview.value = null;
        }

        imageFile.value = null;
        videoFile.value = null;
        currentStep.value = 0;

        if (val.company_unit_id) {
            buildLevelsFromPath(buildPathToUnit(val.company_unit_id));
        } else {
            initLevels();
        }
    },
    { immediate: true },
);

watch(model, (open) => {
    if (!open) nextTick(() => reset());
});

const today = computed(() => {
    return new Date().toISOString().split("T")[0];
});

const minExpiredAt = computed(() => {
    return event_date.value || today.value;
});

const localDateErrors = computed(() => {
    const e: Record<string, string[]> = {};

    if (event_date.value && event_date.value < today.value) {
        e.event_date = [t('content_form.step_dates.event_date_past')];
    }

    if (!expired_at.value) {
        e.expired_at = [t('content_form.step_dates.expiration_required')];
    }

    if (event_date.value && expired_at.value) {
        const eventDate = new Date(event_date.value);
        const expireDate = new Date(expired_at.value);

        if (expireDate < eventDate) {
            e.expired_at = [t('content_form.step_dates.expiration_after_event')];
        }
    }

    return e;
});

const timeRangeError = ref(false);

const timeRules = [
    (v: string) => {
        if (!v) return true;
        return /^([01]?[0-9]|2[0-3]):[0-5][0-9]$/.test(v) || t('content_form.step_dates.invalid_time');
    },
];

const endTimeRules = computed(() => {
    return [
        (v: string) => {
            if (!v) return true;
            if (!/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/.test(v)) {
                return t('content_form.step_dates.invalid_time');
            }
            if (!event_start.value) return true;
            return v > event_start.value || t('content_form.step_dates.end_after_start');
        },
    ];
});

function validateTimeRange() {
    if (event_start.value && event_end.value) {
        timeRangeError.value = event_end.value <= event_start.value;
    } else {
        timeRangeError.value = false;
    }
}

watch([event_start, event_end], () => {
    validateTimeRange();
});

const quickDurations = [
    { label: "30min", minutes: 30 },
    { label: "1h", minutes: 60 },
    { label: "1.5h", minutes: 90 },
    { label: "2h", minutes: 120 },
    { label: "3h", minutes: 180 },
    { label: t('content_form.step_dates.all_day'), minutes: 480 },
];

function setDuration(minutes: number) {
    if (!event_start.value) {
        const now = new Date();
        event_start.value = `${String(now.getHours()).padStart(2, "0")}:${String(now.getMinutes()).padStart(2, "0")}`;
    }

    const [hours, mins] = event_start.value.split(":").map(Number);
    const totalMinutes = hours * 60 + mins + minutes;
    const newHours = Math.floor(totalMinutes / 60) % 24;
    const newMins = totalMinutes % 60;
    event_end.value = `${String(newHours).padStart(2, "0")}:${String(newMins).padStart(2, "0")}`;
}

function isDurationActive(minutes: number): boolean {
    if (!event_start.value || !event_end.value) return false;
    const [sh, sm] = event_start.value.split(":").map(Number);
    const [eh, em] = event_end.value.split(":").map(Number);
    const startMinutes = sh * 60 + sm;
    const endMinutes = eh * 60 + em;
    const diff = endMinutes >= startMinutes
        ? endMinutes - startMinutes
        : (24 * 60 - startMinutes) + endMinutes;
    return diff === minutes;
}

function submit() {
    if (Object.keys(localDateErrors.value).length > 0) return;
    if (timeRangeError.value) return;

    const fd = new FormData();
    fd.append("title", title.value);
    fd.append("summary", summary.value);
    fd.append("icon", icon.value);
    fd.append("is_active", is_active.value ? "1" : "0");
    fd.append("type", String(props.contentType));

    if (event_start.value) fd.append("event_start", event_start.value);
    if (event_end.value) fd.append("event_end", event_end.value);
    if (event_date.value) fd.append("event_date", event_date.value);
    fd.append("expired_at", expired_at.value);

    if (selectedCompanyUnitId.value)
        fd.append("company_unit_id", String(selectedCompanyUnitId.value));
    if (imageFile.value instanceof File) fd.append("image", imageFile.value);
    if (videoFile.value instanceof File) fd.append("video", videoFile.value);
    if (props.mode === "edit") fd.append("_method", "PUT");

    emit("submit", fd);
    model.value = false;
    reset();
}
</script>

<template>
    <VDialog v-model="model" max-width="800" persistent scrollable>
        <VCard elevation="0">
            <div
                class="d-flex align-center justify-space-between px-6 py-4 flex-shrink-0"
                style="border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));"
            >
                <div class="d-flex align-center gap-3">
                    <div class="d-flex align-center justify-center rounded-lg flex-shrink-0"
                        style="width: 40px; height: 40px; background: #eef2ff">
                        <i :class="headerIcon" style="color: #6366f1; font-size: 16px" />
                    </div>
                    <div>
                        <p class="text-body-1 font-weight-semibold mb-0">{{ headerTitle }}</p>
                        <p class="text-caption text-medium-emphasis mb-0">{{ headerSubtitle }}</p>
                    </div>
                </div>
                <VBtn icon="tabler-x" variant="text" size="small" @click="model = false" />
            </div>

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
                            <i :class="step.icon" style="font-size: 14px" />
                        </div>
                        <span class="stepper-title">{{ step.title }}</span>
                        <div
                            v-if="index < iconsSteps.length - 1"
                            class="stepper-line"
                            :class="{
                                'stepper-line--completed': index < currentStep,
                            }"
                        />
                    </div>
                </div>
            </VCardText>

            <VDivider />

            <VCardText class="pa-6" style="overflow-y: auto; max-height: 50vh">
                <VWindow v-model="currentStep" class="disable-tab-transition">
                    <VWindowItem>
                        <VRow>
                            <VCol cols="12">
                                <h6 class="text-h6 font-weight-medium">{{ t('content_form.step_content.title') }}</h6>
                                <p class="mb-4">{{ mode === "edit" ? t('content_form.step_content.subtitle_edit') : t('content_form.step_content.subtitle_add') }}</p>
                            </VCol>
                            <VCol cols="12">
                                <VTextField
                                    v-model="title"
                                    :label="t('content_form.step_content.title_label')"
                                    variant="outlined"
                                    density="compact"
                                    :placeholder="t('content_form.step_content.title_placeholder')"
                                    prepend-inner-icon="tabler-text-size"
                                    :error-messages="errors.title"
                                />
                            </VCol>
                            <VCol cols="12">
                                <p class="text-caption text-medium-emphasis mb-2">{{ t('content_form.step_content.summary_label') }}</p>
                                <ProductDescriptionEditor
                                    :model-value="summary"
                                    @update:model-value="summary = $event"
                                />
                                <p v-if="errors.summary" class="text-caption text-error mt-1">{{ errors.summary }}</p>
                            </VCol>
                        </VRow>
                    </VWindowItem>

                    <VWindowItem>
                        <VRow>
                            <VCol cols="12">
                                <h6 class="text-h6 font-weight-medium">{{ t('content_form.step_media.title') }}</h6>
                                <p class="mb-4">{{ mode === "edit" ? t('content_form.step_media.subtitle_edit') : t('content_form.step_media.subtitle_add') }}</p>
                            </VCol>
                            <VCol cols="12">
                                <VSelect
                                    v-model="icon"
                                    :items="iconPresets"
                                    item-title="label"
                                    item-value="value"
                                    :label="t('content_form.step_media.icon_label')"
                                    variant="outlined"
                                    density="compact"
                                    prepend-inner-icon="tabler-shapes"
                                    clearable
                                    :placeholder="t('content_form.step_media.icon_placeholder')"
                                >
                                    <template #item="{ item, props: ip }">
                                        <VListItem v-bind="ip" :title="item.raw.label">
                                            <template #prepend><i :class="item.raw.value" style="font-size: 16px; color: #6366f1;" /></template>
                                        </VListItem>
                                    </template>
                                    <template #selection="{ item }">
                                        <i :class="item.raw.value" style="font-size: 16px; color: #6366f1;" class="me-2" />{{ item.raw.label }}
                                    </template>
                                </VSelect>
                            </VCol>
                            <VCol cols="12" class="mt-4">
                                <p class="text-overline text-medium-emphasis mb-3">{{ t('content_form.step_media.media_label') }}</p>
                                <VRow v-if="!imagePreview && !videoPreview">
                                    <VCol cols="6">
                                        <label class="drop-zone">
                                            <div class="text-center">
                                                <i class="fa-solid fa-image mb-2" style="font-size: 24px; opacity: 0.3;" />
                                                <p class="text-caption text-medium-emphasis mb-0">{{ t('content_form.step_media.image_formats') }}</p>
                                            </div>
                                            <input type="file" accept=".jpg,.jpeg,.png,.webp" class="sr-only" @change="handleImage" />
                                        </label>
                                    </VCol>
                                    <VCol cols="6">
                                        <label class="drop-zone">
                                            <div class="text-center">
                                                <i class="fa-solid fa-film mb-2" style="font-size: 24px; opacity: 0.3;" />
                                                <p class="text-caption text-medium-emphasis mb-0">{{ t('content_form.step_media.video_formats') }}</p>
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
                                                        <i class="fa-solid fa-arrow-rotate-right me-1" />{{ t('content_form.step_media.change') }}
                                                    </VBtn>
                                                    <input type="file" accept=".jpg,.jpeg,.png,.webp" class="sr-only" @change="handleImage" />
                                                </label>
                                                <VBtn size="small" color="error" variant="flat" @click="removeMedia">
                                                    <i class="fa-solid fa-trash me-1" />{{ t('content_form.step_media.remove') }}
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
                                                        <i class="fa-solid fa-arrow-rotate-right me-1" />{{ t('content_form.step_media.change') }}
                                                    </VBtn>
                                                    <input type="file" accept="video/*" class="sr-only" @change="handleVideo" />
                                                </label>
                                                <VBtn size="small" color="error" variant="flat" @click="removeMedia">
                                                    <i class="fa-solid fa-trash me-1" />{{ t('content_form.step_media.remove') }}
                                                </VBtn>
                                            </div>
                                        </div>
                                    </VCol>
                                </VRow>
                            </VCol>
                        </VRow>
                    </VWindowItem>

                    <VWindowItem>
                        <VRow>
                            <VCol cols="12">
                                <h6 class="text-h6 font-weight-medium">{{ t('content_form.step_organization.title') }}</h6>
                                <p class="mb-4">{{ t('content_form.step_organization.subtitle') }}</p>
                            </VCol>
                            <VCol cols="12">
                                <template v-if="levels.length > 0">
                                    <div v-for="(level, index) in levels" :key="index" class="mb-3">
                                        <div class="d-flex align-center gap-2 mb-2">
                                            <i v-if="index > 0" class="fa-solid fa-arrow-turn-down-right" style="font-size: 10px; color: #94a3b8; margin-left: 4px;" />
                                            <div class="level-badge" :style="{ background: getTypeConfig(level.children[0]?.type).bg, color: getTypeConfig(level.children[0]?.type).color }">
                                                <i :class="`fa-solid ${getTypeConfig(level.children[0]?.type).icon}`" style="font-size: 10px" />
                                                {{ getLevelLabel(level) }}
                                            </div>
                                        </div>
                                        <VSelect
                                            :model-value="level.selectedId"
                                            :items="level.children"
                                            item-title="name"
                                            item-value="id"
                                            :label="t('content_form.step_organization.select_label', { level: getLevelLabel(level) })"
                                            :prepend-inner-icon="getTypeConfig(level.children[0]?.type).mdiIcon"
                                            variant="outlined"
                                            density="compact"
                                            clearable
                                            @update:model-value="onLevelSelect(index, $event)"
                                        />
                                    </div>
                                </template>
                                <p v-else class="text-caption text-medium-emphasis text-center py-2">{{ t('content_form.step_organization.no_units') }}</p>
                            </VCol>
                        </VRow>
                    </VWindowItem>

                    <VWindowItem>
                        <VRow>
                            <VCol cols="12">
                                <h6 class="text-h6 font-weight-medium">{{ t('content_form.step_dates.title') }}</h6>
                                <p class="mb-4">{{ t('content_form.step_dates.subtitle') }}</p>
                            </VCol>
                            <VCol cols="12" md="6">
                                <VTextField
                                    v-model="event_date"
                                    :label="t('content_form.step_dates.event_date')"
                                    type="date"
                                    variant="outlined"
                                    density="compact"
                                    prepend-inner-icon="tabler-calendar"
                                    :min="today"
                                    :error-messages="localDateErrors.event_date || errors.event_date"
                                />
                            </VCol>
                            <VCol cols="12" md="6">
                                <VTextField
                                    v-model="expired_at"
                                    :label="t('content_form.step_dates.expiration_date')"
                                    type="date"
                                    variant="outlined"
                                    density="compact"
                                    prepend-inner-icon="tabler-calendar-clock"
                                    :min="minExpiredAt"
                                    :rules="[(v: string) => !!v || t('content_form.step_dates.expiration_required')]"
                                    :error-messages="localDateErrors.expired_at || errors.expired_at"
                                />
                            </VCol>
                            <VCol cols="12" md="6">
                                <VTextField
                                    v-model="event_start"
                                    :label="t('content_form.step_dates.start_time')"
                                    type="time"
                                    variant="outlined"
                                    density="compact"
                                    prepend-inner-icon="tabler-clock"
                                    :error-messages="errors.event_start"
                                    :rules="timeRules"
                                    @update:model-value="validateTimeRange"
                                    :hint="t('content_form.step_dates.time_hint')"
                                    persistent-hint
                                />
                            </VCol>
                            <VCol cols="12" md="6">
                                <VTextField
                                    v-model="event_end"
                                    :label="t('content_form.step_dates.end_time')"
                                    type="time"
                                    variant="outlined"
                                    density="compact"
                                    prepend-inner-icon="tabler-clock-check"
                                    :error-messages="errors.event_end"
                                    :rules="endTimeRules"
                                    :min="event_start || undefined"
                                    @update:model-value="validateTimeRange"
                                    :hint="t('content_form.step_dates.end_time_hint')"
                                    persistent-hint
                                />
                            </VCol>
                            <VCol cols="12" v-if="event_start || event_end">
                                <div class="d-flex align-center flex-wrap gap-2 mt-1">
                                    <span class="text-caption text-medium-emphasis">{{ t('content_form.step_dates.quick_set') }}</span>
                                    <VChip
                                        v-for="duration in quickDurations"
                                        :key="duration.label"
                                        size="x-small"
                                        :variant="isDurationActive(duration.minutes) ? 'tonal' : 'outlined'"
                                        color="primary"
                                        class="cursor-pointer"
                                        @click="setDuration(duration.minutes)"
                                    >
                                        {{ duration.label }}
                                    </VChip>
                                </div>
                            </VCol>
                            <VCol cols="12" v-if="timeRangeError">
                                <VAlert type="warning" variant="tonal" density="compact" closable @click:close="timeRangeError = false">
                                    <template v-slot:text>{{ t('content_form.step_dates.time_error') }}</template>
                                </VAlert>
                            </VCol>
                            <VCol cols="12">
                                <VSwitch v-model="is_active" :label="t('content_form.step_dates.active')" color="primary" density="compact" hide-details inset />
                            </VCol>
                        </VRow>
                    </VWindowItem>

                    <VWindowItem>
                        <VRow>
                            <VCol cols="12">
                                <h6 class="text-h6 font-weight-medium">{{ t('content_form.step_review.title') }}</h6>
                                <p class="mb-4">{{ mode === "edit" ? t('content_form.step_review.subtitle_edit') : t('content_form.step_review.subtitle_add') }}</p>
                            </VCol>
                            <VCol cols="12">
                                <div class="review-section">
                                    <h6 class="text-subtitle-2 font-weight-bold mb-3">{{ t('content_form.step_review.content_section') }}</h6>
                                    <div class="review-item">
                                        <span class="review-label">{{ t('content_form.step_review.title_label') }}</span>
                                        <span class="review-value">{{ title || "—" }}</span>
                                    </div>
                                    <div v-if="icon" class="review-item">
                                        <span class="review-label">{{ t('content_form.step_review.icon_label') }}</span>
                                        <span class="review-value"><i :class="icon" style="color: #6366f1" /></span>
                                    </div>
                                    <div class="review-item">
                                        <span class="review-label">{{ t('content_form.step_review.summary_label') }}</span>
                                        <span class="review-value" v-html="summary || '—'" />
                                    </div>
                                    <VDivider class="my-3" />
                                    <h6 class="text-subtitle-2 font-weight-bold mb-3">{{ t('content_form.step_review.media_section') }}</h6>
                                    <div v-if="imagePreview" class="review-media">
                                        <img :src="imagePreview" alt="Preview" class="review-media-img" />
                                    </div>
                                    <div v-else-if="videoPreview" class="review-media">
                                        <video :src="videoPreview" class="review-media-video" />
                                    </div>
                                    <p v-else class="text-caption text-medium-emphasis">{{ t('content_form.step_review.no_media') }}</p>
                                    <VDivider class="my-3" />
                                    <h6 class="text-subtitle-2 font-weight-bold mb-3">{{ t('content_form.step_review.organization_section') }}</h6>
                                    <p v-if="selectedUnitPath" class="text-body-2">
                                        <i class="fa-solid fa-sitemap me-2 text-primary" />{{ selectedUnitPath }}
                                    </p>
                                    <p v-else class="text-caption text-medium-emphasis">{{ t('content_form.step_review.all_units') }}</p>
                                    <VDivider class="my-3" />
                                    <h6 class="text-subtitle-2 font-weight-bold mb-3">{{ t('content_form.step_review.dates_section') }}</h6>
                                    <div class="review-item">
                                        <span class="review-label">{{ t('content_form.step_review.event_date') }}</span>
                                        <span class="review-value">{{ event_date || "—" }}</span>
                                    </div>
                                    <div class="review-item">
                                        <span class="review-label">{{ t('content_form.step_review.event_time') }}</span>
                                        <span class="review-value">{{ event_start ? `${event_start}${event_end ? ` → ${event_end}` : ""}` : "—" }}</span>
                                    </div>
                                    <div class="review-item">
                                        <span class="review-label">{{ t('content_form.step_review.expires') }}</span>
                                        <span class="review-value">{{ expired_at || "—" }}</span>
                                    </div>
                                    <VDivider class="my-3" />
                                    <h6 class="text-subtitle-2 font-weight-bold mb-3">{{ t('content_form.step_review.status_section') }}</h6>
                                    <VChip :color="is_active ? 'success' : 'default'" size="small" variant="tonal">
                                        {{ is_active ? t('content_form.step_review.active') : t('content_form.step_review.inactive') }}
                                    </VChip>
                                </div>
                            </VCol>
                        </VRow>
                    </VWindowItem>
                </VWindow>
            </VCardText>

            <div
                class="d-flex flex-wrap gap-4 justify-space-between pa-6 flex-shrink-0"
                style="border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));"
            >
                <VBtn variant="tonal" color="secondary" :disabled="currentStep === 0" @click="prevStep">
                    <VIcon icon="tabler-arrow-left" start class="flip-in-rtl" />{{ t('content_form.footer.previous') }}
                </VBtn>
                <VBtn
                    v-if="iconsSteps.length - 1 === currentStep"
                    :color="submitColor"
                    :loading="isLoading"
                    @click="submit"
                >
                    <template #loader>
                        <VProgressCircular indeterminate size="18" width="2" color="white" class="me-2" />
                        <span>{{ t('content_form.footer.saving') }}</span>
                    </template>
                    <i :class="`${submitIcon} me-2`" />{{ submitLabel }}
                </VBtn>
                <VBtn v-else color="primary" :disabled="!canGoNext" @click="nextStep">
                    {{ t('content_form.footer.next') }}
                    <VIcon icon="tabler-arrow-right" end class="flip-in-rtl" />
                </VBtn>
            </div>
        </VCard>
    </VDialog>
</template>
