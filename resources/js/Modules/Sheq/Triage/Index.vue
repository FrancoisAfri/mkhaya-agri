<script setup lang="ts">
/**
 * The queue of incidents waiting for somebody to pick them up.
 *
 * The clock and the owner live in the data; this is where they become visible to the person who
 * has to act. An escalation that only reaches an inbox is one more email — a queue sorted by how
 * late each thing is, is the thing somebody works through.
 *
 * Sorted by lateness rather than by when it was reported: a fortnight-old could-have-killed with
 * no owner is a different problem from a fortnight-old first-aid case, and a list that puts them
 * in the same place teaches people to scroll past both.
 */
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t } = useI18n()

type Incident = {
  uuid: string; reference: string; incident_type: string; description: string; status: string
  reported_at: string | null; acknowledged: boolean; due_at: string | null
  days_remaining: number | null; potential_severity: number | null; actual_severity: number | null
  is_reportable: boolean; assigned_to: number | null; owner: string | null
}

const props = defineProps<{
  incidents: Incident[]
  summary: { unacknowledged: number; unassigned: number; overdue: number; open: number }
  people: { id: number; name: string }[]
  can: { triage: boolean }
  noCompany?: boolean
}>()

const filter = ref<'needs_action' | 'overdue' | 'all'>('needs_action')

const filtered = computed(() => props.incidents.filter(i => {
  if (filter.value === 'all') return true
  if (filter.value === 'overdue') return i.days_remaining !== null && i.days_remaining < 0
  // Needs action: nobody has read it, or nobody owns it, or it is late.
  return !i.acknowledged || i.assigned_to === null || (i.days_remaining !== null && i.days_remaining < 0)
}))

const open = ref<Incident | null>(null)
const owner = ref<number | null>(null)
const severity = ref<number | null>(null)
const notes = ref('')
const saving = ref(false)

function start(incident: Incident) {
  open.value = incident
  owner.value = incident.assigned_to
  severity.value = incident.potential_severity
  notes.value = ''
}

function save() {
  if (!open.value) return
  saving.value = true
  router.post(`/sheq/incidents/${open.value.uuid}/triage`, {
    assigned_to: owner.value,
    potential_severity: severity.value,
    notes: notes.value || null,
  }, {
    preserveScroll: true,
    onFinish: () => { saving.value = false; open.value = null },
  })
}

/** Colour tracks lateness, not status: a week overdue reads differently to due on Friday. */
function urgency(i: Incident): string {
  if (i.days_remaining === null) return 'grey'
  if (i.days_remaining < 0) return 'error'
  if (i.days_remaining <= 2) return 'deep-orange'
  return 'success'
}

function due(i: Incident): string {
  if (i.days_remaining === null) return t('sheq.triage.no_deadline')
  if (i.days_remaining < 0) return t('sheq.triage.days_overdue', { n: Math.abs(i.days_remaining) })
  if (i.days_remaining === 0) return t('sheq.triage.due_today')
  return t('sheq.triage.days_left', { n: i.days_remaining })
}

