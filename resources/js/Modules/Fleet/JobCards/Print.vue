<script setup lang="ts">
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
defineProps<{ card: any }>()

const money = (n: any) => Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

// Open the print dialog once the card has rendered.
onMounted(() => setTimeout(() => window.print(), 400))
</script>

<template>
  <div class="pa-8" style="max-width: 900px; margin: 0 auto;">
    <div class="d-flex justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('fleet_job_cards.job_card') }}</h1>
        <p class="text-body-2">{{ card.card_number }}</p>
      </div>
      <div class="text-end text-body-2">
        <div>{{ card.vehicle?.registration_number }}</div>
        <div>{{ card.vehicle?.make }} {{ card.vehicle?.model }}</div>
        <div>{{ card.opened_date }}</div>
      </div>
    </div>

    <table class="w-100 mb-6" style="border-collapse: collapse;">
      <tbody>
        <tr><td class="pa-1"><strong>{{ t('fleet_job_cards.fault_description') }}</strong></td><td class="pa-1">{{ card.fault_description ?? '—' }}</td></tr>
        <tr><td class="pa-1"><strong>{{ t('fleet_job_cards.work_requested') }}</strong></td><td class="pa-1">{{ card.work_requested ?? '—' }}</td></tr>
        <tr><td class="pa-1"><strong>{{ t('fleet_job_cards.work_performed') }}</strong></td><td class="pa-1">{{ card.work_performed ?? '—' }}</td></tr>
        <tr><td class="pa-1"><strong>{{ t('fleet_job_cards.odometer') }}</strong></td><td class="pa-1">{{ card.odometer ?? '—' }}</td></tr>
      </tbody>
    </table>

    <h2 class="text-subtitle-1 font-weight-bold mb-2">{{ t('fleet_job_cards.tab_parts') }}</h2>
    <table class="w-100 mb-6" style="border-collapse: collapse;">
      <thead>
        <tr>
          <th class="text-start pa-1" style="border-bottom:1px solid #ccc;">{{ t('fleet_job_cards.part') }}</th>
          <th class="text-end pa-1" style="border-bottom:1px solid #ccc;">{{ t('fleet_job_cards.qty') }}</th>
          <th class="text-end pa-1" style="border-bottom:1px solid #ccc;">{{ t('fleet_job_cards.unit_price') }}</th>
          <th class="text-end pa-1" style="border-bottom:1px solid #ccc;">{{ t('fleet_job_cards.line_total') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="p in card.parts" :key="p.id">
          <td class="pa-1">{{ p.name }}</td>
          <td class="text-end pa-1">{{ p.quantity }}</td>
          <td class="text-end pa-1">{{ money(p.unit_price) }}</td>
          <td class="text-end pa-1">{{ money(p.line_total) }}</td>
        </tr>
      </tbody>
    </table>

    <table class="mb-8" style="margin-left:auto;">
      <tbody>
        <tr><td class="pa-1 text-end">{{ t('fleet_job_cards.parts_cost') }}</td><td class="pa-1 text-end">{{ money(card.parts_cost) }}</td></tr>
        <tr><td class="pa-1 text-end">{{ t('fleet_job_cards.labour_cost') }}</td><td class="pa-1 text-end">{{ money(card.labour_cost) }}</td></tr>
        <tr><td class="pa-1 text-end"><strong>{{ t('fleet_job_cards.total_cost') }}</strong></td><td class="pa-1 text-end"><strong>{{ money(card.total_cost) }}</strong></td></tr>
      </tbody>
    </table>

    <div class="d-flex justify-space-between mt-8">
      <div style="width:45%; border-top:1px solid #333;" class="pt-1 text-caption">{{ t('fleet_job_cards.mechanic_signature') }}</div>
      <div style="width:45%; border-top:1px solid #333;" class="pt-1 text-caption">{{ t('fleet_job_cards.supervisor_signature') }}</div>
    </div>
  </div>
</template>

<style>
@media print {
  .v-navigation-drawer, .v-app-bar, .v-footer { display: none !important; }
}
</style>
