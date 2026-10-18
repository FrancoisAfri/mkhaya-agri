<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RecordDocuments from '@/Modules/DocumentManagement/Components/RecordDocuments.vue'
import { inspectionStatusColor, resultColor } from './colors'

const { t } = useI18n()
const route = ziggyRoute

type Check = { id: string; name: string; kind: string; uom: string | null; target: number | null; lower_limit: number | null; upper_limit: number | null; is_critical: boolean; method: string | null }
type Result = { id: number; sample_no: number; name: string; kind: string; uom: string | null; lower_limit: number | null; upper_limit: number | null; measured_value: number | null; passed: boolean; is_critical: boolean; notes: string | null }

const props = defineProps<{
  inspection: {
    id: string; number: string; stage: string; status: string; result: string; disposition: string | null
    item_code: string | null; item_name: string | null; lot: string | null; qty: number; sample_size: number | null
    plan_code: string | null; order: { id: string; number: string } | null; created_at: string | null
    plan: { id: string; code: string; name: string; requires_certificate: boolean; blocks_production: boolean } | null
    checks: Check[]; results: Result[]; warehouse: string | null; held_status: string | null; operation: string | null
    order_status: string | null; supplier: string | null; purchase_order: string | null; receipt_reference: string | null
    ncr: { id: string; number: string; status: string } | null; disposition_reason: string | null
    disposed_at: string | null; inspected_at: string | null; notes: string | null
  }
  dispositions: string[]
  documents_panel: {
    available: boolean; relation: string | null; record: string | number | null; can_upload: boolean; count: number
    documents: Array<{ uuid: string; title: string; category: string | null; expiry_date: string | null; is_expired: boolean; uploaded_at: string | null }>
  }
  can: { record: boolean; dispose: boolean }
}>()

const busy = ref(false)
const samples = computed(() => Math.max(1, props.inspection.sample_size ?? 1))

// ── Recording against the plan, or ad hoc where there is none ───────────────
type Entry = { characteristic: string | null; name: string; sample_no: number; value: number | null; passed: boolean | null; notes: string; check: Check | null }
const entries = ref<Entry[]>(props.inspection.checks.length
  ? props.inspection.checks.flatMap(c => Array.from({ length: samples.value }, (_, i) => ({
      characteristic: c.id, name: c.name, sample_no: i + 1, value: null, passed: null, notes: '', check: c,
    })))
  : [{ characteristic: null, name: '', sample_no: 1, value: null, passed: true, notes: '', check: null }])
const overall = ref('')

const limits = (c: { lower_limit: number | null; upper_limit: number | null; uom?: string | null }) =>
  c.lower_limit === null && c.upper_limit === null ? '—'
    : `${c.lower_limit ?? '−∞'} … ${c.upper_limit ?? '∞'}${c.uom ? ` ${c.uom}` : ''}`

/** What the server will decide, shown as the inspector types -- so nobody is surprised by a fail. */
function verdict(e: Entry): boolean | null {
  if (!e.check || e.check.kind === 'ATTRIBUTE') return e.passed
  if (e.value === null || (e.value as unknown) === '') return null
  const lo = e.check.lower_limit
  const hi = e.check.upper_limit
  return (lo === null || e.value >= lo) && (hi === null || e.value <= hi)
}

function addAdHoc() { entries.value.push({ characteristic: null, name: '', sample_no: 1, value: null, passed: true, notes: '', check: null }) }

function submitResults() {
  busy.value = true
  router.post(route('manufacturing.quality.inspections.record', props.inspection.id), {
    results: entries.value.map(e => ({
      characteristic: e.characteristic, name: e.name || null, sample_no: e.sample_no,
      value: e.value === null || (e.value as unknown) === '' ? null : e.value,
      passed: e.check?.kind === 'MEASURED' ? null : e.passed, notes: e.notes || null,
    })),
    notes: overall.value || null,
  }, { preserveScroll: true, onFinish: () => { busy.value = false } })
}

