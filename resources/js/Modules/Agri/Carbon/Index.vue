<script setup lang="ts">
/**
 * Carbon projects with a registry: the listing (Verra VCS, Gold Standard or another), its status,
 * monitoring periods and their data, issuances with the buffer withheld, retirements and transfers,
 * documents for the verifier, and the registry-ready export package. Kept by hand — registries take
 * submissions through their own portals, not an API.
 */
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PageErrors from '../Components/PageErrors.vue'
import FarmFilter from '../Components/FarmFilter.vue'
import FormDialog, { type FormField } from '../Components/FormDialog.vue'
import { num, today } from '../Components/agri'

const props = defineProps<{
  filters: { farm: string | null }
  farms: Array<{ id: string; name: string }>
  projects: any[]
  options: { registries: Record<string, string>; statuses: string[]; period_statuses: string[]; document_kinds: string[] }
  can: { manage: boolean }
}>()

const { t } = useI18n()
const k = (key: string, params?: Record<string, unknown>) => t(`agri.carbon.${key}`, params ?? {})
const keep = { preserveScroll: true }
const dialog = ref<null | { kind: string; project?: any; row?: any }>(null)
const close = (v: boolean) => { if (!v) dialog.value = null }
const statusColour = (s: string) => ({ listed: 'info', under_validation: 'warning', registered: 'primary', issuing: 'success' } as Record<string, string>)[s]

