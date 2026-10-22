<script setup lang="ts">
/**
 * Residues & MRLs: each lot's status per target market, laboratory residue tests, the blocks to
 * test before harvest, the MRL table (starter set, CSV import), banned/restricted actives, markets.
 */
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PageErrors from '../Components/PageErrors.vue'
import FarmFilter from '../Components/FarmFilter.vue'
import FormDialog, { type FormField } from '../Components/FormDialog.vue'
import ResidueChip from './ResidueChip.vue'
import { human, num, today } from '../Components/agri'

type Market = { id: string; code: string; name: string; countries: string[]; default_mrl_mg_kg: number | null; requires_result: boolean; default_target: boolean; is_active: boolean; is_system: boolean; notes: string | null }
const props = defineProps<{
  tab: 'lots' | 'tests' | 'risks' | 'limits' | 'restrictions' | 'markets'
  data: Record<string, any>
  filters: { farm: string | null; crop: string | null; market: string | null; q: string | null }
  farms: Array<{ id: string; name: string }>
  markets: Market[]
  options: { crops: Array<{ value: string; title: string }>; lots: Array<{ value: number; title: string }>; fields: Array<{ value: number; title: string }>
    plans: Array<{ value: number; title: string; target_markets: string[] }>; statuses: string[]; starter_source: string }
  can: { manage: boolean; override: boolean }
}>()

const { t } = useI18n()
const k = (key: string, params?: Record<string, unknown>) => t(`agri.residues.${key}`, params ?? {})
const keep = { preserveScroll: true }
const TABS = ['lots', 'tests', 'risks', 'limits', 'restrictions', 'markets']
const go = (tab: string, extra: Record<string, any> = {}) => router.get(route('agri.residues', tab), { farm: props.filters.farm ?? undefined, ...extra }, { preserveState: false })
const marketItems = computed(() => props.markets.filter(m => m.is_active).map(m => ({ title: `${m.code} — ${m.name}`, value: m.code })))
const STATUS_COLOR: Record<string, string> = { pass: 'success', exceeds: 'error', no_mrl: 'warning', banned: 'error', restricted: 'warning', not_tested: 'warning' }

// target markets of a lot / a crop plan
const marketsFor = ref<null | { url: string; title: string; markets: string[] }>(null)
const editLotMarkets = (l: any) => { marketsFor.value = { url: route('agri.residues.lots.markets', l.id), title: k('markets_of', { what: l.code }), markets: [...(l.target_markets ?? [])] } }
const editPlanMarkets = (p: { value: number; title: string; target_markets: string[] }, id: string) => { marketsFor.value = { url: route('agri.residues.plans.markets', id), title: k('markets_of', { what: p.title }), markets: [...p.target_markets] } }
const saveMarketsFor = () => marketsFor.value && router.post(marketsFor.value.url, { markets: marketsFor.value.markets }, { ...keep, onSuccess: () => { marketsFor.value = null } })

// a residue test, with a result per active
const testOpen = ref(false)
const test = useForm<Record<string, any>>({})
const openTest = () => {
  test.defaults({ farm_id: props.filters.farm, laboratory: '', lab_reference: '', sample_type: 'lot', produce_lot_id: null, field_id: null, crop_plan_id: null, crop_code: null,
    sampled_on: today(), reported_on: today(), notes: '', certificate: null, results: [{ active_ingredient: '', measured_mg_kg: null, loq_mg_kg: 0.01, below_loq: false }] })
  test.reset(); test.clearErrors(); testOpen.value = true
}
const saveTest = () => test.post(route('agri.residues.tests.store'), { ...keep, forceFormData: true, onSuccess: () => { testOpen.value = false } })

