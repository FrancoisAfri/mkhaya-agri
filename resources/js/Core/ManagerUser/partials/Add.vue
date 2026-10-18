<script setup lang="ts">
import type { VForm } from "vuetify/components/VForm";
import { ref, nextTick, watch, computed } from "vue";
import { useForm } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import { UserProperties } from "../types/type";

const { t } = useI18n();

interface Role         { id: number; name: string }
interface Position     { id: number; name: string }
interface LeaveProfile { id: number; name: string }
interface CompanyUnit  { id: number; name: string; parent_id: number | null; type?: string|null}

interface CompanyUnitsObject {
    divisions?:   CompanyUnit[]
    departments?: CompanyUnit[]
    teams?:       CompanyUnit[]
    sections?:    CompanyUnit[]
    units?:       CompanyUnit[]
}

interface Level {
    selectedId: number | null
    children:   CompanyUnit[]
}

interface Props {
    isDrawerOpen:   boolean
    roles:          Role[]
    positions:      Position[]
    leave_profiles: LeaveProfile[]
    companyUnits:   CompanyUnitsObject
    managers:       UserProperties[]
}

// ── 1. Props & emits ──────────────────────────────────────────────────────────
const props = defineProps<Props>()
const emit  = defineEmits<{
    (e: "update:isDrawerOpen", value: boolean): void
    (e: "userData"): void
}>()

// ── 2. Computed dérivés de props ──────────────────────────────────────────────
const formattedRoles = computed(() =>
    (props.roles ?? []).map(role => ({
        title: role.name.charAt(0).toUpperCase() + role.name.slice(1),
        value: role.name,
    }))
)

const formattedManagers = computed(() =>
    (props.managers ?? [])
        .filter(m => m.person != null)
        .map(m => ({
            title: `${m.person!.first_name ?? ''} ${m.person!.surname ?? ''}`.trim(),
            value: m.id,
        }))
)

// ── 3. Form ───────────────────────────────────────────────────────────────────
const isFormValid = ref(false)
const refForm     = ref<VForm>()

const form = useForm({
    will_login:           true,
    email:                "",
    phone_number:         "",
    role:                 null as string | null,
    status:               1,
    first_name:           "",
    surname:              "",
    initial:              "",
    employee_number:      "",
    date_joined:          null as string | null,
    manager_id:           null as number | null,
    leave_profile:        "",
    position_id:          null as number | null,
    gender:               null as number | null,
    job_function:         "",
    occupational_level:   "",
    nature_of_disability: "",
    disabled:             false as boolean,
    company_ids:          [] as number[],
})

// ── 4. Type config — UNE SEULE fois ──────────────────────────────────────────
const typeConfig: Record<string, { label: string; icon: string; mdiIcon: string; color: string; bg: string }> = {
    division:   { label: 'Division',   icon: 'fa-building',     mdiIcon: 'tabler-building', color: '#6366f1', bg: '#eef2ff' },
    department: { label: 'Department', icon: 'fa-sitemap',      mdiIcon: 'tabler-sitemap',          color: '#0d9488', bg: '#f0fdfa' },
    team:       { label: 'Team',       icon: 'fa-people-group', mdiIcon: 'tabler-users',    color: '#f59e0b', bg: '#fffbeb' },
    section:    { label: 'Section',    icon: 'fa-layer-group',  mdiIcon: 'tabler-layout-dashboard',   color: '#ec4899', bg: '#fdf2f8' },
    unit:       { label: 'Unit',       icon: 'fa-cube',         mdiIcon: 'tabler-cube',     color: '#64748b', bg: '#f8fafc' },
}

const getTypeConfig = (type: string | undefined) =>
    typeConfig[type ?? ''] ?? { label: type ?? 'Unit', icon: 'fa-cube', mdiIcon: 'tabler-cube', color: '#64748b', bg: '#f8fafc' }

const getLevelLabel = (level: Level): string =>
    getTypeConfig(level.children[0]?.type).label

// ── 5. Company units dynamiques ───────────────────────────────────────────────
const allUnits = computed<CompanyUnit[]>(() => [
    ...(props.companyUnits.divisions   ?? []),
    ...(props.companyUnits.departments ?? []),
    ...(props.companyUnits.teams       ?? []),
    ...(props.companyUnits.sections    ?? []),
    ...(props.companyUnits.units       ?? []),
])

const levels = ref<Level[]>([])

const roots = computed(() =>
    allUnits.value.filter(u => u.parent_id === null)
)

const initLevels = () => {
    levels.value = roots.value.length
        ? [{ selectedId: null, children: roots.value }]
        : []
    form.company_ids = []
}

