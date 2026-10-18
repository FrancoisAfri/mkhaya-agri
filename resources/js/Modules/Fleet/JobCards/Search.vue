<script setup lang="ts">
import { ref } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()

const props = defineProps<{
  cards: { data: any[] } | null
  filters: Record<string, any>
  statuses: string[]
  vehicles: any[]
  mechanics: { id: number; name: string }[]
}>()

const f = ref({
  search: props.filters?.search ?? '',
  status: props.filters?.status ?? '',
  vehicle_id: props.filters?.vehicle_id ?? '',
  mechanic_id: props.filters?.mechanic_id ?? '',
  from: props.filters?.from ?? '',
  to: props.filters?.to ?? '',
})

function runSearch() {
  router.get(route('fleet.job-cards.search'), {
    search: f.value.search || undefined,
    status: f.value.status || undefined,
    vehicle_id: f.value.vehicle_id || undefined,
    mechanic_id: f.value.mechanic_id || undefined,
    from: f.value.from || undefined,
    to: f.value.to || undefined,
  }, { preserveState: true })
}

const statusLabel = (s: string) => t(`fleet_job_cards.status.${s}`)
</script>

<template>
  <VContainer fluid class="pa-6">
    <h1 class="text-h5 font-weight-bold mb-6">{{ t('fleet_job_cards.search_title') }}</h1>

    <VCard class="mb-4">
      <VCardText>
        <VRow dense>
          <VCol cols="12" md="4">
            <VTextField v-model="f.search" :label="t('fleet_job_cards.search_placeholder')" density="compact" clearable />
          </VCol>
          <VCol cols="6" md="2">
            <VSelect v-model="f.status" :items="props.statuses.map(s => ({ title: statusLabel(s), value: s }))" :label="t('fleet_job_cards.col_status')" density="compact" clearable />
          </VCol>
          <VCol cols="6" md="3">
            <VSelect v-model="f.vehicle_id" :items="props.vehicles" :item-title="(v: any) => v.registration_number" item-value="id" :label="t('fleet_job_cards.vehicle')" density="compact" clearable />
          </VCol>
          <VCol cols="6" md="3">
            <VSelect v-model="f.mechanic_id" :items="props.mechanics" item-title="name" item-value="id" :label="t('fleet_job_cards.mechanic')" density="compact" clearable />
          </VCol>
          <VCol cols="6" md="3">
            <VTextField v-model="f.from" type="date" :label="t('fleet_job_cards.from')" density="compact" />
          </VCol>
          <VCol cols="6" md="3">
            <VTextField v-model="f.to" type="date" :label="t('fleet_job_cards.to')" density="compact" />
          </VCol>
          <VCol cols="12" md="2" class="d-flex align-center">
            <VBtn color="primary" block @click="runSearch">{{ t('fleet_job_cards.search') }}</VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard v-if="props.cards">
      <VTable density="compact">
        <thead>
          <tr>
            <th>{{ t('fleet_job_cards.col_number') }}</th>
            <th>{{ t('fleet_job_cards.col_vehicle') }}</th>
            <th>{{ t('fleet_job_cards.col_title') }}</th>
            <th>{{ t('fleet_job_cards.col_opened') }}</th>
            <th>{{ t('fleet_job_cards.col_status') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="card in props.cards.data" :key="card.uuid">
            <td>
              <Link :href="route('fleet.job-cards.show', card.uuid)" class="text-primary">{{ card.card_number }}</Link>
            </td>
            <td>{{ card.vehicle?.registration_number ?? '—' }}</td>
            <td>{{ card.title ?? '—' }}</td>
            <td>{{ card.opened_date ?? '—' }}</td>
            <td><VChip size="x-small" variant="tonal">{{ statusLabel(card.status) }}</VChip></td>
          </tr>
          <tr v-if="!props.cards.data.length">
            <td colspan="5" class="text-center text-medium-emphasis py-6">{{ t('fleet_job_cards.no_results') }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </VContainer>
</template>
