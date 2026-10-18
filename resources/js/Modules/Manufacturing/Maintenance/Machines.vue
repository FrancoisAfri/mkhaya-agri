<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Machine = { id: string; name: string; centres: string[]; hold_status: string; hold_reason: string | null; maintenance_cost: number
  work_orders: number; open_work_orders: number; downtime_minutes: number; breakdowns: number; run_hours: number }

defineProps<{
  machines: Machine[]
  overdue: Array<{ id: string; number: string; title: string; machine: string | null; scheduled_for: string | null; status: string }>
  fault_codes: Array<{ id: string; code: string; name: string; category: string | null; is_active: boolean }>
  categories: string[]
  can: { manage: boolean }
}>()

const faultDialog = ref(false)
const fault = useForm({ code: '', name: '', category: 'MECHANICAL' })
const saveFault = () => fault.post(route('manufacturing.maintenance.fault-codes.store'), { preserveScroll: true, onSuccess: () => { faultDialog.value = false; fault.reset() } })
const holdColor = (s: string) => ({ NONE: 'success', MAINTENANCE: 'warning', SAFETY: 'error' } as Record<string, string>)[s] ?? 'default'

const headers = [
  { title: t('manufacturing.maint.machine'), key: 'name' },
  { title: t('manufacturing.maint.status'), key: 'hold_status' },
  { title: t('manufacturing.maint.run_hours'), key: 'run_hours', align: 'end' as const },
  { title: t('manufacturing.maint.breakdowns_30'), key: 'breakdowns', align: 'end' as const },
  { title: t('manufacturing.maint.downtime_30'), key: 'downtime_minutes', align: 'end' as const },
  { title: t('manufacturing.maint.open_wo'), key: 'open_work_orders', align: 'end' as const },
  { title: t('manufacturing.maint.cost_30'), key: 'maintenance_cost', align: 'end' as const },
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.maint.machines_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.maint.machines_subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" :href="route('manufacturing.maintenance.preventive')">{{ t('manufacturing.maint.preventive_title') }}</VBtn>
        <VBtn variant="tonal" :href="route('manufacturing.maintenance.oee')">OEE</VBtn>
      </div>
    </div>

    <VAlert v-if="overdue.length" type="warning" variant="tonal" class="mb-4">
      <div class="font-weight-medium mb-1">{{ t('manufacturing.maint.overdue_n', { n: overdue.length }) }}</div>
      <div v-for="o in overdue" :key="o.id" class="text-body-2"><a :href="route('assets.work-orders.show', o.id)">{{ o.number }}</a> · {{ o.title }} · {{ o.scheduled_for }}</div>
    </VAlert>

    <VCard border class="mb-4">
      <VDataTable :headers="headers" :items="machines" item-value="id" hover :items-per-page="50"
                  @click:row="(_: unknown, { item }: any) => router.visit(route('manufacturing.maintenance.machines.show', item.id))">
        <template #item.name="{ item }"><div class="font-weight-medium">{{ item.name }}</div><div class="text-caption text-medium-emphasis">{{ item.centres.join(', ') }}</div></template>
        <template #item.hold_status="{ item }">
          <VChip size="small" label :color="holdColor(item.hold_status)">{{ t(`manufacturing.maint.hold.${item.hold_status}`) }}</VChip>
          <div v-if="item.hold_reason && item.hold_status !== 'NONE'" class="text-caption">{{ item.hold_reason }}</div>
        </template>
        <template #item.maintenance_cost="{ item }">{{ item.maintenance_cost.toFixed(2) }}</template>
        <template #no-data><div class="py-8 text-medium-emphasis">{{ t('manufacturing.maint.no_machines') }}</div></template>
      </VDataTable>
    </VCard>

    <VCard border>
      <VCardItem>
        <VCardTitle>{{ t('manufacturing.maint.fault_codes') }}</VCardTitle>
        <VCardSubtitle>{{ t('manufacturing.maint.fault_codes_hint') }}</VCardSubtitle>
        <template #append><VBtn v-if="can.manage" size="small" color="primary" prepend-icon="tabler-plus" @click="faultDialog = true">{{ t('manufacturing.common.add') }}</VBtn></template>
      </VCardItem>
      <VCardText class="d-flex flex-wrap gap-2">
        <VChip v-for="f in fault_codes" :key="f.id" label :disabled="!f.is_active">{{ f.code }} — {{ f.name }}</VChip>
        <span v-if="!fault_codes.length" class="text-medium-emphasis">{{ t('manufacturing.maint.no_fault_codes') }}</span>
      </VCardText>
    </VCard>

    <VDialog v-model="faultDialog" max-width="460">
      <VCard :title="t('manufacturing.maint.new_fault_code')">
        <VCardText>
          <VTextField v-model="fault.code" :error-messages="fault.errors.code" :label="t('manufacturing.common.code')" class="mb-2" />
          <VTextField v-model="fault.name" :error-messages="fault.errors.name" :label="t('manufacturing.common.name')" class="mb-2" />
          <VSelect v-model="fault.category" :items="categories.map(c => ({ title: t(`manufacturing.maint.fault_category.${c}`), value: c }))" :label="t('manufacturing.floor.category_label')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="faultDialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="fault.processing" @click="saveFault">{{ t('manufacturing.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
