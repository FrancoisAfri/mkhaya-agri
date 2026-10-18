<script setup lang="ts">
/**
 * What people were seen doing, and what keeps happening (OBS-1 to OBS-4).
 *
 * The register and the trend on one screen, deliberately. One observation is an anecdote — one
 * person, one morning, one shortcut — and acting on anecdotes is how a safety programme ends up
 * chasing whichever story was told most recently. The value is in the pattern: the same behaviour
 * seen eleven times, mostly at one site, mostly by one contractor. Putting the recurrence report
 * behind its own menu item would mean the people filing observations never see what their filing
 * produced, which is the surest way to stop them filing.
 */
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

type Row = {
  uuid: string
  reference: string
  observation_type: string
  activity: string | null
  location_text: string | null
  behaviour_code: string | null
  behaviour: string | null
  behaviour_name: string | null
  is_high_consequence: boolean | null
  risk_rating: string | null
  immediate_action: string | null
  work_stopped: boolean
  observed_at: string
  status: string
  is_anonymous: boolean
  action_id: number | null
  site_name: string | null
  contractor_name: string | null
  subject_name: string | null
}

type Recurrence = {
  from: string
  to: string
  by_behaviour: { code: string; name: string | null; is_high_consequence: boolean | null
    total: number; stopped: number }[]
  by_site: { name: string | null; total: number }[]
  by_contractor: { name: string | null; total: number }[]
  by_activity: { activity: string; total: number }[]
  by_department: { name: string | null; total: number }[]
  by_period: { period: string; total: number }[]
  mix: {
    by_type: { type: string; label: string; total: number }[]
    positive: number; concerning: number; total: number
    positive_share: number | null; interventions: number
  }
}

const props = defineProps<{
  days: number
  siteId: number | null
  types: { type: string; label: string; concerning: boolean; note: string }[]
  riskRatings: string[]
  rows: Row[]
  behaviours: { code: string; name: string; category: string | null
    is_high_consequence: boolean }[]
  recurrence: Recurrence | null
  sites: { id: number; name: string }[]
  activities: { code: string; name: string }[]
  contractors: { id: number; name: string }[]
  workers: { id: number; name: string; contractor_name: string }[]
  people: Record<string, any>[]
  canRecord?: boolean
  canAction?: boolean
  behavioursInstalled?: boolean
  noCompany?: boolean
}>()

const days = ref(props.days)
const siteId = ref(props.siteId)
const typeFilter = ref<string | null>(null)
const search = ref('')

watch([days, siteId, typeFilter], () => {
  router.get('/sheq/observations',
    { days: days.value, site_id: siteId.value ?? undefined, type: typeFilter.value ?? undefined },
    { preserveState: true, preserveScroll: true, replace: true })
})

const visible = computed(() => {
  const needle = search.value.trim().toLowerCase()

  if (!needle) return props.rows

  return props.rows.filter(r => [r.reference, r.behaviour ?? '', r.behaviour_name ?? '',
    r.site_name ?? '', r.contractor_name ?? '', r.subject_name ?? '', r.location_text ?? '']
    .join(' ').toLowerCase().includes(needle))
})

const typeColour = (type: string) => ({
  POSITIVE: 'success', UNSAFE: 'error', INTERVENTION: 'warning', COACHING: 'info',
}[type] ?? 'grey')

const riskColour = (rating: string | null) => ({
  LOW: 'success', MEDIUM: 'info', HIGH: 'warning', CRITICAL: 'error',
}[rating ?? ''] ?? 'grey')

const label = (value: string) =>
  value.toLowerCase().replace(/_/g, ' ').replace(/^./, c => c.toUpperCase())

// ── Recording one ─────────────────────────────────────────────────────────────────────────────
const dialog = ref(false)

const form = useForm({
  observation_type: 'UNSAFE',
  site_id: null as number | null,
  activity: null as string | null,
  location_text: '',
  contractor_id: null as number | null,
  contractor_worker_id: null as number | null,
  hr_person_id: null as number | null,
  behaviour_code: null as string | null,
  behaviour: '',
  risk_rating: null as string | null,
  immediate_action: '',
  work_stopped: false,
  observed_at: '',
  is_anonymous: false,
})

const chosenType = computed(() =>
  props.types.find(t => t.type === form.observation_type) ?? null)

