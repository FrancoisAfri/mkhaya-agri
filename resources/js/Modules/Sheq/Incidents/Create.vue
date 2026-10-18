<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  sites: { id: number; name: string; site_type: string }[]
  types: string[]
  outcomes: string[]
  treatmentLevels: string[]
  allowAnonymous: boolean
  /** Staff who can be named as the person it happened to. Empty when HR is not installed. */
  people: { id: number; full_name: string; employee_number: string | null; job_title: string | null }[]
  /** The rulebook in force, or null when this tenant has adopted none. */
  activePack: { name: string; version: string } | null
  /** Why reporting is refused without one, in the same words the server uses. */
  noPackReason: string
  /** False when nothing is installed at all, which a tenant administrator cannot fix. */
  anyPackInstalled: boolean
}>()

/**
 * SHEQ-INC-1: a report must be possible in under a minute with four fields. Everything else lives
 * behind "Add detail", collapsed by default — a practitioner enriches it later. A form that asks a
 * shaken reporter for twenty fields is a form that produces no reports.
 */
const showDetail = ref(false)

// Client-generated idempotency key (SHEQ-OFF-2): submitting twice, or a retry after a dropped
// connection, must not file the same incident twice.
const clientId = crypto.randomUUID()

const form = useForm({
  description: '',
  occurred_at: new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16),
  incident_type: 'NEAR_MISS',
  site_id: null as number | null,
  location_text: '',
  outcome: null as string | null,
  treatment_level: null as string | null,
  actual_severity: null as number | null,
  potential_severity: null as number | null,
  likely_incapacity_days: null as number | null,
  immediate_action: '',
  activity_at_time: '',
  dangerous_substance_release: false,
  machinery_failure_causing_danger: false,
  is_confidential: false,
  is_anonymous: false,
  client_id: clientId,

  /*
   * Who it happened to.
   *
   * The controller has always accepted `people[]` and the reporter has always written it; nothing
   * ever sent one, because the form did not ask. That is not cosmetic: StatutoryFormService reads
   * the person with role INJURED or AFFECTED to fill in the W.Cl.2, so every form sent to the
   * Compensation Commissioner went out with the injured person blank.
   *
   * Sent as a list because the table is a list — a vehicle incident can hurt two people — but the
   * form asks for one, which is the ordinary case. Somebody who needs to add a second does it on
   * the incident afterwards.
   */
  people: [] as Array<{ role: string; hr_person_id: number | null; external_name: string | null }>,
})

/*
 * Staff, or a name typed in.
 *
 * A dropdown alone cannot record an incident that happened to a visitor, a delivery driver or a
 * contractor's employee — and on a site those are common. Forced to choose from staff, somebody
 * picks the nearest wrong name, and a wrong name on a statutory form is worse than none.
 */
const affectedPersonId = ref<number | null>(null)
const affectedExternalName = ref('')

const peopleItems = computed(() => props.people.map(person => ({
  // The employee number tells two people of the same name apart, which is the whole reason it is here.
  title: person.employee_number ? `${person.full_name} · ${person.employee_number}` : person.full_name,
  subtitle: person.job_title,
  value: person.id,
})))

/*
 * Rebuilt whenever either input changes rather than assembled on submit — a form that quietly
 * drops a field because the submit handler forgot it is the bug this whole field is fixing.
 */
watch([affectedPersonId, affectedExternalName], ([id, name]) => {
  const named = id !== null || (name ?? '').trim() !== ''

  form.people = named
    ? [{
        // AFFECTED, not WITNESS: the reporter's default. This is the role the statutory form reads.
        role: 'AFFECTED',
        hr_person_id: id,
        external_name: id === null ? (name ?? '').trim() || null : null,
      }]
    : []
})

const severities = [1, 2, 3, 4, 5]

/**
 * Potential severity drives investigation depth (SHEQ-INC-7), so it is asked for even on a
 * near-miss where nothing happened — that is the entire point of the field.
 */
const potentialHint = computed(() => {
  if (!form.potential_severity) return null
  return form.potential_severity >= 4 ? t('sheq.incident.potential_hint_high') : null
})

