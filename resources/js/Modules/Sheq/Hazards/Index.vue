<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t } = useI18n()

type Hazard = {
  uuid: string; reference: string; description: string; category: string | null
  severity: number | null; status: string; site_name: string | null; location: string | null
  reported_at: string; triage_due_at: string | null; triage_overdue: boolean
  rejection_reason: string | null
}

const props = defineProps<{
  hazards: Hazard[]
  summary: { open: number; triage_overdue: number; controlled: number; rejected: number }
  noCompany?: boolean
  /** Open incidents this hazard could turn out to be. */
  linkableIncidents?: { id: number; label: string }[]
  can: { report: boolean; triage: boolean }
}>()

const linking = ref<Hazard | null>(null)
const linkForm = useForm({ incident_id: null as number | null })

function openLink(h: Hazard) {
  linking.value = h
  linkForm.reset()
  linkForm.clearErrors()
}

function submitLink() {
  if (!linking.value) return
  linkForm.post(`/sheq/hazards/${linking.value.uuid}/link-incident`, {
    preserveScroll: true,
    onSuccess: () => { linking.value = null; linkForm.reset() },
  })
}

const filter = ref<'open' | 'all'>('open')
const triaging = ref<Hazard | null>(null)

const filtered = computed(() => props.hazards.filter(h =>
  filter.value === 'all' ? true : ['OPEN', 'TRIAGED'].includes(h.status)))

const triageForm = useForm({
  outcome: 'ACTION' as 'ACTION' | 'CONTROLLED' | 'REJECTED',
  reason: '',
  action_title: '',
  priority: null as string | null,
  due_date: '',
  hierarchy_level: null as string | null,
})

function openTriage(h: Hazard) {
  triaging.value = h
  triageForm.reset()
  triageForm.action_title = h.description.slice(0, 80)
}

function submitTriage() {
  if (!triaging.value) return
  triageForm.post(`/sheq/hazards/${triaging.value.uuid}/triage`, {
    preserveScroll: true,
    onSuccess: () => { triaging.value = null },
  })
}

