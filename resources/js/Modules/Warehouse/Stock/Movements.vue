<script setup lang="ts">
import { reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import MovementTable from '../components/MovementTable.vue'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  movements: any
  filters: Record<string, string | undefined>
  warehouses: Array<{ id: string; title: string }>
  types: string[]
}>()

const f = reactive({ warehouse: props.filters.warehouse ?? null, type: props.filters.type ?? null, from: props.filters.from ?? '', to: props.filters.to ?? '', reason_code: props.filters.reason_code ?? '' })
function apply() {
  router.get(route('warehouse.stock.movements'), { ...props.filters, ...f }, { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('warehouse_management.movements.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.movements.subtitle') }}</p>
    </div>
    <VCard border>
      <VCardText class="d-flex gap-3 flex-wrap align-center">
        <VSelect v-model="f.warehouse" :items="warehouses" item-title="title" item-value="id" clearable density="compact" hide-details style="max-width: 240px" :label="t('warehouse_management.common.warehouse')" />
        <VSelect v-model="f.type" :items="types" clearable density="compact" hide-details style="max-width: 180px" :label="t('warehouse_management.movements.type')" />
        <VTextField v-model="f.from" type="date" density="compact" hide-details style="max-width: 170px" :label="t('warehouse_management.movements.from')" />
        <VTextField v-model="f.to" type="date" density="compact" hide-details style="max-width: 170px" :label="t('warehouse_management.movements.to')" />
        <VTextField v-model="f.reason_code" density="compact" hide-details style="max-width: 160px" :label="t('warehouse_management.movements.reason_code')" />
        <VBtn color="primary" @click="apply">{{ t('warehouse_management.movements.apply') }}</VBtn>
        <VBtn v-if="filters.item" variant="text" @click="router.get(route('warehouse.stock.movements'))">{{ t('warehouse_management.movements.all_items') }}</VBtn>
      </VCardText>
      <VCardText><MovementTable :movements="movements" show-item /></VCardText>
    </VCard>
  </div>
</template>
