<script setup lang="ts">
import { computed, watch, ref } from "vue"
import { useI18n } from "vue-i18n"
import { useApprovalStore } from "./Store/Store"
import type { ApprovalItem, HRPerson } from "./type/type"
import { LABEL_MAPS } from "./type/type"

const { t } = useI18n()

const props = defineProps<{
    hrPerson: HRPerson | null
    approval: ApprovalItem | null
}>()

const store = useApprovalStore()

watch(
    () => props.approval,
    (val) => store.setSelected(val),
    { immediate: true },
)
watch(
    () => props.hrPerson,
    (val) => store.setHRPerson(val),
    { immediate: true },
)

const showRejectDialog = ref(false)
const rejectReason = ref("")
const rejectError = ref("")

const fieldLabels: Record<string, string> = {
    first_name: t('first_name'),
    surname: t('surname'),
    middle_name: t('middle_name'),
    maiden_name: t('maiden_name'),
    aka: t('approvals_page.field_aka'),
    known_as: t('approvals_page.field_known_as'),
    initial: t('initial'),
    title: t('title'),
    email: t('email'),
    cell_number: t('cell_number'),
    phone_number: t('phone_number'),
    alternate_number: t('approvals_page.field_alternate_number'),
    id_number: t('approvals_page.field_id_number'),
    passport_number: t('approvals_page.field_passport_number'),
    drivers_licence_number: t('approvals_page.field_drivers_licence_number'),
    drivers_licence_code: t('approvals_page.field_drivers_licence_code'),
    drivers_licence_exp_date: t('approvals_page.field_drivers_licence_exp_date'),
    proof_drive_permit: t('approvals_page.field_proof_drive_permit'),
    proof_drive_permit_exp_date: t('approvals_page.field_proof_drive_permit_exp_date'),
    can_drive: t('approvals_page.field_can_drive'),
    own_transport: t('own_transport'),
    res_address: t('approvals_page.field_res_address'),
    res_suburb: t('approvals_page.field_res_suburb'),
    res_city: t('approvals_page.field_res_city'),
    res_postal_code: t('approvals_page.field_res_postal_code'),
    res_province_id: t('approvals_page.field_res_province_id'),
    res_country_id: t('approvals_page.field_res_country_id'),
    date_of_birth: t('date_of_birth'),
    gender: t('gender'),
    marital_status: t('marital_status'),
    ethnicity: t('approvals_page.field_ethnicity'),
    citizenship: t('approvals_page.field_citizenship'),
    disabled: t('disabled'),
    nature_of_disability: t('nature_of_disability'),
    employment_type: t('approvals_page.field_employment_type'),
    occupational_level: t('occupational_level'),
    job_function: t('job_function'),
    profile_pic: t('approvals_page.field_profile_pic'),
    next_of_kin: t('next_of_kin'),
    next_of_kin_number: t('next_of_kin_number'),
    next_of_kin_work_number: t('approvals_page.field_next_of_kin_work_number'),
    complex_name: t('approvals_page.field_complex_name'),
    unit_number: t('approvals_page.field_unit_number'),
    income_tax_number: t('approvals_page.field_income_tax_number'),
    tax_office: t('approvals_page.field_tax_office'),
    account_type: t('approvals_page.field_account_type'),
    account_holder_name: t('account_holder_name'),
    bank_name: t('bank_name'),
    branch_name: t('approvals_page.field_branch_name'),
    account_number: t('account_number'),
    start_time: t('approvals_page.field_start_time'),
    end_time: t('approvals_page.field_end_time'),
    other: t('other'),
    leave_profile: t('leave_profile'),
}

const formatValue = (key: string, value: any): string => {
    if (value === null || value === undefined) return "—"

    if (LABEL_MAPS[key] && typeof value === "number") {
        return LABEL_MAPS[key][value] || String(value)
    }

    if (
        ["date_of_birth","date_joined","date_left","drivers_licence_exp_date","proof_drive_permit_exp_date"].includes(key) &&
        typeof value === "number"
    ) {
        return new Date(value * 1000).toLocaleDateString("fr-FR", {
            day: "2-digit", month: "long", year: "numeric",
        })
    }

    if (typeof value === "object" && value !== null && "name" in value) {
        return value.name as string
    }

    if (key === "own_transport") return value === 1 ? t('yes') : t('no')

    return String(value)
}