// An intervention IS work being stopped, so the switch follows the kind rather than asking
// twice and letting the two disagree.
watch(() => form.observation_type, type => {
  if (type === 'INTERVENTION') form.work_stopped = true
})

const behaviourItems = computed(() => props.behaviours.map(b => ({
  value: b.code,
  title: b.is_high_consequence ? `${b.name} — high consequence` : b.name,
  props: { subtitle: b.category ? label(b.category) : undefined },
})))

function submit() {
  form.post('/sheq/observations', {
    preserveScroll: true,
    onSuccess: () => { dialog.value = false; form.reset() },
  })
}

// ── Raising a CAPA (OBS-3) ────────────────────────────────────────────────────────────────────
const actioning = ref<Row | null>(null)

const actionForm = useForm({
  title: '',
  description: '',
  due_date: '',
  priority: 'MEDIUM',
  hierarchy_level: null as string | null,
})

function openAction(row: Row) {
  actioning.value = row
  actionForm.reset()
  actionForm.title = `${row.reference}: ${(row.behaviour ?? '').slice(0, 100)}`
  actionForm.description = row.behaviour ?? ''
}

function submitAction() {
  if (!actioning.value) return

  actionForm.post(`/sheq/observations/${actioning.value.uuid}/action`, {
    preserveScroll: true,
    onSuccess: () => { actioning.value = null },
  })
}

function close(row: Row) {
  router.post(`/sheq/observations/${row.uuid}/close`, {}, { preserveScroll: true })
}

