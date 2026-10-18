<script lang="ts" setup>
import { ref, computed, onMounted } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ReassignDialog from './components/ReassignDialog.vue'
const route = ziggyRoute
const { t } = useI18n()

interface Lead { id: number; uuid: string; name: string; company: string | null; email: string | null; phone: string | null; source: string | null; status: string; status_label: string; status_color: string; score: number | null; score_reason: string | null; owner: string | null; product_id: number | null; product: string | null; created_at: string | null; custom?: Record<string, any> }
interface CustomField { id: number; name: string; label: string; field_type: string; options: string[]; is_required: boolean }
interface SavedView { id: number; name: string; filters: { field: string; operator: string; value: string }[]; is_shared: boolean; mine: boolean }
interface Product { id: number; name: string; type: string }

interface Owner { id: number; name: string }

const props = defineProps<{ leads: Lead[]; products: Product[]; statuses: Record<string, { label: string; color: string }>; aiEnabled: boolean; showOwner: boolean; assignableOwners: Owner[]; customFields: CustomField[]; savedViews: SavedView[]; activeView: number | null
  leadTotal: number
  leadLimit: number
  focusLead: number | null
}>()

const search = ref(''); const statusFilter = ref('all'); const productFilter = ref<number | 'all'>('all'); const confirm = ref()
const dialog = ref(false); const convertTarget = ref<Lead | null>(null); const convertDialog = ref(false); const viewDialog = ref(false)

// Reassignment is only offered to someone who can see other people's records — a consultant
// working their own list has nobody to hand anything to.
const selected = ref<number[]>([])
const reassignIds = ref<number[]>([])
const reassignDialog = ref(false)
const canReassign = computed(() => props.showOwner && props.assignableOwners.length > 0)
function openReassign(ids: number[]) { reassignIds.value = ids; reassignDialog.value = true }
function afterReassign() { selected.value = []; router.reload({ only: ['leads'] }) }

const form = useForm({ id: null as number | null, uuid: '', first_name: '', last_name: '', company: '', email: '', phone: '', source: '', status: 'new', product_id: null as number | null, notes: '', custom: {} as Record<string, any> })
const convertForm = useForm({ create_deal: true, deal_title: '', amount: 0 })
const viewForm = useForm({ entity: 'lead', name: '', filters: [] as { field: string; operator: string; value: string }[], is_shared: false })

function applyView(id: number | null) { router.get(route('crm.leads'), id ? { view: id } : {}, { preserveState: false }) }
function openSaveView() { viewForm.reset(); viewForm.filters = statusFilter.value !== 'all' ? [{ field: 'status', operator: '=', value: statusFilter.value }] : []; viewDialog.value = true }
function saveView() { viewForm.post(route('crm.saved-views.store'), { preserveScroll: true, onSuccess: () => viewDialog.value = false }) }
function deleteView(v: SavedView) { useForm({}).delete(route('crm.saved-views.destroy', v.id), { preserveScroll: true }) }

const filtered = computed(() => { let r = props.leads; if (statusFilter.value !== 'all') r = r.filter(l => l.status === statusFilter.value); if (productFilter.value !== 'all') r = r.filter(l => l.product_id === productFilter.value); if (search.value) { const q = search.value.toLowerCase(); r = r.filter(l => l.name.toLowerCase().includes(q) || (l.company ?? '').toLowerCase().includes(q) || (l.email ?? '').toLowerCase().includes(q)) } return r })
const productItems = computed(() => [{ title: 'All products/services', value: 'all' as const }, ...props.products.map(p => ({ title: p.name, value: p.id }))])

const headers = computed(() => [
  { title: t('crm_leads_index.table.name'), key: 'name' }, { title: t('crm_leads_index.table.company'), key: 'company' },
  ...(props.showOwner ? [{ title: t('crm_leads_index.table.sales_rep'), key: 'owner' }] : []),
  { title: t('crm_leads_index.table.email'), key: 'email' }, { title: t('crm_leads_index.table.source'), key: 'source' },
  { title: t('crm_leads_index.table.score'), key: 'score' }, { title: t('crm_leads_index.table.status'), key: 'status' },
  { title: '', key: 'actions', sortable: false },
])

