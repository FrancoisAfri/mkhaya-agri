<script setup lang="ts">
/**
 * What people are exposed to, and what has accumulated (OH-1 to OH-6).
 *
 * The surveillance list leads, because it is the part with a legal obligation attached and the
 * part nobody can assemble by hand: somebody exposed above the action level for noise needs
 * audiometry whether or not anybody remembered to enrol them, and that list is derived from the
 * exposure rather than from an enrolment register.
 *
 * An injury is an event and an exposure is a dose. So every result shows the limit AS IT STOOD
 * when the measurement was taken — a limit tightened in 2031 must not retrospectively make 2026
 * a breach, and that stamped number is what a disease claim fourteen years later turns on.
 */
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const props = defineProps<{
  agents: Record<string, any>[]
  groups: Record<string, any>[]
  results: Record<string, any>[]
  surveillance: Record<string, any>[]
  history: {
    results: Record<string, any>[]
    by_agent: Record<string, any>[]
    total: number
    exceedances: number
  } | null
  historyFor: { person: number | null; worker: number | null }
  agentTypes: { key: string; label: string }[]
  sampleTypes: { key: string; label: string }[]
  ppeItems: { id: number; code: string; name: string; category: string | null }[]
  medicalTypes: { id: number; name: string; examination_type: string }[]
  sites: { id: number; name: string }[]
  people: Record<string, any>[]
  workers: { id: number; name: string; contractor_name: string }[]
  agentsInstalled?: boolean
  canRecord?: boolean
  canConfigure?: boolean
  noCompany?: boolean
}>()

const tab = ref('results')
const exceededOnly = ref(false)
const agentFilter = ref<number | null>(null)

watch([exceededOnly, agentFilter], () => {
  router.get('/sheq/occupational-health', {
    exceeded: exceededOnly.value ? 1 : undefined,
    agent_id: agentFilter.value ?? undefined,
  }, { preserveState: true, preserveScroll: true, replace: true })
})

const label = (value: string | null) =>
  (value ?? '').toLowerCase().replace(/_/g, ' ').replace(/^./, c => c.toUpperCase())

const exceedances = computed(() => props.results.filter(r => r.exceeded_limit).length)

function showHistory(personId: number | null, workerId: number | null) {
  router.get('/sheq/occupational-health',
    { person: personId ?? undefined, worker: workerId ?? undefined },
    { preserveState: true, preserveScroll: true, replace: true })
}

// ── OH-2 ──────────────────────────────────────────────────────────────────────────────────────
const resultDialog = ref(false)

const resultForm = useForm({
  agent_id: null as number | null,
  group_id: null as number | null,
  hr_person_id: null as number | null,
  contractor_worker_id: null as number | null,
  site_id: null as number | null,
  location_text: '',
  value: null as number | null,
  unit: '',
  sample_type: 'PERSONAL',
  duration_minutes: null as number | null,
  measured_on: '',
  measured_by: '',
  laboratory: '',
  certificate_reference: '',
  notes: '',
})

const chosenAgent = computed(() =>
  props.agents.find(a => a.id === resultForm.agent_id) ?? null)

// The unit follows the agent, because a noise result in mg/m³ is a typing mistake nobody catches.
watch(() => resultForm.agent_id, () => {
  resultForm.unit = chosenAgent.value?.limit_unit ?? ''
})

const willExceed = computed(() => {
  const limit = chosenAgent.value?.limit_value
  return limit !== null && limit !== undefined && resultForm.value !== null
    && resultForm.value > Number(limit)
})

function submitResult() {
  resultForm.post('/sheq/occupational-health/results', {
    preserveScroll: true,
    onSuccess: () => { resultDialog.value = false; resultForm.reset() },
  })
}

// ── OH-1 / OH-4 / OH-5 / OH-6 ─────────────────────────────────────────────────────────────────
const editingAgent = ref<Record<string, any> | null>(null)
const agentForm = useForm({
  limit_value: null as number | null,
  limit_unit: '',
  action_level: null as number | null,
  limit_source: '',
  requires_medical_surveillance: false,
  medical_type_id: null as number | null,
  capa_on_exceedance: true,
  investigate_on_exceedance: false,
})