function installBehaviours() {
  router.post('/sheq/observations/behaviours/install', {}, { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">Safety observations</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          What people were seen doing — and which behaviours keep coming back
        </p>
      </div>
      <VBtn v-if="canRecord" color="primary" variant="flat" prepend-icon="mdi-plus"
            @click="dialog = true">
        Record an observation
      </VBtn>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VAlert v-if="!behavioursInstalled" type="info" variant="tonal" class="mb-4">
        <div class="d-flex align-center flex-wrap ga-3">
          <div class="flex-grow-1">
            <!-- Said rather than silently working, because recurrence cannot be found in free
                 text: "not clipped on", "no harness" and "unsecured at height" are one
                 behaviour typed three ways, and without a vocabulary the report says every
                 behaviour happened once. -->
            <div class="font-weight-semibold">No behaviour list yet</div>
            <div class="text-body-2">
              Observations can be filed without one, and the recurring-behaviour report needs it:
              the same behaviour typed three different ways counts as three separate things.
            </div>
          </div>
          <VBtn variant="flat" size="small" @click="installBehaviours">
            Add the starting list
          </VBtn>
        </div>
      </VAlert>

      <!-- ── The ratio that says whether anybody trusts the programme ──────────────────── -->
      <VRow v-if="recurrence" dense class="mb-1">
        <VCol v-for="tile in [
          { label: 'Observations', value: recurrence.mix.total, hint: `last ${days} days` },
          { label: 'Unsafe or stopped', value: recurrence.mix.concerning, hint: '' },
          { label: 'Interventions', value: recurrence.mix.interventions,
            hint: 'work actually stopped' },
          { label: 'Positive share',
            value: recurrence.mix.positive_share === null ? '—'
              : recurrence.mix.positive_share + '%',
            hint: 'good practice as a share of all' },
        ]" :key="tile.label" cols="6" md="3">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-caption text-uppercase text-medium-emphasis">{{ tile.label }}</div>
            <div class="text-h4 font-weight-bold">{{ tile.value }}</div>
            <div v-if="tile.hint" class="text-caption text-medium-emphasis">{{ tile.hint }}</div>
          </VCard>
        </VCol>
      </VRow>

      <VAlert v-if="recurrence && recurrence.mix.positive_share !== null
                && recurrence.mix.positive_share < 20 && recurrence.mix.total >= 10"
              type="warning" variant="tonal" class="mb-4">
        <!--
          The number a safety manager should read before any of the others. A site filing ninety
          unsafe observations and two positive ones does not have a more dangerous workforce than
          one filing fifty of each — it has a culture in which being observed is a punishment, and
          the behaviour figures coming out of it cannot be believed.
        -->
        Only {{ recurrence.mix.positive_share }}% of these observations record good practice.
        Where being observed is only ever bad news, people stop filing — including the near
        misses. Treat the behaviour counts below with that in mind.
      </VAlert>

      <VCard elevation="0" border rounded="lg" class="mb-4">
        <VCardText class="d-flex flex-wrap align-center ga-3">
          <VTextField
            v-model="search" density="compact" variant="outlined" hide-details clearable
            prepend-inner-icon="mdi-magnify" style="max-width: 280px" label="Search"
          />
          <VSelect
            v-model="typeFilter" :items="types.map(t => ({ value: t.type, title: t.label }))"
            density="compact" variant="outlined" hide-details clearable style="max-width: 220px"
            label="Kind"
          />
          <VSelect
            v-model="siteId" :items="sites.map(s => ({ value: s.id, title: s.name }))"
            density="compact" variant="outlined" hide-details clearable style="max-width: 220px"
            label="Site"
          />
          <VSelect
            v-model="days" density="compact" variant="outlined" hide-details
            style="max-width: 170px" label="Period"
            :items="[
              { value: 30, title: 'Last 30 days' },
              { value: 90, title: 'Last 90 days' },
              { value: 180, title: 'Last 6 months' },
              { value: 365, title: 'Last year' },
            ]"
          />
        </VCardText>
      </VCard>

      <VRow>
        <!-- ── The register ─────────────────────────────────────────────────────────────── -->
        <VCol cols="12" md="7">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">Observations</VCardTitle>
            <VDivider />

            <div v-if="!visible.length" class="text-center py-10 text-medium-emphasis">
              <VIcon icon="mdi-eye-outline" size="32" class="mb-2" />
              <div class="text-body-2">
                {{ rows.length ? 'Nothing matches that.' : 'Nothing recorded in this period.' }}
              </div>
            </div>

            <VTable v-else density="compact" class="obs-list">
              <tbody>
                <tr v-for="row in visible" :key="row.uuid">
                  <td class="py-2">
                    <div class="d-flex align-center flex-wrap ga-2">
                      <VChip size="x-small" variant="tonal"
                             :color="typeColour(row.observation_type)">
                        {{ label(row.observation_type) }}
                      </VChip>
                      <span class="text-body-2 font-weight-medium">{{ row.reference }}</span>
                      <VChip v-if="row.behaviour_name" size="x-small" variant="tonal"
                             :color="row.is_high_consequence ? 'error' : 'grey'">
                        {{ row.behaviour_name }}
                      </VChip>
                      <VChip v-if="row.risk_rating" size="x-small" variant="tonal"
                             :color="riskColour(row.risk_rating)">
                        {{ label(row.risk_rating) }}
                      </VChip>
                      <VChip v-if="row.work_stopped" size="x-small" variant="tonal"
                             color="warning" prepend-icon="mdi-hand-back-left">
                        work stopped
                      </VChip>
                      <VChip v-if="row.action_id" size="x-small" variant="tonal" color="indigo">
                        CAPA raised
                      </VChip>
                    </div>
                    <div class="text-body-2 mt-1">{{ row.behaviour }}</div>
                    <div class="text-caption text-medium-emphasis">
                      {{ row.observed_at }}
                      <template v-if="row.site_name"> · {{ row.site_name }}</template>
                      <template v-if="row.location_text"> · {{ row.location_text }}</template>
                      <template v-if="row.activity"> · {{ label(row.activity) }}</template>
                      <template v-if="row.contractor_name"> · {{ row.contractor_name }}</template>
                      <template v-if="row.subject_name"> · {{ row.subject_name }}</template>
                    </div>
                    <div v-if="row.immediate_action" class="text-caption mt-1">
                      Done there and then: {{ row.immediate_action }}
                    </div>
                  </td>
                  <td style="width: 150px" class="text-end">
                    <VBtn v-if="canAction && !row.action_id && row.status !== 'CLOSED'"
                          size="x-small" variant="text" @click="openAction(row)">
                      Raise CAPA
                    </VBtn>
                    <VBtn v-if="row.status === 'OPEN' && canRecord" size="x-small" variant="text"
                          @click="close(row)">
                      Close
                    </VBtn>
                    <VChip v-if="row.status === 'CLOSED'" size="x-small" variant="text">
                      Closed
                    </VChip>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>

        <!-- ── OBS-4: what keeps happening ──────────────────────────────────────────────── -->
        <VCol cols="12" md="5">
          <VCard v-if="recurrence" elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              What keeps happening
              <span class="text-caption text-medium-emphasis font-weight-regular">
                · unsafe and interventions only
              </span>
            </VCardTitle>
            <VDivider />
            <VCardText class="pb-2">
              <p class="text-caption text-medium-emphasis mb-0">
                Positive observations are left out of these counts — including them would make
                the best-reporting crew look like the worst offender.
              </p>
            </VCardText>

            <VExpansionPanels variant="accordion" flat>
              <VExpansionPanel v-for="cut in [
                { title: 'By behaviour', rows: recurrence.by_behaviour, key: 'behaviour' },
                { title: 'By site', rows: recurrence.by_site, key: 'site' },
                { title: 'By contractor', rows: recurrence.by_contractor, key: 'contractor' },
                { title: 'By activity', rows: recurrence.by_activity, key: 'activity' },
                { title: 'By department', rows: recurrence.by_department, key: 'department' },
                { title: 'By month', rows: recurrence.by_period, key: 'period' },
              ]" :key="cut.key" :title="`${cut.title} (${cut.rows.length})`">
                <VExpansionPanelText>
                  <div v-if="!cut.rows.length" class="text-caption text-medium-emphasis">
                    Nothing recorded against this.
                  </div>
                  <VTable v-else density="compact">
                    <tbody>
                      <tr v-for="(r, i) in cut.rows" :key="i">
                        <td class="text-body-2">
                          {{ (r as any).name || (r as any).activity || (r as any).period
                             || (r as any).code || 'Not recorded' }}
                          <VChip v-if="(r as any).is_high_consequence" size="x-small"
                                 variant="tonal" color="error" class="ml-1">
                            high consequence
                          </VChip>
                        </td>
                        <td class="text-end text-body-2 font-weight-medium">
                          {{ (r as any).total }}
                        </td>
                        <td v-if="(r as any).stopped !== undefined"
                            class="text-end text-caption text-medium-emphasis">
                          {{ (r as any).stopped }} stopped
                        </td>
                      </tr>
                    </tbody>
                  </VTable>
                </VExpansionPanelText>
              </VExpansionPanel>
            </VExpansionPanels>
          </VCard>

          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">The four kinds</VCardTitle>
            <VDivider />
            <VTable density="compact">
              <tbody>
                <tr v-for="t in types" :key="t.type">
                  <td class="py-2">
                    <VChip size="x-small" variant="tonal" :color="typeColour(t.type)">
                      {{ t.label }}
                    </VChip>
                    <div class="text-caption text-medium-emphasis mt-1">{{ t.note }}</div>
                  </td>
                  <td class="text-end text-body-2 font-weight-medium" style="width: 56px">
                    {{ recurrence?.mix.by_type.find(m => m.type === t.type)?.total ?? 0 }}
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <!-- ── Recording one (OBS-1, OBS-2) ──────────────────────────────────────────────────── -->
    <VDialog v-model="dialog" max-width="820" scrollable>
      <VCard>
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          Record an observation
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VSelect
                v-model="form.observation_type"
                :items="types.map(t => ({ value: t.type, title: t.label }))"
                density="comfortable" variant="outlined" hide-details="auto" label="Kind"
                :error-messages="form.errors.observation_type"
              />
              <p v-if="chosenType" class="text-caption text-medium-emphasis mb-0 mt-1">
                {{ chosenType.note }}
              </p>
            </VCol>

            <VCol cols="12" md="6">
              <VSelect v-model="form.site_id"
                       :items="sites.map(s => ({ value: s.id, title: s.name }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Site" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="form.activity"
                       :items="activities.map(a => ({ value: a.code, title: a.name }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Activity" />
            </VCol>
            <VCol cols="12">
              <VTextField v-model="form.location_text" density="comfortable" variant="outlined"
                          hide-details label="Where exactly" />
            </VCol>

            <VCol cols="12">
              <VSelect v-model="form.behaviour_code" :items="behaviourItems"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Behaviour" />
            </VCol>
            <VCol cols="12">
              <!-- Required, where the code is not. An observer who cannot find the right code
                   must still be able to file what they saw: a dropdown that blocks the report is
                   a dropdown that loses the report. -->
              <VTextarea v-model="form.behaviour" rows="3" density="comfortable"
                         variant="outlined" hide-details="auto"
                         label="What you saw"
                         :error-messages="form.errors.behaviour" />
            </VCol>

            <VCol cols="12" md="6">
              <VSelect v-model="form.risk_rating"
                       :items="riskRatings.map(r => ({ value: r, title: label(r) }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Risk had it carried on"
                       hint="Not what happened — nothing happened, which is the point."
                       persistent-hint />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.observed_at" type="datetime-local" density="comfortable"
                          variant="outlined" hide-details label="When"
                          hint="Empty means now." persistent-hint />
            </VCol>

            <VCol cols="12">
              <VDivider class="mb-3" />
              <div class="text-subtitle-2 font-weight-bold">Who it was about</div>
              <!-- Optional on purpose: attributing every observation to an individual is how a
                   behavioural programme becomes a disciplinary one, and then observers stop
                   filing. What gets counted is the behaviour. -->
              <p class="text-caption text-medium-emphasis mb-0">
                Optional. Leave it off where naming somebody would stop people reporting — the
                behaviour is what gets counted.
              </p>
            </VCol>
            <VCol cols="12" md="6">
              <VAutocomplete v-model="form.contractor_worker_id"
                             :items="workers.map(w => ({ value: w.id,
                               title: `${w.name} — ${w.contractor_name}` }))"
                             density="comfortable" variant="outlined" hide-details clearable
                             label="Contractor worker" />
            </VCol>
            <VCol cols="12" md="6">
              <VAutocomplete v-model="form.hr_person_id"
                             :items="people.map(p => ({ value: p.id, title: p.name ?? p.label }))"
                             density="comfortable" variant="outlined" hide-details clearable
                             label="Employee" />
            </VCol>
            <VCol v-if="!form.contractor_worker_id" cols="12" md="6">
              <VSelect v-model="form.contractor_id"
                       :items="contractors.map(c => ({ value: c.id, title: c.name }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Contractor (no individual named)" />
            </VCol>

            <VCol cols="12">
              <VDivider class="mb-3" />
              <VTextarea v-model="form.immediate_action" rows="2" density="comfortable"
                         variant="outlined" hide-details
                         label="What you did there and then" />
            </VCol>
            <VCol cols="12" md="6">
              <VSwitch v-model="form.work_stopped" color="warning" density="compact" hide-details
                       :disabled="form.observation_type === 'INTERVENTION'"
                       label="Work was stopped" />
            </VCol>
            <VCol cols="12" md="6">
              <VSwitch v-model="form.is_anonymous" color="primary" density="compact" hide-details
                       label="Do not show my name" />
              <p class="text-caption text-medium-emphasis mb-0">
                Your name is still recorded for audit; the screens will not show it.
              </p>
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="dialog = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="form.processing"
                :disabled="!form.behaviour.trim()" @click="submit">
            Record
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── OBS-3: raising a CAPA ─────────────────────────────────────────────────────────── -->
    <VDialog :model-value="actioning !== null" max-width="640" scrollable
             @update:model-value="actioning = null">
      <VCard v-if="actioning">
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          Raise a corrective action from {{ actioning.reference }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VTextField v-model="actionForm.title" density="comfortable" variant="outlined"
                          hide-details="auto" label="Action"
                          :error-messages="actionForm.errors.title" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="actionForm.description" rows="3" density="comfortable"
                         variant="outlined" hide-details label="What has to be done" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="actionForm.due_date" type="date" density="comfortable"
                          variant="outlined" hide-details label="Due" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="actionForm.priority"
                       :items="['LOW', 'MEDIUM', 'HIGH'].map(p => ({ value: p, title: label(p) }))"
                       density="comfortable" variant="outlined" hide-details label="Priority" />
            </VCol>
            <VCol cols="12">
              <!-- The question that asks whether anything about the work changed. An observation
                   answered only by "spoke to the crew" is the commonest way a behavioural
                   programme achieves nothing at all. -->
              <VSelect v-model="actionForm.hierarchy_level"
                       :items="['ELIMINATION', 'SUBSTITUTION', 'ENGINEERING', 'ADMINISTRATIVE',
                         'PPE'].map(h => ({ value: h, title: label(h) }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Hierarchy of control"
                       hint="Talking to people is the weakest control there is. What changes about the work?"
                       persistent-hint />
            </VCol>
            <VCol v-if="actionForm.errors.action" cols="12">
              <VAlert type="error" variant="tonal" density="compact">
                {{ actionForm.errors.action }}
              </VAlert>
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="actioning = null">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="actionForm.processing"
                @click="submitAction">
            Raise it
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.obs-list { max-height: 760px; overflow-y: auto; }
</style>