// limits, restrictions, markets
const dialog = ref<null | { kind: string; row?: any }>(null)
const limitFields: FormField[] = [
  { key: 'active_ingredient', label: k('active'), required: true },
  { key: 'crop_code', label: k('crop'), type: 'autocomplete', items: props.options.crops, required: true },
  { key: 'market_code', label: k('market'), type: 'select', items: marketItems.value, required: true },
  { key: 'mrl_mg_kg', label: k('mrl'), type: 'number', required: true },
  { key: 'effective_from', label: k('effective_from'), type: 'date', required: true, default: today() },
  { key: 'source', label: k('source'), cols: 12, hint: k('source_hint') },
  { key: 'notes', label: k('notes'), type: 'textarea', cols: 12 },
]
const restrictionFields: FormField[] = [
  { key: 'active_ingredient', label: k('active'), required: true },
  { key: 'market_code', label: k('market'), type: 'select', items: marketItems.value, required: true },
  { key: 'status', label: k('restriction'), type: 'select', items: props.options.statuses.map(s => ({ title: k(`status.${s}`), value: s })), required: true, default: 'banned' },
  { key: 'effective_from', label: k('effective_from'), type: 'date' },
  { key: 'source', label: k('source'), cols: 12 },
  { key: 'notes', label: k('notes'), type: 'textarea', cols: 12 },
]
const marketFields = (m?: Market): FormField[] => [
  ...(m?.is_system ? [] : [{ key: 'code', label: k('market_code'), required: true } as FormField]),
  { key: 'name', label: k('market_name'), required: true },
  { key: 'countries_text', label: k('countries'), cols: 12, hint: k('countries_hint') },
  { key: 'default_mrl_mg_kg', label: k('default_mrl'), type: 'number', hint: k('default_mrl_hint') },
  { key: 'requires_result', label: k('requires_result'), type: 'switch' },
  { key: 'default_target', label: k('default_target'), type: 'switch' },
  { key: 'is_active', label: k('active_market'), type: 'switch', default: true },
  { key: 'notes', label: k('notes'), type: 'textarea', cols: 12 },
]
const saveMarket = (form: any, m?: Market) => {
  const data = { ...form, countries: String(form.countries_text ?? '').split(/[\s,;]+/).filter(Boolean) }
  delete data.countries_text
  const opts = { ...keep, onSuccess: () => { dialog.value = null } }
  m ? router.patch(route('agri.residues.markets.update', m.id), data, opts) : router.post(route('agri.residues.markets.store'), data, opts)
}
const marketForm = ref<Record<string, any>>({})
const editMarket = (m?: Market) => {
  marketForm.value = m ? { ...m, countries_text: m.countries.join(', ') } : { is_active: true, requires_result: false, default_target: false, countries_text: '' }
  dialog.value = { kind: 'market', row: m }
}
const removeLimit = (l: any) => confirm(k('confirm_remove')) && router.delete(route('agri.residues.limits.destroy', l.id), keep)
const removeRestriction = (r: any) => confirm(k('confirm_remove')) && router.delete(route('agri.residues.restrictions.destroy', r.id), keep)
const installStarter = () => router.post(route('agri.residues.starter'), {}, keep)
const importForm = useForm<{ file: File | null }>({ file: null })
const importCsv = () => importForm.post(route('agri.residues.import'), { ...keep, forceFormData: true, onSuccess: () => importForm.reset() })
const limitFilter = ref({ crop: props.filters.crop, market: props.filters.market, q: props.filters.q })
const filterLimits = () => go('limits', limitFilter.value)
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <h4 class="text-h4 mb-1">{{ k('title') }}</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">{{ k('subtitle') }}</p>
      </div>
      <FarmFilter :farms="farms" :model-value="filters.farm" :allow-all="false" />
    </div>
    <VTabs :model-value="tab" class="mb-4" @update:model-value="(v: any) => go(String(v))">
      <VTab v-for="x in TABS" :key="x" :value="x">{{ k(`tabs.${x}`) }}</VTab>
    </VTabs>

    <!-- lots -->
    <VCard v-if="tab === 'lots'">
      <VTable density="compact">
        <thead><tr><th>{{ k('lot') }}</th><th>{{ k('product') }}</th><th>{{ k('target_markets') }}</th><th>{{ k('status_col') }}</th><th>{{ k('findings') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="l in data.lots" :key="l.id">
            <td class="text-no-wrap">{{ l.code }}<div class="text-caption">{{ human(l.kind) }} · {{ l.produced_on }}</div></td>
            <td>{{ l.product }}<span v-if="l.grade" class="text-caption"> · {{ l.grade }}</span><div class="text-caption">{{ l.residue.crop_code ?? '—' }}</div></td>
            <td class="text-caption">{{ Object.keys(l.residue.markets).join(', ') || '—' }}<div v-if="!l.target_markets.length" class="text-medium-emphasis">{{ k('inherited') }}</div></td>
            <td><ResidueChip :status="l.residue.overall" /></td>
            <td class="text-caption">
              <div v-for="(r, m) in l.residue.markets" :key="m"><strong>{{ m }}</strong> {{ k(`status.${r.status}`) }}<span v-if="r.requires_result && r.status === 'not_tested'" class="text-error"> · {{ k('result_required') }}</span>
                <div v-for="(f, i) in r.findings" :key="i" class="ms-2">{{ f }}</div><div v-for="(n, i) in r.notes" :key="`n${i}`" class="ms-2 text-medium-emphasis">{{ n }}</div></div>
            </td>
            <td class="text-end"><VBtn v-if="can.manage" size="x-small" variant="text" @click="editLotMarkets(l)">{{ k('set_markets') }}</VBtn></td>
          </tr>
          <tr v-if="!data.lots?.length"><td colspan="6" class="text-center text-medium-emphasis py-4">{{ k('no_lots') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- tests -->
    <template v-if="tab === 'tests'">
      <div class="d-flex mb-3"><VBtn v-if="can.manage && filters.farm" color="primary" prepend-icon="tabler-plus" @click="openTest">{{ k('new_test') }}</VBtn></div>
      <VCard>
        <VTable density="compact">
          <thead><tr><th>{{ k('test') }}</th><th>{{ k('sample') }}</th><th>{{ k('results') }}</th><th /></tr></thead>
          <tbody>
            <tr v-for="x in data.tests" :key="x.id">
              <td class="text-no-wrap">{{ x.reference }}<div class="text-caption">{{ x.laboratory }} {{ x.lab_reference }}</div><div class="text-caption">{{ k('sampled', { on: x.sampled_on }) }}</div></td>
              <td class="text-caption">{{ x.sample_type === 'lot' ? k('sample_lot', { lot: x.lot }) : k('sample_field', { field: x.field ?? '—', plan: x.plan ?? '—' }) }}<div>{{ x.crop_code }}</div></td>
              <td>
                <VTable density="compact" class="bg-transparent">
                  <thead><tr><th>{{ k('active') }}</th><th class="text-end">mg/kg</th><th v-for="m in x.markets" :key="m">{{ m }}</th></tr></thead>
                  <tbody><tr v-for="r in x.results" :key="r.active"><td>{{ r.active }}</td><td class="text-end">{{ r.below_loq ? k('below_loq') : num(r.measured, 4) }}</td>
                    <td v-for="m in x.markets" :key="m"><VChip size="x-small" :color="STATUS_COLOR[r.markets[m].status]">{{ k(`status.${r.markets[m].status}`) }}</VChip>
                      <span v-if="r.markets[m].limit !== null" class="text-caption ms-1">≤ {{ num(r.markets[m].limit, 4) }}<template v-if="!r.markets[m].specific">*</template></span></td></tr></tbody>
                </VTable>
              </td>
              <td class="text-end"><a v-if="x.certificate" :href="route('agri.residues.tests.certificate', x.id)" target="_blank" class="text-caption">{{ k('certificate') }}</a></td>
            </tr>
            <tr v-if="!data.tests?.length"><td colspan="4" class="text-center text-medium-emphasis py-4">{{ k('no_tests') }}</td></tr>
          </tbody>
        </VTable>
        <VCardText class="text-caption text-medium-emphasis">{{ k('default_limit_note') }}</VCardText>
      </VCard>
    </template>

    <!-- pre-harvest risks -->
    <VCard v-if="tab === 'risks'" :subtitle="k('risks_subtitle')">
      <VTable density="compact">
        <thead><tr><th>{{ k('crop_plan') }}</th><th>{{ k('active') }}</th><th>{{ k('applied') }}</th><th>{{ k('phi_ends') }}</th><th>{{ k('harvest') }}</th><th>{{ k('margin') }}</th><th>{{ k('tested') }}</th></tr></thead>
        <tbody>
          <tr v-for="(r, i) in data.risks" :key="i">
            <td>{{ r.plan }}<div class="text-caption">{{ r.field }} · {{ r.crop }}</div></td>
            <td>{{ r.active }}<div class="text-caption">{{ r.product }}</div><div v-if="r.banned_in.length" class="text-caption text-error">{{ k('banned_in', { markets: r.banned_in.join(', ') }) }}</div></td>
            <td>{{ r.applied_on }}<div class="text-caption">PHI {{ r.phi_days ?? '—' }} d</div></td>
            <td>{{ r.phi_ends_on }}</td><td>{{ r.harvest_on }}</td>
            <td><VChip size="x-small" :color="r.before_phi ? 'error' : 'warning'">{{ k('days', { n: r.margin_days }) }}</VChip></td>
            <td><VChip size="x-small" :color="r.tested ? 'success' : 'error'">{{ r.tested ? k('yes') : k('test_needed') }}</VChip></td>
          </tr>
          <tr v-if="!data.risks?.length"><td colspan="7" class="text-center text-medium-emphasis py-4">{{ k('no_risks') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VCard v-if="tab === 'risks'" class="mt-4" :title="k('crop_markets_title')" :subtitle="k('crop_markets_subtitle')">
      <VTable density="compact"><tbody>
        <tr v-for="p in data.crop_markets" :key="p.id"><td>{{ p.code }}</td><td class="text-caption">{{ p.target_markets.join(', ') || k('default_markets') }}</td>
          <td class="text-end"><VBtn v-if="can.manage" size="x-small" variant="text" @click="editPlanMarkets({ value: 0, title: p.code, target_markets: p.target_markets }, p.id)">{{ k('set_markets') }}</VBtn></td></tr>
      </tbody></VTable>
    </VCard>

    <!-- MRL table -->
    <template v-if="tab === 'limits'">
      <VAlert type="warning" variant="tonal" density="compact" class="mb-3">{{ k('starter_warning') }}</VAlert>
      <div class="d-flex flex-wrap gap-2 align-center mb-3">
        <VSelect v-model="limitFilter.crop" :items="options.crops" :label="k('crop')" density="compact" hide-details clearable style="max-width: 200px" />
        <VSelect v-model="limitFilter.market" :items="marketItems" :label="k('market')" density="compact" hide-details clearable style="max-width: 220px" />
        <VTextField v-model="limitFilter.q" :label="k('active')" density="compact" hide-details style="max-width: 200px" @keyup.enter="filterLimits" />
        <VBtn variant="tonal" @click="filterLimits">{{ k('filter') }}</VBtn>
        <VSpacer />
        <template v-if="can.manage">
          <VBtn v-if="!data.starter_installed" variant="tonal" color="secondary" @click="installStarter">{{ k('install_starter') }}</VBtn>
          <VBtn color="primary" prepend-icon="tabler-plus" @click="dialog = { kind: 'limit' }">{{ k('new_limit') }}</VBtn>
        </template>
      </div>
      <VCard v-if="can.manage" class="mb-3" :title="k('import_title')" :subtitle="k('import_subtitle')">
        <VCardText class="d-flex gap-2 align-center">
          <VFileInput :label="k('csv_file')" accept=".csv,text/csv" density="compact" hide-details prepend-icon="" prepend-inner-icon="tabler-file-spreadsheet"
            :error-messages="importForm.errors.file" @update:model-value="(v: any) => (importForm.file = Array.isArray(v) ? v[0] : v)" />
          <VBtn color="primary" :disabled="!importForm.file" :loading="importForm.processing" @click="importCsv">{{ k('import') }}</VBtn>
        </VCardText>
      </VCard>
      <VCard>
        <VTable density="compact">
          <thead><tr><th>{{ k('crop') }}</th><th>{{ k('active') }}</th><th>{{ k('market') }}</th><th class="text-end">{{ k('mrl') }}</th><th>{{ k('effective_from') }}</th><th>{{ k('source') }}</th><th /></tr></thead>
          <tbody>
            <tr v-for="l in data.limits" :key="l.id">
              <td>{{ l.crop_code }}</td><td>{{ l.active_ingredient }}</td><td>{{ l.market_code }}</td><td class="text-end">{{ num(l.mrl_mg_kg, 4) }}</td><td>{{ l.effective_from }}</td>
              <td class="text-caption">{{ l.source }}<VChip v-if="l.is_starter" size="x-small" color="warning" class="ms-1">{{ k('starter') }}</VChip></td>
              <td class="text-end text-no-wrap"><template v-if="can.manage">
                <VBtn size="x-small" variant="text" @click="dialog = { kind: 'limit', row: l }">{{ k('edit') }}</VBtn>
                <VBtn size="x-small" variant="text" color="error" @click="removeLimit(l)">{{ k('remove') }}</VBtn></template></td>
            </tr>
            <tr v-if="!data.limits?.length"><td colspan="7" class="text-center text-medium-emphasis py-4">{{ k('no_limits') }}</td></tr>
          </tbody>
        </VTable>
      </VCard>
    </template>

    <!-- banned / restricted -->
    <template v-if="tab === 'restrictions'">
      <div class="d-flex mb-3"><VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="dialog = { kind: 'restriction' }">{{ k('new_restriction') }}</VBtn></div>
      <VCard :subtitle="k('restrictions_subtitle')">
        <VTable density="compact">
          <thead><tr><th>{{ k('market') }}</th><th>{{ k('active') }}</th><th>{{ k('restriction') }}</th><th>{{ k('source') }}</th><th /></tr></thead>
          <tbody>
            <tr v-for="r in data.restrictions" :key="r.id">
              <td>{{ r.market_code }}</td><td>{{ r.active_ingredient }}</td><td><VChip size="x-small" :color="STATUS_COLOR[r.status]">{{ k(`status.${r.status}`) }}</VChip></td>
              <td class="text-caption">{{ r.source }}<VChip v-if="r.is_starter" size="x-small" color="warning" class="ms-1">{{ k('starter') }}</VChip></td>
              <td class="text-end"><VBtn v-if="can.manage" size="x-small" variant="text" color="error" @click="removeRestriction(r)">{{ k('remove') }}</VBtn></td>
            </tr>
            <tr v-if="!data.restrictions?.length"><td colspan="5" class="text-center text-medium-emphasis py-4">{{ k('no_restrictions') }}</td></tr>
          </tbody>
        </VTable>
      </VCard>
    </template>

    <!-- markets -->
    <template v-if="tab === 'markets'">
      <div class="d-flex mb-3"><VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="editMarket()">{{ k('new_market') }}</VBtn></div>
      <VCard :subtitle="k('markets_subtitle')">
        <VTable density="compact">
          <thead><tr><th>{{ k('market') }}</th><th>{{ k('countries') }}</th><th>{{ k('default_mrl') }}</th><th>{{ k('requires_result') }}</th><th>{{ k('default_target') }}</th><th /></tr></thead>
          <tbody>
            <tr v-for="m in markets" :key="m.id" :class="{ 'text-disabled': !m.is_active }">
              <td><strong>{{ m.code }}</strong> {{ m.name }}</td><td class="text-caption">{{ m.countries.join(', ') || k('other_countries') }}</td>
              <td>{{ m.default_mrl_mg_kg ?? '—' }}</td><td>{{ m.requires_result ? k('yes') : k('no') }}</td><td>{{ m.default_target ? k('yes') : k('no') }}</td>
              <td class="text-end"><VBtn v-if="can.manage" size="x-small" variant="text" @click="editMarket(m)">{{ k('edit') }}</VBtn></td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </template>

    <!-- dialogs -->
    <FormDialog v-if="dialog?.kind === 'limit'" :model-value="true" :title="dialog.row ? k('edit_limit') : k('new_limit')" :fields="limitFields" :values="dialog.row"
      :url="dialog.row ? route('agri.residues.limits.update', dialog.row.id) : route('agri.residues.limits.store')" :method="dialog.row ? 'patch' : 'post'"
      :submit-label="k('save')" @update:model-value="dialog = null" />
    <FormDialog v-if="dialog?.kind === 'restriction'" :model-value="true" :title="k('new_restriction')" :fields="restrictionFields" :url="route('agri.residues.restrictions.store')"
      :submit-label="k('save')" @update:model-value="dialog = null" />
    <VDialog v-if="dialog?.kind === 'market'" :model-value="true" max-width="620" @update:model-value="dialog = null">
      <VCard :title="dialog.row ? k('edit_market', { code: dialog.row.code }) : k('new_market')">
        <VCardText><VRow>
          <VCol v-for="f in marketFields(dialog.row)" :key="f.key" cols="12" :sm="f.cols ?? 6">
            <VSwitch v-if="f.type === 'switch'" v-model="marketForm[f.key]" :label="f.label" color="primary" inset hide-details />
            <VTextarea v-else-if="f.type === 'textarea'" v-model="marketForm[f.key]" :label="f.label" rows="2" auto-grow />
            <VTextField v-else v-model="marketForm[f.key]" :label="f.label + (f.required ? ' *' : '')" :type="f.type === 'number' ? 'number' : 'text'" :hint="f.hint" :persistent-hint="!!f.hint" />
          </VCol></VRow></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = null">{{ k('cancel') }}</VBtn><VBtn color="primary" @click="saveMarket(marketForm, dialog.row)">{{ k('save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
    <VDialog v-if="marketsFor" :model-value="true" max-width="480" @update:model-value="marketsFor = null">
      <VCard :title="marketsFor.title">
        <VCardText><VSelect v-model="marketsFor.markets" :items="marketItems" multiple chips closable-chips :label="k('target_markets')" :hint="k('markets_hint')" persistent-hint /></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="marketsFor = null">{{ k('cancel') }}</VBtn><VBtn color="primary" @click="saveMarketsFor">{{ k('save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
    <VDialog v-model="testOpen" max-width="820" scrollable>
      <VCard :title="k('new_test')">
        <VCardText>
          <VAlert v-if="Object.keys(test.errors).length" type="error" variant="tonal" density="compact" class="mb-3"><div v-for="(e, i) in test.errors" :key="i">{{ e }}</div></VAlert>
          <VRow>
            <VCol cols="12" sm="6"><VTextField v-model="test.laboratory" :label="k('laboratory') + ' *'" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="test.lab_reference" :label="k('lab_reference')" /></VCol>
            <VCol cols="12" sm="4"><VSelect v-model="test.sample_type" :items="[{ title: k('sample_type_lot'), value: 'lot' }, { title: k('sample_type_field'), value: 'field' }]" :label="k('sample')" /></VCol>
            <VCol v-if="test.sample_type === 'lot'" cols="12" sm="8"><VAutocomplete v-model="test.produce_lot_id" :items="options.lots" :label="k('lot') + ' *'" /></VCol>
            <template v-else>
              <VCol cols="12" sm="4"><VSelect v-model="test.field_id" :items="options.fields" :label="k('field')" clearable /></VCol>
              <VCol cols="12" sm="4"><VSelect v-model="test.crop_plan_id" :items="options.plans" :label="k('crop_plan')" clearable /></VCol>
            </template>
            <VCol cols="12" sm="4"><VSelect v-model="test.crop_code" :items="options.crops" :label="k('crop')" :hint="k('crop_hint')" persistent-hint clearable /></VCol>
            <VCol cols="12" sm="4"><VTextField v-model="test.sampled_on" type="date" :label="k('sampled_on') + ' *'" /></VCol>
            <VCol cols="12" sm="4"><VTextField v-model="test.reported_on" type="date" :label="k('reported_on')" /></VCol>
          </VRow>
          <div class="text-subtitle-2 mt-2 mb-1">{{ k('results') }}</div>
          <VRow v-for="(r, i) in test.results" :key="i" dense class="align-center">
            <VCol cols="12" sm="4"><VTextField v-model="r.active_ingredient" :label="k('active')" density="compact" hide-details /></VCol>
            <VCol cols="6" sm="2"><VTextField v-model.number="r.measured_mg_kg" type="number" label="mg/kg" density="compact" hide-details :disabled="r.below_loq" /></VCol>
            <VCol cols="6" sm="2"><VTextField v-model.number="r.loq_mg_kg" type="number" label="LOQ" density="compact" hide-details /></VCol>
            <VCol cols="8" sm="3"><VCheckbox v-model="r.below_loq" :label="k('below_loq')" density="compact" hide-details /></VCol>
            <VCol cols="4" sm="1"><VBtn icon="tabler-x" size="x-small" variant="text" :disabled="test.results.length === 1" @click="test.results.splice(i, 1)" /></VCol>
          </VRow>
          <VBtn size="small" variant="text" prepend-icon="tabler-plus" class="mt-1" @click="test.results.push({ active_ingredient: '', measured_mg_kg: null, loq_mg_kg: 0.01, below_loq: false })">{{ k('add_active') }}</VBtn>
          <VFileInput :label="k('certificate')" accept=".pdf,image/*" class="mt-3" prepend-icon="" prepend-inner-icon="tabler-paperclip" :error-messages="test.errors.certificate"
            @update:model-value="(v: any) => (test.certificate = Array.isArray(v) ? v[0] : v)" />
          <VTextarea v-model="test.notes" :label="k('notes')" rows="2" auto-grow />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="testOpen = false">{{ k('cancel') }}</VBtn><VBtn color="primary" :loading="test.processing" @click="saveTest">{{ k('save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
