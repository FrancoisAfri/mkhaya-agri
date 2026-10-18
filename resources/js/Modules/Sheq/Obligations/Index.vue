<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t } = useI18n()

type Obligation = {
  uuid: string; definition: string; label: string; due_at: string; status: string
  hours_remaining: number | null; responsible_role: string | null; recipient: string | null
  evidence_required: boolean; submitted_at: string | null; pack: string
  incident: { reference: string; uuid: string; statutory_category: string | null } | null
}

const props = defineProps<{
  obligations: Obligation[]
  summary: { breached: number; due_24h: number; open: number; submitted: number }
  can: { submit: boolean }
  noCompany?: boolean
}>()

const filter = ref<'open' | 'breached' | 'all'>('open')

const filtered = computed(() => props.obligations.filter(o => {
  if (filter.value === 'all') return true
  if (filter.value === 'breached') return o.status === 'BREACHED'
  return ['PENDING', 'IN_PROGRESS', 'BREACHED'].includes(o.status)
}))

function submit(o: Obligation) {
  router.post(`/sheq/obligations/${o.uuid}/submit`, {}, { preserveScroll: true })
}

/** Colour tracks urgency, not just state: a deadline eleven hours out reads differently to one next week. */
function urgency(o: Obligation): string {
  if (o.status === 'BREACHED') return 'error'
  if (o.status === 'SUBMITTED' || o.status === 'ACKNOWLEDGED') return 'success'
  if (o.hours_remaining !== null && o.hours_remaining <= 24) return 'deep-orange'
  return 'warning'
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('sheq.obligation.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.obligation.subtitle') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <VRow class="mb-2">
      <VCol cols="6" sm="3">
        <VCard elevation="0" border rounded="lg" :color="summary.breached ? 'error' : undefined"
               :variant="summary.breached ? 'tonal' : 'elevated'">
          <VCardText>
            <div class="text-caption">{{ t('sheq.obligation.breached') }}</div>
            <div class="text-h4 font-weight-bold">{{ summary.breached }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border rounded="lg">
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('sheq.obligation.due_24h') }}</div>
            <div class="text-h4 font-weight-bold">{{ summary.due_24h }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border rounded="lg">
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('sheq.obligation.open') }}</div>
            <div class="text-h4 font-weight-bold">{{ summary.open }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border rounded="lg">
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('sheq.obligation.submitted') }}</div>
            <div class="text-h4 font-weight-bold">{{ summary.submitted }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VBtnToggle v-model="filter" mandatory density="compact" variant="outlined" class="mb-3">
      <VBtn value="open" size="small">{{ t('sheq.obligation.filter_open') }}</VBtn>
      <VBtn value="breached" size="small">{{ t('sheq.obligation.filter_breached') }}</VBtn>
      <VBtn value="all" size="small">{{ t('sheq.obligation.filter_all') }}</VBtn>
    </VBtnToggle>

    <VCard elevation="0" border rounded="lg">
      <VTable density="comfortable">
        <thead>
          <tr>
            <th scope="col">{{ t('sheq.obligation.obligation') }}</th>
            <th scope="col">{{ t('sheq.obligation.incident') }}</th>
            <th scope="col">{{ t('sheq.obligation.due') }}</th>
            <th scope="col">{{ t('sheq.obligation.responsible') }}</th>
            <th scope="col">{{ t('sheq.obligation.status') }}</th>
            <th scope="col" v-if="can.submit"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="o in filtered" :key="o.uuid">
            <td>
              <div class="font-weight-medium text-body-2">{{ o.label }}</div>
              <div class="text-caption text-medium-emphasis">
                {{ o.recipient }}
                <VIcon v-if="o.evidence_required" size="13" class="ms-1" :title="t('sheq.obligation.evidence_required')">
                  mdi-paperclip
                </VIcon>
              </div>
            </td>
            <td>
              <Link v-if="o.incident" :href="`/sheq/incidents/${o.incident.uuid}`"
                    class="text-primary text-decoration-none text-body-2">
                {{ o.incident.reference }}
              </Link>
              <span v-else class="text-medium-emphasis">—</span>
            </td>
            <td class="text-body-2">
              {{ new Date(o.due_at).toLocaleString() }}
              <div v-if="o.hours_remaining !== null" class="text-caption"
                   :class="o.hours_remaining < 0 ? 'text-error font-weight-bold' : 'text-medium-emphasis'">
                {{ o.hours_remaining >= 0
                   ? t('sheq.obligation.hours_left', { count: o.hours_remaining })
                   : t('sheq.obligation.hours_overdue', { count: Math.abs(o.hours_remaining) }) }}
              </div>
            </td>
            <td class="text-body-2">{{ o.responsible_role ?? '—' }}</td>
            <td>
              <VChip :color="urgency(o)" size="x-small" variant="tonal">{{ o.status }}</VChip>
            </td>
            <td v-if="can.submit" class="text-end">
              <VBtn v-if="['PENDING', 'IN_PROGRESS', 'BREACHED'].includes(o.status)"
                    size="x-small" variant="tonal" @click="submit(o)">
                {{ t('sheq.obligation.mark_submitted') }}
              </VBtn>
            </td>
          </tr>
          <tr v-if="!filtered.length">
            <td :colspan="can.submit ? 6 : 5" class="text-center py-10 text-medium-emphasis">
              {{ t('sheq.obligation.empty') }}
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
