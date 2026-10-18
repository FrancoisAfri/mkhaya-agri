<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Reason = { uuid: string; code: string; name: string; category: string; direction: string; approval_qty: number | null; approval_value: number | null; always_requires_approval: boolean; is_active: boolean; requires_evidence: boolean; approve_high_risk: boolean; used: number }

defineProps<{ reasons: Reason[]; categories: string[]; can: { manage: boolean } }>()

const dialog = ref(false)
const editing = ref<Reason | null>(null)
const form = useForm({ code: '', name: '', category: 'LOSS', direction: 'OUT', approval_qty: null as number | null, approval_value: null as number | null, always_requires_approval: false, is_active: true, requires_evidence: false, approve_high_risk: true })
function open(r: Reason | null) {
  editing.value = r
  form.reset()
  if (r) Object.assign(form, { code: r.code, name: r.name, category: r.category, direction: r.direction, approval_qty: r.approval_qty, approval_value: r.approval_value, always_requires_approval: r.always_requires_approval, is_active: r.is_active, requires_evidence: r.requires_evidence, approve_high_risk: r.approve_high_risk })
  form.clearErrors()
  dialog.value = true
}
function save() {
  const done = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  if (editing.value) form.patch(route('warehouse.stock.reasons.update', editing.value.uuid), done)
  else form.post(route('warehouse.stock.reasons.store'), done)
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.reasons.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.reasons.subtitle') }}</p>
      </div>
      <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="open(null)">{{ t('warehouse_management.common.add') }}</VBtn>
    </div>
    <RefusalAlert />
    <VCard border>
      <VTable density="comfortable">
        <thead>
          <tr><th>{{ t('warehouse_management.common.code') }}</th><th>{{ t('warehouse_management.common.name') }}</th><th>{{ t('warehouse_management.reasons.category') }}</th>
            <th>{{ t('warehouse_management.reasons.direction') }}</th><th>{{ t('warehouse_management.reasons.approval') }}</th><th class="text-end">{{ t('warehouse_management.reasons.used') }}</th><th /></tr>
        </thead>
        <tbody>
          <tr v-for="r in reasons" :key="r.uuid" :class="{ 'opacity-60': !r.is_active }">
            <td style="font-family: ui-monospace, monospace">{{ r.code }}</td>
            <td>{{ r.name }}</td>
            <td>{{ t(`warehouse_management.reason_category.${r.category}`) }}</td>
            <td>{{ t(`warehouse_management.reason_direction.${r.direction}`) }}</td>
            <td class="text-caption">
              <template v-if="r.always_requires_approval">{{ t('warehouse_management.reasons.always') }}</template>
              <div v-if="r.requires_evidence" class="text-caption">{{ t('warehouse_management.reasons.requires_evidence') }}</div>
              <template v-else>
                <div v-if="r.approval_qty !== null">{{ t('warehouse_management.reasons.above_qty', { n: r.approval_qty }) }}</div>
                <div v-if="r.approval_value !== null">{{ t('warehouse_management.reasons.above_value', { n: r.approval_value }) }}</div>
                <div v-if="r.approval_qty === null && r.approval_value === null">{{ t('warehouse_management.reasons.never') }}</div>
              </template>
            </td>
            <td class="text-end">{{ r.used }}</td>
            <td class="text-end"><VBtn v-if="can.manage" icon="tabler-pencil" size="small" variant="text" :aria-label="t('warehouse_management.common.edit')" @click="open(r)" /></td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="560">
      <VCard>
        <VCardTitle>{{ editing ? t('warehouse_management.common.edit') : t('warehouse_management.common.add') }}</VCardTitle>
        <VCardText>
          <VRow dense>
            <VCol cols="5"><VTextField v-model="form.code" :label="t('warehouse_management.common.code')" :hint="t('warehouse_management.reasons.code_hint')" persistent-hint :error-messages="form.errors.code" /></VCol>
            <VCol cols="7"><VTextField v-model="form.name" :label="t('warehouse_management.common.name')" :error-messages="form.errors.name" /></VCol>
            <VCol cols="6"><VSelect v-model="form.category" :items="categories.map(c => ({ value: c, title: t(`warehouse_management.reason_category.${c}`) }))" :label="t('warehouse_management.reasons.category')" /></VCol>
            <VCol cols="6"><VSelect v-model="form.direction" :items="['IN', 'OUT', 'BOTH'].map(d => ({ value: d, title: t(`warehouse_management.reason_direction.${d}`) }))" :label="t('warehouse_management.reasons.direction')" /></VCol>
            <VCol cols="12"><VSwitch v-model="form.always_requires_approval" color="warning" density="compact" :label="t('warehouse_management.reasons.always')" /></VCol>
            <VCol v-if="!form.always_requires_approval" cols="6"><VTextField v-model.number="form.approval_qty" type="number" min="0" :label="t('warehouse_management.reasons.qty_limit')" /></VCol>
            <VCol v-if="!form.always_requires_approval" cols="6"><VTextField v-model.number="form.approval_value" type="number" min="0" :label="t('warehouse_management.reasons.value_limit')" /></VCol>
            <VCol cols="12"><VSwitch v-model="form.requires_evidence" color="primary" density="compact" hide-details :label="t('warehouse_management.reasons.requires_evidence')" /></VCol>
            <VCol cols="12"><VSwitch v-model="form.approve_high_risk" color="warning" density="compact" hide-details :label="t('warehouse_management.reasons.approve_high_risk')" /></VCol>
            <VCol cols="12"><VSwitch v-model="form.is_active" color="primary" density="compact" :label="t('warehouse_management.reasons.active')" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ t('warehouse_management.common.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
