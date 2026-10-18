<script setup lang="ts">
/**
 * The emergency command centre (EMR-1 to EMR-6).
 *
 * Every other screen in this module is read at a desk. This one is read on a phone, outside a
 * building, by somebody with people waiting on them — so it is laid out against that rather than
 * against the shape of the data.
 *
 * The roll call is the page. Not a tab, not a panel below the summary: the names of the people
 * who have not been found are the first thing on the screen and the count of them is in the
 * largest type on it. Everything else — the responders, the equipment, the hazards, the drills —
 * is below or behind a tab, because none of it is the question being asked in the first minute.
 *
 * Marking somebody is one tap per state, with no confirm dialog. A commander who has just found
 * three people should not have to open a form three times, and the states are all reversible by
 * tapping another one. The destructive action here is not a mis-tap; it is a delay.
 */
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

type Row = Record<string, any>

const props = defineProps<{
  siteId: number | null
  board: {
    emergency: Row
    counts: { total: number; unaccounted: number; accounted: number; missing: number
      injured: number; evacuated: number; by_category: Record<string, number> }
    roll_call: Row[]
    responders: Record<string, Row[]>
    resources: Record<string, Row[]>
    hazards: {
      active_permits: Row[]
      hazardous_materials: { substances: Row[]; agents: Row[]; open_spills: Row[] }
      high_risk_activities: Row[]
      major_risks: Row[]
      as_at: string
    }
    statuses: string[]
    as_at: string
  } | null
  register: Row[]
  drills: Row[]
  overdueDrills: Row[]
  substances: Row[]
  resources: Record<string, Row[]>
  types: string[]
  statuses: string[]
  categories: string[]
  roles: string[]
  sites: { id: number; name: string }[]
  people: { id: number; name: string }[]
  canDeclare?: boolean
  canConfigure?: boolean
  noCompany?: boolean
}>()

const tab = ref('roll-call')
const siteId = ref(props.siteId)

function filterSite() {
  router.get('/sheq/emergency-command', { site_id: siteId.value ?? undefined },
    { preserveState: true, preserveScroll: true, replace: true })
}

const label = (value: string | null) =>
  (value ?? '').toLowerCase().replace(/_/g, ' ').replace(/^./, c => c.toUpperCase())

/** The two states that mean this person has not been found. Mirrors the service's NOT_FOUND. */
const notFound = ['UNACCOUNTED', 'MISSING']

const statusColour: Record<string, string> = {
  UNACCOUNTED: 'grey',
  MISSING: 'error',
  INJURED: 'warning',
  ACCOUNTED: 'success',
  EVACUATED: 'success',
}

const outstanding = computed(() =>
  props.board ? props.board.counts.unaccounted + props.board.counts.missing : 0)

