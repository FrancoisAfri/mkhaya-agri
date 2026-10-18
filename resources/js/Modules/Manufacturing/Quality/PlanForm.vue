<script setup lang="ts">
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'

/** The plan header form, shared by the create dialog and the plan page. */
const { t } = useI18n()

export type PlanOptions = {
  materials: Array<{ id: number; code: string; name: string }>
  products: Array<{ id: string; code: string | null; name: string | null }>
  suppliers: Array<{ id: number; name: string }>
  operations: Array<{ id: string; item_id: string; name: string }>
}

const props = defineProps<{ form: any; options: PlanOptions; stages: string[] }>()

const incoming = computed(() => props.form.stage === 'INCOMING')
const operations = computed(() => props.options.operations.filter(o => o.item_id === props.form.item_uuid))
watch(() => props.form.item_uuid, () => {
  if (!operations.value.some(o => o.id === props.form.routing_operation_uuid)) props.form.routing_operation_uuid = null
})
</script>

<template>
  <VRow dense>
    <VCol cols="12" sm="4"><VTextField v-model="form.code" :error-messages="form.errors.code" :label="t('manufacturing.common.code')" /></VCol>
    <VCol cols="12" sm="8"><VTextField v-model="form.name" :error-messages="form.errors.name" :label="t('manufacturing.common.name')" /></VCol>
    <VCol cols="12" sm="6">
      <VSelect v-model="form.stage" :items="stages.map(s => ({ title: t(`manufacturing.quality.stage.${s}`), value: s }))"
               :error-messages="form.errors.stage" :label="t('manufacturing.quality.stage_label')" />
    </VCol>
    <template v-if="incoming">
      <VCol cols="12" sm="6">
        <VAutocomplete v-model="form.stock_item_id" :items="options.materials.map(m => ({ title: `${m.code} — ${m.name}`, value: m.id }))"
                       :error-messages="form.errors.stock_item_id" :label="t('manufacturing.orders.material')" />
      </VCol>
      <VCol cols="12">
        <VAutocomplete v-model="form.supplier_id" :items="options.suppliers" item-value="id" item-title="name" clearable
                       :error-messages="form.errors.supplier_id" :label="t('manufacturing.quality.supplier')" :hint="t('manufacturing.quality.supplier_hint')" persistent-hint />
      </VCol>
    </template>
    <template v-else>
      <VCol cols="12" sm="6">
        <VAutocomplete v-model="form.item_uuid" :items="options.products" item-value="id" :item-title="(p: any) => `${p.code} — ${p.name}`"
                       :error-messages="form.errors.item_uuid" :label="t('manufacturing.bom.product')" />
      </VCol>
      <VCol v-if="form.stage === 'IN_PROCESS'" cols="12" sm="6">
        <VSelect v-model="form.routing_operation_uuid" :items="operations" item-value="id" item-title="name" clearable
                 :error-messages="form.errors.routing_operation_uuid" :label="t('manufacturing.quality.operation')" :hint="t('manufacturing.quality.operation_hint')" persistent-hint />
      </VCol>
      <VCol v-if="form.stage === 'IN_PROCESS'" cols="12" sm="6">
        <VTextField v-model.number="form.every_qty" type="number" min="0" step="any" clearable :error-messages="form.errors.every_qty"
                    :label="t('manufacturing.quality.every_qty')" :hint="t('manufacturing.quality.every_qty_hint')" persistent-hint />
      </VCol>
    </template>
    <VCol cols="12" sm="6">
      <VTextField v-model.number="form.sample_size" type="number" min="1" clearable :error-messages="form.errors.sample_size"
                  :label="t('manufacturing.quality.sample_size')" :hint="t('manufacturing.quality.sample_size_hint')" persistent-hint />
    </VCol>
    <VCol cols="12" sm="6"><VSwitch v-model="form.blocks_production" color="primary" hide-details :label="t('manufacturing.quality.blocks_production')" /></VCol>
    <VCol cols="12" sm="6"><VSwitch v-model="form.requires_certificate" color="primary" hide-details :label="t('manufacturing.quality.requires_certificate')" /></VCol>
    <VCol cols="12" sm="6"><VSwitch v-model="form.is_active" color="primary" hide-details :label="t('manufacturing.quality.active')" /></VCol>
    <VCol cols="12"><VTextarea v-model="form.notes" rows="2" auto-grow :label="t('manufacturing.orders.notes')" /></VCol>
  </VRow>
</template>
