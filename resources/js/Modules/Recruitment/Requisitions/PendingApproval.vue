<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm, Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { pageLinks } from '@/Modules/Recruitment/pagination'

const { t } = useI18n()
const route = ziggyRoute

type PendingRequisition = { id: number; uuid: string; requisition_number: string; title: string; department: string | null; submitted_by: string | null; submitted_at: string | null; status: { value: string; label: string; color: string } }
const props = defineProps<{ requisitions: { data: PendingRequisition[]; meta: any; links: any }; filters: { status: string | null; search: string | null }; stats: { total: number; draft: number; pending: number; approved: number } }>()

const headers = computed(() => [
  { title: t('recruitment_requisition_pages.table_req_number'), key: 'requisition_number', sortable: false, width: '160px' },
  { title: t('recruitment_requisition_pages.table_title'), key: 'title', sortable: false },
  { title: t('recruitment_requisition_pages.table_department'), key: 'department', sortable: false, width: '160px' },
  { title: t('recruitment_requisition_pages.table_submitted_by'), key: 'submitted_by', sortable: false, width: '180px' },
  { title: t('recruitment_requisition_pages.table_submitted_date'), key: 'submitted_at', sortable: false, width: '150px' },
  { title: t('recruitment_requisition_pages.table_actions'), key: 'actions', sortable: false, width: '180px' },
])