function openAgent(agent: Record<string, any>) {
  editingAgent.value = agent
  agentForm.limit_value = agent.limit_value
  agentForm.limit_unit = agent.limit_unit ?? ''
  agentForm.action_level = agent.action_level
  agentForm.limit_source = agent.limit_source ?? ''
  agentForm.requires_medical_surveillance = !!agent.requires_medical_surveillance
  agentForm.medical_type_id = agent.medical_type_id ?? null
  agentForm.capa_on_exceedance = !!agent.capa_on_exceedance
  agentForm.investigate_on_exceedance = !!agent.investigate_on_exceedance
}

function submitAgent() {
  if (!editingAgent.value) return
  agentForm.post(`/sheq/occupational-health/agents/${editingAgent.value.uuid}`, {
    preserveScroll: true,
    onSuccess: () => { editingAgent.value = null },
  })
}

const ppeFor = ref<Record<string, any> | null>(null)
const ppeForm = useForm({
  ppe_item_id: null as number | null,
  is_required: true,
  minimum_specification: '',
})

function submitPpe() {
  if (!ppeFor.value) return
  ppeForm.post(`/sheq/occupational-health/agents/${ppeFor.value.uuid}/ppe`, {
    preserveScroll: true,
    onSuccess: () => { ppeFor.value = null; ppeForm.reset() },
  })
}

// ── Groups ────────────────────────────────────────────────────────────────────────────────────
const groupDialog = ref(false)
const groupForm = useForm({
  code: '', name: '', description: '', site_id: null as number | null, activity: '',
})

function submitGroup() {
  groupForm.post('/sheq/occupational-health/groups', {
    preserveScroll: true,
    onSuccess: () => { groupDialog.value = false; groupForm.reset() },
  })
}

const addingTo = ref<Record<string, any> | null>(null)
const memberForm = useForm({
  hr_person_id: null as number | null,
  contractor_worker_id: null as number | null,
  from_date: '',
})

function submitMember() {
  if (!addingTo.value) return
  memberForm.post(`/sheq/occupational-health/groups/${addingTo.value.uuid}/members`, {
    preserveScroll: true,
    onSuccess: () => { addingTo.value = null; memberForm.reset() },
  })
}

