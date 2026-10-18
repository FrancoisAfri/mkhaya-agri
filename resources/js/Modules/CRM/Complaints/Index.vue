<script lang="ts" setup>
import { ref, computed, onMounted } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import axios from 'axios'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

const languages = ['English', 'Afrikaans', 'Zulu', 'Xhosa', 'Sepedi', 'Setswana', 'Sesotho', 'Xitsonga', 'siSwati', 'Tshivenda', 'isiNdebele', 'French', 'Portuguese']
const translateLang = ref('English'); const translated = ref<string | null>(null); const translating = ref(false)
async function translate(text: string) { translating.value = true; translated.value = null; try { const { data } = await axios.post(route('crm.translate'), { text, language: translateLang.value }, { headers: { 'X-CSRF-TOKEN': (document.querySelector('meta[name=csrf-token]') as HTMLMetaElement)?.content ?? '' } }); translated.value = data.translated } catch { translated.value = t('crm_complaints_index.drawer.translation_failed') } finally { translating.value = false } }

interface Complaint { id: number; reference: string; type: string; source: string; subject: string; customer_name: string | null; severity: string | null; severity_label: string | null; severity_color: string; category: string | null; status: string; status_label: string; status_color: string; against: string | null; assignee: string | null; assigned_to: number | null; overdue: boolean; created_at: string | null }
interface Detail extends Complaint { body: string; customer_email: string | null; customer_phone: string | null; sentiment: string | null; ai_summary: string | null; recommendations: Record<string, string[]>; respond_by: string | null; resolve_by: string | null; escalated: boolean }
interface TimelineRow { type: string; from: string | null; to: string | null; note: string | null; user: string | null; at: string | null }

const props = defineProps<{ complaints: Complaint[]; severities: Record<string, { label: string; color: string }>; statuses: Record<string, { label: string; color: string }>; handlers: { id: number; name: string }[]; filters: { status?: string; type?: string; severity?: string }; openId: number | null }>()

const newDialog = ref(false); const drawer = ref(false); const detail = ref<Detail | null>(null); const timeline = ref<TimelineRow[]>([]); const loading = ref(false); const confirm = ref()
const snack = ref<{ show: boolean; text: string; color: string }>({ show: false, text: '', color: 'success' })

const statusFilter = ref(props.filters.status ?? 'all'); const typeFilter = ref(props.filters.type ?? 'all'); const severityFilter = ref(props.filters.severity ?? 'all')

const form = useForm({ subject: '', body: '', customer_name: '', customer_email: '', customer_phone: '' })
const actionForm = useForm({ status: '', assigned_to: null as number | null, note: '' })

function applyFilters() { router.get(route('crm.complaints'), { status: statusFilter.value === 'all' ? undefined : statusFilter.value, type: typeFilter.value === 'all' ? undefined : typeFilter.value, severity: severityFilter.value === 'all' ? undefined : severityFilter.value }, { preserveState: false }) }

async function openDetail(c: Complaint) { drawer.value = true; detail.value = null; loading.value = true; try { const res = await fetch(route('crm.complaints.show', c.id), { headers: { Accept: 'application/json' } }); const data = await res.json(); detail.value = data.complaint; timeline.value = data.timeline; actionForm.status = data.complaint.status; actionForm.assigned_to = data.complaint.assigned_to; actionForm.note = '' } finally { loading.value = false } }

function saveCreate() { form.post(route('crm.complaints.store'), { preserveScroll: true, onSuccess: () => { newDialog.value = false; form.reset(); snack.value = { show: true, text: t('crm_complaints_index.toasts.logged'), color: 'success' } } }) }
function applyAction() { if (!detail.value) return; actionForm.patch(route('crm.complaints.update', detail.value.id), { preserveScroll: true, onSuccess: () => { openDetail(detail.value as Complaint); snack.value = { show: true, text: t('crm_complaints_index.toasts.updated'), color: 'success' } } }) }
function reanalyze() { if (!detail.value) return; router.post(route('crm.complaints.analyze', detail.value.id), {}, { preserveScroll: true, onSuccess: () => openDetail(detail.value as Complaint) }) }
function remove(c: Complaint) { confirm.value.open({ title: t('crm_complaints_index.delete.title'), message: t('crm_complaints_index.delete.message', { ref: c.reference }), confirmText: t('crm_complaints_index.delete.confirm'), confirmColor: 'error' }, () => useForm({}).delete(route('crm.complaints.destroy', c.id), { preserveScroll: true, onSuccess: () => drawer.value = false })) }

const headers = computed(()=> [
  { title: t('crm_complaints_index.table.ref'), key: 'reference' }, { title: t('crm_complaints_index.table.subject'), key: 'subject' },
  { title: t('crm_complaints_index.table.customer'), key: 'customer_name' }, { title: t('crm_complaints_index.table.against'), key: 'against' },
  { title: t('crm_complaints_index.table.severity'), key: 'severity' }, { title: t('crm_complaints_index.table.status'), key: 'status' },
  { title: t('crm_complaints_index.table.handler'), key: 'assignee' }, { title: '', key: 'actions', sortable: false },
])
const portalUrl = computed(() => `${window.location.origin}/feedback`)

