<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PlanForm from './PlanForm.vue'
import type { PlanOptions } from './PlanForm.vue'

const { t } = useI18n()
const route = ziggyRoute

type Characteristic = {
  id: string; sequence: number; name: string; kind: string; uom: string | null; target: number | null
  lower_limit: number | null; upper_limit: number | null; is_critical: boolean; method: string | null
}

const props = defineProps<{
  plan: {
    id: string; code: string; name: string; stage: string; subject_code: string | null; subject_name: string | null
    operation: string | null; supplier_id: number | null; sample_size: number | null; every_qty: number | null
    blocks_production: boolean; requires_certificate: boolean; is_active: boolean; notes: string | null
    item_uuid: string | null; stock_item_id: number | null; routing_operation_id: string | null
    characteristics: Characteristic[]
  }
  stages: string[]
  options: PlanOptions | null
  can: { manage: boolean }
}>()

const header = useForm({
  code: props.plan.code, name: props.plan.name, stage: props.plan.stage, stock_item_id: props.plan.stock_item_id,
  item_uuid: props.plan.item_uuid, supplier_id: props.plan.supplier_id, routing_operation_uuid: props.plan.routing_operation_id,
  sample_size: props.plan.sample_size, every_qty: props.plan.every_qty, blocks_production: props.plan.blocks_production,
  requires_certificate: props.plan.requires_certificate, is_active: props.plan.is_active, notes: props.plan.notes ?? '',
})
function saveHeader() { header.patch(route('manufacturing.quality.plans.update', props.plan.id), { preserveScroll: true }) }

// ── Checks ──────────────────────────────────────────────────────────────────
const blank = () => ({ sequence: null as number | null, name: '', kind: 'MEASURED', uom: '', target: null as number | null,
  lower_limit: null as number | null, upper_limit: null as number | null, is_critical: false, method: '' })