function openCreate() { form.reset(); form.clearErrors(); form.id = null; form.custom = {}; dialog.value = true }
// A notification links to one lead. Without this the page opened on a list of five hundred with
// no indication which of them the message was about.
onMounted(() => {
  if (!props.focusLead) return
  const lead = props.leads.find(l => l.id === props.focusLead)
  if (lead) openEdit(lead)
})

function openEdit(lead: Lead) { const [first, ...rest] = (lead.name || '').split(' '); form.id = lead.id; form.uuid = lead.uuid; form.first_name = first ?? ''; form.last_name = rest.join(' '); form.company = lead.company ?? ''; form.email = lead.email ?? ''; form.phone = lead.phone ?? ''; form.source = lead.source ?? ''; form.status = lead.status; form.product_id = lead.product_id ?? null; form.notes = ''; form.custom = { ...(lead.custom ?? {}) }; form.clearErrors(); dialog.value = true }
// Fields that have somewhere on screen to show their own message. Anything else the server
// refuses — a custom field, an owner, a rule added after this dialog was written — has nowhere to
// land, and an error nobody can see is why saving a lead looked like it did nothing at all.
const FIELDS_ON_SCREEN = ['first_name', 'last_name', 'company', 'email', 'phone', 'source', 'product_id']
const unfieldedErrors = computed(() =>
  Object.entries(form.errors)
    .filter(([key]) => !FIELDS_ON_SCREEN.includes(key))
    .map(([, message]) => message as string))