// ── Decision ────────────────────────────────────────────────────────────────
const deciding = ref<string | null>(null)
const reason = ref('')
const decisionColor = (d: string) => ({ ACCEPT: 'success', CONDITIONAL_ACCEPT: 'warning', REJECT: 'error', SCRAP: 'error', RETURN_TO_SUPPLIER: 'error', REWORK: 'orange' } as Record<string, string>)[d] ?? 'primary'
function submitDecision() {
  busy.value = true
  router.post(route('manufacturing.quality.inspections.dispose', props.inspection.id), { decision: deciding.value, reason: reason.value || null }, {
    preserveScroll: true, onSuccess: () => { deciding.value = null; reason.value = '' }, onFinish: () => { busy.value = false },
  })
}
const when = (s: string | null) => s ? new Date(s).toLocaleString() : '—'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-start justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <VBtn variant="text" size="small" prepend-icon="tabler-arrow-left" class="mb-1 px-0"
              :href="route('manufacturing.quality.inspections.index')">{{ t('manufacturing.quality.inspections_title') }}</VBtn>
        <h4 class="text-h4">{{ inspection.number }}</h4>
        <div class="text-body-2 text-medium-emphasis">
          {{ t(`manufacturing.quality.stage.${inspection.stage}`) }} · {{ inspection.item_code }} — {{ inspection.item_name }}
        </div>
        <div class="d-flex gap-2 mt-2 flex-wrap">
          <VChip size="small" label variant="tonal" :color="inspectionStatusColor(inspection.status)">{{ t(`manufacturing.quality.status.${inspection.status}`) }}</VChip>
          <VChip size="small" label variant="tonal" :color="resultColor(inspection.result)">{{ t(`manufacturing.quality.result_${inspection.result}`) }}</VChip>
          <VChip v-if="inspection.disposition" size="small" label :color="decisionColor(inspection.disposition)">{{ t(`manufacturing.quality.disposition.${inspection.disposition}`) }}</VChip>
        </div>
      </div>
    </div>

    <VAlert v-if="inspection.held_status && inspection.status !== 'CLOSED'" type="warning" variant="tonal" class="mb-4">
      {{ t('manufacturing.quality.held_alert', { qty: inspection.qty, status: t(`manufacturing.quality.stock_status.${inspection.held_status}`) }) }}
    </VAlert>
    <VAlert v-if="inspection.result === 'FAILED' && inspection.status !== 'CLOSED' && inspection.stage === 'IN_PROCESS' && inspection.plan?.blocks_production !== false"
            type="error" variant="tonal" class="mb-4">{{ t('manufacturing.quality.blocking_alert') }}</VAlert>

    <VRow>
      <VCol cols="12" md="8">
        <!-- Record -->
        <VCard v-if="can.record" border class="mb-4">
          <VCardItem>
            <VCardTitle>{{ t('manufacturing.quality.record_title') }}</VCardTitle>
            <VCardSubtitle>{{ inspection.plan ? t('manufacturing.quality.record_hint_plan', { n: samples }) : t('manufacturing.quality.record_hint_adhoc') }}</VCardSubtitle>
          </VCardItem>
          <VTable density="compact">
            <thead>
              <tr>
                <th>{{ t('manufacturing.quality.check') }}</th>
                <th v-if="samples > 1">#</th>
                <th>{{ t('manufacturing.quality.limits') }}</th>
                <th style="min-width: 140px">{{ t('manufacturing.quality.value') }}</th>
                <th>{{ t('manufacturing.quality.result') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(e, i) in entries" :key="i">
                <td>
                  <template v-if="e.check">
                    {{ e.check.name }}
                    <VChip v-if="e.check.is_critical" size="x-small" color="error" label class="ms-1">{{ t('manufacturing.quality.critical') }}</VChip>
                    <div v-if="e.check.method" class="text-caption text-medium-emphasis">{{ e.check.method }}</div>
                  </template>
                  <VTextField v-else v-model="e.name" density="compact" hide-details :placeholder="t('manufacturing.quality.check_name')" />
                </td>
                <td v-if="samples > 1">{{ e.sample_no }}</td>
                <td class="text-no-wrap">{{ e.check ? limits(e.check) : '—' }}</td>
                <td>
                  <VTextField v-if="!e.check || e.check.kind === 'MEASURED'" v-model.number="e.value" type="number" step="any" density="compact" hide-details
                              :suffix="e.check?.uom ?? ''" />
                  <span v-else class="text-medium-emphasis">—</span>
                </td>
                <td>
                  <VBtnToggle v-if="!e.check || e.check.kind === 'ATTRIBUTE'" v-model="e.passed" density="compact" divided variant="outlined">
                    <VBtn :value="true" size="small" color="success">{{ t('manufacturing.quality.pass') }}</VBtn>
                    <VBtn :value="false" size="small" color="error">{{ t('manufacturing.quality.fail') }}</VBtn>
                  </VBtnToggle>
                  <VChip v-else-if="verdict(e) !== null" size="small" label :color="verdict(e) ? 'success' : 'error'">
                    {{ verdict(e) ? t('manufacturing.quality.pass') : t('manufacturing.quality.fail') }}
                  </VChip>
                </td>
              </tr>
            </tbody>
          </VTable>
          <VCardText>
            <VBtn v-if="!inspection.plan" size="small" variant="text" prepend-icon="tabler-plus" class="mb-2" @click="addAdHoc">{{ t('manufacturing.quality.add_check') }}</VBtn>
            <VTextarea v-model="overall" rows="2" auto-grow :label="t('manufacturing.orders.notes')" />
          </VCardText>
          <VCardActions>
            <VSpacer />
            <VBtn color="primary" :loading="busy" @click="submitResults">{{ t('manufacturing.quality.save_results') }}</VBtn>
          </VCardActions>
        </VCard>

        <!-- Results -->
        <VCard v-if="inspection.results.length" border class="mb-4">
          <VCardItem><VCardTitle>{{ t('manufacturing.quality.results') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.quality.inspected_at', { at: when(inspection.inspected_at) }) }}</VCardSubtitle></VCardItem>
          <VTable density="compact">
            <thead><tr><th>{{ t('manufacturing.quality.check') }}</th><th>#</th><th>{{ t('manufacturing.quality.limits') }}</th><th class="text-end">{{ t('manufacturing.quality.value') }}</th><th>{{ t('manufacturing.quality.result') }}</th></tr></thead>
            <tbody>
              <tr v-for="r in inspection.results" :key="r.id">
                <td>{{ r.name }} <VChip v-if="r.is_critical" size="x-small" color="error" label class="ms-1">{{ t('manufacturing.quality.critical') }}</VChip></td>
                <td>{{ r.sample_no }}</td>
                <td class="text-no-wrap">{{ limits(r) }}</td>
                <td class="text-end">{{ r.measured_value ?? '—' }} {{ r.measured_value !== null ? (r.uom ?? '') : '' }}</td>
                <td><VChip size="x-small" label :color="r.passed ? 'success' : 'error'">{{ r.passed ? t('manufacturing.quality.pass') : t('manufacturing.quality.fail') }}</VChip></td>
              </tr>
            </tbody>
          </VTable>
        </VCard>

        <!-- Decide -->
        <VCard v-if="can.dispose" border class="mb-4">
          <VCardItem><VCardTitle>{{ t('manufacturing.quality.decide') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.quality.decide_hint') }}</VCardSubtitle></VCardItem>
          <VCardText class="d-flex gap-2 flex-wrap">
            <VBtn v-for="d in dispositions" :key="d" :color="decisionColor(d)" variant="tonal" @click="deciding = d">{{ t(`manufacturing.quality.disposition.${d}`) }}</VBtn>
          </VCardText>
          <VCardText v-if="inspection.plan?.requires_certificate" class="pt-0 text-caption text-medium-emphasis">{{ t('manufacturing.quality.certificate_required') }}</VCardText>
        </VCard>

        <VCard v-if="inspection.disposition" border class="mb-4">
          <VCardText>
            <div class="text-subtitle-2">{{ t(`manufacturing.quality.disposition.${inspection.disposition}`) }} · {{ when(inspection.disposed_at) }}</div>
            <div v-if="inspection.disposition_reason" class="text-body-2">{{ inspection.disposition_reason }}</div>
          </VCardText>
        </VCard>

        <RecordDocuments v-bind="documents_panel" :can-upload="documents_panel.can_upload" :title="t('manufacturing.quality.evidence')" />
      </VCol>

      <VCol cols="12" md="4">
        <VCard border>
          <VList density="compact">
            <VListItem :title="t('manufacturing.quality.plan')" :subtitle="inspection.plan ? `${inspection.plan.code} — ${inspection.plan.name}` : t('manufacturing.quality.no_plan')" />
            <VListItem :title="t('manufacturing.quality.lot')" :subtitle="inspection.lot ?? '—'" />
            <VListItem :title="t('manufacturing.orders.quantity')" :subtitle="String(inspection.qty)" />
            <VListItem v-if="inspection.warehouse" :title="t('manufacturing.quality.warehouse')" :subtitle="inspection.warehouse" />
            <VListItem v-if="inspection.order" :title="t('manufacturing.orders.number')">
              <template #subtitle><a :href="route('manufacturing.production.orders.show', inspection.order.id)">{{ inspection.order.number }}</a>
                <span v-if="inspection.operation"> · {{ inspection.operation }}</span></template>
            </VListItem>
            <VListItem v-if="inspection.supplier" :title="t('manufacturing.quality.supplier')" :subtitle="inspection.supplier" />
            <VListItem v-if="inspection.purchase_order" :title="t('manufacturing.quality.purchase_order')" :subtitle="inspection.purchase_order" />
            <VListItem v-if="inspection.receipt_reference" :title="t('manufacturing.quality.reference')" :subtitle="inspection.receipt_reference" />
            <VListItem v-if="inspection.ncr" :title="t('manufacturing.quality.ncr')">
              <template #subtitle><a :href="route('manufacturing.quality.ncrs.show', inspection.ncr.id)">{{ inspection.ncr.number }}</a> · {{ t(`manufacturing.quality.ncr_status.${inspection.ncr.status}`) }}</template>
            </VListItem>
          </VList>
          <VCardText v-if="inspection.notes" class="text-body-2">{{ inspection.notes }}</VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog :model-value="deciding !== null" max-width="520" @update:model-value="v => { if (!v) deciding = null }">
      <VCard v-if="deciding" :title="t(`manufacturing.quality.disposition.${deciding}`)" :subtitle="t(`manufacturing.quality.disposition_hint.${deciding}`)">
        <VCardText>
          <VTextarea v-model="reason" rows="3" auto-grow autofocus :label="deciding === 'ACCEPT' ? t('manufacturing.quality.comment_optional') : t('manufacturing.orders.reason')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="deciding = null">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn :color="decisionColor(deciding)" :loading="busy" :disabled="deciding !== 'ACCEPT' && !reason.trim()" @click="submitDecision">{{ t('manufacturing.common.confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