const onLevelSelect = (levelIndex: number, selectedId: number | null) => {
    levels.value[levelIndex].selectedId = selectedId
    levels.value = levels.value.slice(0, levelIndex + 1)

    if (selectedId !== null) {
        const children = allUnits.value.filter(u => u.parent_id === selectedId)
        if (children.length > 0) {
            levels.value.push({ selectedId: null, children })
        }
    }

    form.company_ids = levels.value
        .map(l => l.selectedId)
        .filter((id): id is number => id !== null)
}

watch(() => props.companyUnits, initLevels, { immediate: true })

// ── 6. Validators ─────────────────────────────────────────────────────────────
const requiredValidator = (v: unknown) =>
    (v !== null && v !== undefined && v !== "") || t("this_field_is_required")

// Login-only / HR fields are only mandatory when the employee will sign in — a
// non-login employee (cleaner) needs just name + cell for a quick entry.
const reqLogin = computed(() => (form.will_login ? [requiredValidator] : []))

const emailValidator = (v: string) =>
    /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v) || t("invalid_email")

// ── 7. Reset on close ─────────────────────────────────────────────────────────
watch(() => props.isDrawerOpen, open => {
    if (!open) {
        nextTick(() => {
            form.reset()
            form.clearErrors()
            refForm.value?.resetValidation()
            initLevels()
        })
    }
})

// ── 8. Options statiques ──────────────────────────────────────────────────────
// 1 and 2, not 0 and 1: every screen that *reads* gender — the profile, the approval queue,
// the EE report — labels 1 as Male and 2 as Female. Capturing 0/1 here meant a person added
// as Female was shown as Male everywhere afterwards.
const genderOptions    = [{ value: 1, label: "Male" }, { value: 2, label: "Female" }]
const occupation_level = [
    { value: 1, label: "Senior Management" },
    { value: 2, label: "Middle Management" },
    { value: 3, label: "Junior Management" },
    { value: 4, label: "Semi Skilled" },
    { value: 5, label: "Unskilled" },
    { value: 6, label: "Professional" },
    { value: 7, label: "Skilled" },
]
const job_function = [
    { value: 1, label: "Core/Operational" },
    { value: 2, label: "Support" },
]

// ── 9. Actions ────────────────────────────────────────────────────────────────
const closeDialog = () => emit("update:isDrawerOpen", false)

const onSubmit = async () => {
    if (!refForm.value) return
    const { valid } = await refForm.value.validate()
    if (!valid) return
    form.post("/settings/storeUser", {
        preserveScroll: true,
        onSuccess: () => {
            emit("userData")
            emit("update:isDrawerOpen", false)
        },
        onError: errors => console.error("Validation errors:", errors),
    })
}
</script>

