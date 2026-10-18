<script setup lang="ts">
import { ref, watch } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()

const props = defineProps<{
  cards: { data: any[] }
  filters: Record<string, any>
  statuses: string[]
}>()

const search = ref(props.filters?.search ?? '')
const status = ref(props.filters?.status ?? '')
let timer: ReturnType<typeof setTimeout>

function applyFilters() {
  router.get(route('fleet.job-cards.my-cards'), {
    search: search.value || undefined,
    status: status.value || undefined,
  }, { preserveState: true, replace: true })
}

watch(search, () => { clearTimeout(timer); timer = setTimeout(applyFilters, 400) })
watch(status, applyFilters)

const statusLabel = (s: string) => t(`fleet_job_cards.status.${s}`)
</script>

<template>
  <VContainer fluid class="pa-6">
    <h1 class="text-h5 font-weight-bold mb-1">{{ t('fleet_job_cards.my_cards') }}</h1>
    <p class="text-body-2 text-medium-emphasis mb-6">{{ t('fleet_job_cards.my_cards_subtitle') }}</p>

    <VCard class="mb-4">
      <VCardText>
        <VRow dense>
          <VCol cols="12" md="6">
            <VTextField v-model="search" :label="t('fleet_job_cards.search_placeholder')" prepend-inner-icon="tabler-search" density="compact" clearable />
          </VCol>
          <VCol cols="12" md="4">
            <VSelect
              v-model="status"
              :items="props.statuses.map(s => ({ title: statusLabel(s), value: s }))"
              :label="t('fleet_job_cards.col_status')"
              density="compact"
              clearable
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard>
      <VTable density="compact">
        <thead>
          <tr>
            <th>{{ t('fleet_job_cards.col_number') }}</th>
            <th>{{ t('fleet_job_cards.col_vehicle') }}</th>
            <th>{{ t('fleet_job_cards.col_title') }}</th>
            <th>{{ t('fleet_job_cards.col_due') }}</th>
            <th>{{ t('fleet_job_cards.col_status') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="card in props.cards.data" :key="card.uuid">
            <td>
              <Link :href="route('fleet.job-cards.show', card.uuid)" class="text-primary font-weight-medium">
                {{ card.card_number }}
              </Link>
            </td>
            <td>{{ card.vehicle?.registration_number ?? '—' }}</td>
            <td>{{ card.title ?? '—' }}</td>
            <td>{{ card.due_date ?? '—' }}</td>
            <td><VChip size="x-small" variant="tonal">{{ statusLabel(card.status) }}</VChip></td>
          </tr>
          <tr v-if="!props.cards.data.length">
            <td colspan="5" class="text-center text-medium-emphasis py-6">{{ t('fleet_job_cards.no_assigned_cards') }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </VContainer>
</template>