const ignoredKeys = new Set([
    "id","hr_main_id","hr_person_id","user_id","reviewed_by","reviewed_at",
    "rejection_reason","status","is_approved","created_at","updated_at","uuid",
    "_token","_method","command","profile_pic_url",
])

const changedFields = computed(() => {
    const change = store.selected
    const original = store.hrPerson
    if (!change || !original) return []

    const fields: { key: string; label: string; oldValue: string; newValue: string }[] = []

    for (const [key, newVal] of Object.entries(change)) {
        if (ignoredKeys.has(key) || newVal === null || newVal === undefined || newVal === "" || key.startsWith("_") || key.startsWith("$")) continue
        const oldVal = (original as any)[key] ?? null
        if (String(oldVal) === String(newVal)) continue

        fields.push({
            key,
            label: fieldLabels[key] || key.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase()),
            oldValue: formatValue(key, oldVal),
            newValue: formatValue(key, newVal),
        })
    }

    return fields.sort((a, b) => a.label.localeCompare(b.label))
})

const formatDate = (d: string) => {
    try {
        return new Date(d).toLocaleDateString("fr-FR", {
            day: "2-digit", month: "long", year: "numeric", hour: "2-digit", minute: "2-digit",
        })
    } catch { return "—" }
}

const initials = computed(() => {
    const first = store.selected?.first_name?.[0] || ""
    const last = store.selected?.surname?.[0] || ""
    return (first + last).toUpperCase() || "?"
})

const fullName = computed(() => {
    const first = store.selected?.first_name || ""
    const last = store.selected?.surname || ""
    return `${first} ${last}`.trim() || "Unknown"
})

const isPending = computed(() => !store.selected?.is_approved)

const goBack = () => window.history.back()

const handleApprove = () => {
    if (!store.selected) return
    store.approve(store.selected.id)
}

const openRejectDialog = () => {
    rejectReason.value = ""
    rejectError.value = ""
    showRejectDialog.value = true
}

const confirmReject = () => {
    if (!store.selected) return
    if (!rejectReason.value.trim()) {
        rejectError.value = t('approvals_page.rejection_reason_required')
        return
    }
    store.reject(store.selected.id, rejectReason.value)
    showRejectDialog.value = false
}

const cancelReject = () => {
    showRejectDialog.value = false
    rejectReason.value = ""
    rejectError.value = ""
}

const selectedProfilePicUrl = computed(() => {
    const url = store.selected?.profile_pic_url ?? store.selected?.profile_pic
    return typeof url === 'string' && url.length ? url : null
})
</script>