onMounted(() => { if (props.openId) { const c = props.complaints.find(x => x.id === props.openId); if (c) openDetail(c) } })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3"><div><h5 class="text-h5 font-weight-semibold">{{ t('crm_complaints_index.page_title') }}</h5><p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_complaints_index.description') }}</p></div><div class="d-flex ga-2"><VBtn variant="tonal" prepend-icon="tabler-settings" :href="route('crm.complaints.settings')">{{ t('crm_complaints_index.settings') }}</VBtn><VBtn variant="tonal" prepend-icon="tabler-external-link" :href="portalUrl" target="_blank">{{ t('crm_complaints_index.portal_link') }}</VBtn><VBtn color="primary" prepend-icon="tabler-plus" @click="newDialog = true">{{ t('crm_complaints_index.log_complaint') }}</VBtn></div></div>

    <VCard><VCardText class="d-flex flex-wrap gap-3">
      <VSelect v-model="typeFilter" :label="t('crm_complaints_index.filters.type_all')" density="compact" hide-details style="max-width: 160px" :items="[{title:t('crm_complaints_index.filters.type_all'),value:'all'},{title:t('crm_complaints_index.filters.type_complaints'),value:'complaint'},{title:t('crm_complaints_index.filters.type_compliments'),value:'compliment'}]" @update:model-value="applyFilters" />
      <VSelect v-model="severityFilter" :label="t('crm_complaints_index.filters.severity_all')" density="compact" hide-details style="max-width: 160px" :items="[{title:t('crm_complaints_index.filters.severity_all'),value:'all'}, ...Object.entries(severities).map(([v,s]) => ({title:s.label,value:v}))]" @update:model-value="applyFilters" />
      <VSelect v-model="statusFilter" :label="t('crm_complaints_index.filters.status_all')" density="compact" hide-details style="max-width: 180px" :items="[{title:t('crm_complaints_index.filters.status_all'),value:'all'}, ...Object.entries(statuses).map(([v,s]) => ({title:s.label,value:v}))]" @update:model-value="applyFilters" />
    </VCardText>
      <VDataTable :headers="headers" :items="complaints" :items-per-page="25" density="comfortable">
        <template #item.reference="{ item }"><a class="text-primary cursor-pointer font-weight-medium" @click="openDetail(item)">{{ item.reference }}</a><VIcon v-if="item.overdue" icon="tabler-alert-triangle" color="error" size="14" class="ms-1" /></template>
        <template #item.subject="{ item }"><span class="text-truncate d-inline-block" style="max-width: 260px">{{ item.subject }}</span></template>
        <template #item.severity="{ item }"><VChip v-if="item.severity" :color="item.severity_color" size="small" label>{{ item.severity_label }}</VChip><VChip v-else-if="item.type === 'compliment'" color="success" size="small" label variant="tonal">{{ t('crm_complaints_index.compliment') }}</VChip><span v-else class="text-disabled">—</span></template>
        <template #item.status="{ item }"><VChip :color="item.status_color" size="small" label>{{ item.status_label }}</VChip></template>
        <template #item.against="{ item }">{{ item.against ?? '—' }}</template>
        <template #item.assignee="{ item }">{{ item.assignee ?? '—' }}</template>
        <template #item.actions="{ item }"><VBtn size="x-small" variant="text" icon="tabler-eye" :title="t('crm_complaints_index.tips.open')" @click="openDetail(item)" /><VBtn size="x-small" variant="text" icon="tabler-trash" color="error" :title="t('crm_complaints_index.tips.delete')" @click="remove(item)" /></template>
      </VDataTable>
    </VCard>

    <VDialog v-model="newDialog" max-width="560" persistent><VCard><VCardTitle>{{ t('crm_complaints_index.dialog_create.title') }}</VCardTitle><VCardText><VTextField v-model="form.subject" :label="t('crm_complaints_index.dialog_create.subject')" class="mb-2" :error-messages="form.errors.subject" /><VTextarea v-model="form.body" :label="t('crm_complaints_index.dialog_create.details')" rows="4" class="mb-2" :error-messages="form.errors.body" /><VRow><VCol cols="12" sm="6"><VTextField v-model="form.customer_name" :label="t('crm_complaints_index.dialog_create.customer_name')" /></VCol><VCol cols="12" sm="6"><VTextField v-model="form.customer_email" :label="t('crm_complaints_index.dialog_create.customer_email')" /></VCol><VCol cols="12" sm="6"><VTextField v-model="form.customer_phone" :label="t('crm_complaints_index.dialog_create.customer_phone')" /></VCol></VRow><VAlert type="info" variant="tonal" density="compact" class="text-caption mt-1">{{ t('crm_complaints_index.dialog_create.ai_notice') }}</VAlert></VCardText><VDivider /><VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="newDialog = false">{{ t('crm_complaints_index.dialog_create.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.subject || !form.body" @click="saveCreate">{{ t('crm_complaints_index.dialog_create.log') }}</VBtn></VCardActions></VCard></VDialog>

    <VNavigationDrawer v-model="drawer" temporary location="right" width="520"><div v-if="detail" class="d-flex flex-column h-100">
      <div class="pa-4 border-b"><div class="d-flex align-center justify-space-between"><span class="text-overline text-medium-emphasis">{{ detail.reference }} · {{ detail.source }}</span><VBtn icon="tabler-x" variant="text" size="small" :title="t('crm_complaints_index.tips.close')" @click="drawer = false" /></div><h6 class="text-h6">{{ detail.subject }}</h6><div class="d-flex flex-wrap ga-1 mt-1"><VChip v-if="detail.severity" :color="detail.severity_color" size="x-small" label>{{ detail.severity_label }}</VChip><VChip :color="detail.status_color" size="x-small" label>{{ detail.status_label }}</VChip><VChip v-if="detail.category" size="x-small" variant="tonal">{{ detail.category }}</VChip><VChip v-if="detail.escalated" color="error" size="x-small" :label="t('crm_complaints_index.drawer.escalated')" /></div></div>
      <div class="flex-grow-1 overflow-y-auto pa-4">
        <div class="text-caption text-medium-emphasis">{{ t('crm_complaints_index.drawer.anonymous') }} {{ detail.customer_name ?? t('crm_complaints_index.drawer.anonymous') }}<span v-if="detail.customer_email"> · {{ detail.customer_email }}</span></div>
        <p class="text-body-2 mt-2" style="white-space: pre-wrap">{{ detail.body }}</p>
        <div class="d-flex ga-2 align-center mt-1"><VSelect v-model="translateLang" :items="languages" density="compact" hide-details style="max-width: 160px" /><VBtn size="x-small" variant="tonal" prepend-icon="tabler-language" :loading="translating" @click="translate(detail.body)">{{ t('crm_complaints_index.drawer.translate') }}</VBtn></div>
        <VAlert v-if="translated" type="info" variant="tonal" density="compact" class="mt-2 text-body-2" style="white-space: pre-wrap">{{ translated }}</VAlert>
        <VCard v-if="detail.ai_summary" variant="tonal" class="my-3"><VCardText><div class="d-flex align-center justify-space-between mb-1"><span class="font-weight-medium"><VIcon icon="tabler-sparkles" size="16" class="me-1" />{{ t('crm_complaints_index.drawer.ai_analysis') }}</span><VBtn size="x-small" variant="text" icon="tabler-refresh" :title="t('crm_complaints_index.tips.reanalyze')" @click="reanalyze" /></div><p class="text-body-2 mb-2">{{ detail.ai_summary }}</p></VCardText></VCard>
        <div v-if="detail.respond_by || detail.resolve_by" class="text-caption text-medium-emphasis mb-3"><div v-if="detail.respond_by">{{ t('crm_complaints_index.drawer.respond_by') }}: {{ detail.respond_by }}</div><div v-if="detail.resolve_by">{{ t('crm_complaints_index.drawer.resolve_by') }}: {{ detail.resolve_by }}</div></div>
        <VDivider class="mb-3" />
        <VSelect v-model="actionForm.status" :label="t('crm_complaints_index.drawer.status')" :items="Object.entries(statuses).map(([v,s]) => ({title:s.label,value:v}))" density="comfortable" class="mb-2" />
        <VSelect v-model="actionForm.assigned_to" :label="t('crm_complaints_index.drawer.assign_to')" :items="handlers" item-title="name" item-value="id" density="comfortable" clearable class="mb-2" />
        <VTextarea v-model="actionForm.note" :label="t('crm_complaints_index.drawer.add_note')" rows="2" density="comfortable" class="mb-2" />
        <VBtn block color="primary" :loading="actionForm.processing" @click="applyAction">{{ t('crm_complaints_index.drawer.update') }}</VBtn>
        <div class="text-overline text-medium-emphasis mt-4 mb-1">{{ t('crm_complaints_index.drawer.history') }}</div>
        <div v-for="(tp, i) in timeline" :key="i" class="mb-2"><div class="text-body-2"><VIcon size="14" class="me-1" :icon="tp.type === 'escalation' ? 'tabler-alert-triangle' : tp.type === 'ai' ? 'tabler-sparkles' : 'tabler-point'" /><span v-if="tp.to"><strong>{{ tp.to }}</strong> </span>{{ tp.note }}</div><div class="text-caption text-disabled">{{ tp.user ?? t('crm_complaints_index.drawer.system') }} · {{ tp.at }}</div></div>
      </div>
    </div><div v-else class="d-flex align-center justify-center h-100"><VProgressCircular v-if="loading" indeterminate color="primary" /></div></VNavigationDrawer>

    <ConfirmDialog ref="confirm" />
    <VSnackbar v-model="snack.show" :color="snack.color" location="bottom right" timeout="3000">{{ snack.text }}</VSnackbar>
  </div>
</template>