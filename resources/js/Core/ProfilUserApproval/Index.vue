<script setup lang="ts">
import { ref } from "vue"
import { router } from "@inertiajs/vue3"
import { useI18n } from "vue-i18n"
import { useApprovalStore } from "./Store/Store"
import { ApprovalItem } from "./type/type"

const { t } = useI18n()

const props = defineProps<{ approvals: ApprovalItem[] | null }>()
const store = useApprovalStore()

import { watch } from "vue"
watch(() => props.approvals, (val) => store.setApprovals(val), { immediate: true })

const showRejectDialog = ref(false)
const selectedItemForReject = ref<ApprovalItem | null>(null)
const rejectReason = ref("")
const rejectError = ref("")

const headers = [
    { title: t('approvals_page.employee'), key: "name", sortable: true },
    { title: t('approvals_page.emp_no'), key: "emp_no", sortable: true },
    { title: t('approvals_page.submitted_col'), key: "created_at", sortable: true },
    { title: t('approvals_page.status'), key: "status", sortable: false },
    { title: t('approvals_page.actions'), key: "actions", sortable: false, align: "center" as const },
]

const formatDate = (d: string) =>
    new Date(d).toLocaleDateString("en-ZA", { day: "2-digit", month: "short", year: "numeric" })

const goToDetail = (item: ApprovalItem) => {
    store.setSelected(item)
    router.visit(`/settings/approvals/${item.id}`)
}

const openRejectDialog = (item: ApprovalItem) => {
    selectedItemForReject.value = item
    rejectReason.value = ""
    rejectError.value = ""
    showRejectDialog.value = true
}

const confirmReject = () => {
    if (!selectedItemForReject.value) return
    if (!rejectReason.value.trim()) {
        rejectError.value = t('approvals_page.rejection_reason_required')
        return
    }
    store.reject(selectedItemForReject.value.id, rejectReason.value)
    showRejectDialog.value = false
    selectedItemForReject.value = null
    rejectReason.value = ""
}

const cancelReject = () => {
    showRejectDialog.value = false
    selectedItemForReject.value = null
    rejectReason.value = ""
    rejectError.value = ""
}

const selectedEmployeeName = () => {
    const item = selectedItemForReject.value
    if (!item) return ""
    return `${item.first_name} ${item.surname}`
}
</script>