// Clear last attempt's errors on the way in, so a refusal that has since been corrected does not
// stay on screen next to a field that is now fine.
function save() {
  form.clearErrors()
  const opts = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  if (form.id) form.patch(route('crm.leads.update', form.uuid), opts)
  else form.post(route('crm.leads.store'), opts)
}
function score(lead: Lead) { useForm({}).post(route('crm.leads.score', lead.uuid), { preserveScroll: true }) }
function remove(lead: Lead) { confirm.value.open({ title: t('crm_leads_index.delete.title'), message: t('crm_leads_index.delete.message', { name: lead.name || 'this lead' }), confirmText: t('crm_leads_index.delete.confirm'), confirmColor: 'error' }, () => useForm({}).delete(route('crm.leads.destroy', lead.uuid), { preserveScroll: true })) }
function removeView(v: SavedView) { confirm.value.open({ title: t('crm_leads_index.delete_view.title'), message: t('crm_leads_index.delete_view.message', { name: v.name }), confirmText: t('crm_leads_index.delete_view.confirm'), confirmColor: 'error' }, () => deleteView(v)) }
function openConvert(lead: Lead) { convertTarget.value = lead; convertForm.reset(); convertForm.deal_title = (lead.company || lead.name) + ' opportunity'; convertDialog.value = true }
function doConvert() { if (!convertTarget.value) return; convertForm.post(route('crm.leads.convert', convertTarget.value.uuid), { preserveScroll: true, onSuccess: () => convertDialog.value = false }) }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3"><div><h5 class="text-h5 font-weight-semibold">{{ t('crm_leads_index.page_title') }}</h5><p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_leads_index.description') }}</p></div><VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('crm_leads_index.new_lead') }}</VBtn></div>

    <div class="d-flex flex-wrap align-center gap-2 mb-3">
      <VChip :color="activeView === null ? 'primary' : undefined" :variant="activeView === null ? 'flat' : 'tonal'" size="small" @click="applyView(null)">{{ t('crm_leads_index.all_leads') }}</VChip>
      <VChip v-for="v in savedViews" :key="v.id" :color="activeView === v.id ? 'primary' : undefined" :variant="activeView === v.id ? 'flat' : 'tonal'" size="small" @click="applyView(v.id)"><VIcon v-if="v.is_shared" start icon="tabler-users" size="13" />{{ v.name }}<VIcon v-if="v.mine" end icon="tabler-x" size="13" class="ms-1" @click.stop="removeView(v)" /></VChip>
      <VBtn size="x-small" variant="text" prepend-icon="tabler-bookmark-plus" @click="openSaveView">{{ t('crm_leads_index.save_view') }}</VBtn>
    </div>

    <VCard><VCardText class="d-flex flex-wrap gap-3"><VTextField v-model="search" :label="t('crm_leads_index.search_placeholder')" density="compact" style="max-width: 240px" prepend-inner-icon="tabler-search" hide-details /><VSelect v-model="statusFilter" :label="t('crm_leads_index.status_filter')" density="compact" hide-details style="max-width: 200px" :items="[{ title: t('crm_leads_index.status_all'), value: 'all' }, ...Object.entries(statuses).map(([v, s]) => ({ title: s.label, value: v }))]" /><VSelect v-if="products.length" v-model="productFilter" label="Product / Service" density="compact" hide-details style="max-width: 220px" :items="productItems" /></VCardText>
      <div v-if="canReassign && selected.length" class="d-flex align-center gap-2 px-4 pb-2">
        <span class="text-body-2 text-medium-emphasis">{{ t('crm_reassign.selected', selected.length) }}</span>
        <VBtn size="small" variant="tonal" color="primary" prepend-icon="tabler-user-share" @click="openReassign(selected)">{{ t('crm_reassign.action') }}</VBtn>
        <VBtn size="small" variant="text" @click="selected = []">{{ t('crm_reassign.clear') }}</VBtn>
      </div>
      <VAlert v-if="leadTotal > leads.length" type="info" variant="tonal" density="compact" class="mb-3">
        {{ t('crm_leads_index.capped', { shown: leads.length, total: leadTotal }) }}
      </VAlert>
      <VDataTable v-model="selected" :headers="headers" :items="filtered" :items-per-page="25" density="comfortable" :show-select="canReassign" item-value="id">
        <template #item.score="{ item }"><VChip v-if="item.score !== null" :color="item.score >= 70 ? 'success' : item.score >= 40 ? 'warning' : 'error'" size="small" label>{{ item.score }}</VChip><span v-else class="text-disabled">—</span></template>
        <template #item.owner="{ item }"><VChip v-if="item.owner" size="small" variant="tonal" prepend-icon="tabler-user">{{ item.owner }}</VChip><span v-else class="text-disabled">{{ t('crm_leads_index.unassigned') }}</span></template>
        <template #item.status="{ item }"><VChip :color="item.status_color" size="small" label>{{ item.status_label }}</VChip></template>
        <template #item.actions="{ item }"><div class="d-flex gap-1"><VBtn v-if="aiEnabled && item.status !== 'converted'" size="x-small" variant="tonal" color="primary" @click="score(item)">{{ t('crm_leads_index.score') }}</VBtn><VBtn v-if="item.status !== 'converted'" size="x-small" variant="tonal" color="success" @click="openConvert(item)">{{ t('crm_leads_index.convert') }}</VBtn><VBtn v-if="canReassign" size="x-small" variant="text" icon="tabler-user-share" :title="t('crm_reassign.action')" @click="openReassign([item.id])" /><VBtn size="x-small" variant="text" icon="tabler-edit" :title="t('crm_leads_index.tips.edit_lead')" @click="openEdit(item)" /><VBtn size="x-small" variant="text" icon="tabler-trash" color="error" :title="t('crm_leads_index.tips.delete_lead')" @click="remove(item)" /></div></template>
      </VDataTable>
    </VCard>

    <ReassignDialog v-model="reassignDialog" type="lead" :owners="assignableOwners" :ids="reassignIds" @done="afterReassign" />

    <VDialog v-model="dialog" max-width="520" persistent><VCard><VCardTitle>{{ form.id ? t('crm_leads_index.dialog_create.title_edit') : t('crm_leads_index.dialog_create.title_create') }}</VCardTitle><VCardText><VAlert v-if="unfieldedErrors.length" type="error" variant="tonal" density="compact" class="mb-3"><div v-for="(m, i) in unfieldedErrors" :key="i">{{ m }}</div></VAlert><VRow><VCol cols="6"><VTextField v-model="form.first_name" :label="t('crm_leads_index.dialog_create.first_name')" :error-messages="form.errors.first_name" /></VCol><VCol cols="6"><VTextField v-model="form.last_name" :label="t('crm_leads_index.dialog_create.last_name')" :error-messages="form.errors.last_name" /></VCol><VCol cols="12"><VTextField v-model="form.company" :label="t('crm_leads_index.dialog_create.company')" :error-messages="form.errors.company" /></VCol><VCol cols="6"><VTextField v-model="form.email" :label="t('crm_leads_index.dialog_create.email')" :error-messages="form.errors.email" /></VCol><VCol cols="6"><VTextField v-model="form.phone" :label="t('crm_leads_index.dialog_create.phone')" :error-messages="form.errors.phone" /></VCol><VCol cols="12"><VTextField v-model="form.source" :label="t('crm_leads_index.dialog_create.source')" :error-messages="form.errors.source" /></VCol><VCol v-if="products.length" cols="12"><VSelect v-model="form.product_id" label="Product / Service" :items="products.map(p => ({ title: p.name, value: p.id }))" clearable placeholder="— none —" :error-messages="form.errors.product_id" /></VCol></VRow><template v-if="customFields.length"><VDivider class="my-3" /><div class="text-overline mb-1">{{ t('crm_leads_index.dialog_create.custom_fields') }}</div><VRow><VCol v-for="cf in customFields" :key="cf.id" cols="12" sm="6"><VTextField v-if="cf.field_type === 'text'" v-model="form.custom[cf.name]" :label="cf.label" /><VTextField v-else-if="cf.field_type === 'number'" v-model="form.custom[cf.name]" type="number" :label="cf.label" /><VTextField v-else-if="cf.field_type === 'date'" v-model="form.custom[cf.name]" type="date" :label="cf.label" /><VCheckbox v-else-if="cf.field_type === 'boolean'" v-model="form.custom[cf.name]" :label="cf.label" /><VSelect v-else-if="cf.field_type === 'select'" v-model="form.custom[cf.name]" :items="cf.options" :label="cf.label" clearable /><VSelect v-else-if="cf.field_type === 'multiselect'" v-model="form.custom[cf.name]" :items="cf.options" :label="cf.label" multiple chips /><VTextField v-else v-model="form.custom[cf.name]" :label="cf.label" /></VCol></VRow></template></VCardText><VDivider /><VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="dialog = false">{{ t('crm_leads_index.dialog_create.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" @click="save">{{ t('crm_leads_index.dialog_create.save') }}</VBtn></VCardActions></VCard></VDialog>

    <VDialog v-model="convertDialog" max-width="460" persistent><VCard><VCardTitle>{{ t('crm_leads_index.convert_dialog.title') }}</VCardTitle><VCardText><p class="text-body-2 text-medium-emphasis mb-3">{{ t('crm_leads_index.convert_dialog.description') }}</p><VCheckbox v-model="convertForm.create_deal" :label="t('crm_leads_index.convert_dialog.create_deal')" /><template v-if="convertForm.create_deal"><VTextField v-model="convertForm.deal_title" :label="t('crm_leads_index.convert_dialog.deal_title')" class="mb-2" /><VTextField v-model.number="convertForm.amount" type="number" :label="t('crm_leads_index.convert_dialog.amount')" /></template></VCardText><VDivider /><VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="convertDialog = false">{{ t('crm_leads_index.convert_dialog.cancel') }}</VBtn><VBtn color="success" :loading="convertForm.processing" @click="doConvert">{{ t('crm_leads_index.convert_dialog.convert') }}</VBtn></VCardActions></VCard></VDialog>

    <VDialog v-model="viewDialog" max-width="440" persistent><VCard><VCardTitle>{{ t('crm_leads_index.view_dialog.title') }}</VCardTitle><VCardText><p class="text-body-2 text-medium-emphasis mb-3">{{ t('crm_leads_index.view_dialog.description_all') }}</p><VTextField v-model="viewForm.name" :label="t('crm_leads_index.view_dialog.view_name')" :placeholder="t('crm_leads_index.view_dialog.view_name_placeholder')" class="mb-2" /><VSwitch v-model="viewForm.is_shared" :label="t('crm_leads_index.view_dialog.share')" color="primary" hide-details /></VCardText><VDivider /><VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="viewDialog = false">{{ t('crm_leads_index.view_dialog.cancel') }}</VBtn><VBtn color="primary" :loading="viewForm.processing" :disabled="!viewForm.name" @click="saveView">{{ t('crm_leads_index.view_dialog.save') }}</VBtn></VCardActions></VCard></VDialog>
    <ConfirmDialog ref="confirm" />
  </div>
</template>