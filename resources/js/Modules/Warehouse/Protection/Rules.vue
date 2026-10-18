<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { reactive } from 'vue'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type R = { id: number; category: string; meaning: string; is_active: boolean; threshold: number; min_count: number; min_impact: number; lookback_days: number; approval_over: number | null; due_days: number }
const props = defineProps<{ rules: R[]; can: { manage: boolean } }>()
const rows = reactive(props.rules.map(r => ({ ...r })))
const save = (r: R) => router.patch(route('warehouse.protection.rules.update', r.id), { ...r }, { preserveScroll: true })
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <div class="text-caption"><a :href="route('warehouse.protection.index')">{{ t('warehouse_management.protection.title') }}</a></div>
      <h4 class="text-h4">{{ t('warehouse_management.protection.rules') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.protection.rules_hint') }}</p>
    </div>
    <RefusalAlert />
    <VCard border>
      <div class="overflow-x-auto">
        <VTable density="comfortable">
          <thead><tr><th>{{ t('warehouse_management.protection.category') }}</th><th>{{ t('warehouse_management.protection.threshold') }}</th><th>{{ t('warehouse_management.protection.min_count') }}</th>
            <th>{{ t('warehouse_management.protection.min_impact') }}</th><th>{{ t('warehouse_management.protection.window') }}</th><th>{{ t('warehouse_management.protection.approval_over') }}</th><th /></tr></thead>
          <tbody>
            <tr v-for="r in rows" :key="r.id">
              <td><VSwitch v-model="r.is_active" :disabled="!can.manage" hide-details density="compact" color="primary" :label="t(`warehouse_management.finding_category.${r.category}`)" />
                <div class="text-caption text-medium-emphasis" style="max-width: 360px">{{ r.meaning }}</div></td>
              <td><VTextField v-model.number="r.threshold" :disabled="!can.manage" type="number" density="compact" hide-details style="width: 90px" /></td>
              <td><VTextField v-model.number="r.min_count" :disabled="!can.manage" type="number" density="compact" hide-details style="width: 80px" /></td>
              <td><VTextField v-model.number="r.min_impact" :disabled="!can.manage" type="number" density="compact" hide-details style="width: 100px" /></td>
              <td><VTextField v-model.number="r.lookback_days" :disabled="!can.manage" type="number" density="compact" hide-details style="width: 80px" /></td>
              <td><VTextField v-model.number="r.approval_over" :disabled="!can.manage" type="number" density="compact" hide-details style="width: 110px" /></td>
              <td><VBtn v-if="can.manage" size="small" variant="tonal" @click="save(r)">{{ t('warehouse_management.common.save') }}</VBtn></td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCard>
  </div>
</template>