<template>
    <section class="bg-[#f8f8ff]">
        <VContainer fluid class="pa-4 pa-sm-6">
            <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
                <div>
                    <h3 class="text-h5 font-weight-semibold mb-1">{{ t('approvals_page.profile_approvals') }}</h3>
                    <p class="text-medium-emphasis mb-0">{{ t('approvals_page.profile_approvals_sub') }}</p>
                </div>
                <VBreadcrumbs
                    :items="[
                        { title: t('approvals_page.home_breadcrumb'), href: '/', disabled: false },
                        { title: t('approvals_page.approvals_breadcrumb'), disabled: true },
                    ]"
                    density="compact" class="pa-0"
                >
                    <template #divider>
                        <i class="fa-solid fa-chevron-right" style="font-size: 11px" />
                    </template>
                </VBreadcrumbs>
            </div>

            <VDivider class="mb-6" />

            <VCard elevation="0" border rounded="lg">
                <VCardText class="pa-5 pa-sm-6">
                    <div class="d-flex flex-wrap align-center gap-3 mb-5">
                        <div v-if="store.pendingCount"
                            class="d-flex align-center gap-2 px-3 py-2 rounded-lg"
                            style="background: #fffbeb; border: 1px solid #fcd34d;"
                        >
                            <div class="rounded-circle" style="width: 8px; height: 8px; background: #f59e0b; animation: pulse 1.5s infinite;" />
                            <span style="font-size: 12px; font-weight: 600; color: #92400e;">
                                {{ t('approvals_page.pending_count', { count: store.pendingCount }) }}
                            </span>
                        </div>

                        <VSpacer class="d-none d-sm-flex" />

                        <VTextField
                            v-model="store.search"
                            :placeholder="t('approvals_page.search_placeholder')"
                            prepend-inner-icon="tabler-search"
                            variant="outlined" density="compact" rounded="lg" clearable hide-details
                            style="max-width: 300px"
                        />

                        <VBtn variant="tonal" icon rounded="lg" @click="router.reload({ only: ['approvals'] })">
                            <i class="fa-solid fa-rotate" style="font-size: 14px" />
                        </VBtn>
                    </div>

                    <VDataTable :headers="headers" :items="store.filtered" :items-per-page="10" hover class="rounded-lg">
                        <template #item.name="{ item }">
                            <div class="d-flex align-center gap-3 py-1">
                                <div class="d-flex align-center justify-center rounded-lg flex-shrink-0 font-weight-bold"
                                    style="width: 36px; height: 36px; font-size: 13px; background: #e0e7ff; color: #4f46e5;">
                                    {{ item.first_name[0] }}{{ item.surname[0] }}
                                </div>
                                <p class="font-weight-medium text-body-2 mb-0">{{ item.first_name }} {{ item.surname }}</p>
                            </div>
                        </template>

                        <template #item.emp_no="{ item }">
                            <span class="text-body-2 text-medium-emphasis font-weight-medium" style="font-family: monospace">
                                {{ item.employee_number || "—" }}
                            </span>
                        </template>

                        <template #item.created_at="{ item }">
                            <span class="text-body-2 text-medium-emphasis">{{ formatDate(item.created_at) }}</span>
                        </template>

                        <template #item.status="{ item }">
                            <VChip :color="item.is_approved === true ? 'success' : 'warning'" size="small" class="font-weight-medium">
                                {{ item.is_approved === true ? t('approvals_page.approved') : t('approvals_page.pending') }}
                            </VChip>
                        </template>

                        <template #item.actions="{ item }">
                            <VMenu location="bottom end" offset-y>
                                <template #activator="{ props: mp }">
                                    <VBtn v-bind="mp" variant="text" size="small" icon>
                                        <i class="fa-solid fa-ellipsis-vertical text-gray-600" />
                                    </VBtn>
                                </template>

                                <VCard class="rounded-lg overflow-hidden" style="min-width: 200px">
                                    <VList density="compact">
                                        <VListItem @click="goToDetail(item)" class="cursor-pointer">
                                            <template #prepend>
                                                <i class="fa-solid fa-eye me-2 text-primary" style="font-size: 13px" />
                                            </template>
                                            <VListItemTitle>
                                                <span class="font-medium text-gray-700">{{ t('approvals_page.review') }}</span>
                                            </VListItemTitle>
                                        </VListItem>

                                        <template v-if="item.is_approved === false">
                                            <VDivider />
                                            <VListItem @click="store.approve(item.id)" :disabled="store.loading" class="cursor-pointer">
                                                <template #prepend>
                                                    <i class="fa-solid fa-check me-2" style="font-size: 13px; color: #22c55e;" />
                                                </template>
                                                <VListItemTitle>
                                                    <span class="font-medium" style="color: #22c55e">{{ t('approvals_page.approve') }}</span>
                                                </VListItemTitle>
                                            </VListItem>

                                            <VDivider />
                                            <VListItem @click="openRejectDialog(item)" :disabled="store.loading" class="cursor-pointer">
                                                <template #prepend>
                                                    <i class="fa-solid fa-xmark me-2 text-error" style="font-size: 13px" />
                                                </template>
                                                <VListItemTitle>
                                                    <span class="font-medium text-error">{{ t('approvals_page.reject') }}</span>
                                                </VListItemTitle>
                                            </VListItem>
                                        </template>
                                    </VList>
                                </VCard>
                            </VMenu>
                        </template>

                        <template #no-data>
                            <div class="d-flex flex-column align-center justify-center py-16 text-medium-emphasis">
                                <i class="fa-solid fa-clipboard-check mb-3" style="font-size: 48px; opacity: 0.2" />
                                <p class="text-body-1 font-weight-medium mb-1">{{ t('approvals_page.no_approval_requests') }}</p>
                                <p class="text-body-2">{{ t('approvals_page.all_reviewed') }}</p>
                            </div>
                        </template>
                    </VDataTable>
                </VCardText>
            </VCard>

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
                            {{ t('approvals_page.reject_confirm_text', { name: selectedEmployeeName() }) }}
                        </p>
                        <VTextarea
                            v-model="rejectReason"
                            :label="t('approvals_page.rejection_reason_label')"
                            :placeholder="t('approvals_page.rejection_reason_placeholder')"
                            :error-messages="rejectError"
                            variant="outlined" rows="3" auto-grow counter maxlength="500" persistent-counter
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
                    <i :class="
                        store.snackColor === 'success' ? 'fa-solid fa-circle-check' :
                        store.snackColor === 'warning' ? 'fa-solid fa-circle-exclamation' : 'fa-solid fa-circle-xmark'
                    " style="font-size: 16px" />
                    {{ store.snackMsg }}
                </div>
            </VSnackbar>
        </VContainer>
    </section>
</template>

<style scoped>
:deep(.v-data-table tbody tr:hover td) {
    background: rgba(var(--v-theme-primary), 0.04);
}
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}
</style>
