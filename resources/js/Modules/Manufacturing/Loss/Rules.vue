<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Rule = { category: string; meaning: string; is_active: boolean; threshold: number; min_count: number; min_impact: number
  lookback_days: number; approval_over: number | null; due_days: number }

const props = defineProps<{ rules: Rule[]; can: { edit: boolean } }>()
const rows = ref(props.rules.map(r => ({ ...r })))
const save = (r: Rule) => router.post(route('manufacturing.loss.rules.update', r.category), { ...r }, { preserveScroll: true })
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('manufacturing.loss.rules_title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.loss.rules_subtitle') }}</p>
    </div>
    <VCard border>
      <VTable density="compact">
        <thead><tr>
          <th>{{ t('manufacturing.loss.category_label') }}</th><th>{{ t('manufacturing.loss.on') }}</th><th>{{ t('manufacturing.loss.threshold') }}</th>
          <th>{{ t('manufacturing.loss.min_count') }}</th><th>{{ t('manufacturing.loss.min_impact') }}</th><th>{{ t('manufacturing.loss.lookback') }}</th>
          <th>{{ t('manufacturing.loss.approval_over') }}</th><th>{{ t('manufacturing.loss.due_days') }}</th><th />
        </tr></thead>
        <tbody>
          <tr v-for="r in rows" :key="r.category">
            <td>{{ t(`manufacturing.loss.category.${r.category}`) }}<div class="text-caption text-medium-emphasis">{{ r.meaning }}</div></td>
            <td><VSwitch v-model="r.is_active" hide-details density="compact" :disabled="!can.edit" /></td>
            <td><VTextField v-model.number="r.threshold" type="number" density="compact" hide-details style="width: 90px" :disabled="!can.edit" /></td>
            <td><VTextField v-model.number="r.min_count" type="number" density="compact" hide-details style="width: 70px" :disabled="!can.edit" /></td>
            <td><VTextField v-model.number="r.min_impact" type="number" density="compact" hide-details style="width: 100px" :disabled="!can.edit" /></td>
            <td><VTextField v-model.number="r.lookback_days" type="number" density="compact" hide-details style="width: 80px" :disabled="!can.edit" /></td>
            <td><VTextField v-model.number="r.approval_over" type="number" density="compact" hide-details style="width: 110px" :disabled="!can.edit" /></td>
            <td><VTextField v-model.number="r.due_days" type="number" density="compact" hide-details style="width: 70px" :disabled="!can.edit" /></td>
            <td><VBtn v-if="can.edit" size="small" variant="tonal" @click="save(r)">{{ t('manufacturing.loss.save') }}</VBtn></td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