<template>
    <section class="min-h-screen bg-slate-100 pb-10">
        <div class="relative h-32 overflow-hidden">
            <div class="absolute inset-0 flex">
                <div class="w-1/3 bg-teal-400" />
                <div class="w-1/4 bg-teal-300/80 skew-x-6 -ml-4" />
                <div class="w-1/4 bg-rose-200/70 skew-x-6 -ml-2" />
                <div class="flex-1 bg-rose-300/80" />
            </div>
            <button
                class="absolute top-4 left-4 flex items-center gap-2 px-3 py-2 bg-white/80 hover:bg-white text-slate-700 text-sm font-medium rounded-xl shadow transition-colors z-10"
                @click="goBack"
            >
                <i class="fa-solid fa-arrow-left text-xs" /> {{ t('approvals_page.back_to_approvals') }}
            </button>
        </div>

        <div class="mx-auto px-4 sm:px-6">
            <div
                v-if="!store.selected || !store.hrPerson"
                class="bg-white rounded-2xl shadow-sm border border-slate-100 -mt-8 relative z-10 px-6 py-10 mb-5 text-center"
            >
                <i class="fa-solid fa-spinner fa-spin text-3xl text-slate-300 mb-3" />
                <p class="text-slate-500">{{ t('approvals_page.loading_details') }}</p>
            </div>

            <template v-else>
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 -mt-8 relative z-10 px-6 py-5 mb-5">
                    <div class="flex flex-col sm:flex-row items-start sm:items-center gap-4 justify-between">
                        <div class="flex items-center gap-4">
                            <div
                                class="w-14 h-14 rounded-2xl flex items-center justify-center font-bold text-xl flex-shrink-0"
                                :class="isPending ? 'bg-amber-100 text-amber-600' : 'bg-emerald-100 text-emerald-600'"
                            >
                                {{ initials }}
                            </div>
                            <div>
                                <h1 class="text-lg font-bold text-slate-800">{{ fullName }}</h1>
                                <div class="flex items-center gap-3 mt-0.5">
                                    <span class="text-xs text-slate-400 font-mono">
                                        {{ store.selected?.employee_number || t('approvals_page.no_employee_number') }}
                                    </span>
                                    <VChip :color="isPending ? 'warning' : 'success'" size="x-small" variant="tonal">
                                        {{ isPending ? t('approvals_page.pending_approval') : t('approvals_page.approved') }}
                                    </VChip>
                                </div>
                            </div>
                        </div>

                        <div v-if="isPending" class="flex gap-2 flex-shrink-0">
                            <VBtn color="error" variant="tonal" :disabled="store.loading" @click="openRejectDialog">
                                <i class="fa-solid fa-xmark mr-1" /> {{ t('approvals_page.reject') }}
                            </VBtn>
                            <VBtn color="success" :loading="store.loading" @click="handleApprove">
                                <i class="fa-solid fa-check mr-1" /> {{ t('approvals_page.approve') }}
                            </VBtn>
                        </div>

                        <div v-else class="flex items-center gap-2 px-3 py-2 bg-emerald-50 border border-emerald-200 rounded-xl">
                            <i class="fa-solid fa-circle-check text-emerald-500 text-sm" />
                            <span class="text-sm font-semibold text-emerald-700">{{ t('approvals_page.changes_applied') }}</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 mt-4 pt-4 border-t border-slate-100">
                        <i class="fa-regular fa-clock text-slate-400 text-sm" />
                        <span class="text-xs text-slate-400">
                            {{ t('approvals_page.submitted') }} {{ formatDate(store.selected.created_at) }}
                        </span>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
                    <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4">
                        {{ t('approvals_page.requested_changes') }}
                        <span class="ml-2 px-2 py-0.5 bg-indigo-50 rounded-full text-indigo-600 font-semibold normal-case text-[11px]">
                            {{ t('approvals_page.fields_count', { count: changedFields.length }) }}
                        </span>
                    </p>

                    <div v-if="!changedFields.length" class="flex flex-col items-center py-10 text-slate-400">
                        <i class="fa-regular fa-file-lines text-4xl opacity-20 mb-3" />
                        <p class="text-sm">{{ t('approvals_page.no_changes') }}</p>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-200">
                                    <th class="text-left py-2 px-3 text-xs font-bold text-slate-400 uppercase tracking-wider w-[180px]">
                                        {{ t('approvals_page.field_column') }}
                                    </th>
                                    <th class="text-left py-2 px-3 text-xs font-bold text-slate-400 uppercase tracking-wider">
                                        <span class="inline-flex items-center gap-1">
                                            <span class="w-2 h-2 rounded-full bg-rose-400"></span>
                                            {{ t('approvals_page.current_value') }}
                                        </span>
                                    </th>
                                    <th class="text-left py-2 px-3 text-xs font-bold text-slate-400 uppercase tracking-wider">
                                        <span class="inline-flex items-center gap-1">
                                            <span class="w-2 h-2 rounded-full bg-indigo-400"></span>
                                            {{ t('approvals_page.new_value') }}
                                        </span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="field in changedFields" :key="field.key" class="border-b border-slate-50 hover:bg-slate-50/50 transition-colors">
                                    <td class="py-3 px-3 font-medium text-slate-600">{{ field.label }}</td>
                                    <td class="py-3 px-3">
                                        <template v-if="field.key !== 'profile_pic'">
                                            <span class="inline-block px-2.5 py-1 rounded-lg text-xs font-medium bg-rose-50 text-rose-700">
                                                {{ field.oldValue }}
                                            </span>
                                        </template>
                                        <template v-else>
                                            <div class="inline-flex items-center gap-2">
                                                <div class="w-14 h-14 rounded-xl overflow-hidden border border-slate-200 shadow-sm bg-slate-50">
                                                    <template v-if="store.hrPerson?.profile_pic_url">
                                                        <img :src="store.hrPerson.profile_pic_url" alt="Current profile pic" class="w-full h-full object-cover" />
                                                    </template>
                                                    <template v-else>
                                                        <div class="w-full h-full flex items-center justify-center text-[10px] text-slate-500 bg-slate-100">
                                                            {{ t('approvals_page.image_empty') }}
                                                        </div>
                                                    </template>
                                                </div>
                                            </div>
                                        </template>
                                    </td>
                                    <td class="py-3 px-3">
                                        <template v-if="field.key !== 'profile_pic'">
                                            <span class="inline-block px-2.5 py-1 rounded-lg text-xs font-semibold bg-indigo-50 text-indigo-700">
                                                {{ field.newValue }}
                                            </span>
                                        </template>
                                        <template v-else>
                                            <div class="inline-flex items-center gap-2">
                                                <div class="w-14 h-14 rounded-xl overflow-hidden border border-slate-200 shadow-sm">
                                                    <img :src="selectedProfilePicUrl || field.newValue" alt="New profile pic" class="w-full h-full object-cover" />
                                                </div>
                                            </div>
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="selectedProfilePicUrl" class="mt-5 pt-4 border-t border-slate-100">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-3">
                                    {{ t('approvals_page.new_profile_picture') }}
                                </p>
                                <p class="text-sm text-slate-500 max-w-xl">
                                    {{ t('approvals_page.new_profile_pic_desc') }}
                                </p>
                            </div>
                            <div class="w-full max-w-[180px] rounded-3xl overflow-hidden border border-slate-200 shadow-sm">
                                <img :src="selectedProfilePicUrl" alt="New profile" class="w-full h-full object-cover" />
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <VDialog v-model="showRejectDialog" max-width="500">
            <VCard rounded="lg">
                <VCardTitle class="d-flex align-center pa-4 pb-2">
                    <div class="d-flex align-center gap-2">
                        <i class="fa-solid fa-triangle-exclamation text-error" />
                        <span>{{ t('approvals_page.reject_changes_title') }}</span>
                    </div>
                </VCardTitle>
                <VCardText class="pa-4 pt-2">
                    <p class="text-body-2 text-medium-emphasis mb-4">
                        {{ t('approvals_page.reject_confirm_text', { name: fullName }) }}
                    </p>
                    <VTextarea
                        v-model="rejectReason"
                        :label="t('approvals_page.rejection_reason_label')"
                        :placeholder="t('approvals_page.rejection_reason_placeholder')"
                        :error-messages="rejectError"
                        variant="outlined"
                        rows="3"
                        auto-grow
                        counter
                        maxlength="500"
                        persistent-counter
                    >
                        <template #prepend-inner>
                            <i class="fa-solid fa-pen mt-2" />
                        </template>
                    </VTextarea>
                    <p class="text-caption text-medium-emphasis mt-2">
                        <i class="fa-solid fa-info-circle mr-1" style="font-size: 10px" />
                        {{ t('approvals_page.rejection_email_notice') }}
                    </p>
                </VCardText>
                <VDivider />
                <VCardActions class="pa-4">
                    <VSpacer />
                    <VBtn variant="text" @click="cancelReject" :disabled="store.loading">
                        {{ t('approvals_page.cancel') }}
                    </VBtn>
                    <VBtn color="error" variant="tonal" :loading="store.loading" @click="confirmReject">
                        <i class="fa-solid fa-xmark mr-1" /> {{ t('approvals_page.confirm_reject') }}
                    </VBtn>
                </VCardActions>
            </VCard>
        </VDialog>

        <VSnackbar v-model="store.snackbar" :timeout="3500" :color="store.snackColor" location="bottom right" rounded="lg">
            <div class="d-flex align-center gap-2">
                <i :class="{
                    'fa-solid fa-circle-check': store.snackColor === 'success',
                    'fa-solid fa-triangle-exclamation': store.snackColor === 'warning',
                    'fa-solid fa-circle-xmark': store.snackColor === 'error',
                }" style="font-size: 16px" />
                {{ store.snackMsg }}
            </div>
        </VSnackbar>
    </section>
</template>