const clock = computed(() => {
  const at = props.board?.as_at
  return at ? new Date(at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—'
})

const started = computed(() => {
  const at = props.board?.emergency?.declared_at
  return at ? new Date(at).toLocaleString([], {
    year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit',
  }) : '—'
})

const elapsed = computed(() => {
  const at = props.board?.emergency?.declared_at
  if (!at) return ''
  const mins = Math.max(0, Math.round((Date.now() - new Date(at).getTime()) / 60000))
  return mins < 60 ? `${mins} min` : `${Math.floor(mins / 60)}h ${mins % 60}m`
})

const rollCallSearch = ref('')

const visibleRollCall = computed(() => {
  const rows = props.board?.roll_call ?? []
  const q = rollCallSearch.value.trim().toLowerCase()
  if (!q) return rows
  return rows.filter(r =>
    (r.person_name ?? '').toLowerCase().includes(q)
    || (r.contractor_name ?? '').toLowerCase().includes(q))
})

// ── EMR-1 ─────────────────────────────────────────────────────────────────────────────────────
const declaring = ref(false)
const declareForm = useForm({
  site_id: props.siteId as number | null,
  emergency_type: 'FIRE',
  incident_commander_id: null as number | null,
  instructions: '',
})

function submitDeclare() {
  declareForm.post('/sheq/emergency-command/declare', {
    preserveScroll: true,
    onSuccess: () => { declaring.value = false; declareForm.reset() },
  })
}

// ── EMR-3 ─────────────────────────────────────────────────────────────────────────────────────
const marking = ref<Row | null>(null)
const markForm = useForm({
  roll_call_uuid: '',
  status: 'ACCOUNTED',
  location_text: '',
  note: '',
})

/** One tap, no dialog. The detail form is for the cases that need a location or a note. */
function quickMark(row: Row, status: string) {
  if (!props.board) return
  router.post(`/sheq/emergency-command/${props.board.emergency.uuid}/roll-call`,
    { roll_call_uuid: row.uuid, status },
    { preserveScroll: true, preserveState: true })
}

function openMark(row: Row, status: string) {
  marking.value = row
  markForm.roll_call_uuid = row.uuid
  markForm.status = status
  markForm.location_text = row.location_text ?? ''
  markForm.note = row.note ?? ''
}

function submitMark() {
  if (!props.board) return
  markForm.post(`/sheq/emergency-command/${props.board.emergency.uuid}/roll-call`, {
    preserveScroll: true,
    onSuccess: () => { marking.value = null; markForm.reset() },
  })
}

function refreshRollCall() {
  if (!props.board) return
  router.post(`/sheq/emergency-command/${props.board.emergency.uuid}/refresh`, {},
    { preserveScroll: true, preserveState: true })
}

// ── Stand down ────────────────────────────────────────────────────────────────────────────────
const standingDown = ref(false)
const standDownForm = useForm({
  stand_down_notes: '',
  stood_down_with_unaccounted_reason: '',
})

function submitStandDown() {
  if (!props.board) return
  standDownForm.post(`/sheq/emergency-command/${props.board.emergency.uuid}/stand-down`, {
    preserveScroll: true,
    onSuccess: () => { standingDown.value = false; standDownForm.reset() },
  })
}

// ── EMR-4 ─────────────────────────────────────────────────────────────────────────────────────
const addingResource = ref(false)
const resourceForm = useForm({
  site_id: props.siteId as number | null,
  kind: 'EQUIPMENT',
  category: 'FIRE_EXTINGUISHER',
  name: '',
  location_text: '',
  phone: '',
  address: '',
  distance_km: null as number | null,
  quantity: null as number | null,
  notes: '',
  last_inspected_on: '',
  next_inspection_due: '',
})

const equipmentCategories = ['FIRE_EXTINGUISHER', 'FIRE_HOSE', 'HYDRANT', 'AED', 'FIRST_AID_BOX',
  'SPILL_KIT', 'EYE_WASH', 'SAFETY_SHOWER', 'STRETCHER', 'BREATHING_APPARATUS', 'ALARM_PANEL',
  'OTHER']

const facilityCategories = ['HOSPITAL', 'CLINIC', 'FIRE_STATION', 'AMBULANCE', 'POLICE',
  'POISON_CENTRE', 'TRAUMA_UNIT', 'OTHER']

function submitResource() {
  resourceForm.post('/sheq/emergency-command/resources', {
    preserveScroll: true,
    onSuccess: () => { addingResource.value = false; resourceForm.reset() },
  })
}

// ── EMR-5 ─────────────────────────────────────────────────────────────────────────────────────
const addingSubstance = ref(false)
const substanceForm = useForm({
  site_id: props.siteId as number | null,
  name: '',
  un_number: '',
  hazard_class: '',
  ghs_classification: '',
  quantity: null as number | null,
  unit: 'L',
  location_text: '',
  storage_conditions: '',
  fire_fighting_media: '',
  incompatible_with: '',
  is_flammable: false,
  is_toxic: false,
})

function submitSubstance() {
  substanceForm.post('/sheq/emergency-command/substances', {
    preserveScroll: true,
    onSuccess: () => { addingSubstance.value = false; substanceForm.reset() },
  })
}

// ── EMR-6 ─────────────────────────────────────────────────────────────────────────────────────
const attendanceFor = ref<Row | null>(null)
const attendanceRows = ref<Row[]>([])

function openAttendance(drill: Row) {
  attendanceFor.value = drill
  attendanceRows.value = [{ person_name: '', subject_type: 'EMPLOYEE', was_present: true,
    accounted_seconds: null, note: '' }]
}

function addAttendanceRow() {
  attendanceRows.value.push({ person_name: '', subject_type: 'EMPLOYEE', was_present: true,
    accounted_seconds: null, note: '' })
}

function submitAttendance() {
  if (!attendanceFor.value) return
  router.post(
    `/sheq/emergency-command/drills/${attendanceFor.value.uuid}/attendance`,
    { people: attendanceRows.value.filter(r => (r.person_name ?? '').trim() !== '') },
    { preserveScroll: true, onSuccess: () => { attendanceFor.value = null } },
  )
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex flex-wrap align-center ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">Emergency command</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          Who is inside, who has been found, and what is dangerous while they are being found
        </p>
      </div>
      <VSpacer />
      <VBtn v-if="canDeclare" color="error" variant="flat" prepend-icon="mdi-alarm-light"
            @click="declaring = true">
        Declare an emergency
      </VBtn>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <!--
        ── The live emergency ─────────────────────────────────────────────────────────────────
        Everything above the tabs. A commander should never have to choose a tab to see the
        number of people who have not been found.
      -->
      <template v-if="board">
        <VCard elevation="0" border rounded="lg" class="mb-4"
               :color="outstanding > 0 ? 'error' : 'success'" variant="tonal">
          <VCardText>
            <div class="d-flex flex-wrap align-center ga-4">
              <div>
                <div class="text-caption text-uppercase">
                  {{ label(board.emergency.emergency_type) }} ·
                  {{ board.emergency.site_name }} ·
                  {{ board.emergency.reference }}
                </div>
                <div class="text-h4 font-weight-bold">
                  {{ outstanding }} not yet found
                </div>
                <div class="text-body-2">
                  of {{ board.counts.total }} on site · declared {{ started }}
                  <span v-if="board.emergency.status === 'ACTIVE'"> · running {{ elapsed }}</span>
                </div>
              </div>

              <VSpacer />

              <!-- EMR-3's four states, as counts, each a number somebody is asked for. -->
              <div class="d-flex flex-wrap ga-4">
                <div v-for="s in ['MISSING', 'INJURED', 'ACCOUNTED', 'EVACUATED']" :key="s"
                     class="text-center">
                  <div class="text-h6 font-weight-bold">
                    {{ board.counts[s.toLowerCase() as keyof typeof board.counts] }}
                  </div>
                  <div class="text-caption text-uppercase">{{ label(s) }}</div>
                </div>
              </div>
            </div>

            <VAlert v-if="board.emergency.instructions" type="info" variant="tonal"
                    density="compact" class="mt-4 mb-0">
              {{ board.emergency.instructions }}
            </VAlert>

            <div class="d-flex flex-wrap align-center ga-2 mt-4">
              <VChip size="small" variant="flat">
                Commander: {{ board.emergency.incident_commander_name ?? 'not named' }}
              </VChip>
              <!-- CMD-3's data freshness, which matters more here than anywhere. -->
              <VChip size="small" variant="text" prepend-icon="mdi-clock-outline">
                Read at {{ clock }}
              </VChip>
              <VSpacer />
              <VBtn v-if="board.emergency.status === 'ACTIVE'" size="small" variant="text"
                    prepend-icon="mdi-refresh" @click="refreshRollCall">
                Re-read who is on site
              </VBtn>
              <VBtn v-if="board.emergency.status === 'ACTIVE' && canDeclare" size="small"
                    variant="flat" color="surface" @click="standingDown = true">
                Stand down
              </VBtn>
            </div>

            <VAlert v-if="board.emergency.status !== 'ACTIVE'" type="warning" variant="tonal"
                    density="compact" class="mt-3 mb-0">
              Stood down.
              <template v-if="board.emergency.unaccounted_at_stand_down > 0">
                {{ board.emergency.unaccounted_at_stand_down }} people were still unaccounted for:
                {{ board.emergency.stood_down_with_unaccounted_reason }}
              </template>
              <template v-else>Everybody was accounted for.</template>
            </VAlert>
          </VCardText>
        </VCard>
      </template>

      <VAlert v-else type="info" variant="tonal" class="mb-4">
        No emergency is running. The roll call, the responders and the hazard picture appear here
        the moment one is declared — the registers below are what to set up before that happens.
      </VAlert>

      <VCard v-if="!board" elevation="0" border rounded="lg" class="mb-4">
        <VCardText class="d-flex flex-wrap align-center ga-3">
          <VSelect v-model="siteId" :items="sites.map(s => ({ value: s.id, title: s.name }))"
                   density="compact" variant="outlined" hide-details clearable
                   style="max-width: 240px" label="Site" @update:model-value="filterSite" />
        </VCardText>
      </VCard>

      <VTabs v-model="tab" class="mb-3">
        <VTab value="roll-call">
          Roll call
          <VBadge v-if="outstanding > 0" :content="outstanding" color="error" inline />
        </VTab>
        <VTab value="responders">Who can help</VTab>
        <VTab value="hazards">What is dangerous</VTab>
        <VTab value="resources">Equipment and facilities</VTab>
        <VTab value="drills">Drills</VTab>
        <VTab value="register">Past emergencies</VTab>
      </VTabs>

      <VWindow v-model="tab">
        <!-- ── EMR-2, EMR-3 ───────────────────────────────────────────────────────────────── -->
        <VWindowItem value="roll-call">
          <VCard v-if="board" elevation="0" border rounded="lg">
            <VCardText class="d-flex flex-wrap align-center ga-3">
              <VTextField v-model="rollCallSearch" density="compact" variant="outlined"
                          hide-details clearable prepend-inner-icon="mdi-magnify"
                          style="max-width: 280px" label="Find a name" />
              <VSpacer />
              <!-- EMR-2's four categories. -->
              <VChip v-for="c in categories" :key="c" size="small" variant="tonal"
                     v-show="board.counts.by_category[c] > 0">
                {{ label(c) }}: {{ board.counts.by_category[c] }}
              </VChip>
            </VCardText>

            <VDivider />

            <VTable density="comfortable">
              <thead>
                <tr>
                  <th>Person</th>
                  <th>Who they are with</th>
                  <th>On site since</th>
                  <th>State</th>
                  <th class="text-right">Found them?</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in visibleRollCall" :key="row.uuid"
                    :class="notFound.includes(row.status) ? 'bg-error-lighten-5' : ''">
                  <td class="font-weight-medium">
                    {{ row.person_name }}
                    <div v-if="row.location_text" class="text-caption text-medium-emphasis">
                      Found at {{ row.location_text }}
                    </div>
                    <div v-if="row.note" class="text-caption text-medium-emphasis">
                      {{ row.note }}
                    </div>
                  </td>
                  <td>
                    <div>{{ row.contractor_name ?? '—' }}</div>
                    <VChip size="x-small" variant="tonal">{{ label(row.subject_type) }}</VChip>
                  </td>
                  <td class="text-no-wrap">
                    {{ row.entered_at ? new Date(row.entered_at).toLocaleTimeString([],
                       { hour: '2-digit', minute: '2-digit' }) : '—' }}
                  </td>
                  <td>
                    <VChip size="small" :color="statusColour[row.status] ?? 'grey'"
                           variant="flat">
                      {{ label(row.status) }}
                    </VChip>
                    <div v-if="row.status_at" class="text-caption text-medium-emphasis">
                      {{ new Date(row.status_at).toLocaleTimeString([],
                         { hour: '2-digit', minute: '2-digit' }) }}
                    </div>
                  </td>
                  <td class="text-right text-no-wrap">
                    <template v-if="board.emergency.status === 'ACTIVE' && canDeclare">
                      <!-- One tap each. The commander has found three people, not opened a form. -->
                      <VBtn size="small" variant="text" color="success"
                            @click="quickMark(row, 'ACCOUNTED')">Safe</VBtn>
                      <VBtn size="small" variant="text" color="success"
                            @click="quickMark(row, 'EVACUATED')">Out</VBtn>
                      <VBtn size="small" variant="text" color="warning"
                            @click="openMark(row, 'INJURED')">Injured</VBtn>
                      <VBtn size="small" variant="text" color="error"
                            @click="openMark(row, 'MISSING')">Missing</VBtn>
                    </template>
                    <span v-else class="text-medium-emphasis">—</span>
                  </td>
                </tr>
                <tr v-if="visibleRollCall.length === 0">
                  <td colspan="5" class="text-center text-medium-emphasis py-6">
                    Nobody was signed in at this site when the emergency was declared. That is
                    either an empty site or a gate that is not recording entries — the second one
                    is worth checking before trusting this number.
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>

          <VAlert v-else type="info" variant="tonal">
            A roll call is built when an emergency is declared, from the people signed in at the
            gate and the visitors signed in at reception.
          </VAlert>
        </VWindowItem>

        <!-- ── EMR-4 ──────────────────────────────────────────────────────────────────────── -->
        <VWindowItem value="responders">
          <VRow dense>
            <VCol v-for="role in roles" :key="role" cols="12" md="6">
              <VCard elevation="0" border rounded="lg" class="mb-3">
                <VCardTitle class="text-subtitle-1">{{ label(role) }}</VCardTitle>
                <VDivider />
                <VList density="compact">
                  <VListItem v-for="p in (board?.responders?.[role] ?? [])" :key="p.person_id">
                    <template #prepend>
                      <VIcon :color="p.on_site ? 'success' : 'grey'"
                             :icon="p.on_site ? 'mdi-account-check' : 'mdi-phone'" />
                    </template>
                    <VListItemTitle>{{ p.name }}</VListItemTitle>
                    <VListItemSubtitle>
                      {{ p.competency }}
                      <template v-if="p.expires_at"> · expires {{ p.expires_at }}</template>
                      <template v-if="p.mobile"> · {{ p.mobile }}</template>
                    </VListItemSubtitle>
                    <template #append>
                      <VChip size="x-small" :color="p.on_site ? 'success' : 'grey'"
                             variant="tonal">
                        {{ p.on_site ? 'On site' : 'Off site' }}
                      </VChip>
                    </template>
                  </VListItem>
                  <VListItem v-if="(board?.responders?.[role] ?? []).length === 0">
                    <VListItemSubtitle>
                      Nobody holds a current appointment for this role. This list is built from
                      competency records, so a lapsed or unaccepted appointment removes a name
                      from it — mark the competency that confers the role under Configure.
                    </VListItemSubtitle>
                  </VListItem>
                </VList>
              </VCard>
            </VCol>
          </VRow>

          <VCard v-if="board" elevation="0" border rounded="lg" class="mb-3">
            <VCardTitle class="text-subtitle-1">Assembly points and contacts</VCardTitle>
            <VDivider />
            <VCardText>
              <div class="text-caption text-uppercase text-medium-emphasis mb-1">
                {{ board.emergency.plan_name ?? 'No plan attached' }}
              </div>
              <p v-if="board.emergency.procedure" class="text-body-2">
                {{ board.emergency.procedure }}
              </p>

              <div class="d-flex flex-wrap ga-2 mb-3">
                <VChip v-for="(a, i) in board.emergency.assembly_points" :key="i" size="small"
                       variant="tonal" prepend-icon="mdi-map-marker">
                  {{ a.name ?? a }}
                </VChip>
              </div>

              <VTable v-if="board.emergency.emergency_contacts.length" density="compact">
                <tbody>
                  <tr v-for="(c, i) in board.emergency.emergency_contacts" :key="i">
                    <td>{{ c.role ?? c.name }}</td>
                    <td class="font-weight-medium">{{ c.phone }}</td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>
          </VCard>
        </VWindowItem>

        <!-- ── EMR-5 ──────────────────────────────────────────────────────────────────────── -->
        <VWindowItem value="hazards">
          <VCard elevation="0" border rounded="lg" class="mb-3">
            <VCardTitle class="text-subtitle-1 d-flex align-center">
              Hazardous substances held here
              <VSpacer />
              <VBtn v-if="canConfigure" size="small" variant="text" prepend-icon="mdi-plus"
                    @click="addingSubstance = true">Add</VBtn>
            </VCardTitle>
            <VDivider />
            <VTable density="comfortable">
              <thead>
                <tr>
                  <th>Substance</th>
                  <th>How much, and where</th>
                  <th>Put this on it</th>
                  <th>Keep it away from</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="s in (board?.hazards?.hazardous_materials?.substances ?? substances)"
                    :key="s.uuid">
                  <td class="font-weight-medium">
                    {{ s.name }}
                    <div class="d-flex ga-1 mt-1">
                      <VChip v-if="s.is_flammable" size="x-small" color="error" variant="flat">
                        Flammable
                      </VChip>
                      <VChip v-if="s.is_toxic" size="x-small" color="warning" variant="flat">
                        Toxic
                      </VChip>
                      <VChip v-if="s.un_number" size="x-small" variant="tonal">
                        {{ s.un_number }}
                      </VChip>
                    </div>
                  </td>
                  <td>
                    {{ s.quantity ?? '—' }} {{ s.unit }}
                    <div class="text-caption text-medium-emphasis">
                      {{ s.location_text ?? 'Location not recorded' }}
                    </div>
                  </td>
                  <td class="text-body-2">{{ s.fire_fighting_media ?? '—' }}</td>
                  <td class="text-body-2">{{ s.incompatible_with ?? '—' }}</td>
                </tr>
                <tr v-if="(board?.hazards?.hazardous_materials?.substances ?? substances).length === 0">
                  <td colspan="4" class="text-center text-medium-emphasis py-6">
                    No hazardous substance has been recorded against this site. This is the first
                    thing a fire officer asks for on arrival, and it is also a chemical inventory
                    the Hazardous Chemical Agents Regulations require in its own right.
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>

          <VRow dense>
            <VCol cols="12" md="6">
              <VCard elevation="0" border rounded="lg" class="mb-3">
                <VCardTitle class="text-subtitle-1">Work that is open right now</VCardTitle>
                <VDivider />
                <VList density="compact">
                  <VListItem v-for="p in (board?.hazards?.active_permits ?? [])" :key="p.uuid">
                    <VListItemTitle>
                      {{ p.reference }} — {{ label(p.permit_type) }}
                    </VListItemTitle>
                    <VListItemSubtitle>
                      {{ p.work_description }}
                      <template v-if="p.location_text"> · {{ p.location_text }}</template>
                      <template v-if="p.contractor_name"> · {{ p.contractor_name }}</template>
                    </VListItemSubtitle>
                    <template #append>
                      <VChip size="x-small"
                             :color="p.status === 'SUSPENDED' ? 'warning' : 'error'"
                             variant="tonal">{{ label(p.status) }}</VChip>
                    </template>
                  </VListItem>
                  <VListItem v-if="(board?.hazards?.active_permits ?? []).length === 0">
                    <VListItemSubtitle>No permit is open on this site.</VListItemSubtitle>
                  </VListItem>
                </VList>
              </VCard>

              <VCard elevation="0" border rounded="lg" class="mb-3">
                <VCardTitle class="text-subtitle-1">Spills not yet cleaned up</VCardTitle>
                <VDivider />
                <VList density="compact">
                  <VListItem v-for="s in (board?.hazards?.hazardous_materials?.open_spills ?? [])"
                             :key="s.uuid">
                    <VListItemTitle>{{ s.reference }} — {{ s.substance }}</VListItemTitle>
                    <VListItemSubtitle>
                      {{ s.quantity }} {{ s.unit }} · {{ label(s.receiving_environment) }}
                      <template v-if="s.location_text"> · {{ s.location_text }}</template>
                    </VListItemSubtitle>
                  </VListItem>
                  <VListItem v-if="(board?.hazards?.hazardous_materials?.open_spills ?? []).length === 0">
                    <VListItemSubtitle>None open.</VListItemSubtitle>
                  </VListItem>
                </VList>
              </VCard>
            </VCol>

            <VCol cols="12" md="6">
              <VCard elevation="0" border rounded="lg" class="mb-3">
                <VCardTitle class="text-subtitle-1">High-risk work on site</VCardTitle>
                <VDivider />
                <VList density="compact">
                  <VListItem v-for="a in (board?.hazards?.high_risk_activities ?? [])"
                             :key="a.uuid">
                    <VListItemTitle>{{ a.name }}</VListItemTitle>
                    <VListItemSubtitle>{{ a.code }}</VListItemSubtitle>
                  </VListItem>
                  <VListItem v-if="(board?.hazards?.high_risk_activities ?? []).length === 0">
                    <VListItemSubtitle>
                      Nobody on site is signed in against a high-risk activity.
                    </VListItemSubtitle>
                  </VListItem>
                </VList>
              </VCard>

              <VCard elevation="0" border rounded="lg" class="mb-3">
                <VCardTitle class="text-subtitle-1">What the assessments call serious</VCardTitle>
                <VDivider />
                <VList density="compact">
                  <VListItem v-for="r in (board?.hazards?.major_risks ?? [])" :key="r.uuid">
                    <VListItemTitle>{{ r.hazard }}</VListItemTitle>
                    <VListItemSubtitle>
                      {{ r.activity }} · {{ r.potential_harm }}
                    </VListItemSubtitle>
                    <template #append>
                      <VChip size="x-small" color="error" variant="tonal">
                        {{ label(r.residual_band) }}
                      </VChip>
                    </template>
                  </VListItem>
                  <VListItem v-if="(board?.hazards?.major_risks ?? []).length === 0">
                    <VListItemSubtitle>
                      No approved assessment on this site records a high residual risk.
                    </VListItemSubtitle>
                  </VListItem>
                </VList>
              </VCard>

              <VCard elevation="0" border rounded="lg">
                <VCardTitle class="text-subtitle-1">Agents people are monitored against</VCardTitle>
                <VDivider />
                <VList density="compact">
                  <VListItem v-for="a in (board?.hazards?.hazardous_materials?.agents ?? [])"
                             :key="a.uuid">
                    <VListItemTitle>{{ a.name }}</VListItemTitle>
                    <VListItemSubtitle>
                      {{ label(a.agent_type) }}
                      <template v-if="a.limit_value">
                        · limit {{ a.limit_value }} {{ a.limit_unit }}
                      </template>
                    </VListItemSubtitle>
                  </VListItem>
                  <VListItem v-if="(board?.hazards?.hazardous_materials?.agents ?? []).length === 0">
                    <VListItemSubtitle>
                      Nothing has been measured on this site.
                    </VListItemSubtitle>
                  </VListItem>
                </VList>
              </VCard>
            </VCol>
          </VRow>
        </VWindowItem>

        <!-- ── EMR-4, the equipment half ──────────────────────────────────────────────────── -->
        <VWindowItem value="resources">
          <VCard elevation="0" border rounded="lg" class="mb-3">
            <VCardTitle class="text-subtitle-1 d-flex align-center">
              Emergency equipment
              <VSpacer />
              <VBtn v-if="canConfigure" size="small" variant="text" prepend-icon="mdi-plus"
                    @click="addingResource = true; resourceForm.kind = 'EQUIPMENT'">Add</VBtn>
            </VCardTitle>
            <VDivider />
            <VTable density="comfortable">
              <thead>
                <tr>
                  <th>What</th><th>Where</th><th>How many</th><th>Next service</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="e in (board?.resources?.EQUIPMENT ?? resources.EQUIPMENT ?? [])"
                    :key="e.uuid">
                  <td class="font-weight-medium">
                    {{ e.name }}
                    <div class="text-caption text-medium-emphasis">{{ label(e.category) }}</div>
                  </td>
                  <td>{{ e.location_text ?? '—' }}</td>
                  <td>{{ e.quantity ?? '—' }}</td>
                  <td>
                    <VChip v-if="e.overdue_inspection" size="small" color="error" variant="flat">
                      Overdue {{ e.next_inspection_due }}
                    </VChip>
                    <span v-else>{{ e.next_inspection_due ?? '—' }}</span>
                  </td>
                </tr>
                <tr v-if="(board?.resources?.EQUIPMENT ?? resources.EQUIPMENT ?? []).length === 0">
                  <td colspan="4" class="text-center text-medium-emphasis py-6">
                    No equipment recorded. An extinguisher nobody has recorded is an extinguisher
                    nobody is checking the service date on.
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>

          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 d-flex align-center">
              Nearest emergency facilities
              <VSpacer />
              <VBtn v-if="canConfigure" size="small" variant="text" prepend-icon="mdi-plus"
                    @click="addingResource = true; resourceForm.kind = 'FACILITY';
                            resourceForm.category = 'HOSPITAL'">Add</VBtn>
            </VCardTitle>
            <VDivider />
            <VTable density="comfortable">
              <thead>
                <tr><th>Who</th><th>Phone</th><th>Where</th><th>How far</th></tr>
              </thead>
              <tbody>
                <tr v-for="f in (board?.resources?.FACILITY ?? resources.FACILITY ?? [])"
                    :key="f.uuid">
                  <td class="font-weight-medium">
                    {{ f.name }}
                    <div class="text-caption text-medium-emphasis">{{ label(f.category) }}</div>
                  </td>
                  <td class="font-weight-medium">{{ f.phone ?? '—' }}</td>
                  <td>{{ f.address ?? '—' }}</td>
                  <td>{{ f.distance_km ? `${f.distance_km} km` : '—' }}</td>
                </tr>
                <tr v-if="(board?.resources?.FACILITY ?? resources.FACILITY ?? []).length === 0">
                  <td colspan="4" class="text-center text-medium-emphasis py-6">
                    No facilities recorded. Leave this blank and somebody will be searching for a
                    hospital number on a phone while holding a pressure dressing.
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VWindowItem>

        <!-- ── EMR-6 ──────────────────────────────────────────────────────────────────────── -->
        <VWindowItem value="drills">
          <VAlert v-if="overdueDrills.length" type="warning" variant="tonal" class="mb-3">
            {{ overdueDrills.length }} site(s) have not rehearsed their plan in over a year:
            {{ overdueDrills.map((d: Row) => d.name ?? d.site_name).join(', ') }}
          </VAlert>

          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1">Drill history</VCardTitle>
            <VDivider />
            <VTable density="comfortable">
              <thead>
                <tr>
                  <th>Date</th><th>Scenario</th><th>Time to clear</th>
                  <th>Out</th><th>Unaccounted</th><th class="text-right">Attendance</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="d in drills" :key="d.uuid">
                  <td class="text-no-wrap">{{ d.drill_date }}</td>
                  <td>
                    {{ d.scenario ?? label(d.drill_type) }}
                    <div v-if="d.observations" class="text-caption text-medium-emphasis">
                      {{ d.observations }}
                    </div>
                  </td>
                  <td class="text-no-wrap">
                    {{ d.evacuation_seconds
                       ? `${Math.floor(d.evacuation_seconds / 60)}m ${d.evacuation_seconds % 60}s`
                       : '—' }}
                  </td>
                  <td>{{ d.participants ?? '—' }}</td>
                  <td>
                    <!--
                      The number that decides whether a drill passed. A four-minute evacuation
                      with two people missing is a failed drill, and showing only the time is how
                      it reads as a pass.
                    -->
                    <VChip size="small" :color="d.unaccounted > 0 ? 'error' : 'success'"
                           variant="flat">{{ d.unaccounted ?? 0 }}</VChip>
                  </td>
                  <td class="text-right">
                    <VBtn v-if="canDeclare" size="small" variant="text"
                          @click="openAttendance(d)">Record who came out</VBtn>
                  </td>
                </tr>
                <tr v-if="drills.length === 0">
                  <td colspan="6" class="text-center text-medium-emphasis py-6">
                    No drill recorded. A plan that has never been rehearsed is a document, and
                    section 8 of the OHS Act is read as including whether the arrangements were
                    tested.
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VWindowItem>

        <VWindowItem value="register">
          <VCard elevation="0" border rounded="lg">
            <VTable density="comfortable">
              <thead>
                <tr>
                  <th>Reference</th><th>Type</th><th>Site</th><th>Declared</th>
                  <th>Stood down</th><th>Unaccounted</th><th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="e in register" :key="e.uuid">
                  <td class="font-weight-medium">{{ e.reference }}</td>
                  <td>{{ label(e.emergency_type) }}</td>
                  <td>{{ e.site_name }}</td>
                  <td class="text-no-wrap">{{ e.declared_at }}</td>
                  <td class="text-no-wrap">
                    <VChip v-if="e.status === 'ACTIVE'" size="small" color="error" variant="flat">
                      Running
                    </VChip>
                    <span v-else>{{ e.stood_down_at }}</span>
                  </td>
                  <td>
                    <VChip v-if="e.unaccounted_at_stand_down > 0" size="small" color="error"
                           variant="tonal">{{ e.unaccounted_at_stand_down }}</VChip>
                    <span v-else-if="e.status !== 'ACTIVE'">0</span>
                    <span v-else>—</span>
                  </td>
                  <td class="text-right">
                    <VBtn size="small" variant="text"
                          :href="`/sheq/emergency-command?emergency=${e.uuid}`">Open</VBtn>
                  </td>
                </tr>
                <tr v-if="register.length === 0">
                  <td colspan="7" class="text-center text-medium-emphasis py-6">
                    No emergency has been declared on this system.
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VWindowItem>
      </VWindow>
    </template>

    <!-- ── EMR-1 ────────────────────────────────────────────────────────────────────────────── -->
    <VDialog v-model="declaring" max-width="620">
      <VCard>
        <VCardTitle>Declare an emergency</VCardTitle>
        <VCardText>
          <VAlert type="warning" variant="tonal" density="compact" class="mb-4">
            This builds a roll call from everybody signed in at this site — workers, contractors,
            subcontractors and visitors — and notifies nobody automatically. Use it when the
            alarm has already been raised.
          </VAlert>

          <VSelect v-model="declareForm.site_id" label="Site" variant="outlined"
                   density="comfortable" class="mb-3"
                   :items="sites.map(s => ({ value: s.id, title: s.name }))"
                   :error-messages="declareForm.errors.site_id" />
          <VSelect v-model="declareForm.emergency_type" label="What kind" variant="outlined"
                   density="comfortable" class="mb-3"
                   :items="types.map(t => ({ value: t, title: label(t) }))"
                   :error-messages="declareForm.errors.emergency_type" />
          <VSelect v-model="declareForm.incident_commander_id" label="Incident commander"
                   variant="outlined" density="comfortable" clearable class="mb-3"
                   :items="people.map(p => ({ value: p.id, title: p.name }))"
                   :error-messages="declareForm.errors.incident_commander_id" />
          <VTextarea v-model="declareForm.instructions" label="Instructions to everybody on site"
                     variant="outlined" rows="3" auto-grow
                     placeholder="Evacuate to the north gate. Do not use the lifts."
                     :error-messages="declareForm.errors.instructions" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="declaring = false">Cancel</VBtn>
          <VBtn color="error" variant="flat" :loading="declareForm.processing"
                @click="submitDeclare">Declare</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── EMR-3, the detail form ───────────────────────────────────────────────────────────── -->
    <VDialog v-model="marking" max-width="520" @update:model-value="v => { if (!v) marking = null }">
      <VCard v-if="marking">
        <VCardTitle>{{ marking.person_name }}</VCardTitle>
        <VCardText>
          <VSelect v-model="markForm.status" label="State" variant="outlined"
                   density="comfortable" class="mb-3"
                   :items="statuses.map(s => ({ value: s, title: label(s) }))"
                   :error-messages="markForm.errors.status" />
          <VTextField v-model="markForm.location_text" label="Where they are"
                      variant="outlined" density="comfortable" class="mb-3"
                      placeholder="Third floor, east stairwell"
                      hint="This is what a rescue team is given." persistent-hint />
          <VTextarea v-model="markForm.note" label="Anything else" variant="outlined" rows="2" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="marking = null">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="markForm.processing"
                @click="submitMark">Record</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="standingDown" max-width="560">
      <VCard>
        <VCardTitle>Stand down</VCardTitle>
        <VCardText>
          <VAlert v-if="outstanding > 0" type="error" variant="tonal" density="compact"
                  class="mb-4">
            {{ outstanding }} people on the roll call have not been found. Standing down now needs
            a reason, and it is kept on the record along with the count.
          </VAlert>

          <VTextarea v-if="outstanding > 0"
                     v-model="standDownForm.stood_down_with_unaccounted_reason"
                     label="Why it is safe to stand down with people outstanding"
                     variant="outlined" rows="3" class="mb-3"
                     placeholder="Both signed out at the far gate without scanning. Confirmed by phone."
                     :error-messages="standDownForm.errors.stood_down_with_unaccounted_reason" />

          <VTextarea v-model="standDownForm.stand_down_notes" label="Notes" variant="outlined"
                     rows="2" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="standingDown = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="standDownForm.processing"
                @click="submitStandDown">Stand down</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── EMR-4 ────────────────────────────────────────────────────────────────────────────── -->
    <VDialog v-model="addingResource" max-width="620">
      <VCard>
        <VCardTitle>
          {{ resourceForm.kind === 'EQUIPMENT' ? 'Emergency equipment' : 'Nearby facility' }}
        </VCardTitle>
        <VCardText>
          <VSelect v-model="resourceForm.category" label="What kind" variant="outlined"
                   density="comfortable" class="mb-3"
                   :items="(resourceForm.kind === 'EQUIPMENT' ? equipmentCategories
                            : facilityCategories).map(c => ({ value: c, title: label(c) }))" />
          <VTextField v-model="resourceForm.name" label="Name" variant="outlined"
                      density="comfortable" class="mb-3"
                      :error-messages="resourceForm.errors.name" />
          <VSelect v-model="resourceForm.site_id" label="Site" variant="outlined" clearable
                   density="comfortable" class="mb-3"
                   :items="sites.map(s => ({ value: s.id, title: s.name }))"
                   hint="Leave blank for something that covers every site, like one ambulance service."
                   persistent-hint />

          <template v-if="resourceForm.kind === 'EQUIPMENT'">
            <VTextField v-model="resourceForm.location_text" label="Where it is"
                        variant="outlined" density="comfortable" class="mb-3" />
            <VTextField v-model.number="resourceForm.quantity" label="How many" type="number"
                        variant="outlined" density="comfortable" class="mb-3" />
            <VTextField v-model="resourceForm.next_inspection_due" label="Next service due"
                        type="date" variant="outlined" density="comfortable" class="mb-3"
                        hint="An extinguisher past its service date is shown as such on the board."
                        persistent-hint />
          </template>

          <template v-else>
            <VTextField v-model="resourceForm.phone" label="Phone" variant="outlined"
                        density="comfortable" class="mb-3" />
            <VTextField v-model="resourceForm.address" label="Address" variant="outlined"
                        density="comfortable" class="mb-3" />
            <VTextField v-model.number="resourceForm.distance_km" label="Distance (km)"
                        type="number" variant="outlined" density="comfortable" class="mb-3" />
          </template>

          <VTextarea v-model="resourceForm.notes" label="Notes" variant="outlined" rows="2" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="addingResource = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="resourceForm.processing"
                @click="submitResource">Save</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── EMR-5 ────────────────────────────────────────────────────────────────────────────── -->
    <VDialog v-model="addingSubstance" max-width="680">
      <VCard>
        <VCardTitle>Hazardous substance held on a site</VCardTitle>
        <VCardText>
          <VSelect v-model="substanceForm.site_id" label="Site" variant="outlined"
                   density="comfortable" class="mb-3"
                   :items="sites.map(s => ({ value: s.id, title: s.name }))"
                   :error-messages="substanceForm.errors.site_id" />
          <VTextField v-model="substanceForm.name" label="Substance" variant="outlined"
                      density="comfortable" class="mb-3"
                      :error-messages="substanceForm.errors.name" />

          <VRow dense class="mb-1">
            <VCol cols="6">
              <VTextField v-model="substanceForm.un_number" label="UN number" variant="outlined"
                          density="comfortable" placeholder="UN1001" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="substanceForm.hazard_class" label="Hazard class"
                          variant="outlined" density="comfortable" placeholder="2.1" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model.number="substanceForm.quantity" label="How much" type="number"
                          variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="substanceForm.unit" label="Unit" variant="outlined"
                          density="comfortable" />
            </VCol>
          </VRow>

          <VTextField v-model="substanceForm.location_text" label="Where it is kept"
                      variant="outlined" density="comfortable" class="mb-3"
                      placeholder="Gas cage, north wall" />

          <VTextarea v-model="substanceForm.fire_fighting_media"
                     label="What to put on it" variant="outlined" rows="2" class="mb-3"
                     hint="The part that stops somebody spraying the wrong thing at it."
                     persistent-hint />
          <VTextarea v-model="substanceForm.incompatible_with"
                     label="What must not go near it" variant="outlined" rows="2" class="mb-3" />

          <div class="d-flex ga-4">
            <VSwitch v-model="substanceForm.is_flammable" label="Flammable" color="error"
                     density="compact" hide-details inset />
            <VSwitch v-model="substanceForm.is_toxic" label="Toxic" color="warning"
                     density="compact" hide-details inset />
          </div>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="addingSubstance = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="substanceForm.processing"
                @click="submitSubstance">Save</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── EMR-6 ────────────────────────────────────────────────────────────────────────────── -->
    <VDialog v-model="attendanceFor" max-width="760"
             @update:model-value="v => { if (!v) attendanceFor = null }">
      <VCard v-if="attendanceFor">
        <VCardTitle>Who came out — {{ attendanceFor.drill_date }}</VCardTitle>
        <VCardText>
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            Per-person times are what turn a drill into a finding about a particular work area.
            The drill's own time is the slowest person's time and says nothing about who that was.
          </VAlert>

          <VRow v-for="(row, i) in attendanceRows" :key="i" dense class="align-center">
            <VCol cols="12" md="4">
              <VTextField v-model="row.person_name" label="Name" variant="outlined"
                          density="compact" hide-details />
            </VCol>
            <VCol cols="6" md="3">
              <VSelect v-model="row.subject_type" label="Who" variant="outlined"
                       density="compact" hide-details
                       :items="categories.map(c => ({ value: c, title: label(c) }))" />
            </VCol>
            <VCol cols="6" md="2">
              <VTextField v-model.number="row.accounted_seconds" label="Seconds" type="number"
                          variant="outlined" density="compact" hide-details />
            </VCol>
            <VCol cols="12" md="3">
              <VSwitch v-model="row.was_present" label="Came out" color="success"
                       density="compact" hide-details inset />
            </VCol>
          </VRow>

          <VBtn size="small" variant="text" prepend-icon="mdi-plus" class="mt-2"
                @click="addAttendanceRow">Add another</VBtn>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="attendanceFor = null">Cancel</VBtn>
          <VBtn color="primary" variant="flat" @click="submitAttendance">Save</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
