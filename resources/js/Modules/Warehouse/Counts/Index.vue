<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Opt = { id: string; title: string }
type CountRow = { id: string; number: string; status: string; warehouse: string; plan: string | null; reason: string | null; trigger: string; blind: boolean; freeze: boolean; lines: number; waiting: number; variances: number; deadline_at: string | null; overdue: boolean; created_at: string }
type Plan = { id: string; name: string; warehouse: string; warehouse_code: string; frequency: string; scope: string; scope_size: number; abc_class: string | null; min_value: number | null; max_lines: number | null; blind: boolean; freeze: boolean; recount_qty: number | null; recount_pct: number | null; recount_value: number | null; deadline_days: number; next_run_at: string | null; last_run_at: string | null; is_active: boolean }

const props = defineProps<{
  counts: { data: CountRow[]; links: Array<{ url: string | null; label: string; active: boolean }> }
  plans: Plan[]
  filters: { status: string | null }
  statuses: string[]
  frequencies: string[]
  scopes: string[]
  warehouses: Opt[]
  zones: Array<Opt & { warehouse: string }>
  categories: Opt[]
  can: { manage: boolean }
}>()

const tab = ref('counts')
const dialog = ref<null | 'count' | 'plan'>(null)
const editing = ref<string | null>(null)
const blank = () => ({ name: '', frequency: 'MONTHLY', warehouse: props.warehouses[0]?.id ?? null as string | null, scope: 'WAREHOUSE',
  scope_refs: [] as string[], abc_class: 'A', min_value: null as number | null, max_lines: null as number | null, blind: true, freeze: false,
  recount_qty: null as number | null, recount_pct: 5 as number | null, recount_value: null as number | null, deadline_days: 2, reason: '', is_active: true })
const form = useForm(blank())

const refOptions = ref<Opt[]>([])
async function loadRefs(q = '') {
  if (form.scope === 'ZONE') refOptions.value = props.zones.filter(z => z.warehouse === form.warehouse)
  else if (form.scope === 'CATEGORY') refOptions.value = props.categories
  else if (form.scope === 'BINS' && form.warehouse) refOptions.value = (await axios.get(route('warehouse.floor.replenishment.bins'), { params: { warehouse: form.warehouse } })).data
  else if (form.scope === 'ITEMS') refOptions.value = (await axios.get(route('warehouse.products.search'), { params: { q } })).data
  else refOptions.value = []
}
watch(() => [form.scope, form.warehouse], () => { form.scope_refs = []; loadRefs() })
const pickable = computed(() => ['ZONE', 'BINS', 'ITEMS', 'CATEGORY'].includes(form.scope))

function openCount() { form.defaults(blank()); form.reset(); editing.value = null; dialog.value = 'count'; loadRefs() }
function openPlan(p?: Plan) {
  form.defaults(blank()); form.reset()
  editing.value = p?.id ?? null
  if (p) Object.assign(form, { name: p.name, frequency: p.frequency, warehouse: p.warehouse, scope: p.scope, abc_class: p.abc_class ?? 'A', min_value: p.min_value, max_lines: p.max_lines, blind: p.blind, freeze: p.freeze, recount_qty: p.recount_qty, recount_pct: p.recount_pct, recount_value: p.recount_value, deadline_days: p.deadline_days, is_active: p.is_active })
  dialog.value = 'plan'
}
function save() {
  const done = { preserveScroll: true, onSuccess: () => { dialog.value = null } }
  if (dialog.value === 'count') form.post(route('warehouse.counts.store'), done)
  else if (editing.value) form.transform(d => { const { scope_refs, ...rest } = d; return d.scope_refs.length ? d : rest }).patch(route('warehouse.counts.plans.update', editing.value), done)
  else form.post(route('warehouse.counts.plans.store'), done)
}
function toggle(p: Plan) {
  router.patch(route('warehouse.counts.plans.update', p.id), { ...p, is_active: !p.is_active }, { preserveScroll: true })
}

