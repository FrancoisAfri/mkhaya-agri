<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type RuleRow = { id: number; event: string; owner: string; treatment: string; description: string; debit: string | null; credit: string | null; approved: boolean; approved_at: string | null; suspended: boolean; suspended_reason: string | null }
type Issue = { id: string; kind: string; severity: string; message: string; amount: number; status: string; resolution: string | null; seen: string | null }
type Landed = { id: string; number: string; status: string; total: number; basis: string; invoice_reference: string | null; to_inventory: number; to_cogs: number; components: Array<{ kind: string; amount: number }>; grn: string | null }
type Reval = { id: string; number: string; item: string; qty: number; old_cost: number; new_cost: number; delta: number; reason: string; status: string; by: string | null; mine: boolean; decision_note: string | null }
const props = defineProps<{ rules: RuleRow[]; issues: Issue[]; valuation: Array<{ account: string; value: number }>; landed: Landed[]; revaluations: Reval[];
  grns: Array<{ id: number; title: string }>; kinds: string[]; filters: { issues: string }; can: { approve: boolean } }>()

const tab = ref(props.issues.length ? 'reconcile' : 'rules')
const money = (v: number) => v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const act = (url: string, body: Record<string, unknown> = {}) => router.post(url, body, { preserveScroll: true })

const suspending = ref<RuleRow | null>(null)
const reason = ref('')
const resolving = ref<Issue | null>(null)
const resolve = useForm({ status: 'RESOLVED', resolution: '' })

const landedDialog = ref(false)
const landed = useForm({ grn_id: null as number | null, basis: 'VALUE', invoice_reference: '', components: [{ kind: 'FREIGHT', description: '', amount: null as number | null }], lines: [] as Array<{ item: string; title: string; qty: number }> })
const items = ref<Array<{ id: string; title: string }>>([])
const pick = ref<string | null>(null)
async function searchItems(q: string) { items.value = (await axios.get(route('warehouse.products.search'), { params: { q } })).data }
function addItem() { const o = items.value.find(x => x.id === pick.value); if (o) landed.lines.push({ item: o.id, title: o.title, qty: 1 }); pick.value = null }

