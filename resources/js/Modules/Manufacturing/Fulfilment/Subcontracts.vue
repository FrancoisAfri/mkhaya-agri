<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { subcontractStatusColor } from './colors'

const { t } = useI18n()
const route = ziggyRoute

type Row = {
  id: string; number: string; status: string; order: { id: string; number: string } | null; operation: string | null; supplier: string | null
  qty: number; sent: number; received: number; lost: number; outstanding: number; unit_price: number; due_date: string | null; overdue: boolean
}

const props = defineProps<{
  subcontracts: Row[]
  candidates: Array<{ id: string; name: string; operations: Array<{ id: string; name: string }> }>
  suppliers: Array<{ id: number; name: string }>
  filters: { status: string }
  can: { manage: boolean }
}>()

const status = ref(props.filters.status)
watch(status, v => router.get(route('manufacturing.fulfilment.subcontracts.index'), { status: v }, { preserveState: true, replace: true }))
const num = (n: number | null | undefined) => n == null ? '—' : Number(n).toLocaleString(undefined, { maximumFractionDigits: 4 })

const dialog = ref(false)
const form = useForm({ production_order: '', operation: null as string | null, supplier_id: null as number | null, qty: null as number | null,
  unit_price: null as number | null, due_date: '', notes: '' })
const operations = computed(() => props.candidates.find(c => c.id === form.production_order)?.operations ?? [])
watch(() => form.production_order, () => { form.operation = null })
const submit = () => form.post(route('manufacturing.fulfilment.subcontracts.store'), { onSuccess: () => { dialog.value = false } })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.fulfilment.subcontract_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.fulfilment.subcontract_subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtnToggle v-model="status" density="compact" variant="outlined" mandatory>
          <VBtn value="open">{{ t('manufacturing.fulfilment.open') }}</VBtn>
          <VBtn value="all">{{ t('manufacturing.fulfilment.all') }}</VBtn>
        </VBtnToggle>
        <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="form.reset(); dialog = true">{{ t('manufacturing.fulfilment.new_subcontract') }}</VBtn>
      </div>
    </div>

    <VCard border>
      <VTable>
        <thead><tr>
          <th>{{ t('manufacturing.fulfilment.subcontract') }}</th><th>{{ t('manufacturing.fulfilment.production_order') }}</th>
          <th>{{ t('manufacturing.fulfilment.operation') }}</th><th>{{ t('manufacturing.fulfilment.supplier') }}</th>
          <th class="text-end">{{ t('manufacturing.fulfilment.qty') }}</th><th class="text-end">{{ t('manufacturing.fulfilment.received') }}</th>
          <th class="text-end">{{ t('manufacturing.fulfilment.outstanding') }}</th><th>{{ t('manufacturing.fulfilment.due') }}</th><th>{{ t('manufacturing.fulfilment.status') }}</th>
        </tr></thead>
        <tbody>
          <tr v-for="s in subcontracts" :key="s.id" class="cursor-pointer" @click="router.visit(route('manufacturing.fulfilment.subcontracts.show', s.id))">
            <td class="font-weight-medium">{{ s.number }}</td>
            <td>{{ s.order?.number }}</td>
            <td>{{ s.operation ?? t('manufacturing.fulfilment.whole_order') }}</td>
            <td>{{ s.supplier }}</td>
            <td class="text-end">{{ num(s.qty) }}</td>
            <td class="text-end">{{ num(s.received) }}</td>
            <td class="text-end">{{ num(s.outstanding) }}</td>
            <td :class="{ 'text-error font-weight-medium': s.overdue }">{{ s.due_date ?? '—' }}</td>
            <td><VChip size="small" label :color="subcontractStatusColor(s.status)">{{ t(`manufacturing.fulfilment.sco_status.${s.status}`) }}</VChip></td>
          </tr>
          <tr v-if="!subcontracts.length"><td colspan="9" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.fulfilment.no_subcontracts') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="640">
      <VCard>
        <VCardItem><VCardTitle>{{ t('manufacturing.fulfilment.new_subcontract') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.fulfilment.new_subcontract_hint') }}</VCardSubtitle></VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="12"><VAutocomplete v-model="form.production_order" :items="candidates" item-title="name" item-value="id" :label="t('manufacturing.fulfilment.production_order')" :error-messages="form.errors.production_order" /></VCol>
            <VCol cols="12"><VSelect v-model="form.operation" :items="operations" item-title="name" item-value="id" clearable
              :label="t('manufacturing.fulfilment.operation')" :hint="t('manufacturing.fulfilment.operation_hint')" persistent-hint /></VCol>
            <VCol cols="12"><VAutocomplete v-model="form.supplier_id" :items="suppliers" item-title="name" item-value="id" clearable
              :label="t('manufacturing.fulfilment.supplier')" :hint="t('manufacturing.fulfilment.supplier_hint')" persistent-hint :error-messages="form.errors.supplier_id" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model.number="form.qty" type="number" :label="t('manufacturing.fulfilment.qty')" :error-messages="form.errors.qty" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model.number="form.unit_price" type="number" :label="t('manufacturing.fulfilment.unit_price')" :error-messages="form.errors.unit_price" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model="form.due_date" type="date" :label="t('manufacturing.fulfilment.due')" /></VCol>
            <VCol cols="12"><VTextarea v-model="form.notes" rows="2" :label="t('manufacturing.fulfilment.notes')" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('manufacturing.fulfilment.close') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="form.processing" :disabled="!form.production_order" @click="submit">{{ t('manufacturing.fulfilment.raise') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
