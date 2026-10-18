<script setup lang="ts">
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()

defineProps<{ card: any; history: { data: any[] } }>()
</script>

<template>
  <VContainer fluid class="pa-6" style="max-width: 900px;">
    <div class="d-flex align-center justify-space-between mb-6">
      <h1 class="text-h5 font-weight-bold">
        {{ t('fleet_job_cards.history_title') }} — {{ card.card_number }}
      </h1>
      <VBtn variant="text" :href="route('fleet.job-cards.show', card.uuid)">{{ t('fleet_job_cards.back') }}</VBtn>
    </div>

    <VCard>
      <VTable density="compact">
        <thead>
          <tr>
            <th>{{ t('fleet_job_cards.col_when') }}</th>
            <th>{{ t('fleet_job_cards.col_action') }}</th>
            <th>{{ t('fleet_job_cards.col_change') }}</th>
            <th>{{ t('fleet_job_cards.col_detail') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="h in history.data" :key="h.id">
            <td>{{ new Date(h.performed_at).toLocaleString() }}</td>
            <td>{{ t(`fleet_job_cards.action.${h.action}`) }}</td>
            <td>
              <template v-if="h.from_status">
                {{ t(`fleet_job_cards.status.${h.from_status}`) }} → {{ t(`fleet_job_cards.status.${h.to_status}`) }}
              </template>
              <template v-else>—</template>
            </td>
            <td>{{ h.description ?? '—' }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </VContainer>
</template>
