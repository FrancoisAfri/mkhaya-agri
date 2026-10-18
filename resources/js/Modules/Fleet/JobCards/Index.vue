<script setup lang="ts">
import { ref, watch } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()

type Card = {
  id: number; uuid: string; card_number: string | null; title: string | null
  status: string; priority: string; opened_date: string | null; due_date: string | null
  total_cost: number; vehicle?: { registration_number: string; make: string; model: string }
}

const props = defineProps<{
  cards: { data: Card[]; meta?: any; links?: any[] }
  filters: Record<string, any>
  statuses: string[]
  vehicles: { id: number; registration_number: string; make: string; model: string }[]
  mechanics: { id: number; name: string }[]
  counts: Record<string, number>
}>()

const search    = ref(props.filters?.search ?? '')
const status    = ref(props.filters?.status ?? '')
const priority  = ref(props.filters?.priority ?? '')
const vehicleId = ref(props.filters?.vehicle_id ?? '')
const mechanicId = ref(props.filters?.mechanic_id ?? '')

let timer: ReturnType<typeof setTimeout>

function applyFilters() {
  router.get(route('fleet.job-cards.index'), {
    search: search.value || undefined,
    status: status.value || undefined,
    priority: priority.value || undefined,
    vehicle_id: vehicleId.value || undefined,
    mechanic_id: mechanicId.value || undefined,
  }, { preserveState: true, replace: true })
}

watch(search, () => { clearTimeout(timer); timer = setTimeout(applyFilters, 400) })
watch([status, priority, vehicleId, mechanicId], applyFilters)

/** Status → chip colour. Terminal states are muted, active work is coloured. */
function statusColor(s: string): string {
  switch (s) {
    case 'draft':             return 'default'
    case 'pending_approval':  return 'warning'
    case 'approved':          return 'info'
    case 'in_progress':       return 'primary'
    case 'awaiting_feedback': return 'orange'
    case 'completed':         return 'success'
    case 'closed':            return 'success'
    case 'rejected':          return 'error'
    case 'cancelled':         return 'error'
    default:                  return 'default'
  }
}

function priorityColor(p: string): string {
  return p === 'emergency' ? 'error' : p === 'high' ? 'warning' : p === 'low' ? 'default' : 'info'
}

const statusLabel = (s: string) => t(`fleet_job_cards.status.${s}`)
const money = (n: number) => Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
</script>

<template>
  <VContainer fluid class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('fleet_job_cards.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('fleet_job_cards.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" :href="route('fleet.job-cards.my-cards')">{{ t('fleet_job_cards.my_cards') }}</VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" :href="route('fleet.job-cards.create')">
          {{ t('fleet_job_cards.new_card') }}
        </VBtn>
      </div>
    </div>

    <!-- Status summary -->
    <VRow class="mb-2">
      <VCol v-for="s in props.statuses" :key="s" cols="6" sm="4" md="2">
        <VCard
          variant="tonal"
          :color="status === s ? statusColor(s) : undefined"
          class="text-center py-3 cursor-pointer"
          @click="status = status === s ? '' : s"
        >
          <div class="text-h6 font-weight-bold">{{ props.counts?.[s] ?? 0 }}</div>
          <div class="text-caption">{{ statusLabel(s) }}</div>
        </VCard>
      </VCol>
    </VRow>

    <!-- Filters -->
    <VCard class="mb-4">
      <VCardText>
        <VRow dense>
          <VCol cols="12" md="4">
            <VTextField
              v-model="search"
              :label="t('fleet_job_cards.search_placeholder')"
              prepend-inner-icon="tabler-search"
              density="compact"
              clearable
            />
          </VCol>
          <VCol cols="6" md="2">
            <VSelect
              v-model="priority"
              :items="[
                { title: t('fleet_job_cards.priority.low'), value: 'low' },
                { title: t('fleet_job_cards.priority.normal'), value: 'normal' },
                { title: t('fleet_job_cards.priority.high'), value: 'high' },
                { title: t('fleet_job_cards.priority.emergency'), value: 'emergency' },
              ]"
              :label="t('fleet_job_cards.priority_label')"
              density="compact"
              clearable
            />
          </VCol>
          <VCol cols="6" md="3">
            <VSelect
              v-model="vehicleId"
              :items="props.vehicles"
              :item-title="(v: any) => `${v.registration_number} — ${v.make} ${v.model}`"
              item-value="id"
              :label="t('fleet_job_cards.vehicle')"
              density="compact"
              clearable
            />
          </VCol>
          <VCol cols="12" md="3">
            <VSelect
              v-model="mechanicId"
              :items="props.mechanics"
              item-title="name"
              item-value="id"
              :label="t('fleet_job_cards.mechanic')"
              density="compact"
              clearable
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Cards -->
    <VCard>
      <VTable density="compact">
        <thead>
          <tr>
            <th>{{ t('fleet_job_cards.col_number') }}</th>
            <th>{{ t('fleet_job_cards.col_vehicle') }}</th>
            <th>{{ t('fleet_job_cards.col_title') }}</th>
            <th>{{ t('fleet_job_cards.col_priority') }}</th>
            <th>{{ t('fleet_job_cards.col_opened') }}</th>
            <th>{{ t('fleet_job_cards.col_due') }}</th>
            <th class="text-end">{{ t('fleet_job_cards.col_cost') }}</th>
            <th>{{ t('fleet_job_cards.col_status') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="card in props.cards.data" :key="card.uuid" class="cursor-pointer">
            <td>
              <Link :href="route('fleet.job-cards.show', card.uuid)" class="text-primary font-weight-medium">
                {{ card.card_number }}
              </Link>
            </td>
            <td>{{ card.vehicle?.registration_number ?? '—' }}</td>
            <td class="text-truncate" style="max-width: 240px;">{{ card.title ?? '—' }}</td>
            <td>
              <VChip size="x-small" :color="priorityColor(card.priority)" variant="tonal">
                {{ t(`fleet_job_cards.priority.${card.priority}`) }}
              </VChip>
            </td>
            <td>{{ card.opened_date ?? '—' }}</td>
            <td>{{ card.due_date ?? '—' }}</td>
            <td class="text-end">{{ money(card.total_cost) }}</td>
            <td>
              <VChip size="x-small" :color="statusColor(card.status)" variant="tonal">
                {{ statusLabel(card.status) }}
              </VChip>
            </td>
          </tr>
          <tr v-if="!props.cards.data.length">
            <td colspan="8" class="text-center text-medium-emphasis py-6">
              {{ t('fleet_job_cards.no_cards') }}
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </VContainer>
</template>