function submit() {
  form.post('/sheq/incidents', { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('sheq.incident.report_title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.incident.report_subtitle') }}</p>
    </div>

    <!--
      Said before the form rather than after it. Submitting without an active pack is refused by
      the server, and a reporter should not discover that having already typed the account of an
      injury.
    -->
    <VAlert v-if="!activePack" type="warning" variant="tonal" class="mb-5">
      <div class="text-body-2">{{ noPackReason }}</div>
      <template v-if="anyPackInstalled" #append>
        <VBtn
          href="/sheq/regulatory-packs"
          variant="tonal"
          size="small"
          color="warning"
        >
          {{ t('sheq.incident.open_regulatory_packs') }}
        </VBtn>
      </template>
    </VAlert>

    <VForm @submit.prevent="submit">
      <VRow>
        <VCol cols="12" md="8">
          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardText>
              <!-- The four mandatory fields, in the order a person tells the story. -->
              <VTextarea
                v-model="form.description"
                :label="t('sheq.incident.what_happened')"
                :error-messages="form.errors.description"
                :placeholder="t('sheq.incident.what_happened_hint')"
                rows="4" variant="outlined" autofocus class="mb-4"
              />

              <VRow>
                <VCol cols="12" sm="6">
                  <AppDateTimePicker
                    v-model="form.occurred_at" :label="t('sheq.incident.when')"
                    placeholder="Select date and time"
                    :config="{ enableTime: true, dateFormat: 'Y-m-d H:i', altInput: true, altFormat: 'd M Y, H:i', maxDate: 'today' }"
                    :error-messages="form.errors.occurred_at" />
                </VCol>
                <VCol cols="12" sm="6">
                  <VSelect
                    v-model="form.incident_type" :items="types"
                    :label="t('sheq.incident.type')" :error-messages="form.errors.incident_type"
                    variant="outlined"
                  />
                </VCol>
                <VCol cols="12" sm="6">
                  <VSelect
                    v-model="form.site_id" :items="sites" item-title="name" item-value="id"
                    :label="t('sheq.incident.site')" :error-messages="form.errors.site_id"
                    variant="outlined" clearable
                    :hint="sites.length ? undefined : t('sheq.incident.no_sites')"
                    persistent-hint
                  />
                </VCol>
                <VCol cols="12" sm="6">
                  <VTextField
                    v-model="form.location_text" :label="t('sheq.incident.where')"
                    :placeholder="t('sheq.incident.where_hint')" variant="outlined"
                  />
                </VCol>
              </VRow>
            </VCardText>
          </VCard>

          <VBtn variant="text" size="small" class="mb-4" @click="showDetail = !showDetail">
            <VIcon start size="18">{{ showDetail ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</VIcon>
            {{ showDetail ? t('sheq.incident.hide_detail') : t('sheq.incident.add_detail') }}
          </VBtn>

          <VExpandTransition>
            <VCard v-show="showDetail" elevation="0" border rounded="lg">
              <VCardText>
                <VRow>
                  <!--
                    First in the detail section, because it is the field the statutory forms need
                    and the one a reporter can answer without thinking.
                  -->
                  <VCol cols="12" sm="6">
                    <VAutocomplete
                      v-model="affectedPersonId" :items="peopleItems" clearable
                      :label="t('sheq.incident.affected_person')"
                      :hint="t('sheq.incident.affected_person_hint')" persistent-hint
                      :no-data-text="t('sheq.incident.no_staff')"
                      variant="outlined"
                    >
                      <template #item="{ props: itemProps, item }">
                        <VListItem v-bind="itemProps" :subtitle="item.raw.subtitle" />
                      </template>
                    </VAutocomplete>
                  </VCol>
                  <VCol cols="12" sm="6">
                    <!-- Disabled once a staff member is chosen: two answers to one question is one too many. -->
                    <VTextField
                      v-model="affectedExternalName" clearable
                      :disabled="affectedPersonId !== null"
                      :label="t('sheq.incident.affected_external')"
                      :hint="t('sheq.incident.affected_external_hint')" persistent-hint
                      variant="outlined"
                    />
                  </VCol>

                  <VCol cols="12" sm="6">
                    <VSelect v-model="form.outcome" :items="outcomes" clearable
                             :label="t('sheq.incident.outcome')" variant="outlined" />
                  </VCol>
                  <VCol cols="12" sm="6">
                    <VSelect v-model="form.treatment_level" :items="treatmentLevels" clearable
                             :label="t('sheq.incident.treatment_level')" variant="outlined" />
                  </VCol>
                  <VCol cols="12" sm="6">
                    <VSelect v-model="form.actual_severity" :items="severities" clearable
                             :label="t('sheq.incident.actual_severity')" variant="outlined" />
                  </VCol>
                  <VCol cols="12" sm="6">
                    <VSelect v-model="form.potential_severity" :items="severities" clearable
                             :label="t('sheq.incident.potential_severity')" variant="outlined"
                             :hint="potentialHint ?? t('sheq.incident.potential_hint')" persistent-hint />
                  </VCol>
                  <VCol cols="12" sm="6">
                    <VTextField v-model.number="form.likely_incapacity_days" type="number" min="0"
                                :label="t('sheq.incident.incapacity_days')" variant="outlined"
                                :hint="t('sheq.incident.incapacity_hint')" persistent-hint />
                  </VCol>
                  <VCol cols="12" sm="6">
                    <VTextField v-model="form.activity_at_time" :label="t('sheq.incident.activity')"
                                variant="outlined" />
                  </VCol>
                  <VCol cols="12">
                    <VTextarea v-model="form.immediate_action" rows="2"
                               :label="t('sheq.incident.immediate_action')" variant="outlined" />
                  </VCol>
                  <VCol cols="12">
                    <VCheckbox v-model="form.dangerous_substance_release" density="compact"
                               :label="t('sheq.incident.substance_release')" hide-details />
                    <VCheckbox v-model="form.machinery_failure_causing_danger" density="compact"
                               :label="t('sheq.incident.machinery_failure')" hide-details />
                  </VCol>
                </VRow>
              </VCardText>
            </VCard>
          </VExpandTransition>
        </VCol>

        <VCol cols="12" md="4">
          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardText>
              <div class="text-body-2 font-weight-semibold mb-2">{{ t('sheq.incident.handling') }}</div>

              <VCheckbox v-model="form.is_confidential" density="compact" hide-details
                         :label="t('sheq.incident.confidential')" />
              <p class="text-caption text-medium-emphasis mb-3">{{ t('sheq.incident.confidential_hint') }}</p>

              <template v-if="allowAnonymous">
                <VCheckbox v-model="form.is_anonymous" density="compact" hide-details
                           :label="t('sheq.incident.anonymous')" />
                <p class="text-caption text-medium-emphasis mb-0">{{ t('sheq.incident.anonymous_hint') }}</p>
              </template>
            </VCardText>
          </VCard>

          <VAlert type="info" variant="tonal" density="comfortable" class="mb-4">
            <div class="text-body-2">{{ t('sheq.incident.classification_note') }}</div>
          </VAlert>

          <VBtn
            type="submit"
            color="primary"
            block
            size="large"
            :loading="form.processing"
            :disabled="!activePack"
          >
            {{ t('sheq.incident.submit') }}
          </VBtn>
        </VCol>
      </VRow>
    </VForm>
  </div>
</template>