const registrationFields = computed<FormField[]>(() => [
  { key: 'registry', label: k('registry'), type: 'select', required: true, items: Object.entries(props.options.registries).map(([value, title]) => ({ title, value })) },
  { key: 'registry_name', label: k('registry_name'), show: f => f.registry === 'other' },
  { key: 'registry_project_id', label: k('registry_project_id') },
  { key: 'status', label: k('status'), type: 'select', items: props.options.statuses.map(s => ({ title: k(`status_${s}`), value: s })) },
  { key: 'status_on', label: k('status_on'), type: 'date' },
  { key: 'methodology', label: k('methodology') }, { key: 'proponent', label: k('proponent') },
  { key: 'crediting_starts_on', label: k('crediting_starts_on'), type: 'date' }, { key: 'crediting_ends_on', label: k('crediting_ends_on'), type: 'date' },
  { key: 'buffer_pct', label: k('buffer_pct'), type: 'number', hint: k('buffer_hint') }, { key: 'notes', label: k('notes'), type: 'textarea', cols: 12 },
])
const periodFields: FormField[] = [
  { key: 'starts_on', label: k('starts_on'), type: 'date', required: true }, { key: 'ends_on', label: k('ends_on'), type: 'date', required: true },
  { key: 'claimed_t', label: k('claimed'), type: 'number' }, { key: 'notes', label: k('notes'), cols: 12 },
]
const periodEditFields = computed<FormField[]>(() => [
  { key: 'status', label: k('status'), type: 'select', required: true, items: props.options.period_statuses.map(s => ({ title: k(`period_${s}`), value: s })) },
  { key: 'claimed_t', label: k('claimed'), type: 'number' }, { key: 'verified_t', label: k('verified'), type: 'number' },
  { key: 'verifier', label: k('verifier') }, { key: 'verified_on', label: k('verified_on'), type: 'date' }, { key: 'notes', label: k('notes'), cols: 12 },
])
const monitoringFields: FormField[] = [
  { key: 'measured_on', label: k('measured_on'), type: 'date', required: true }, { key: 'parameter', label: k('parameter'), required: true },
  { key: 'value', label: k('value'), type: 'number', required: true, cols: 3 }, { key: 'unit', label: k('unit'), required: true, cols: 3 },
  { key: 'method', label: k('method') }, { key: 'source', label: k('source') },
]
const issuanceFields = (project: any): FormField[] => [
  { key: 'period_id', label: k('period'), type: 'select', items: (project.registration?.periods ?? []).map((p: any) => ({ title: `${p.starts_on} – ${p.ends_on}`, value: p.id })) },
  { key: 'vintage', label: k('vintage'), type: 'number', required: true, cols: 4 }, { key: 'quantity_t', label: k('quantity'), type: 'number', required: true, cols: 4 },
  { key: 'buffer_t', label: k('buffer_t'), type: 'number', cols: 4, hint: k('buffer_t_hint') },
  { key: 'serial_start', label: k('serial_start') }, { key: 'serial_end', label: k('serial_end') }, { key: 'issued_on', label: k('issued_on'), type: 'date', required: true },
]
const transactionFields: FormField[] = [
  { key: 'kind', label: k('transaction_kind'), type: 'select', required: true, items: [{ title: k('retirement'), value: 'retirement' }, { title: k('transfer'), value: 'transfer' }] },
  { key: 'quantity_t', label: k('quantity'), type: 'number', required: true }, { key: 'transacted_on', label: k('date'), type: 'date', required: true },
  { key: 'counterparty', label: k('counterparty'), show: f => f.kind === 'transfer' }, { key: 'beneficiary', label: k('beneficiary'), show: f => f.kind === 'retirement' },
  { key: 'serial_start', label: k('serial_start') }, { key: 'serial_end', label: k('serial_end') }, { key: 'reason', label: k('reason'), cols: 12 },
]
const documentFields = (project: any): FormField[] => [
  { key: 'kind', label: k('document_kind'), type: 'select', required: true, items: props.options.document_kinds.map(d => ({ title: k(`doc_${d}`), value: d })) },
  { key: 'period_id', label: k('period'), type: 'select', items: (project.registration?.periods ?? []).map((p: any) => ({ title: `${p.starts_on} – ${p.ends_on}`, value: p.id })) },
  { key: 'file', label: k('file'), type: 'file', required: true, cols: 12 },
]
const buildExport = (project: any, periodId: string | null = null) => router.post(route('agri.carbon.exports.store', project.registration.id), { period_id: periodId }, keep)
const kb = (n: number) => `${num(n / 1024, 0)} kB`
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <h4 class="text-h4 mb-1">{{ k('title') }}</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">{{ k('subtitle') }}</p>
      </div>
      <FarmFilter :farms="farms" :model-value="filters.farm" />
    </div>
    <VAlert type="info" variant="tonal" density="compact" class="mb-4">{{ k('no_api') }}</VAlert>

    <VCard v-for="p in projects" :key="p.id" class="mb-4">
      <VCardItem>
        <VCardTitle class="text-wrap">{{ p.description }}</VCardTitle>
        <VCardSubtitle>{{ p.farm }} · {{ p.recorded_on }}<template v-if="p.methodology"> · {{ p.methodology }}</template>
          · <span :class="p.verification === 'verified' ? 'text-success' : ''">{{ p.verification === 'verified' ? k('verified_by', { verifier: p.verifier }) : k('unverified') }}</span></VCardSubtitle>
        <template #append>
          <VChip v-if="p.registration" :color="statusColour(p.registration.status)">{{ k(`status_${p.registration.status}`) }}</VChip>
          <VBtn v-else-if="can.manage" color="primary" variant="tonal" @click="dialog = { kind: 'register', project: p }">{{ k('track') }}</VBtn>
        </template>
      </VCardItem>
      <VCardText v-if="p.registration">
        <div class="d-flex flex-wrap gap-4 mb-4 align-center">
          <div><div class="text-caption text-medium-emphasis">{{ k('registry') }}</div>
            <strong>{{ p.registration.registry === 'other' ? p.registration.registry_name : options.registries[p.registration.registry] }}</strong>
            <span v-if="p.registration.registry_project_id"> · {{ p.registration.registry_project_id }}</span></div>
          <div v-for="m in ['issued', 'buffer', 'retired', 'transferred', 'held']" :key="m">
            <div class="text-caption text-medium-emphasis">{{ k(m === 'issued' ? 'issued_total' : m === 'buffer' ? 'buffer_total' : m) }}</div>
            <strong>{{ num(p.registration.balance[m], 1) }}</strong> <span class="text-caption">tCO₂e</span>
          </div>
          <VSpacer />
          <VBtn v-if="can.manage" size="small" variant="text" prepend-icon="tabler-edit" @click="dialog = { kind: 'edit', project: p, row: p.registration }">{{ k('edit') }}</VBtn>
        </div>

        <div class="d-flex align-center mb-1"><div class="text-subtitle-1">{{ k('periods') }}</div><VSpacer />
          <VBtn v-if="can.manage" size="small" variant="tonal" @click="dialog = { kind: 'period', project: p }">{{ k('add_period') }}</VBtn></div>
        <VTable density="compact" class="mb-4">
          <thead><tr><th>{{ k('period') }}</th><th>{{ k('status') }}</th><th class="text-end">{{ k('claimed') }}</th><th class="text-end">{{ k('verified') }}</th><th>{{ k('verifier') }}</th><th class="text-end">{{ k('values') }}</th><th /></tr></thead>
          <tbody>
            <tr v-for="x in p.registration.periods" :key="x.id">
              <td class="text-no-wrap">{{ x.starts_on }} – {{ x.ends_on }}</td><td>{{ k(`period_${x.status}`) }}</td>
              <td class="text-end">{{ x.claimed_t !== null ? num(x.claimed_t, 1) : '—' }}</td><td class="text-end">{{ x.verified_t !== null ? num(x.verified_t, 1) : '—' }}</td>
              <td>{{ x.verifier ?? '—' }}<span v-if="x.verified_on" class="text-caption"> · {{ x.verified_on }}</span></td><td class="text-end">{{ x.values }}</td>
              <td class="text-end text-no-wrap"><template v-if="can.manage">
                <VBtn size="x-small" variant="tonal" @click="dialog = { kind: 'monitoring', project: p, row: x }">{{ k('add_value') }}</VBtn>
                <VBtn size="x-small" variant="text" @click="dialog = { kind: 'period_edit', project: p, row: x }">{{ k('edit') }}</VBtn>
                <VBtn size="x-small" variant="text" @click="buildExport(p, x.id)">{{ k('export_period') }}</VBtn></template></td>
            </tr>
            <tr v-if="!p.registration.periods.length"><td colspan="7" class="text-medium-emphasis text-center">{{ k('no_periods') }}</td></tr>
          </tbody>
        </VTable>

        <div class="d-flex align-center mb-1"><div class="text-subtitle-1">{{ k('issuances') }}</div><VSpacer />
          <VBtn v-if="can.manage" size="small" variant="tonal" :disabled="!['registered', 'issuing'].includes(p.registration.status)" @click="dialog = { kind: 'issuance', project: p }">{{ k('add_issuance') }}</VBtn></div>
        <VTable density="compact" class="mb-4">
          <thead><tr><th>{{ k('vintage') }}</th><th>{{ k('issued_on') }}</th><th class="text-end">{{ k('quantity') }}</th><th class="text-end">{{ k('buffer_t') }}</th><th>{{ k('serials') }}</th><th class="text-end">{{ k('held') }}</th><th /></tr></thead>
          <tbody>
            <template v-for="i in p.registration.issuances" :key="i.id">
              <tr>
                <td>{{ i.vintage }}</td><td>{{ i.issued_on }}</td><td class="text-end">{{ num(i.quantity_t, 1) }}</td><td class="text-end">{{ num(i.buffer_t, 1) }}</td>
                <td class="text-caption">{{ i.serial_start }}<template v-if="i.serial_end"> – {{ i.serial_end }}</template></td><td class="text-end">{{ num(i.held, 1) }}</td>
                <td class="text-end"><VBtn v-if="can.manage && i.held > 0" size="x-small" variant="tonal" @click="dialog = { kind: 'transaction', project: p, row: i }">{{ k('retire_transfer') }}</VBtn></td>
              </tr>
              <tr v-for="(x, n) in i.transactions" :key="`${i.id}-${n}`" class="text-caption">
                <td /><td>{{ x.on }}</td><td class="text-end">−{{ num(x.quantity_t, 1) }}</td><td /><td>{{ x.serials }}</td>
                <td colspan="2">{{ k(x.kind) }}<template v-if="x.beneficiary"> · {{ x.beneficiary }}</template><template v-if="x.counterparty"> → {{ x.counterparty }}</template></td>
              </tr>
            </template>
            <tr v-if="!p.registration.issuances.length"><td colspan="7" class="text-medium-emphasis text-center">{{ k('no_issuances') }}</td></tr>
          </tbody>
        </VTable>

        <VRow>
          <VCol cols="12" md="6">
            <div class="d-flex align-center mb-1"><div class="text-subtitle-1">{{ k('documents') }}</div><VSpacer />
              <VBtn v-if="can.manage" size="small" variant="tonal" @click="dialog = { kind: 'document', project: p }">{{ k('attach') }}</VBtn></div>
            <VList density="compact">
              <VListItem v-if="p.evidence" :title="p.evidence" :subtitle="k('project_evidence')" prepend-icon="tabler-file-check" />
              <VListItem v-for="d in p.registration.documents" :key="d.id" :title="d.name" :subtitle="`${k(`doc_${d.kind}`)}${d.period ? ' · ' + d.period : ''} · ${kb(d.size)}`"
                :href="route('agri.carbon.documents.show', d.id)" prepend-icon="tabler-file" />
              <VListItem v-if="!p.evidence && !p.registration.documents.length" :title="k('no_documents')" />
            </VList>
          </VCol>
          <VCol cols="12" md="6">
            <div class="d-flex align-center mb-1"><div class="text-subtitle-1">{{ k('exports') }}</div><VSpacer />
              <VBtn v-if="can.manage" size="small" color="primary" variant="tonal" prepend-icon="tabler-file-zip" @click="buildExport(p)">{{ k('build_export') }}</VBtn></div>
            <VList density="compact">
              <VListItem v-for="e in p.registration.exports" :key="e.id" :title="e.file_name" :subtitle="`${e.at} · ${k('files', { n: e.files })} · ${kb(e.size)}`"
                :href="route('agri.carbon.exports.show', e.id)" prepend-icon="tabler-download" />
              <VListItem v-if="!p.registration.exports.length" :title="k('no_exports')" :subtitle="k('export_hint')" />
            </VList>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>
    <VCard v-if="!projects.length"><VCardText class="text-center text-medium-emphasis py-8">{{ k('no_projects') }}</VCardText></VCard>

    <FormDialog v-if="dialog?.kind === 'register'" :model-value="true" :title="k('track')" :url="route('agri.carbon.register', dialog.project.id)" :fields="registrationFields"
      :values="{ registry: 'verra_vcs', status: 'listed', status_on: today(), methodology: dialog.project.methodology, buffer_pct: 20 }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'edit'" :model-value="true" :title="k('edit')" method="patch" :url="route('agri.carbon.update', dialog.row.id)" :fields="registrationFields"
      :values="{ ...dialog.row }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'period'" :model-value="true" :title="k('add_period')" :url="route('agri.carbon.periods.store', dialog.project.registration.id)" :fields="periodFields"
      @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'period_edit'" :model-value="true" :title="`${dialog.row.starts_on} – ${dialog.row.ends_on}`" method="patch" :url="route('agri.carbon.periods.update', dialog.row.id)"
      :fields="periodEditFields" :values="{ ...dialog.row }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'monitoring'" :model-value="true" :title="k('add_value')" :url="route('agri.carbon.monitoring.store', dialog.row.id)" :fields="monitoringFields"
      :values="{ measured_on: dialog.row.ends_on }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'issuance'" :model-value="true" :title="k('add_issuance')" :url="route('agri.carbon.issuances.store', dialog.project.registration.id)"
      :fields="issuanceFields(dialog.project)" :values="{ vintage: new Date().getFullYear() - 1, issued_on: today() }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'transaction'" :model-value="true" :title="k('retire_transfer')" :url="route('agri.carbon.transactions.store', dialog.row.id)" :fields="transactionFields"
      :values="{ kind: 'retirement', transacted_on: today(), quantity_t: dialog.row.held }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'document'" :model-value="true" :title="k('attach')" :url="route('agri.carbon.documents.store', dialog.project.registration.id)"
      :fields="documentFields(dialog.project)" :values="{ kind: 'monitoring_report' }" @update:model-value="close" />
  </div>
</template>