const dialog = ref(false)
const editing = ref<Characteristic | null>(null)
const check = useForm(blank())
function openCreate() { editing.value = null; check.defaults(blank()); check.reset(); check.clearErrors(); dialog.value = true }
function openEdit(c: Characteristic) {
  editing.value = c
  Object.assign(check, { sequence: c.sequence, name: c.name, kind: c.kind, uom: c.uom ?? '', target: c.target,
    lower_limit: c.lower_limit, upper_limit: c.upper_limit, is_critical: c.is_critical, method: c.method ?? '' })
  check.clearErrors()
  dialog.value = true
}
function saveCheck() {
  const done = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  editing.value
    ? check.patch(route('manufacturing.quality.plans.characteristics.update', { uuid: props.plan.id, characteristicUuid: editing.value.id }), done)
    : check.post(route('manufacturing.quality.plans.characteristics.store', props.plan.id), done)
}
function remove(c: Characteristic) {
  router.delete(route('manufacturing.quality.plans.characteristics.destroy', { uuid: props.plan.id, characteristicUuid: c.id }), { preserveScroll: true })
}
const limits = (c: Characteristic) => c.lower_limit === null && c.upper_limit === null ? '—'
  : `${c.lower_limit ?? '−∞'} … ${c.upper_limit ?? '∞'}${c.uom ? ` ${c.uom}` : ''}`
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <VBtn variant="text" size="small" prepend-icon="tabler-arrow-left" class="mb-1 px-0" :href="route('manufacturing.quality.plans.index')">{{ t('manufacturing.quality.plans_title') }}</VBtn>
      <h4 class="text-h4">{{ plan.code }} — {{ plan.name }}</h4>
      <div class="text-body-2 text-medium-emphasis">
        {{ t(`manufacturing.quality.stage.${plan.stage}`) }} · {{ plan.subject_code }} — {{ plan.subject_name }}<template v-if="plan.operation"> · {{ plan.operation }}</template>
      </div>
    </div>

    <VRow>
      <VCol cols="12" lg="7">
        <VCard border>
          <VCardItem>
            <VCardTitle>{{ t('manufacturing.quality.checks') }}</VCardTitle>
            <VCardSubtitle>{{ t('manufacturing.quality.checks_hint') }}</VCardSubtitle>
            <template #append>
              <VBtn v-if="can.manage" size="small" color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('manufacturing.quality.add_check') }}</VBtn>
            </template>
          </VCardItem>
          <VTable>
            <thead><tr><th>#</th><th>{{ t('manufacturing.quality.check') }}</th><th>{{ t('manufacturing.quality.kind_label') }}</th><th>{{ t('manufacturing.quality.limits') }}</th><th v-if="can.manage" /></tr></thead>
            <tbody>
              <tr v-for="c in plan.characteristics" :key="c.id">
                <td>{{ c.sequence }}</td>
                <td>
                  {{ c.name }} <VChip v-if="c.is_critical" size="x-small" color="error" label class="ms-1">{{ t('manufacturing.quality.critical') }}</VChip>
                  <div v-if="c.method" class="text-caption text-medium-emphasis">{{ c.method }}</div>
                </td>
                <td>{{ t(`manufacturing.quality.kind.${c.kind}`) }}</td>
                <td class="text-no-wrap">{{ c.kind === 'MEASURED' ? limits(c) : t('manufacturing.quality.pass_fail') }}<span v-if="c.target !== null" class="text-caption text-medium-emphasis"> · {{ t('manufacturing.quality.target_n', { n: c.target }) }}</span></td>
                <td v-if="can.manage" class="text-end text-no-wrap">
                  <IconBtn size="small" @click="openEdit(c)"><VIcon icon="tabler-pencil" /></IconBtn>
                  <IconBtn size="small" color="error" @click="remove(c)"><VIcon icon="tabler-trash" /></IconBtn>
                </td>
              </tr>
              <tr v-if="!plan.characteristics.length"><td colspan="5" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.quality.no_checks') }}</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>

      <VCol cols="12" lg="5">
        <VCard border>
          <VCardItem><VCardTitle>{{ t('manufacturing.quality.plan_settings') }}</VCardTitle></VCardItem>
          <VCardText v-if="options && can.manage">
            <PlanForm :form="header" :options="options" :stages="stages" />
          </VCardText>
          <VCardText v-else>
            <div>{{ t('manufacturing.quality.sample_size') }}: {{ plan.sample_size ?? 1 }}</div>
            <div v-if="plan.every_qty">{{ t('manufacturing.quality.every_qty') }}: {{ plan.every_qty }}</div>
            <div>{{ t('manufacturing.quality.blocks_production') }}: {{ plan.blocks_production ? '✓' : '—' }}</div>
            <div>{{ t('manufacturing.quality.requires_certificate') }}: {{ plan.requires_certificate ? '✓' : '—' }}</div>
          </VCardText>
          <VCardActions v-if="options && can.manage">
            <VSpacer />
            <VBtn color="primary" :loading="header.processing" @click="saveHeader">{{ t('manufacturing.common.save') }}</VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="dialog" max-width="560">
      <VCard :title="editing ? t('manufacturing.quality.edit_check') : t('manufacturing.quality.add_check')">
        <VCardText>
          <VRow dense>
            <VCol cols="12" sm="8"><VTextField v-model="check.name" :error-messages="check.errors.name" :label="t('manufacturing.quality.check_name')" /></VCol>
            <VCol cols="12" sm="4"><VTextField v-model.number="check.sequence" type="number" min="1" :label="t('manufacturing.routing.sequence')" /></VCol>
            <VCol cols="12" sm="6">
              <VSelect v-model="check.kind" :items="['MEASURED', 'ATTRIBUTE'].map(k => ({ title: t(`manufacturing.quality.kind.${k}`), value: k }))" :label="t('manufacturing.quality.kind_label')" />
            </VCol>
            <template v-if="check.kind === 'MEASURED'">
              <VCol cols="12" sm="6"><VTextField v-model="check.uom" :label="t('manufacturing.quality.uom')" /></VCol>
              <VCol cols="4"><VTextField v-model.number="check.lower_limit" type="number" step="any" :label="t('manufacturing.quality.lower')" /></VCol>
              <VCol cols="4"><VTextField v-model.number="check.target" type="number" step="any" :label="t('manufacturing.quality.target')" /></VCol>
              <VCol cols="4"><VTextField v-model.number="check.upper_limit" type="number" step="any" :error-messages="check.errors.upper_limit" :label="t('manufacturing.quality.upper')" /></VCol>
            </template>
            <VCol cols="12"><VTextField v-model="check.method" :label="t('manufacturing.quality.method')" /></VCol>
            <VCol cols="12"><VCheckbox v-model="check.is_critical" density="compact" hide-details :label="t('manufacturing.quality.critical_hint')" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="check.processing" :disabled="!check.name" @click="saveCheck">{{ t('manufacturing.common.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