const rows = computed(() => props.requisitions?.data ?? [])
const meta = computed(() => props.requisitions?.meta ?? {})
const links = computed(() => pageLinks(props.requisitions))
function formatDate(val: string | null) { if (!val) return '—'; return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' }) }
function goToPage(url: string | null) { if (url) router.visit(url, { preserveState: true }) }

const activeDialog = ref<{ open: boolean; mode: 'approve' | 'reject'; requisition: PendingRequisition | null }>({ open: false, mode: 'approve', requisition: null })
const actionForm = useForm({ notes: '', reason: '' })
function openApprove(req: PendingRequisition) { actionForm.reset(); activeDialog.value = { open: true, mode: 'approve', requisition: req } }
function openReject(req: PendingRequisition) { actionForm.reset(); activeDialog.value = { open: true, mode: 'reject', requisition: req } }
function submitAction() { const req = activeDialog.value.requisition!; if (activeDialog.value.mode === 'approve') { actionForm.post(route('recruitment.approvals.requisitions.approve', req.uuid), { onSuccess: () => { activeDialog.value.open = false } }) } else { actionForm.post(route('recruitment.approvals.requisitions.reject', req.uuid), { onSuccess: () => { activeDialog.value.open = false } }) } }
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-start justify-space-between mb-6 flex-wrap gap-3">
      <div><div class="d-flex align-center gap-2 mb-1"><h4 class="font-weight-bold" style="font-size: 1.5rem; font-weight: 700; color: #0f172a;">{{ t('recruitment_requisition_pages.title_approvals') }}</h4><VBadge v-if="stats.pending > 0" :content="stats.pending" color="warning" inline /></div><p class="text-medium-emphasis mb-0">{{ t('recruitment_requisition_pages.subtitle_approvals') }}</p></div>
      <Link :href="route('recruitment.requisitions.index')"><VBtn variant="outlined" color="default" prepend-icon="tabler-arrow-left" size="small">{{ t('recruitment_requisition_pages.all_requisitions') }}</VBtn></Link>
    </div>
    <template v-if="!rows.length"><VCard elevation="0" border style="border-radius: 12px;"><VCardText class="text-center py-16"><VIcon icon="tabler-circle-check" size="64" color="success" class="mb-4 d-block mx-auto" /><p class="text-body-1 font-weight-medium mb-1">{{ t('recruitment_requisition_pages.no_pending') }}</p><p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_requisition_pages.no_pending_sub') }}</p></VCardText></VCard></template>
    <template v-else>
      <VCard elevation="0" border style="border-radius: 12px;">
        <VDataTable :headers="headers" :items="rows" item-value="id" hide-default-footer :items-per-page="meta.per_page ?? 25" hover>
          <template #item.requisition_number="{ item }"><span class="text-body-2 font-weight-semibold text-primary cursor-pointer" @click="router.visit(route('recruitment.requisitions.show', item.uuid))">{{ item.requisition_number }}</span></template>
          <template #item.title="{ item }"><p class="text-body-2 font-weight-medium mb-0 cursor-pointer text-primary" @click="router.visit(route('recruitment.requisitions.show', item.uuid))">{{ item.title }}</p></template>
          <template #item.department="{ item }"><span class="text-body-2 text-medium-emphasis">{{ item.department ?? '—' }}</span></template>
          <template #item.submitted_by="{ item }"><div class="d-flex align-center gap-2"><div class="d-flex align-center justify-center rounded-circle flex-shrink-0" style="width: 28px; height: 28px; background: rgba(115,103,240,0.12);"><VIcon icon="tabler-user" size="14" color="primary" /></div><span class="text-body-2">{{ item.submitted_by ?? '—' }}</span></div></template>
          <template #item.submitted_at="{ item }"><span class="text-body-2 text-medium-emphasis">{{ formatDate(item.submitted_at) }}</span></template>
          <template #item.actions="{ item }"><div class="d-flex gap-1 align-center"><VBtn size="x-small" color="success" variant="tonal" prepend-icon="tabler-check" @click.stop="openApprove(item)">{{ t('recruitment_requisition_pages.approve') }}</VBtn><VBtn size="x-small" color="error" variant="tonal" prepend-icon="tabler-x" @click.stop="openReject(item)">{{ t('recruitment_requisition_pages.reject') }}</VBtn><VBtn size="x-small" color="default" variant="text" icon="tabler-eye" @click.stop="router.visit(route('recruitment.requisitions.show', item.uuid))"><VTooltip activator="parent" location="top">{{ t('recruitment_requisition_pages.view_details') }}</VTooltip></VBtn></div></template>
          <template #no-data><div class="text-center py-12"><VIcon icon="tabler-circle-check" size="52" color="success" class="mb-3 d-block mx-auto" /><p class="text-body-2 font-weight-medium mb-0">{{ t('recruitment_requisition_pages.no_pending') }}</p></div></template>
        </VDataTable>
        <VDivider v-if="meta.last_page > 1" />
        <div v-if="meta.last_page > 1" class="d-flex align-center justify-space-between pa-4 flex-wrap gap-2"><p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_requisition_pages.page_of', { current: meta.current_page, last: meta.last_page }) }} · {{ meta.total }} {{ t('recruitment_requisition_pages.total_label') }}</p><div class="d-flex gap-1"><VBtn v-for="link in links" :key="link.label" :disabled="!link.url" :variant="link.active ? 'flat' : 'text'" :color="link.active ? 'primary' : 'default'" size="small" @click="goToPage(link.url)" v-html="link.label" /></div></div>
      </VCard>
    </template>
    <VDialog v-model="activeDialog.open" max-width="500" persistent>
      <VCard>
        <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ activeDialog.mode === 'approve' ? t('recruitment_requisition_pages.dialog_approve_title') : t('recruitment_requisition_pages.dialog_reject_title') }}</VCardTitle>
        <VCardText class="pa-5 pt-0">
          <p class="text-body-2 text-medium-emphasis mb-4"><template v-if="activeDialog.mode === 'approve'">{{ t('recruitment_requisition_pages.dialog_approve_text', { number: activeDialog.requisition?.requisition_number, title: activeDialog.requisition?.title }) }}</template><template v-else>{{ t('recruitment_requisition_pages.dialog_reject_text', { number: activeDialog.requisition?.requisition_number, title: activeDialog.requisition?.title }) }}</template></p>
          <VTextarea v-if="activeDialog.mode === 'approve'" v-model="actionForm.notes" :label="t('recruitment_requisition_pages.dialog_approve_notes')" variant="outlined" density="comfortable" rows="3" :placeholder="t('recruitment_requisition_pages.dialog_approve_notes_placeholder')" :error-messages="actionForm.errors.notes" hide-details="auto" />
          <VTextarea v-if="activeDialog.mode === 'reject'" v-model="actionForm.reason" :label="t('recruitment_requisition_pages.dialog_reject_reason')" variant="outlined" density="comfortable" rows="3" :placeholder="t('recruitment_requisition_pages.dialog_reject_reason_placeholder')" :error-messages="actionForm.errors.reason" hide-details="auto" />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2"><VSpacer /><VBtn variant="text" color="default" :disabled="actionForm.processing" @click="activeDialog.open = false">{{ t('recruitment_requisition_pages.cancel') }}</VBtn><VBtn :color="activeDialog.mode === 'approve' ? 'success' : 'error'" variant="flat" :loading="actionForm.processing" :disabled="activeDialog.mode === 'reject' && !actionForm.reason.trim()" @click="submitAction"><VIcon :icon="activeDialog.mode === 'approve' ? 'tabler-check' : 'tabler-x'" size="18" class="me-1" />{{ activeDialog.mode === 'approve' ? t('recruitment_requisition_pages.confirm_approval') : t('recruitment_requisition_pages.confirm_rejection') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
