<script lang="ts" setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Acc {
  id: number; uuid: string; name: string; industry: string | null; health_score: number | null
  health_status: string | null; churn_risk: string | null; summary: string | null
  assessed_at: string | null; open_value: number; deals: number
}
defineProps<{ accounts: Acc[]; aiEnabled: boolean }>()

const busy = ref<number | null>(null)
const expanded = ref<number[]>([])
function assess(a: Acc) { busy.value = a.id; router.post(route('crm.account-health.assess', a.uuid), {}, { preserveScroll: true, onFinish: () => busy.value = null }) }

const money = (n: number) => 'R ' + (n ?? 0).toLocaleString('en-ZA', { maximumFractionDigits: 0 })
const scoreColor = (s: number | null) => s == null ? 'grey' : s >= 70 ? 'success' : s >= 40 ? 'warning' : 'error'
const statusColor: Record<string, string> = { healthy: 'success', growth: 'teal', at_risk: 'warning', critical: 'error' }
const churnColor: Record<string, string> = { low: 'success', medium: 'warning', high: 'error' }
const headers = computed(()=>[
  { title: t('crm_account_health_index.table.account'), key: 'name' },
  { title: t('crm_account_health_index.table.health'), key: 'health_score', align: 'end' as const },
  { title: t('crm_account_health_index.table.status'), key: 'health_status' },
  { title: t('crm_account_health_index.table.churn_risk'), key: 'churn_risk' },
  { title: t('crm_account_health_index.table.open_value'), key: 'open_value', align: 'end' as const },
  { title: '', key: 'actions', sortable: false },
])
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h5 class="text-h5 font-weight-semibold"><VIcon icon="tabler-heart-rate-monitor" color="error" class="me-1" />{{ t('crm_account_health_index.page_title') }}</h5>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_account_health_index.description') }}</p>
    </div>

    <VAlert v-if="!aiEnabled" type="warning" variant="tonal" class="mb-3">{{ t('crm_account_health_index.ai_disabled') }}</VAlert>

    <VCard>
      <VDataTable :headers="headers" :items="accounts" :items-per-page="25" density="comfortable"
        show-expand v-model:expanded="expanded" item-value="id">
        <template #item.name="{ item, internalItem, toggleExpand, isExpanded }">
          <div class="d-flex align-center ga-1">
            <a class="text-primary font-weight-medium" :href="route('crm.accounts.show', item.uuid)">{{ item.name }}</a>
            <VBtn v-if="item.summary" :icon="isExpanded(internalItem) ? 'tabler-chevron-up' : 'tabler-chevron-down'"
              size="x-small" variant="text" @click="toggleExpand(internalItem)" />
          </div>
        </template>
        <template #expanded-row="{ columns, item }">
          <tr>
            <td :colspan="columns.length" class="py-3" style="background: rgba(var(--v-theme-primary), 0.03)">
              <div class="text-body-2" style="white-space: pre-wrap; max-width: 980px">{{ item.summary || t('crm_account_health_index.not_assessed') }}</div>
            </td>
          </tr>
        </template>
        <template #item.health_score="{ item }">
          <VChip v-if="item.health_score != null" :color="scoreColor(item.health_score)" size="small" label>{{ item.health_score }}</VChip>
          <span v-else class="text-disabled">—</span>
        </template>
        <template #item.health_status="{ item }">
          <VChip v-if="item.health_status" :color="statusColor[item.health_status] ?? 'grey'" size="small" label class="text-capitalize">{{ item.health_status.replace('_',' ') }}</VChip>
          <span v-else class="text-disabled">—</span>
        </template>
        <template #item.churn_risk="{ item }">
          <VChip v-if="item.churn_risk" :color="churnColor[item.churn_risk] ?? 'grey'" size="small" label class="text-capitalize">{{ item.churn_risk }}</VChip>
          <span v-else class="text-disabled">—</span>
        </template>
        <template #item.open_value="{ item }">{{ money(item.open_value) }}</template>
        <template #item.actions="{ item }">
          <VBtn size="x-small" variant="tonal" color="primary" :loading="busy === item.id" :disabled="!aiEnabled" prepend-icon="tabler-sparkles" @click="assess(item)">
            {{ item.health_score != null ? t('crm_account_health_index.re_assess') : t('crm_account_health_index.assess') }}
          </VBtn>
        </template>
      </VDataTable>
    </VCard>
  </div>
</template>