const color = (s: string) => ({ OPEN: 'secondary', COUNTING: 'primary', REVIEW: 'warning', POSTED: 'success', CANCELLED: 'secondary' } as Record<string, string>)[s]
const when = (s: string | null) => s ? new Date(s).toLocaleString() : '—'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.counts.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.counts.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VBtn variant="tonal" prepend-icon="tabler-target" :href="route('warehouse.counts.accuracy')">{{ t('warehouse_management.cards.count_accuracy.title') }}</VBtn>
        <VBtn v-if="can.manage" variant="tonal" prepend-icon="tabler-chart-bar" @click="router.post(route('warehouse.counts.classify'), {}, { preserveScroll: true })">{{ t('warehouse_management.counts.classify') }}</VBtn>
        <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="tab === 'plans' ? openPlan() : openCount()">{{ tab === 'plans' ? t('warehouse_management.counts.new_plan') : t('warehouse_management.counts.new') }}</VBtn>
      </div>
    </div>
    <RefusalAlert />

    <VTabs v-model="tab" class="mb-3">
      <VTab value="counts">{{ t('warehouse_management.counts.tab_counts') }}</VTab>
      <VTab value="plans">{{ t('warehouse_management.counts.tab_plans') }} ({{ plans.length }})</VTab>
    </VTabs>

    <template v-if="tab === 'counts'">
      <VSelect :model-value="filters.status" :items="statuses.map(s => ({ value: s, title: t(`warehouse_management.count_status.${s}`) }))" clearable density="compact"
               hide-details class="mb-3" style="max-width: 240px" :label="t('warehouse_management.locations.status')"
               @update:model-value="v => router.get(route('warehouse.counts.index'), { status: v })" />
      <VCard border>
        <VCardText v-if="!counts.data.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.counts.none') }}</VCardText>
        <VTable v-else density="comfortable">
          <thead><tr>
            <th>{{ t('warehouse_management.counts.number') }}</th><th>{{ t('warehouse_management.common.warehouse') }}</th>
            <th>{{ t('warehouse_management.counts.why') }}</th><th class="text-end">{{ t('warehouse_management.counts.lines') }}</th>
            <th class="text-end">{{ t('warehouse_management.counts.waiting') }}</th><th class="text-end">{{ t('warehouse_management.counts.variances') }}</th>
            <th>{{ t('warehouse_management.counts.deadline') }}</th><th>{{ t('warehouse_management.locations.status') }}</th>
          </tr></thead>
          <tbody>
            <tr v-for="c in counts.data" :key="c.id" style="cursor: pointer" @click="router.get(route('warehouse.counts.show', c.id))">
              <td class="font-weight-medium">{{ c.number }}
                <VIcon v-if="c.freeze" icon="tabler-snowflake" size="14" class="ms-1" :title="t('warehouse_management.counts.frozen')" />
                <VIcon v-if="c.blind" icon="tabler-eye-off" size="14" class="ms-1" :title="t('warehouse_management.counts.blind')" /></td>
              <td>{{ c.warehouse }}</td>
              <td>{{ c.plan ?? c.reason ?? t(`warehouse_management.count_trigger.${c.trigger}`) }}</td>
              <td class="text-end">{{ c.lines }}</td>
              <td class="text-end">{{ c.waiting }}</td>
              <td class="text-end">{{ c.variances }}</td>
              <td :class="c.overdue ? 'text-error' : ''">{{ when(c.deadline_at) }}</td>
              <td><VChip size="small" :color="color(c.status)" variant="tonal">{{ t(`warehouse_management.count_status.${c.status}`) }}</VChip></td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
      <div class="d-flex gap-1 mt-3 flex-wrap">
        <VBtn v-for="l in counts.links" :key="l.label" size="small" :variant="l.active ? 'flat' : 'text'" :disabled="!l.url" @click="l.url && router.get(l.url)"><span v-html="l.label" /></VBtn>
      </div>
    </template>

    <VCard v-else border>
      <VCardText v-if="!plans.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.counts.no_plans') }}</VCardText>
      <VTable v-else density="comfortable">
        <thead><tr>
          <th>{{ t('warehouse_management.counts.plan_name') }}</th><th>{{ t('warehouse_management.common.warehouse') }}</th>
          <th>{{ t('warehouse_management.counts.frequency') }}</th><th>{{ t('warehouse_management.counts.scope') }}</th>
          <th>{{ t('warehouse_management.counts.next_run') }}</th><th />
        </tr></thead>
        <tbody>
          <tr v-for="p in plans" :key="p.id" :class="p.is_active ? '' : 'text-disabled'">
            <td class="font-weight-medium">{{ p.name }}</td>
            <td>{{ p.warehouse_code }}</td>
            <td>{{ t(`warehouse_management.count_frequency.${p.frequency}`) }}</td>
            <td>{{ t(`warehouse_management.count_scope.${p.scope}`) }}<span v-if="p.scope === 'ABC'"> {{ p.abc_class }}</span><span v-else-if="p.scope_size"> ({{ p.scope_size }})</span></td>
            <td>{{ p.is_active ? when(p.next_run_at) : t('warehouse_management.counts.paused') }}</td>
            <td class="text-end text-no-wrap">
              <template v-if="can.manage">
                <VBtn size="small" variant="text" @click="router.post(route('warehouse.counts.plans.run', p.id), {}, { preserveScroll: true })">{{ t('warehouse_management.counts.run_now') }}</VBtn>
                <VBtn size="small" variant="text" @click="openPlan(p)">{{ t('warehouse_management.common.edit') }}</VBtn>
                <VBtn size="small" variant="text" @click="toggle(p)">{{ p.is_active ? t('warehouse_management.counts.pause') : t('warehouse_management.counts.resume') }}</VBtn>
              </template>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog :model-value="dialog !== null" max-width="640" @update:model-value="v => !v && (dialog = null)">
      <VCard :title="dialog === 'plan' ? (editing ? t('warehouse_management.counts.edit_plan') : t('warehouse_management.counts.new_plan')) : t('warehouse_management.counts.new')">
        <VCardText class="d-flex flex-column gap-3">
          <template v-if="dialog === 'plan'">
            <VTextField v-model="form.name" :label="t('warehouse_management.counts.plan_name')" :error-messages="form.errors.name" />
            <VSelect v-model="form.frequency" :items="frequencies.map(f => ({ value: f, title: t(`warehouse_management.count_frequency.${f}`) }))" :label="t('warehouse_management.counts.frequency')" />
          </template>
          <VTextField v-else v-model="form.reason" :label="t('warehouse_management.counts.why')" :hint="t('warehouse_management.counts.why_hint')" />
          <VSelect v-model="form.warehouse" :items="warehouses" item-value="id" :label="t('warehouse_management.common.warehouse')" :error-messages="form.errors.warehouse" />
          <VSelect v-model="form.scope" :items="scopes.map(s => ({ value: s, title: t(`warehouse_management.count_scope.${s}`) }))" :label="t('warehouse_management.counts.scope')"
                   :hint="t(`warehouse_management.count_scope_hint.${form.scope}`)" persistent-hint />
          <VAutocomplete v-if="pickable" v-model="form.scope_refs" :items="refOptions" item-value="id" multiple chips closable-chips
                         :label="t(`warehouse_management.count_scope.${form.scope}`)" @update:search="q => form.scope === 'ITEMS' && loadRefs(q)" />
          <VCombobox v-if="form.scope === 'LOTS'" v-model="form.scope_refs" multiple chips closable-chips :label="t('warehouse_management.counts.lot_numbers')" />
          <VSelect v-if="form.scope === 'ABC'" v-model="form.abc_class" :items="['A', 'B', 'C']" :label="t('warehouse_management.counts.abc_class')" />
          <VTextField v-if="form.scope === 'VALUE'" v-model.number="form.min_value" type="number" :label="t('warehouse_management.counts.min_value')" />
          <VTextField v-model.number="form.max_lines" type="number" clearable :label="t('warehouse_management.counts.max_lines')" :hint="t('warehouse_management.counts.max_lines_hint')" persistent-hint />
          <div class="d-flex gap-4 flex-wrap">
            <VSwitch v-model="form.blind" :label="t('warehouse_management.counts.blind')" hide-details color="primary" />
            <VSwitch v-model="form.freeze" :label="t('warehouse_management.counts.freeze')" hide-details color="primary" />
          </div>
          <div class="text-caption text-medium-emphasis">{{ t('warehouse_management.counts.thresholds_hint') }}</div>
          <div class="d-flex gap-2">
            <VTextField v-model.number="form.recount_qty" type="number" clearable :label="t('warehouse_management.counts.recount_qty')" />
            <VTextField v-model.number="form.recount_pct" type="number" clearable :label="t('warehouse_management.counts.recount_pct')" />
            <VTextField v-model.number="form.recount_value" type="number" clearable :label="t('warehouse_management.counts.recount_value')" />
          </div>
          <VTextField v-model.number="form.deadline_days" type="number" :label="t('warehouse_management.counts.deadline_days')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = null">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ dialog === 'plan' ? t('warehouse_management.common.save') : t('warehouse_management.counts.open') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