function installAgents() {
  router.post('/sheq/occupational-health/agents/install', {}, { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">Occupational exposure</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          Noise, dust, silica, chemicals, fumes, heat, vibration and radiation — measured, and
          accumulated against the people exposed
        </p>
      </div>
      <VBtn v-if="canRecord && agentsInstalled" color="primary" variant="flat"
            prepend-icon="mdi-plus" @click="resultDialog = true">
        Record a result
      </VBtn>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VAlert v-if="!agentsInstalled" type="info" variant="tonal" class="mb-4">
        <div class="d-flex align-center flex-wrap ga-3">
          <div class="flex-grow-1">
            <div class="font-weight-semibold">No exposure register yet</div>
            <div class="text-body-2">
              A starting register with the limits South African law sets — noise, silica, dust,
              welding fume, vibration, heat, lead and asbestos. Have an occupational hygienist
              confirm them before relying on one as evidence.
            </div>
          </div>
          <VBtn v-if="canConfigure" variant="flat" size="small" @click="installAgents">
            Add the starting register
          </VBtn>
        </div>
      </VAlert>

      <!--
        OH-4, first on the page. Derived from exposure rather than from an enrolment list,
        because the obligation follows from what somebody was exposed to — not from somebody
        remembering to enrol them.
      -->
      <VAlert v-if="surveillance.length" type="warning" variant="tonal" class="mb-4">
        <div class="font-weight-semibold mb-1">
          {{ surveillance.length }}
          {{ surveillance.length === 1 ? 'person needs' : 'people need' }}
          medical surveillance from their exposure
        </div>
        <div class="text-body-2 mb-2">
          Measured above the action level for an agent that requires surveillance. The action
          level, not the limit — surveillance exists to catch harm before it is done.
        </div>
        <VTable density="compact" class="bg-transparent">
          <tbody>
            <tr v-for="(s, i) in surveillance.slice(0, 12)" :key="i">
              <td class="text-body-2">{{ s.name }}</td>
              <td class="text-caption">{{ s.result_reference }} · {{ s.measured_on }}</td>
              <td class="text-end">
                <!-- Honest about what this system can and cannot see: a contractor worker's
                     medicals are a credential their employer files, and there is no clinical
                     record here to check. -->
                <VChip size="x-small" variant="tonal"
                       :color="s.has_record === true ? 'success'
                         : s.has_record === false ? 'error' : 'grey'">
                  {{ s.has_record === true ? 'Examined in the last year'
                     : s.has_record === false ? 'No examination on record'
                     : 'Not known here' }}
                </VChip>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VAlert>

      <VTabs v-model="tab" class="mb-3">
        <VTab value="results">Results</VTab>
        <VTab value="agents">Agents and limits</VTab>
        <VTab value="groups">Exposure groups</VTab>
        <VTab value="history">One person's history</VTab>
      </VTabs>

      <VWindow v-model="tab">
        <!-- ── OH-2 ──────────────────────────────────────────────────────────────────────── -->
        <VWindowItem value="results">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              Measurements
              <span v-if="exceedances" class="text-caption text-error font-weight-regular">
                · {{ exceedances }} over the limit
              </span>
            </VCardTitle>
            <VDivider />
            <VCardText class="d-flex flex-wrap align-center ga-3 pb-2">
              <VSelect v-model="agentFilter"
                       :items="agents.map(a => ({ value: a.id, title: a.name }))"
                       density="compact" variant="outlined" hide-details clearable
                       style="max-width: 280px" label="Agent" />
              <VSwitch v-model="exceededOnly" color="error" density="compact" hide-details
                       label="Only exceedances" />
            </VCardText>
            <VDivider />

            <div v-if="!results.length" class="text-center py-10 text-medium-emphasis">
              <VIcon icon="mdi-air-filter" size="32" class="mb-2" />
              <div class="text-body-2">No measurements recorded.</div>
            </div>

            <VTable v-else density="compact">
              <tbody>
                <tr v-for="r in results" :key="r.uuid">
                  <td class="py-2">
                    <div class="d-flex align-center flex-wrap ga-2">
                      <VChip size="x-small" variant="tonal"
                             :color="r.exceeded_limit ? 'error'
                               : r.exceeded_action_level ? 'warning' : 'success'">
                        {{ r.value }} {{ r.unit }}
                      </VChip>
                      <span class="text-body-2">{{ r.agent_name }}</span>
                      <VChip size="x-small" variant="text">{{ label(r.sample_type) }}</VChip>
                      <VChip v-if="r.action_reference" size="x-small" variant="tonal"
                             color="indigo">
                        {{ r.action_reference }}
                      </VChip>
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      {{ r.reference }} · {{ r.measured_on }}
                      <!-- The limit as it stood then, which is what a claim years later turns
                           on — not the limit as it stands now. -->
                      <template v-if="r.limit_at_measurement !== null">
                        · limit then {{ r.limit_at_measurement }}
                      </template>
                      <template v-if="r.subject_name"> · {{ r.subject_name }}</template>
                      <template v-if="r.site_name"> · {{ r.site_name }}</template>
                      <template v-if="r.location_text"> · {{ r.location_text }}</template>
                      <template v-if="r.laboratory"> · {{ r.laboratory }}</template>
                    </div>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VWindowItem>

        <!-- ── OH-1 / OH-4 / OH-5 ────────────────────────────────────────────────────────── -->
        <VWindowItem value="agents">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              Agents and their limits
            </VCardTitle>
            <VDivider />
            <div v-if="!agents.length" class="text-center py-10 text-medium-emphasis">
              <div class="text-body-2">Nothing registered.</div>
            </div>
            <VTable v-else density="compact">
              <tbody>
                <tr v-for="a in agents" :key="a.uuid">
                  <td class="py-2">
                    <div class="d-flex align-center flex-wrap ga-2">
                      <VChip size="x-small" variant="tonal">{{ label(a.agent_type) }}</VChip>
                      <span class="text-body-2">{{ a.name }}</span>
                      <VChip v-if="a.requires_medical_surveillance" size="x-small"
                             variant="tonal" color="pink">
                        surveillance
                      </VChip>
                      <VChip v-if="a.capa_on_exceedance" size="x-small" variant="text">
                        CAPA on exceedance
                      </VChip>
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      {{ a.code }}
                      <template v-if="a.limit_value !== null">
                        · limit {{ a.limit_value }} {{ a.limit_unit }}
                      </template>
                      <template v-else>· no numerical limit</template>
                      <template v-if="a.action_level !== null">
                        · act at {{ a.action_level }}
                      </template>
                      <template v-if="a.limit_source"> · {{ a.limit_source }}</template>
                      <template v-if="a.medical_name"> · {{ a.medical_name }}</template>
                    </div>
                    <!-- OH-5. PPE is the weakest control in the hierarchy and the one most often
                         treated as the whole answer, so what is listed here is a statement that
                         nothing higher up is available. -->
                    <div v-if="a.ppe.length" class="d-flex flex-wrap ga-1 mt-1">
                      <VChip v-for="p in a.ppe" :key="p.code" size="x-small" variant="tonal"
                             :color="p.is_required ? 'primary' : 'grey'">
                        {{ p.name }}
                        <template v-if="p.minimum_specification">
                          ({{ p.minimum_specification }})
                        </template>
                      </VChip>
                    </div>
                  </td>
                  <td style="width: 130px" class="text-end">
                    <VBtn v-if="canConfigure" size="x-small" variant="text" @click="openAgent(a)">
                      Limit
                    </VBtn>
                    <VBtn v-if="canConfigure" size="x-small" variant="text" @click="ppeFor = a">
                      PPE
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VWindowItem>

        <!-- ── Groups ────────────────────────────────────────────────────────────────────── -->
        <VWindowItem value="groups">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="d-flex align-center justify-space-between py-3">
              <span class="text-subtitle-1 font-weight-bold">Similar-exposure groups</span>
              <VBtn v-if="canConfigure" size="small" variant="tonal" prepend-icon="mdi-plus"
                    @click="groupDialog = true">
                Add
              </VBtn>
            </VCardTitle>
            <VDivider />
            <VCardText class="pb-2">
              <!-- Hygiene monitoring samples a representative worker and applies the result to
                   everybody doing that job in that area. A register that could only record
                   results against individuals would either be empty or attribute one person's
                   sample to one person. -->
              <p class="text-caption text-medium-emphasis mb-0">
                A sample is taken on one person and applies to everybody doing that job in that
                area. Membership is dated, so a result only ever counts against the people who
                were in the group on the day it was measured.
              </p>
            </VCardText>
            <div v-if="!groups.length" class="text-center py-8 text-medium-emphasis">
              <div class="text-body-2">No groups defined.</div>
            </div>
            <VTable v-else density="compact">
              <tbody>
                <tr v-for="g in groups" :key="g.uuid">
                  <td class="text-body-2">
                    {{ g.name }}
                    <div class="text-caption text-medium-emphasis">
                      {{ g.code }}
                      <template v-if="g.site_name"> · {{ g.site_name }}</template>
                      <template v-if="g.activity"> · {{ label(g.activity) }}</template>
                    </div>
                  </td>
                  <td class="text-end text-body-2">{{ g.members }} current</td>
                  <td style="width: 100px" class="text-end">
                    <VBtn v-if="canConfigure" size="x-small" variant="text"
                          @click="addingTo = g">
                      Add person
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VWindowItem>

        <!-- ── OH-3 ──────────────────────────────────────────────────────────────────────── -->
        <VWindowItem value="history">
          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardText class="d-flex flex-wrap align-center ga-3">
              <VAutocomplete
                :model-value="historyFor.person"
                :items="people.map(p => ({ value: p.id, title: p.name ?? p.label }))"
                density="compact" variant="outlined" hide-details clearable
                style="max-width: 300px" label="Employee"
                @update:model-value="v => showHistory(v, null)"
              />
              <VAutocomplete
                :model-value="historyFor.worker"
                :items="workers.map(w => ({ value: w.id,
                  title: `${w.name} — ${w.contractor_name}` }))"
                density="compact" variant="outlined" hide-details clearable
                style="max-width: 320px" label="Contractor worker"
                @update:model-value="v => showHistory(null, v)"
              />
            </VCardText>
          </VCard>

          <VCard v-if="!history" elevation="0" border rounded="lg">
            <div class="text-center py-10 text-medium-emphasis">
              <VIcon icon="mdi-account-clock-outline" size="32" class="mb-2" />
              <div class="text-body-2">
                Pick somebody. This is the answer a disease claim asks for years later.
              </div>
            </div>
          </VCard>

          <template v-else>
            <VRow dense class="mb-1">
              <VCol cols="6" md="3">
                <VCard elevation="0" border rounded="lg" class="pa-4">
                  <div class="text-caption text-uppercase text-medium-emphasis">
                    Measurements
                  </div>
                  <div class="text-h4 font-weight-bold">{{ history.total }}</div>
                </VCard>
              </VCol>
              <VCol cols="6" md="3">
                <VCard elevation="0" border rounded="lg" class="pa-4">
                  <div class="text-caption text-uppercase text-medium-emphasis">
                    Over the limit
                  </div>
                  <div class="text-h4 font-weight-bold"
                       :class="history.exceedances ? 'text-error' : ''">
                    {{ history.exceedances }}
                  </div>
                  <div class="text-caption text-medium-emphasis">
                    judged against the limit of the day
                  </div>
                </VCard>
              </VCol>
            </VRow>

            <VCard elevation="0" border rounded="lg" class="mb-4">
              <VCardTitle class="text-subtitle-1 font-weight-bold py-3">By agent</VCardTitle>
              <VDivider />
              <VTable density="compact">
                <tbody>
                  <tr v-for="a in history.by_agent" :key="a.agent_code">
                    <td class="text-body-2">
                      {{ a.agent_name }}
                      <div class="text-caption text-medium-emphasis">
                        {{ a.first }} to {{ a.last }}
                      </div>
                    </td>
                    <td class="text-end text-body-2">{{ a.measurements }}</td>
                    <td class="text-end text-body-2">highest {{ a.highest }}</td>
                    <td class="text-end">
                      <VChip v-if="a.exceedances" size="x-small" variant="tonal" color="error">
                        {{ a.exceedances }} over
                      </VChip>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </VCard>

            <VCard elevation="0" border rounded="lg">
              <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
                Every measurement
              </VCardTitle>
              <VDivider />
              <VTable density="compact">
                <tbody>
                  <tr v-for="r in history.results" :key="r.uuid">
                    <td class="text-body-2">
                      {{ r.agent_name }}
                      <div class="text-caption text-medium-emphasis">
                        {{ r.reference }} · {{ label(r.sample_type) }}
                        <template v-if="r.laboratory"> · {{ r.laboratory }}</template>
                      </div>
                    </td>
                    <td class="text-end text-body-2">{{ r.value }} {{ r.unit }}</td>
                    <td class="text-end text-caption text-medium-emphasis">
                      limit then {{ r.limit_at_measurement ?? '—' }}
                    </td>
                    <td class="text-end text-caption">{{ r.measured_on }}</td>
                    <td class="text-end">
                      <VChip v-if="r.exceeded_then" size="x-small" variant="tonal" color="error">
                        over
                      </VChip>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </VCard>
          </template>
        </VWindowItem>
      </VWindow>
    </template>

    <!-- ── Recording a result ────────────────────────────────────────────────────────────── -->
    <VDialog v-model="resultDialog" max-width="820" scrollable>
      <VCard>
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          Record an exposure measurement
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VSelect v-model="resultForm.agent_id"
                       :items="agents.map(a => ({ value: a.id, title: a.name }))"
                       density="comfortable" variant="outlined" hide-details="auto"
                       label="Agent" :error-messages="resultForm.errors.agent_id" />
              <p v-if="chosenAgent" class="text-caption text-medium-emphasis mb-0 mt-1">
                Limit {{ chosenAgent.limit_value ?? '—' }} {{ chosenAgent.limit_unit }}
                <template v-if="chosenAgent.action_level !== null">
                  · act at {{ chosenAgent.action_level }}
                </template>
                <template v-if="chosenAgent.limit_source">
                  · {{ chosenAgent.limit_source }}
                </template>
              </p>
            </VCol>
            <VCol cols="12" md="4">
              <VTextField v-model.number="resultForm.value" type="number" step="0.0001"
                          density="comfortable" variant="outlined" hide-details="auto"
                          label="Result" :error-messages="resultForm.errors.value" />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField v-model="resultForm.unit" density="comfortable" variant="outlined"
                          hide-details label="Unit" />
            </VCol>
            <VCol cols="12" md="4">
              <VSelect v-model="resultForm.sample_type"
                       :items="sampleTypes.map(s => ({ value: s.key, title: s.label }))"
                       density="comfortable" variant="outlined" hide-details label="Sample" />
            </VCol>
            <VCol v-if="willExceed" cols="12">
              <VAlert type="error" variant="tonal" density="compact">
                This exceeds the limit. A corrective action will be raised and everybody in the
                exposure group will appear on the surveillance list.
              </VAlert>
            </VCol>

            <VCol cols="12">
              <VDivider class="mb-3" />
              <div class="text-subtitle-2 font-weight-bold">Who it applies to</div>
              <p class="text-caption text-medium-emphasis mb-0">
                A group, or one person. A result attached to nobody cannot become anybody's
                exposure history, which is the whole purpose of the register.
              </p>
            </VCol>
            <VCol cols="12" md="4">
              <VSelect v-model="resultForm.group_id"
                       :items="groups.map(g => ({ value: g.id, title: g.name }))"
                       density="comfortable" variant="outlined" hide-details="auto" clearable
                       label="Exposure group" :error-messages="resultForm.errors.group_id" />
            </VCol>
            <VCol cols="12" md="4">
              <VAutocomplete v-model="resultForm.hr_person_id"
                             :items="people.map(p => ({ value: p.id, title: p.name ?? p.label }))"
                             density="comfortable" variant="outlined" hide-details clearable
                             label="Employee" />
            </VCol>
            <VCol cols="12" md="4">
              <VAutocomplete v-model="resultForm.contractor_worker_id"
                             :items="workers.map(w => ({ value: w.id, title: w.name }))"
                             density="comfortable" variant="outlined" hide-details clearable
                             label="Contractor worker" />
            </VCol>

            <VCol cols="12" md="6">
              <VSelect v-model="resultForm.site_id"
                       :items="sites.map(s => ({ value: s.id, title: s.name }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Site" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="resultForm.location_text" density="comfortable"
                          variant="outlined" hide-details label="Where" />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField v-model="resultForm.measured_on" type="date" density="comfortable"
                          variant="outlined" hide-details label="Measured on" />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField v-model.number="resultForm.duration_minutes" type="number"
                          density="comfortable" variant="outlined" hide-details
                          label="Duration (minutes)" />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField v-model="resultForm.measured_by" density="comfortable"
                          variant="outlined" hide-details label="Measured by" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="resultForm.laboratory" density="comfortable"
                          variant="outlined" hide-details label="Laboratory" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="resultForm.certificate_reference" density="comfortable"
                          variant="outlined" hide-details label="Certificate reference" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="resultDialog = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="resultForm.processing"
                :disabled="!resultForm.agent_id || resultForm.value === null"
                @click="submitResult">
            Record
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── An agent's limit ──────────────────────────────────────────────────────────────── -->
    <VDialog :model-value="editingAgent !== null" max-width="640"
             @update:model-value="editingAgent = null">
      <VCard v-if="editingAgent">
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          {{ editingAgent.name }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="4">
              <VTextField v-model.number="agentForm.limit_value" type="number" step="0.0001"
                          density="comfortable" variant="outlined" hide-details="auto"
                          label="Limit" :error-messages="agentForm.errors.limit_value" />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField v-model="agentForm.limit_unit" density="comfortable"
                          variant="outlined" hide-details label="Unit" />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField v-model.number="agentForm.action_level" type="number" step="0.0001"
                          density="comfortable" variant="outlined" hide-details="auto"
                          label="Action level"
                          :error-messages="agentForm.errors.action_level" />
            </VCol>
            <VCol cols="12">
              <VTextField v-model="agentForm.limit_source" density="comfortable"
                          variant="outlined" hide-details label="Where the limit comes from"
                          hint="The limits move — an unattributed number cannot be defended"
                          persistent-hint />
            </VCol>
            <VCol cols="12">
              <VDivider class="mb-2" />
              <VSwitch v-model="agentForm.requires_medical_surveillance" color="pink"
                       density="compact" hide-details
                       label="Exposure puts a person into medical surveillance" />
              <VSelect v-if="agentForm.requires_medical_surveillance"
                       v-model="agentForm.medical_type_id"
                       :items="medicalTypes.map(m => ({ value: m.id, title: m.name }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       class="mt-2" label="Which examination" />
            </VCol>
            <VCol cols="12">
              <VSwitch v-model="agentForm.capa_on_exceedance" color="primary" density="compact"
                       hide-details label="Raise a corrective action on an exceedance" />
              <VSwitch v-model="agentForm.investigate_on_exceedance" color="primary"
                       density="compact" hide-details
                       label="Open a root-cause investigation on an exceedance" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="editingAgent = null">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="agentForm.processing"
                @click="submitAgent">
            Save
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── OH-5 ──────────────────────────────────────────────────────────────────────────── -->
    <VDialog :model-value="ppeFor !== null" max-width="560"
             @update:model-value="ppeFor = null">
      <VCard v-if="ppeFor">
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          PPE that controls {{ ppeFor.name }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VSelect v-model="ppeForm.ppe_item_id"
                   :items="ppeItems.map(p => ({ value: p.id, title: p.name }))"
                   density="comfortable" variant="outlined" hide-details="auto" class="mb-3"
                   label="From the PPE catalogue"
                   :error-messages="ppeForm.errors.ppe_item_id" />
          <VTextField v-model="ppeForm.minimum_specification" density="comfortable"
                      variant="outlined" hide-details class="mb-3"
                      label="Minimum specification"
                      hint="An FFP3, not any dust mask" persistent-hint />
          <VSwitch v-model="ppeForm.is_required" color="primary" density="compact" hide-details
                   label="Required rather than one option" />
          <!-- PPE is the weakest control in the hierarchy and the one most often treated as the
               whole answer. Marking an item required is a statement that nothing higher up is
               available. -->
          <p class="text-caption text-medium-emphasis mb-0">
            Marking it required says nothing higher up the hierarchy of control is available.
          </p>
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="ppeFor = null">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="ppeForm.processing"
                :disabled="!ppeForm.ppe_item_id" @click="submitPpe">
            Link
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── A group ───────────────────────────────────────────────────────────────────────── -->
    <VDialog v-model="groupDialog" max-width="620">
      <VCard>
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          Add a similar-exposure group
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="4">
              <VTextField v-model="groupForm.code" density="comfortable" variant="outlined"
                          hide-details="auto" label="Code"
                          :error-messages="groupForm.errors.code" />
            </VCol>
            <VCol cols="12" md="8">
              <VTextField v-model="groupForm.name" density="comfortable" variant="outlined"
                          hide-details="auto" label="Name"
                          hint="Shotcrete crew — north decline"
                          persistent-hint :error-messages="groupForm.errors.name" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="groupForm.site_id"
                       :items="sites.map(s => ({ value: s.id, title: s.name }))"
                       density="comfortable" variant="outlined" hide-details clearable
                       label="Site" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="groupForm.activity" density="comfortable" variant="outlined"
                          hide-details label="Activity" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="groupDialog = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="groupForm.processing"
                :disabled="!groupForm.code.trim() || !groupForm.name.trim()"
                @click="submitGroup">
            Add
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Group membership ──────────────────────────────────────────────────────────────── -->
    <VDialog :model-value="addingTo !== null" max-width="560"
             @update:model-value="addingTo = null">
      <VCard v-if="addingTo">
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          Add somebody to {{ addingTo.name }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VAutocomplete v-model="memberForm.hr_person_id"
                         :items="people.map(p => ({ value: p.id, title: p.name ?? p.label }))"
                         density="comfortable" variant="outlined" hide-details="auto"
                         clearable class="mb-3" label="Employee"
                         :error-messages="memberForm.errors.hr_person_id" />
          <VAutocomplete v-model="memberForm.contractor_worker_id"
                         :items="workers.map(w => ({ value: w.id,
                           title: `${w.name} — ${w.contractor_name}` }))"
                         density="comfortable" variant="outlined" hide-details clearable
                         class="mb-3" label="Or a contractor worker" />
          <VTextField v-model="memberForm.from_date" type="date" density="comfortable"
                      variant="outlined" hide-details label="In the group from"
                      hint="Empty means today. Results measured before this date are not theirs."
                      persistent-hint />
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="tonal" @click="addingTo = null">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="memberForm.processing"
                @click="submitMember">
            Add
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
