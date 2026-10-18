<script setup lang="ts">
import { ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Task = { id: string; number: string; status: string; priority: number; reasons: string[]; warehouse: string; item: string; name: string; uom: string; from: string | null; to: string | null; qty: number; done: number; transfer: string | null; exception: string | null }
type Rule = { id: string; item: string; item_id: string; warehouse: string; warehouse_id: string; pick_bin: string | null; pick_bin_id: string | null; source: string; source_warehouse: string | null; min_qty: number; max_qty: number; multiple: number | null; is_active: boolean }
type Suggestion = { item_id: string; code: string; name: string; uom: string; available: number; on_order: number; backordered: number; position: number; daily_demand: number; lead_time_days: number; safety_stock: number; reorder_point: number; suggested_qty: number; days_of_cover: number | null }

const props = defineProps<{
  tasks: Task[]
  rules: Rule[]
  suggestions: Suggestion[]
  warehouses: Array<{ id: string; title: string }>
  view: string
  can: { rules: boolean; move: boolean; request: boolean }
}>()

const tab = ref('tasks')
const fmt = (n: number) => new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(n)

// ── Tasks ───────────────────────────────────────────────────────────────────
const doing = ref<Task | null>(null)
const taskForm = useForm({ action: 'complete', qty: 0, item_scan: '', to_scan: '', reason: '' })
function openTask(tk: Task, action: string) {
  doing.value = tk
  taskForm.reset()
  taskForm.action = action
  taskForm.qty = tk.qty - tk.done
}
function submitTask() {
  if (!doing.value) return
  taskForm.post(route('warehouse.floor.replenishment.tasks.act', doing.value.id), { preserveScroll: true, onSuccess: () => { doing.value = null } })
}

// ── Rules ───────────────────────────────────────────────────────────────────
const ruleDialog = ref(false)
const editing = ref<Rule | null>(null)
const ruleForm = useForm({ warehouse: null as string | null, item: null as string | null, source: 'BULK', pick_bin: null as string | null,
  source_warehouse: null as string | null, min_qty: 0, max_qty: 10, multiple: null as number | null, is_active: true })
const itemOptions = ref<Array<{ id: string; title: string }>>([])
const bins = ref<Array<{ id: string; title: string }>>([])
async function searchItems(q: string) {
  const { data } = await axios.get(route('warehouse.products.search'), { params: { q } })
  itemOptions.value = data
}
watch(() => ruleForm.warehouse, async (w) => {
  bins.value = w ? (await axios.get(route('warehouse.floor.replenishment.bins'), { params: { warehouse: w } })).data : []
})
function openRule(r: Rule | null) {
  editing.value = r
  ruleForm.reset()
  if (r) {
    Object.assign(ruleForm, { warehouse: r.warehouse_id, item: r.item_id, source: r.source, pick_bin: r.pick_bin_id, min_qty: r.min_qty, max_qty: r.max_qty, multiple: r.multiple, is_active: r.is_active })
    itemOptions.value = [{ id: r.item_id, title: r.item }]
  }
  ruleDialog.value = true
}
function saveRule() {
  const done = { preserveScroll: true, onSuccess: () => { ruleDialog.value = false } }
  if (editing.value) ruleForm.patch(route('warehouse.floor.replenishment.rules.update', editing.value.id), done)
  else ruleForm.post(route('warehouse.floor.replenishment.rules.store'), done)
}

// ── Reorder ─────────────────────────────────────────────────────────────────
const order = ref<Record<string, number>>(Object.fromEntries(props.suggestions.map(s => [s.item_id, s.suggested_qty])))
const selected = ref<string[]>([])
function raise() {
  router.post(route('warehouse.floor.replenishment.request'), { lines: Object.fromEntries(selected.value.map(id => [id, order.value[id]])) }, { preserveScroll: true })
}
const color = (s: string) => ({ OPEN: 'primary', PARTIAL: 'warning', FAILED: 'error', DONE: 'success', CANCELLED: 'secondary' } as Record<string, string>)[s]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.replenishment.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.replenishment.subtitle') }}</p>
      </div>
      <VBtn v-if="can.move" variant="tonal" prepend-icon="tabler-refresh" @click="router.post(route('warehouse.floor.replenishment.generate'), {}, { preserveScroll: true })">{{ t('warehouse_management.replenishment.check_now') }}</VBtn>
    </div>
    <RefusalAlert />
    <VTabs v-model="tab" class="mb-4">
      <VTab value="tasks">{{ t('warehouse_management.replenishment.tab.tasks') }} ({{ tasks.length }})</VTab>
      <VTab value="rules">{{ t('warehouse_management.replenishment.tab.rules') }} ({{ rules.length }})</VTab>
      <VTab value="reorder">{{ t('warehouse_management.replenishment.tab.reorder') }} ({{ suggestions.length }})</VTab>
    </VTabs>
    <VWindow v-model="tab">
      <VWindowItem value="tasks">
        <VCard border>
          <VCardText v-if="!tasks.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.replenishment.no_tasks') }}</VCardText>
          <VTable v-else density="comfortable">
            <thead><tr><th>{{ t('warehouse_management.transfers.priority') }}</th><th>{{ t('warehouse_management.movements.item') }}</th><th>{{ t('warehouse_management.putaway.from') }}</th><th>{{ t('warehouse_management.putaway.to') }}</th>
              <th class="text-end">{{ t('warehouse_management.locations.qty') }}</th><th>{{ t('warehouse_management.locations.status') }}</th><th /></tr></thead>
            <tbody>
              <tr v-for="tk in tasks" :key="tk.id">
                <td><VChip size="small" :color="tk.priority >= 60 ? 'error' : tk.priority >= 35 ? 'warning' : undefined" variant="tonal">{{ tk.priority }}</VChip>
                  <div class="text-caption">{{ tk.reasons.join('; ') }}</div></td>
                <td>{{ tk.item }}<div class="text-caption text-medium-emphasis">{{ tk.name }} · {{ tk.warehouse }}</div></td>
                <td>{{ tk.transfer ? t('warehouse_management.replenishment.via_transfer', { n: tk.transfer }) : (tk.from ?? '—') }}</td>
                <td>{{ tk.to ?? '—' }}</td>
                <td class="text-end">{{ tk.done ? `${tk.done} / ` : '' }}{{ fmt(tk.qty) }} {{ tk.uom }}</td>
                <td><VChip size="small" label variant="tonal" :color="color(tk.status)">{{ t(`warehouse_management.task_status.${tk.status}`) }}</VChip>
                  <div v-if="tk.exception" class="text-caption text-error">{{ tk.exception }}</div></td>
                <td class="text-end text-no-wrap">
                  <template v-if="can.move && !tk.transfer && ['OPEN', 'PARTIAL'].includes(tk.status)">
                    <VBtn size="small" color="primary" variant="tonal" @click="openTask(tk, 'complete')">{{ t('warehouse_management.replenishment.done') }}</VBtn>
                    <VBtn size="small" variant="text" @click="openTask(tk, 'fail')">{{ t('warehouse_management.putaway.cannot') }}</VBtn>
                  </template>
                  <VBtn v-else-if="tk.transfer" size="small" variant="text" :href="route('warehouse.floor.transfers.index')">{{ t('warehouse_management.cards.transfers.title') }}</VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VWindowItem>

      <VWindowItem value="rules">
        <VCard border>
          <VCardTitle class="d-flex justify-space-between align-center">{{ t('warehouse_management.replenishment.tab.rules') }}
            <VBtn v-if="can.rules" size="small" color="primary" prepend-icon="tabler-plus" @click="openRule(null)">{{ t('warehouse_management.common.add') }}</VBtn></VCardTitle>
          <VCardText v-if="!rules.length" class="text-medium-emphasis">{{ t('warehouse_management.replenishment.no_rules') }}</VCardText>
          <VTable v-else density="compact">
            <thead><tr><th>{{ t('warehouse_management.movements.item') }}</th><th>{{ t('warehouse_management.common.warehouse') }}</th><th>{{ t('warehouse_management.replenishment.fills') }}</th><th>{{ t('warehouse_management.replenishment.from_source') }}</th><th class="text-end">{{ t('warehouse_management.replenishment.min') }}</th><th class="text-end">{{ t('warehouse_management.replenishment.max') }}</th><th /></tr></thead>
            <tbody>
              <tr v-for="r in rules" :key="r.id" :class="{ 'opacity-60': !r.is_active }">
                <td>{{ r.item }}</td><td>{{ r.warehouse }}</td><td>{{ r.pick_bin ?? t('warehouse_management.replenishment.whole_warehouse') }}</td>
                <td>{{ r.source === 'BULK' ? t('warehouse_management.replenishment.bulk') : r.source_warehouse }}</td>
                <td class="text-end">{{ r.min_qty }}</td><td class="text-end">{{ r.max_qty }}<span v-if="r.multiple" class="text-caption"> ×{{ r.multiple }}</span></td>
                <td class="text-end"><VBtn v-if="can.rules" icon="tabler-pencil" size="small" variant="text" :aria-label="t('warehouse_management.common.edit')" @click="openRule(r)" /></td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VWindowItem>

      <VWindowItem value="reorder">
        <VCard border>
          <VCardText class="text-body-2 text-medium-emphasis">{{ t('warehouse_management.replenishment.reorder_hint') }}</VCardText>
          <VCardText v-if="!suggestions.length" class="text-center text-medium-emphasis py-6">{{ t('warehouse_management.replenishment.no_suggestions') }}</VCardText>
          <div v-else style="overflow-x: auto">
            <VTable density="compact">
              <thead><tr><th /><th>{{ t('warehouse_management.movements.item') }}</th><th class="text-end">{{ t('warehouse_management.replenishment.position') }}</th><th class="text-end">{{ t('warehouse_management.replenishment.reorder_point') }}</th>
                <th class="text-end">{{ t('warehouse_management.replenishment.daily') }}</th><th class="text-end">{{ t('warehouse_management.replenishment.cover') }}</th><th class="text-end">{{ t('warehouse_management.replenishment.order_qty') }}</th></tr></thead>
              <tbody>
                <tr v-for="s in suggestions" :key="s.item_id">
                  <td><VCheckbox v-model="selected" :value="s.item_id" density="compact" hide-details :aria-label="s.code" /></td>
                  <td>{{ s.code }}<div class="text-caption text-medium-emphasis">{{ t('warehouse_management.replenishment.breakdown', { available: fmt(s.available), on_order: fmt(s.on_order), back: fmt(s.backordered) }) }}</div></td>
                  <td class="text-end">{{ fmt(s.position) }}</td>
                  <td class="text-end">{{ fmt(s.reorder_point) }}<div class="text-caption">{{ t('warehouse_management.replenishment.rp_rule', { safety: fmt(s.safety_stock), lead: s.lead_time_days }) }}</div></td>
                  <td class="text-end">{{ fmt(s.daily_demand) }}</td>
                  <td class="text-end" :class="{ 'text-error': s.days_of_cover !== null && s.days_of_cover < s.lead_time_days }">{{ s.days_of_cover ?? '—' }}</td>
                  <td class="text-end"><VTextField v-model.number="order[s.item_id]" type="number" min="0" density="compact" hide-details style="max-width: 110px; margin-left: auto" /></td>
                </tr>
              </tbody>
            </VTable>
          </div>
          <VCardActions v-if="can.request && suggestions.length"><VSpacer /><VBtn color="primary" :disabled="!selected.length" @click="raise">{{ t('warehouse_management.replenishment.raise', { n: selected.length }) }}</VBtn></VCardActions>
        </VCard>
      </VWindowItem>
    </VWindow>

    <VDialog :model-value="!!doing" max-width="460" @update:model-value="v => { if (!v) doing = null }">
      <VCard v-if="doing">
        <VCardTitle>{{ doing.number }} · {{ doing.item }}</VCardTitle>
        <VCardText>
          <template v-if="taskForm.action === 'complete'">
            <p class="text-body-2">{{ doing.from }} → {{ doing.to }}</p>
            <VTextField v-model.number="taskForm.qty" type="number" min="0" :label="t('warehouse_management.locations.qty')" />
            <VTextField v-model="taskForm.item_scan" prepend-inner-icon="tabler-scan" :label="t('warehouse_management.putaway.scan_item')" />
            <VTextField v-model="taskForm.to_scan" prepend-inner-icon="tabler-scan" :label="t('warehouse_management.putaway.scan_bin')" />
          </template>
          <VTextarea v-else v-model="taskForm.reason" rows="2" auto-grow :label="t('warehouse_management.common.reason')" />
          <VAlert v-if="(taskForm.errors as any).refused" type="error" variant="tonal">{{ (taskForm.errors as any).refused }}</VAlert>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="doing = null">{{ t('warehouse_management.common.cancel') }}</VBtn><VBtn color="primary" :loading="taskForm.processing" @click="submitTask">{{ t('warehouse_management.common.confirm') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="ruleDialog" max-width="560">
      <VCard>
        <VCardTitle>{{ editing ? t('warehouse_management.common.edit') : t('warehouse_management.common.add') }}</VCardTitle>
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6"><VSelect v-model="ruleForm.warehouse" :items="warehouses" item-title="title" item-value="id" :label="t('warehouse_management.common.warehouse')" /></VCol>
            <VCol cols="12" md="6"><VAutocomplete v-model="ruleForm.item" :items="itemOptions" item-title="title" item-value="id" :label="t('warehouse_management.movements.item')" @update:search="searchItems" /></VCol>
            <VCol cols="12"><VRadioGroup v-model="ruleForm.source" inline>
              <VRadio value="BULK" :label="t('warehouse_management.replenishment.source_bulk')" />
              <VRadio value="WAREHOUSE" :label="t('warehouse_management.replenishment.source_warehouse')" />
            </VRadioGroup></VCol>
            <VCol v-if="ruleForm.source === 'BULK'" cols="12"><VSelect v-model="ruleForm.pick_bin" :items="bins" item-title="title" item-value="id" :label="t('warehouse_management.replenishment.pick_bin')" /></VCol>
            <VCol v-else cols="12"><VSelect v-model="ruleForm.source_warehouse" :items="warehouses.filter(w => w.id !== ruleForm.warehouse)" item-title="title" item-value="id" :label="t('warehouse_management.replenishment.source_wh')" /></VCol>
            <VCol cols="4"><VTextField v-model.number="ruleForm.min_qty" type="number" min="0" :label="t('warehouse_management.replenishment.min')" /></VCol>
            <VCol cols="4"><VTextField v-model.number="ruleForm.max_qty" type="number" min="0" :label="t('warehouse_management.replenishment.max')" :error-messages="ruleForm.errors.max_qty" /></VCol>
            <VCol cols="4"><VTextField v-model.number="ruleForm.multiple" type="number" min="0" :label="t('warehouse_management.replenishment.multiple')" /></VCol>
            <VCol cols="12"><VSwitch v-model="ruleForm.is_active" color="primary" density="compact" :label="t('warehouse_management.reasons.active')" /></VCol>
          </VRow>
          <VAlert v-if="(ruleForm.errors as any).refused" type="error" variant="tonal">{{ (ruleForm.errors as any).refused }}</VAlert>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="ruleDialog = false">{{ t('warehouse_management.common.cancel') }}</VBtn><VBtn color="primary" :loading="ruleForm.processing" @click="saveRule">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