const statusColour: Record<string, string> = {
  OPEN: 'warning', TRIAGED: 'info', CONTROLLED: 'success', CLOSED: 'success', REJECTED: 'grey',
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-5 gap-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.hazard.list_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.hazard.list_subtitle') }}</p>
      </div>
      <Link v-if="can.report" href="/sheq/hazards/create">
        <VBtn color="primary" prepend-icon="mdi-shield-alert-outline">{{ t('sheq.hazard.report_button') }}</VBtn>
      </Link>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <VRow class="mb-2">
      <VCol cols="6" sm="3">
        <VCard elevation="0" border rounded="lg"><VCardText>
          <div class="text-caption text-medium-emphasis">{{ t('sheq.hazard.open') }}</div>
          <div class="text-h4 font-weight-bold">{{ summary.open }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border rounded="lg" :color="summary.triage_overdue ? 'error' : undefined"
               :variant="summary.triage_overdue ? 'tonal' : 'elevated'"><VCardText>
          <div class="text-caption">{{ t('sheq.hazard.triage_overdue') }}</div>
          <div class="text-h4 font-weight-bold">{{ summary.triage_overdue }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border rounded="lg"><VCardText>
          <div class="text-caption text-medium-emphasis">{{ t('sheq.hazard.controlled') }}</div>
          <div class="text-h4 font-weight-bold">{{ summary.controlled }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border rounded="lg"><VCardText>
          <div class="text-caption text-medium-emphasis">{{ t('sheq.hazard.rejected') }}</div>
          <div class="text-h4 font-weight-bold">{{ summary.rejected }}</div>
        </VCardText></VCard>
      </VCol>
    </VRow>

    <VBtnToggle v-model="filter" mandatory density="compact" variant="outlined" class="mb-3">
      <VBtn value="open" size="small">{{ t('sheq.hazard.filter_open') }}</VBtn>
      <VBtn value="all" size="small">{{ t('sheq.obligation.filter_all') }}</VBtn>
    </VBtnToggle>

    <VCard elevation="0" border rounded="lg">
      <VTable density="comfortable">
        <thead>
          <tr>
            <th scope="col">{{ t('sheq.hazard.reference') }}</th>
            <th scope="col">{{ t('sheq.hazard.what_is_wrong') }}</th>
            <th scope="col">{{ t('sheq.hazard.category') }}</th>
            <th scope="col">{{ t('sheq.hazard.triage_due') }}</th>
            <th scope="col">{{ t('sheq.incident.status') }}</th>
            <th scope="col" v-if="can.triage"><span class="d-sr-only">{{ t('sheq.action.actions') }}</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="h in filtered" :key="h.uuid">
            <td class="text-body-2 font-weight-medium">
              {{ h.reference }}
              <div v-if="h.site_name || h.location" class="text-caption text-medium-emphasis">
                {{ [h.site_name, h.location].filter(Boolean).join(' · ') }}
              </div>
            </td>
            <td class="text-body-2" style="max-width: 340px">
              {{ h.description }}
              <!-- SHEQ-HAZ-3: a rejection is never silent — the reason travels with the record. -->
              <div v-if="h.rejection_reason" class="text-caption text-medium-emphasis mt-1">
                <VIcon size="13">mdi-information-outline</VIcon>
                {{ t('sheq.hazard.rejected_because') }} {{ h.rejection_reason }}
              </div>
            </td>
            <td>
              <VChip v-if="h.category" size="x-small" variant="tonal">{{ h.category }}</VChip>
              <span v-else class="text-medium-emphasis">—</span>
            </td>
            <td class="text-body-2">
              <template v-if="h.triage_due_at">
                {{ new Date(h.triage_due_at).toLocaleString() }}
                <div v-if="h.triage_overdue" class="text-caption text-error font-weight-bold">
                  {{ t('sheq.hazard.overdue') }}
                </div>
              </template>
              <span v-else class="text-medium-emphasis">—</span>
            </td>
            <td><VChip :color="statusColour[h.status]" size="x-small" variant="tonal">{{ h.status }}</VChip></td>
            <td v-if="can.triage" class="text-end">
              <VBtn v-if="h.status === 'OPEN'" size="x-small" variant="tonal" @click="openTriage(h)">
                {{ t('sheq.hazard.triage') }}
              </VBtn>
              <!-- A hazard that turned out to be an incident, joined to it (SHEQ-HAZ-4). -->
              <VBtn v-if="can.triage && h.status !== 'REJECTED' && (linkableIncidents?.length ?? 0) > 0"
                    size="x-small" variant="text" class="ms-1" @click="openLink(h)">
                {{ t('sheq.hazard.link_incident') }}
              </VBtn>
            </td>
          </tr>
          <tr v-if="!filtered.length">
            <td :colspan="can.triage ? 6 : 5" class="text-center py-10 text-medium-emphasis">
              {{ t('sheq.hazard.empty') }}
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Triage -->
    <VDialog :model-value="!!triaging" max-width="640" @update:model-value="triaging = null">
      <VCard rounded="lg">
        <VCardTitle class="pt-5 px-5">{{ t('sheq.hazard.triage_title') }}</VCardTitle>
        <VCardText class="px-5">
          <p class="text-body-2 text-medium-emphasis mb-4">{{ triaging?.description }}</p>

          <VSelect v-model="triageForm.outcome" variant="outlined" density="comfortable" class="mb-3"
                   :label="t('sheq.hazard.outcome')"
                   :items="[
                     { title: t('sheq.hazard.outcome_action'), value: 'ACTION' },
                     { title: t('sheq.hazard.outcome_controlled'), value: 'CONTROLLED' },
                     { title: t('sheq.hazard.outcome_rejected'), value: 'REJECTED' },
                   ]" />

          <template v-if="triageForm.outcome === 'ACTION'">
            <VTextField v-model="triageForm.action_title" :label="t('sheq.hazard.action_title')"
                        variant="outlined" density="comfortable" class="mb-3" />
            <VRow>
              <VCol cols="12" sm="6">
                <VSelect v-model="triageForm.priority" :items="['CRITICAL', 'HIGH', 'MEDIUM', 'LOW']"
                         :label="t('sheq.action.priority')" variant="outlined" density="comfortable" clearable />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField v-model="triageForm.due_date" type="date" :label="t('sheq.action.due')"
                            variant="outlined" density="comfortable" />
              </VCol>
              <VCol cols="12">
                <VSelect v-model="triageForm.hierarchy_level" clearable variant="outlined" density="comfortable"
                         :items="['ELIMINATION', 'SUBSTITUTION', 'ENGINEERING', 'ADMINISTRATIVE', 'PPE']"
                         :label="t('sheq.action.hierarchy')" :hint="t('sheq.action.hierarchy_hint')" persistent-hint />
              </VCol>
            </VRow>
          </template>

          <!-- Required for rejection, and the server refuses without it regardless of this form. -->
          <VTextarea v-if="triageForm.outcome === 'REJECTED'" v-model="triageForm.reason" rows="3"
                     :label="t('sheq.hazard.reason_required')" variant="outlined"
                     :error-messages="triageForm.errors.reason"
                     :hint="t('sheq.hazard.reason_hint')" persistent-hint class="mt-2" />
        </VCardText>
        <VCardActions class="px-5 pb-5 justify-end gap-2">
          <VBtn variant="tonal" @click="triaging = null">{{ t('sheq.action.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="triageForm.processing" @click="submitTriage">
            {{ t('sheq.hazard.triage') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>

    <VDialog :model-value="!!linking" max-width="620" @update:model-value="linking = null">
      <VCard v-if="linking">
        <VCardTitle>{{ t('sheq.hazard.link_title', { reference: linking.reference }) }}</VCardTitle>
        <VDivider />
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">{{ t('sheq.hazard.link_hint') }}</p>
          <VAutocomplete
            v-model="linkForm.incident_id"
            :items="linkableIncidents ?? []"
            item-title="label"
            item-value="id"
            :label="t('sheq.hazard.incident')"
            variant="outlined"
            density="comfortable"
            :error-messages="linkForm.errors.incident_id"
          />
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="linking = null">{{ t('sheq.action.cancel') }}</VBtn>
          <VBtn color="primary" :loading="linkForm.processing"
                :disabled="!linkForm.incident_id" @click="submitLink">
            {{ t('sheq.hazard.link_incident') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </template>