<template>
    <VDialog
        :model-value="props.isDrawerOpen"
        max-width="800"
        persistent
        scrollable
        @update:model-value="emit('update:isDrawerOpen', $event)"
    >
        <VCard rounded="lg" elevation="0">

            <!-- Header -->
            <div
                class="d-flex align-center justify-space-between px-6 py-4 flex-shrink-0"
                style="border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity))"
            >
                <div class="d-flex align-center gap-3 min-w-0 flex-grow-1">
                    <div
                        class="d-flex align-center justify-center rounded-lg flex-shrink-0"
                        style="width:40px;height:40px;background:#ede9fe"
                    >
                        <i class="fa-solid fa-user-plus" style="color:#6366f1;font-size:16px" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-body-1 font-weight-semibold mb-0">{{ t("add_user") }}</p>
                        <p class="text-caption text-medium-emphasis mb-0">{{ t("fill_in_the_information_below") }}</p>
                    </div>
                </div>
                <VBtn icon="tabler-x" variant="text" size="small" class="flex-shrink-0" @click="closeDialog" />
            </div>

            <!-- Body -->
            <VCardText class="pa-6" style="overflow-y:auto;max-height:60vh">
                <VForm ref="refForm" v-model="isFormValid" @submit.prevent="onSubmit">
                    <VRow dense>

                        <!-- Login toggle -->
                        <VCol cols="12">
                            <div class="form-block mb-4" style="background:#f8fafc">
                                <VSwitch
                                    v-model="form.will_login"
                                    color="primary"
                                    density="compact"
                                    hide-details
                                    :label="form.will_login
                                        ? 'This employee will sign in (an email is required)'
                                        : 'Non-login employee — no email needed (e.g. a cleaner). A supervisor manages their leave.'"
                                />
                            </div>
                        </VCol>

                        <!-- Account -->
                        <VCol cols="12">
                            <div class="form-block mb-4">
                                <p class="block-label">{{ t("account") }}</p>
                                <VRow dense>
                                    <VCol cols="12" :md="form.will_login ? 12 : 6">
                                        <VTextField
                                            v-model="form.email"
                                            :rules="form.will_login ? [requiredValidator, emailValidator] : (form.email ? [emailValidator] : [])"
                                            :error-messages="form.errors.email"
                                            :label="form.will_login ? `${t('email')} *` : t('email')"
                                            placeholder="user@example.com"
                                            prepend-inner-icon="tabler-mail"
                                            variant="outlined"
                                            density="compact"
                                        />
                                    </VCol>
                                    <VCol v-if="!form.will_login" cols="12" md="6">
                                        <VTextField
                                            v-model="form.phone_number"
                                            :rules="[requiredValidator]"
                                            :error-messages="form.errors.phone_number"
                                            :label="`${t('phone_number') || 'Cell number'} *`"
                                            prepend-inner-icon="tabler-phone"
                                            variant="outlined"
                                            density="compact"
                                        />
                                    </VCol>
                                    <VCol cols="12" md="6">
                                        <VSelect
                                            v-model="form.role"
                                            :items="formattedRoles"
                                            item-title="title"
                                            item-value="value"
                                            :rules="reqLogin"
                                            :error-messages="form.errors.role"
                                            :label="`${t('role')} *`"
                                            prepend-inner-icon="tabler-shield-lock"
                                            variant="outlined"
                                            density="compact"
                                        />
                                    </VCol>
                                    <VCol cols="12" md="6">
                                        <VSelect
                                            v-model="form.position_id"
                                            :items="positions"
                                            item-title="name"
                                            item-value="id"
                                            :rules="reqLogin"
                                            :error-messages="form.errors.position_id"
                                            :label="`${t('position')} *`"
                                            prepend-inner-icon="tabler-briefcase"
                                            variant="outlined"
                                            density="compact"
                                        />
                                    </VCol>
                                </VRow>
                            </div>
                        </VCol>

                        <!-- Personal Info -->
                        <VCol cols="12">
                            <div class="form-block mb-4">
                                <p class="block-label">{{ t("personal_info") }}</p>
                                <VRow dense>
                                    <VCol cols="12" md="6">
                                        <VTextField
                                            v-model="form.first_name"
                                            :rules="[requiredValidator]"
                                            :error-messages="form.errors.first_name"
                                            :label="`${t('first_name')} *`"
                                            placeholder="John"
                                            prepend-inner-icon="tabler-user"
                                            variant="outlined"
                                            density="compact"
                                        />
                                    </VCol>
                                    <VCol cols="12" md="6">
                                        <VTextField
                                            v-model="form.surname"
                                            :rules="[requiredValidator]"
                                            :error-messages="form.errors.surname"
                                            :label="`${t('surname')} *`"
                                            placeholder="Doe"
                                            prepend-inner-icon="tabler-user"
                                            variant="outlined"
                                            density="compact"
                                        />
                                    </VCol>
                                    <VCol cols="12" md="4">
                                        <VTextField
                                            v-model="form.initial"
                                            :error-messages="form.errors.initial"
                                            :label="t('initial')"
                                            placeholder="JD"
                                            variant="outlined"
                                            density="compact"
                                        />
                                    </VCol>
                                    <VCol cols="12" md="4">
                                        <VTextField
                                            v-model="form.employee_number"
                                            :error-messages="form.errors.employee_number"
                                            :label="t('employee_number')"
                                            placeholder="EMP001"
                                            variant="outlined"
                                            density="compact"
                                        />
                                    </VCol>
                                    <VCol cols="12" md="4">
                                        <VSelect
                                            v-model="form.gender"
                                            :items="genderOptions"
                                            :rules="reqLogin"
                                            item-title="label"
                                            item-value="value"
                                            :error-messages="form.errors.gender"
                                            :label="`${t('gender')} *`"
                                            variant="outlined"
                                            density="compact"
                                        />
                                    </VCol>
                                </VRow>
                            </div>
                        </VCol>

                        <!-- Employment -->
                        <VCol cols="12">
                            <div class="form-block mb-4">
                                <p class="block-label">{{ t("employment") }}</p>
                                <VRow dense>
                                    <VCol cols="12" md="6">
                                        <VSelect
                                            v-model="form.job_function"
                                            :rules="reqLogin"
                                            :error-messages="form.errors.job_function"
                                            :items="job_function"
                                            item-title="label"
                                            item-value="value"
                                            :label="t('job_function')"
                                            variant="outlined"
                                            density="compact"
                                        />
                                    </VCol>
                                    <VCol cols="12" md="6">
                                        <VSelect
                                            v-model="form.occupational_level"
                                            :rules="reqLogin"
                                            :error-messages="form.errors.occupational_level"
                                            :items="occupation_level"
                                            item-title="label"
                                            item-value="value"
                                            :label="t('occupational_level')"
                                            variant="outlined"
                                            density="compact"
                                        />
                                    </VCol>
                                    <VCol cols="12" md="6">
                                        <VSelect
                                            v-model="form.leave_profile"
                                            :items="leave_profiles"
                                            item-title="name"
                                            item-value="id"
                                            :rules="reqLogin"
                                            :error-messages="form.errors.leave_profile"
                                            :label="t('leave_profile')"
                                            variant="outlined"
                                            density="compact"
                                        />
                                    </VCol>
                                    <VCol cols="12" md="6">
                                        <VTextField
                                            v-model="form.date_joined"
                                            :rules="reqLogin"
                                            :error-messages="form.errors.date_joined"
                                            :label="t('date_joined')"
                                            type="date"
                                            variant="outlined"
                                            density="compact"
                                        />
                                    </VCol>
                                    <VCol cols="12" md="6">
                                        <VSelect
                                            v-model="form.manager_id"
                                            :rules="reqLogin"
                                            :items="formattedManagers"
                                            item-title="title"
                                            item-value="value"
                                            :error-messages="form.errors.manager_id"
                                            :label="t('manager')"
                                            variant="outlined"
                                            density="compact"
                                        />
                                    </VCol>
                                </VRow>
                            </div>
                        </VCol>

                        <!-- Organisation dynamique -->
                        <VCol cols="12">
                            <div class="form-block mb-4">
                                <p class="block-label">{{ t("organization") }}</p>

                                <template v-if="levels.length > 0">
                                    <div
                                        v-for="(level, index) in levels"
                                        :key="index"
                                        class="mb-3"
                                    >
                                        <!-- Badge type -->
                                        <div class="d-flex align-center gap-2 mb-2">
                                            <i
                                                v-if="index > 0"
                                                class="fa-solid fa-arrow-turn-down-right"
                                                style="font-size:10px;color:#94a3b8;margin-left:4px"
                                            />
                                            <div
                                                class="level-badge"
                                                :style="{
                                                    background: getTypeConfig(level.children[0]?.type).bg,
                                                    color:      getTypeConfig(level.children[0]?.type).color,
                                                }"
                                            >
                                                <i
                                                    :class="`fa-solid ${getTypeConfig(level.children[0]?.type).icon}`"
                                                    style="font-size:10px"
                                                />
                                                {{ getLevelLabel(level) }}
                                            </div>
                                        </div>

                                        <!-- VSelect sans slot #item pour éviter le warning Vuetify -->
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
                                    {{ t("no_units_available") }}
                                </p>
                            </div>
                        </VCol>

                        <!-- Disability -->
                        <VCol cols="12">
                            <div class="form-block mb-4">
                                <p class="block-label">{{ t("disability") }}</p>
                                <VRow dense>
                                    <VCol cols="12">
                                        <VTextField
                                            v-model="form.nature_of_disability"
                                            :error-messages="form.errors.nature_of_disability"
                                            :label="t('nature_of_disability')"
                                            placeholder="Nature of disability"
                                            variant="outlined"
                                            density="compact"
                                        />
                                    </VCol>
                                    <VCol cols="12">
                                        <VSwitch
                                            v-model="form.disabled"
                                            :error-messages="form.errors.disabled"
                                            :label="t('disabled')"
                                            color="primary"
                                            density="compact"
                                            hide-details
                                            inset
                                        />
                                    </VCol>
                                </VRow>
                            </div>
                        </VCol>

                    </VRow>
                </VForm>
            </VCardText>

            <!-- Footer -->
            <div
                class="d-flex gap-3 px-6 py-4 flex-shrink-0"
                style="border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity))"
            >
                <VBtn
                    variant="tonal"
                    color="error"
                    class="flex-grow-1"
                    rounded="lg"
                    :disabled="form.processing"
                    @click="closeDialog"
                >
                    <i class="fa-regular fa-x me-2" /> {{ t("cancel") }}
                </VBtn>
                <VBtn
                    color="success"
                    class="flex-grow-1"
                    rounded="lg"
                    :loading="form.processing"
                    @click="onSubmit"
                >
                    <template #loader>
                        <VProgressCircular indeterminate size="18" width="2" color="white" class="me-2" />
                        <span>{{ t("saving") }}…</span>
                    </template>
                    <i class="fa-regular fa-floppy-disk me-2" /> {{ t("save") }}
                </VBtn>
            </div>

        </VCard>
    </VDialog>
</template>

<style scoped>
.form-block {
    background: rgba(var(--v-border-color), 0.04);
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 12px;
    padding: 16px;
}
.block-label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.6px;
    color: rgba(var(--v-theme-on-surface), 0.5);
    text-transform: uppercase;
    margin: 0 0 12px;
}
.level-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 20px;
    text-transform: capitalize;
    letter-spacing: 0.3px;
}
</style>