const revalDialog = ref(false)
const reval = useForm({ item: null as string | null, new_cost: null as number | null, reason: '' })
const deciding = ref<Reval | null>(null)
const decision = useForm({ decision: 'approve', note: '' })
const sevColor = (s: string) => ({ HIGH: 'error', MEDIUM: 'warning', LOW: 'info' } as Record<string, string>)[s]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.accounting.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.accounting.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" :href="route('warehouse.accounting.costs')">{{ t('warehouse_management.cards.stock_costs.title') }}</VBtn>
        <VBtn color="primary" prepend-icon="tabler-scale" @click="act(route('warehouse.accounting.reconcile'))">{{ t('warehouse_management.accounting.reconcile_now') }}</VBtn>
      </div>
    </div>
    <RefusalAlert />
    <div class="d-flex gap-3 mb-4 flex-wrap">
      <VCard v-for="v in valuation" :key="v.account" border class="pa-3" min-width="180"><div class="text-caption">{{ t('warehouse_management.accounting.stock_in', { account: v.account }) }}</div><div class="text-h5">{{ money(v.value) }}</div></VCard>
    </div>

    <VTabs v-model="tab" class="mb-3">
      <VTab value="reconcile">{{ t('warehouse_management.accounting.tab_reconcile') }} <VChip v-if="issues.length && filters.issues === 'OPEN'" size="x-small" color="error" class="ms-1">{{ issues.length }}</VChip></VTab>
      <VTab value="rules">{{ t('warehouse_management.accounting.tab_rules') }}</VTab>
      <VTab value="landed">{{ t('warehouse_management.accounting.tab_landed') }}</VTab>
      <VTab value="reval">{{ t('warehouse_management.accounting.tab_reval') }}</VTab>
    </VTabs>

    <VCard v-if="tab === 'reconcile'" border>
      <VCardText class="d-flex gap-2">
        <VBtnToggle :model-value="filters.issues" mandatory density="compact" variant="outlined" @update:model-value="v => router.get(route('warehouse.accounting.index'), { issues: v })">
          <VBtn value="OPEN">{{ t('warehouse_management.accounting.open') }}</VBtn><VBtn value="RESOLVED">{{ t('warehouse_management.accounting.resolved') }}</VBtn><VBtn value="ACCEPTED">{{ t('warehouse_management.accounting.accepted') }}</VBtn>
        </VBtnToggle>
      </VCardText>
      <VCardText v-if="!issues.length" class="text-medium-emphasis">{{ t('warehouse_management.accounting.no_issues') }}</VCardText>
      <VList v-else lines="two">
        <VListItem v-for="i in issues" :key="i.id">
          <VListItemTitle class="text-wrap"><VChip size="x-small" :color="sevColor(i.severity)" class="me-1">{{ t(`warehouse_management.issue_kind.${i.kind}`) }}</VChip>{{ i.message }}</VListItemTitle>
          <VListItemSubtitle v-if="i.resolution">{{ i.resolution }}</VListItemSubtitle>
          <template v-if="can.approve && i.status === 'OPEN'" #append><VBtn size="small" variant="text" @click="resolving = i; resolve.reset()">{{ t('warehouse_management.accounting.close_issue') }}</VBtn></template>
        </VListItem>
      </VList>
    </VCard>

    <VCard v-if="tab === 'rules'" border>
      <VCardText class="text-body-2 text-medium-emphasis">{{ t('warehouse_management.accounting.rules_hint') }}</VCardText>
      <div class="overflow-x-auto">
        <VTable density="comfortable">
          <thead><tr><th>{{ t('warehouse_management.accounting.event') }}</th><th>{{ t('warehouse_management.accounting.owner') }}</th><th>{{ t('warehouse_management.accounting.treatment') }}</th>
            <th>{{ t('warehouse_management.accounting.debit') }}</th><th>{{ t('warehouse_management.accounting.credit') }}</th><th /></tr></thead>
          <tbody>
            <tr v-for="r in rules" :key="r.id">
              <td class="font-weight-medium">{{ t(`warehouse_management.accounting_event.${r.event}`) }}<div class="text-caption text-medium-emphasis" style="max-width: 420px">{{ r.description }}</div></td>
              <td>{{ t(`warehouse_management.accounting_owner.${r.owner}`) }}</td>
              <td><VChip size="small" variant="tonal">{{ r.treatment }}</VChip>
                <div v-if="r.suspended" class="text-caption text-error">{{ t('warehouse_management.accounting.suspended') }}: {{ r.suspended_reason }}</div>
                <div v-else-if="r.treatment === 'POST' && !r.approved" class="text-caption text-warning">{{ t('warehouse_management.accounting.not_approved') }}</div></td>
              <td>{{ r.debit === 'INVENTORY' ? t('warehouse_management.accounting.item_inventory') : (r.debit ?? '—') }}</td>
              <td>{{ r.credit === 'INVENTORY' ? t('warehouse_management.accounting.item_inventory') : (r.credit ?? '—') }}</td>
              <td class="text-end text-no-wrap">
                <template v-if="can.approve && r.owner === 'WAREHOUSE' && r.treatment === 'POST'">
                  <VBtn v-if="!r.approved || r.suspended" size="small" color="success" variant="tonal" @click="act(route('warehouse.accounting.rules.act', r.id), { action: 'approve' })">{{ t('warehouse_management.adjustments.approve') }}</VBtn>
                  <VBtn v-else size="small" variant="text" color="error" @click="suspending = r; reason = ''">{{ t('warehouse_management.accounting.suspend') }}</VBtn>
                </template>
              </td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCard>

    <VCard v-if="tab === 'landed'" border>
      <VCardText class="d-flex justify-space-between align-center"><span class="text-body-2 text-medium-emphasis">{{ t('warehouse_management.accounting.landed_hint') }}</span>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="landedDialog = true">{{ t('warehouse_management.accounting.new_landed') }}</VBtn></VCardText>
      <VTable density="comfortable">
        <tbody>
          <tr v-if="!landed.length"><td class="text-medium-emphasis">{{ t('warehouse_management.accounting.no_landed') }}</td></tr>
          <tr v-for="l in landed" :key="l.id">
            <td class="font-weight-medium">{{ l.number }}<div class="text-caption">{{ l.grn ?? '' }} {{ l.invoice_reference ?? '' }}</div></td>
            <td>{{ l.components.map(c => `${t(`warehouse_management.landed_kind.${c.kind}`)} ${money(Number(c.amount))}`).join(', ') }}</td>
            <td class="text-end">{{ money(l.total) }}<div v-if="l.status === 'POSTED'" class="text-caption">{{ t('warehouse_management.accounting.split', { inv: money(l.to_inventory), cogs: money(l.to_cogs) }) }}</div></td>
            <td><VChip size="small" variant="tonal" :color="l.status === 'POSTED' ? 'success' : 'secondary'">{{ t(`warehouse_management.landed_status.${l.status}`) }}</VChip></td>
            <td class="text-end"><VBtn v-if="can.approve && l.status === 'DRAFT'" size="small" color="primary" variant="tonal" @click="act(route('warehouse.accounting.landed.post', l.id))">{{ t('warehouse_management.accounting.post') }}</VBtn></td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VCard v-if="tab === 'reval'" border>
      <VCardText class="d-flex justify-space-between align-center"><span class="text-body-2 text-medium-emphasis">{{ t('warehouse_management.accounting.reval_hint') }}</span>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="revalDialog = true">{{ t('warehouse_management.accounting.new_reval') }}</VBtn></VCardText>
      <VTable density="comfortable">
        <tbody>
          <tr v-if="!revaluations.length"><td class="text-medium-emphasis">{{ t('warehouse_management.accounting.no_reval') }}</td></tr>
          <tr v-for="r in revaluations" :key="r.id">
            <td class="font-weight-medium">{{ r.number }}<div class="text-caption">{{ r.item }} · {{ r.by }}</div></td>
            <td>{{ r.old_cost }} → {{ r.new_cost }} × {{ r.qty }}<div class="text-caption">{{ r.reason }}</div></td>
            <td class="text-end" :class="r.delta < 0 ? 'text-error' : 'text-success'">{{ money(r.delta) }}</td>
            <td><VChip size="small" variant="tonal">{{ t(`warehouse_management.adjustment_status.${r.status}`) }}</VChip></td>
            <td class="text-end"><VBtn v-if="can.approve && r.status === 'PENDING_APPROVAL' && !r.mine" size="small" color="primary" variant="tonal" @click="deciding = r; decision.reset()">{{ t('warehouse_management.quality.decide') }}</VBtn></td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog :model-value="suspending !== null" max-width="440" @update:model-value="v => !v && (suspending = null)">
      <VCard v-if="suspending" :title="t('warehouse_management.accounting.suspend')"><VCardText><VTextarea v-model="reason" rows="2" :label="t('warehouse_management.common.reason')" /></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="suspending = null">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="error" @click="act(route('warehouse.accounting.rules.act', suspending.id), { action: 'suspend', reason }); suspending = null">{{ t('warehouse_management.common.confirm') }}</VBtn></VCardActions></VCard>
    </VDialog>
    <VDialog :model-value="resolving !== null" max-width="480" @update:model-value="v => !v && (resolving = null)">
      <VCard v-if="resolving" :title="t('warehouse_management.accounting.close_issue')">
        <VCardText class="d-flex flex-column gap-3"><p class="text-body-2 mb-0">{{ resolving.message }}</p>
          <VRadioGroup v-model="resolve.status" inline hide-details><VRadio value="RESOLVED" :label="t('warehouse_management.accounting.resolved')" /><VRadio value="ACCEPTED" :label="t('warehouse_management.accounting.accepted')" /></VRadioGroup>
          <VTextarea v-model="resolve.resolution" rows="2" :label="t('warehouse_management.accounting.what_done')" :error-messages="resolve.errors.resolution" /></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="resolving = null">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" @click="resolve.post(route('warehouse.accounting.issues.resolve', resolving.id), { preserveScroll: true, onSuccess: () => resolving = null })">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions></VCard>
    </VDialog>
    <VDialog v-model="landedDialog" max-width="640">
      <VCard :title="t('warehouse_management.accounting.new_landed')">
        <VCardText class="d-flex flex-column gap-3">
          <VSelect v-model="landed.grn_id" :items="grns" item-value="id" clearable :label="t('warehouse_management.accounting.grn')" :hint="t('warehouse_management.accounting.grn_hint')" persistent-hint />
          <div v-if="!landed.grn_id" class="d-flex gap-2 align-center">
            <VAutocomplete v-model="pick" :items="items" item-value="id" hide-details :label="t('warehouse_management.counts.item')" @update:search="searchItems" />
            <VBtn variant="tonal" @click="addItem">{{ t('warehouse_management.common.add') }}</VBtn></div>
          <div v-for="(l, i) in landed.lines" :key="i" class="d-flex gap-2 align-center"><span class="flex-grow-1">{{ l.title }}</span>
            <VTextField v-model.number="l.qty" type="number" density="compact" hide-details style="max-width: 110px" /></div>
          <VSelect v-model="landed.basis" :items="['VALUE', 'QTY', 'WEIGHT'].map(b => ({ value: b, title: t(`warehouse_management.landed_basis.${b}`) }))" :label="t('warehouse_management.accounting.basis')" />
          <VTextField v-model="landed.invoice_reference" :label="t('warehouse_management.accounting.invoice_ref')" />
          <div v-for="(c, i) in landed.components" :key="`c${i}`" class="d-flex gap-2">
            <VSelect v-model="c.kind" :items="kinds.map(k => ({ value: k, title: t(`warehouse_management.landed_kind.${k}`) }))" density="compact" hide-details style="max-width: 170px" />
            <VTextField v-model="c.description" density="compact" hide-details :label="t('warehouse_management.accounting.description')" />
            <VTextField v-model.number="c.amount" type="number" density="compact" hide-details style="max-width: 130px" :label="t('warehouse_management.returns_page.amount')" /></div>
          <VBtn variant="text" prepend-icon="tabler-plus" class="align-self-start" @click="landed.components.push({ kind: 'DUTY', description: '', amount: null })">{{ t('warehouse_management.accounting.add_cost') }}</VBtn>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="landedDialog = false">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="landed.processing" @click="landed.post(route('warehouse.accounting.landed.store'), { preserveScroll: true, onSuccess: () => { landedDialog = false; landed.reset() } })">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
    <VDialog v-model="revalDialog" max-width="480">
      <VCard :title="t('warehouse_management.accounting.new_reval')">
        <VCardText class="d-flex flex-column gap-3">
          <VAutocomplete v-model="reval.item" :items="items" item-value="id" :label="t('warehouse_management.counts.item')" @update:search="searchItems" />
          <VTextField v-model.number="reval.new_cost" type="number" min="0" :label="t('warehouse_management.accounting.new_cost')" />
          <VTextarea v-model="reval.reason" rows="2" :label="t('warehouse_management.common.reason')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="revalDialog = false">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="reval.processing" @click="reval.post(route('warehouse.accounting.revaluations.store'), { preserveScroll: true, onSuccess: () => { revalDialog = false; reval.reset() } })">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
    <VDialog :model-value="deciding !== null" max-width="440" @update:model-value="v => !v && (deciding = null)">
      <VCard v-if="deciding" :title="deciding.number">
        <VCardText class="d-flex flex-column gap-3"><p class="text-body-2 mb-0">{{ deciding.item }}: {{ deciding.old_cost }} → {{ deciding.new_cost }} ({{ money(deciding.delta) }})</p>
          <VRadioGroup v-model="decision.decision" inline hide-details><VRadio value="approve" :label="t('warehouse_management.adjustments.approve')" /><VRadio value="reject" :label="t('warehouse_management.adjustments.reject')" /></VRadioGroup>
          <VTextarea v-model="decision.note" rows="2" :label="t('warehouse_management.counts.investigation')" /></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="deciding = null">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" @click="decision.post(route('warehouse.accounting.revaluations.decide', deciding.id), { preserveScroll: true, onSuccess: () => deciding = null })">{{ t('warehouse_management.common.confirm') }}</VBtn></VCardActions></VCard>
    </VDialog>
  </div>
</template>