/** A rating of 4 or 5 is "this could have killed somebody", which is the whole basis of the clock. */
function severityColour(n: number | null): string {
  if (n === null) return 'grey'
  return n >= 4 ? 'error' : n === 3 ? 'warning' : 'info'
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('sheq.triage.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.triage.subtitle') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <VRow class="mb-2">
      <VCol cols="6" sm="3">
        <VCard elevation="0" border rounded="lg"
               :color="summary.overdue ? 'error' : undefined"
               :variant="summary.overdue ? 'tonal' : 'elevated'">
          <VCardText>
            <div class="text-caption">{{ t('sheq.triage.overdue') }}</div>
            <div class="text-h4 font-weight-bold">{{ summary.overdue }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border rounded="lg"
               :color="summary.unacknowledged ? 'warning' : undefined"
               :variant="summary.unacknowledged ? 'tonal' : 'elevated'">
          <VCardText>
            <div class="text-caption">{{ t('sheq.triage.unacknowledged') }}</div>
            <div class="text-h4 font-weight-bold">{{ summary.unacknowledged }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border rounded="lg">
          <VCardText>
            <div class="text-caption">{{ t('sheq.triage.unassigned') }}</div>
            <div class="text-h4 font-weight-bold">{{ summary.unassigned }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border rounded="lg">
          <VCardText>
            <div class="text-caption">{{ t('sheq.triage.open') }}</div>
            <div class="text-h4 font-weight-bold">{{ summary.open }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VBtnToggle v-model="filter" mandatory density="comfortable" variant="outlined" class="mb-4">
      <VBtn value="needs_action">{{ t('sheq.triage.filter_needs_action') }}</VBtn>
      <VBtn value="overdue">{{ t('sheq.triage.filter_overdue') }}</VBtn>
      <VBtn value="all">{{ t('sheq.triage.filter_all') }}</VBtn>
    </VBtnToggle>

    <VCard elevation="0" border rounded="lg">
      <VTable density="comfortable">
        <thead>
          <tr>
            <th>{{ t('sheq.triage.col_reference') }}</th>
            <th>{{ t('sheq.triage.col_what') }}</th>
            <th class="text-center">{{ t('sheq.triage.col_potential') }}</th>
            <th>{{ t('sheq.triage.col_owner') }}</th>
            <th>{{ t('sheq.triage.col_due') }}</th>
            <th class="text-right">{{ t('sheq.triage.col_action') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="i in filtered" :key="i.uuid">
            <td class="text-no-wrap">
              <Link :href="`/sheq/incidents/${i.uuid}`" class="text-primary">{{ i.reference }}</Link>
              <div class="text-caption text-medium-emphasis">{{ i.incident_type }}</div>
            </td>
            <td style="max-width: 360px">
              <div class="text-truncate">{{ i.description }}</div>
              <VChip v-if="!i.acknowledged" size="x-small" color="warning" variant="tonal" class="mt-1">
                {{ t('sheq.triage.not_read') }}
              </VChip>
              <VChip v-if="i.is_reportable" size="x-small" color="error" variant="tonal" class="mt-1 ms-1">
                {{ t('sheq.triage.reportable') }}
              </VChip>
            </td>
            <td class="text-center">
              <VChip size="small" :color="severityColour(i.potential_severity)" variant="tonal">
                {{ i.potential_severity ?? '—' }}
              </VChip>
            </td>
            <td>
              <span v-if="i.owner">{{ i.owner }}</span>
              <VChip v-else size="x-small" color="warning" variant="tonal">
                {{ t('sheq.triage.nobody') }}
              </VChip>
            </td>
            <td class="text-no-wrap">
              <VChip size="small" :color="urgency(i)" variant="tonal">{{ due(i) }}</VChip>
            </td>
            <td class="text-right">
              <VBtn v-if="can.triage" size="small" variant="tonal" @click="start(i)">
                {{ i.acknowledged ? t('sheq.triage.reassign') : t('sheq.triage.acknowledge') }}
              </VBtn>
            </td>
          </tr>
          <tr v-if="!filtered.length">
            <td colspan="6" class="text-center text-medium-emphasis py-8">
              {{ t('sheq.triage.nothing_waiting') }}
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog :model-value="open !== null" max-width="640" @update:model-value="open = null">
      <VCard v-if="open" rounded="lg">
        <VCardItem>
          <VCardTitle>{{ t('sheq.triage.dialog_title') }}</VCardTitle>
          <VCardSubtitle>{{ open.reference }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">{{ t('sheq.triage.dialog_help') }}</p>

          <VSelect
            v-model="owner"
            :items="people"
            item-title="name"
            item-value="id"
            :label="t('sheq.triage.owner')"
            variant="outlined"
            density="comfortable"
            clearable
            class="mb-3"
          />

          <VSelect
            v-model="severity"
            :items="[1, 2, 3, 4, 5]"
            :label="t('sheq.triage.potential')"
            :hint="t('sheq.triage.potential_hint')"
            persistent-hint
            variant="outlined"
            density="comfortable"
            class="mb-3"
          />

          <VTextarea
            v-model="notes"
            :label="t('sheq.triage.notes')"
            variant="outlined"
            density="comfortable"
            rows="2"
            hide-details
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="open = null">{{ t('sheq.triage.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="saving" @click="save">
            {{ t('sheq.triage.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
