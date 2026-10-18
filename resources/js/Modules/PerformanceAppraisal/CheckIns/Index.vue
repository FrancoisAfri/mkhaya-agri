<template>
  <VContainer fluid class="pa-6">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('pa.checkins.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('pa.checkins.subtitle') }}</p>
      </div>
      <div class="d-flex ga-2">
        <VBtn variant="tonal" prepend-icon="tabler-plus" @click="openCreateCheckin">
          {{ t('pa.checkins.new') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreateSession">
          {{ t('pa.checkins.coach') }}
        </VBtn>
      </div>
    </div>

    <!-- Cycle filter -->
    <VSelect
      v-model="selectedCycleId"
      :items="cycles"
      item-title="name"
      item-value="id"
      :label="t('pa.probation.filter_cycle')"
      density="compact"
      variant="outlined"
      clearable
      hide-details
      style="max-width: 320px"
      class="mb-5"
      @update:model-value="applyFilter"
    />

    <!-- Tabs -->
    <VTabs v-model="tab" class="mb-4">
      <VTab value="checkins">
        <VIcon start>tabler-calendar-check</VIcon>
        Check-Ins ({{ checkins.length }})
      </VTab>
      <VTab value="sessions">
        <VIcon start>tabler-microphone</VIcon>
        Coaching Sessions ({{ sessions.length }})
      </VTab>
    </VTabs>

    <VTabsWindow v-model="tab">
      <!-- ── Check-Ins ──────────────────────────────────────────────────── -->
      <VTabsWindowItem value="checkins">
        <VRow v-if="checkins.length" dense>
          <VCol v-for="ci in checkins" :key="ci.id" cols="12" sm="6" xl="4">
            <VCard border flat>
              <VCardItem>
                <template #prepend>
                  <VAvatar :color="statusColor(ci.status)" variant="tonal" size="36">
                    <VIcon size="16">tabler-calendar</VIcon>
                  </VAvatar>
                </template>
                <VCardTitle class="text-body-1 font-weight-medium">{{ ci.title }}</VCardTitle>
                <VCardSubtitle>
                  {{ ci.employee_name }} ↔ {{ ci.manager_name }}
                </VCardSubtitle>
                <template #append>
                  <div class="d-flex ga-1">
                    <VBtn icon size="x-small" variant="text" @click="openEditCheckin(ci)">
                      <VIcon>tabler-pencil</VIcon>
                    </VBtn>
                    <VBtn icon size="x-small" variant="text" color="error" @click="openDelCheckin(ci)">
                      <VIcon>tabler-trash</VIcon>
                    </VBtn>
                  </div>
                </template>
              </VCardItem>

              <VDivider />

              <VCardText class="text-body-2">
                <div class="d-flex align-center ga-1 mb-1">
                  <VIcon size="14" color="primary">tabler-clock</VIcon>
                  {{ ci.scheduled_at }}
                  <span v-if="ci.duration_minutes" class="text-medium-emphasis">({{ ci.duration_minutes }}min)</span>
                </div>
                <div v-if="ci.location" class="d-flex align-center ga-1 mb-1">
                  <VIcon size="14" color="secondary">tabler-map-pin</VIcon>
                  {{ ci.location }}
                </div>
                <div v-if="ci.agenda" class="mt-2 text-medium-emphasis text-caption">
                  {{ truncate(ci.agenda) }}
                </div>
              </VCardText>

              <VCardActions class="px-4 pb-3 pt-0">
                <VChip size="x-small" :color="statusColor(ci.status)" variant="tonal">
                  {{ ci.status }}
                </VChip>
                <span v-if="ci.cycle_name" class="ml-2 text-caption text-medium-emphasis">
                  {{ ci.cycle_name }}
                </span>
                <VSpacer />
                <VBtn size="x-small" variant="tonal" color="primary" prepend-icon="tabler-list-details" @click="openWorkspace(ci)">
                  1:1 Workspace
                  <VBadge v-if="(ci.talking_points?.length || 0) + (ci.action_item_records?.length || 0)" color="primary" inline
                    :content="(ci.talking_points?.length || 0) + (ci.action_item_records?.length || 0)" />
                </VBtn>
              </VCardActions>
            </VCard>
          </VCol>
        </VRow>
        <VAlert v-else type="info" variant="tonal" class="mt-2">
          No check-ins found. Schedule one using the button above.
        </VAlert>
      </VTabsWindowItem>

      <!-- ── Coaching Sessions ──────────────────────────────────────────── -->
      <VTabsWindowItem value="sessions">
        <VRow v-if="sessions.length" dense>
          <VCol v-for="s in sessions" :key="s.id" cols="12" sm="6" xl="4">
            <VCard border flat>
              <VCardItem>
                <template #prepend>
                  <VAvatar :color="statusColor(s.status)" variant="tonal" size="36">
                    <VIcon size="16">tabler-microphone</VIcon>
                  </VAvatar>
                </template>
                <VCardTitle class="text-body-1 font-weight-medium">{{ s.title }}</VCardTitle>
                <VCardSubtitle>
                  {{ s.employee_name }} with {{ s.coach_name }}
                </VCardSubtitle>
                <template #append>
                  <div class="d-flex ga-1">
                    <VBtn icon size="x-small" variant="text" @click="openEditSession(s)">
                      <VIcon>tabler-pencil</VIcon>
                    </VBtn>
                    <VBtn icon size="x-small" variant="text" color="error" @click="openDelSession(s)">
                      <VIcon>tabler-trash</VIcon>
                    </VBtn>
                  </div>
                </template>
              </VCardItem>

              <VDivider />

              <VCardText class="text-body-2">
                <div class="d-flex align-center ga-1 mb-1">
                  <VIcon size="14" color="primary">tabler-calendar</VIcon>
                  {{ s.session_date }}
                  <span v-if="s.duration_minutes" class="text-medium-emphasis">({{ s.duration_minutes }}min)</span>
                </div>
                <div v-if="s.objectives" class="mt-2 text-medium-emphasis text-caption">
                  <strong>Objectives:</strong> {{ truncate(s.objectives) }}
                </div>
                <div v-if="s.outcomes" class="mt-1 text-caption">
                  <strong>Outcomes:</strong> {{ truncate(s.outcomes) }}
                </div>
              </VCardText>

              <VCardActions class="px-4 pb-3 pt-0">
                <VChip size="x-small" :color="statusColor(s.status)" variant="tonal">
                  {{ s.status }}
                </VChip>
                <span v-if="s.cycle_name" class="ml-2 text-caption text-medium-emphasis">
                  {{ s.cycle_name }}
                </span>
              </VCardActions>
            </VCard>
          </VCol>
        </VRow>
        <VAlert v-else type="info" variant="tonal" class="mt-2">
          No coaching sessions found.
        </VAlert>
      </VTabsWindowItem>
    </VTabsWindow>

    <!-- ── Check-In Drawer ──────────────────────────────────────────────── -->
    <VNavigationDrawer v-model="checkinOpen" location="right" width="480" temporary>
      <VToolbar color="primary" density="compact">
        <VToolbarTitle class="text-body-1">
          {{ editCheckin ? 'Edit Check-In' : 'Schedule Check-In' }}
        </VToolbarTitle>
        <template #append>
          <VBtn icon @click="checkinOpen = false"><VIcon>tabler-x</VIcon></VBtn>
        </template>
      </VToolbar>

      <VForm ref="checkinForm" @submit.prevent="submitCheckin" class="pa-4">
        <VTextField
          v-model="ciForm.title"
          label="Title"
          density="compact"
          variant="outlined"
          :rules="[v => !!v || 'Required']"
          class="mb-3"
        />
        <VSelect
          v-model="ciForm.employee_id"
          :items="employees"
          item-title="name"
          item-value="id"
          label="Employee"
          density="compact"
          variant="outlined"
          :rules="[v => !!v || 'Required']"
          class="mb-3"
        />
        <VSelect
          v-model="ciForm.manager_id"
          :items="employees"
          item-title="name"
          item-value="id"
          label="Manager"
          density="compact"
          variant="outlined"
          :rules="[v => !!v || 'Required']"
          class="mb-3"
        />
        <VSelect
          v-model="ciForm.cycle_id"
          :items="cycles"
          item-title="name"
          item-value="id"
          label="Cycle (optional)"
          density="compact"
          variant="outlined"
          clearable
          class="mb-3"
        />
        <VTextField
          v-model="ciForm.scheduled_at"
          label="Scheduled At"
          type="datetime-local"
          density="compact"
          variant="outlined"
          :rules="[v => !!v || 'Required']"
          class="mb-3"
        />
        <VTextField
          v-model.number="ciForm.duration_minutes"
          label="Duration (minutes)"
          type="number"
          density="compact"
          variant="outlined"
          class="mb-3"
        />
        <VTextField
          v-model="ciForm.location"
          label="Location (optional)"
          density="compact"
          variant="outlined"
          class="mb-3"
        />
        <VTextarea
          v-model="ciForm.agenda"
          label="Agenda (optional)"
          density="compact"
          variant="outlined"
          rows="3"
          class="mb-3"
        />
        <template v-if="editCheckin">
          <VTextarea
            v-model="ciForm.notes"
            label="Notes"
            density="compact"
            variant="outlined"
            rows="3"
            class="mb-3"
          />
          <VSelect
            v-model="ciForm.status"
            :items="['scheduled', 'completed', 'cancelled']"
            label="Status"
            density="compact"
            variant="outlined"
            class="mb-3"
          />
        </template>
        <VBtn type="submit" color="primary" block :loading="savingCi">
          {{ editCheckin ? 'Save Changes' : 'Schedule Check-In' }}
        </VBtn>
      </VForm>
    </VNavigationDrawer>

    <!-- ── Coaching Session Drawer ──────────────────────────────────────── -->
    <VNavigationDrawer v-model="sessionOpen" location="right" width="480" temporary>
      <VToolbar color="primary" density="compact">
        <VToolbarTitle class="text-body-1">
          {{ editSession ? 'Edit Coaching Session' : 'New Coaching Session' }}
        </VToolbarTitle>
        <template #append>
          <VBtn icon @click="sessionOpen = false"><VIcon>tabler-x</VIcon></VBtn>
        </template>
      </VToolbar>

      <VForm ref="sessionForm" @submit.prevent="submitSession" class="pa-4">
        <VTextField
          v-model="sForm.title"
          label="Title"
          density="compact"
          variant="outlined"
          :rules="[v => !!v || 'Required']"
          class="mb-3"
        />
        <VSelect
          v-model="sForm.employee_id"
          :items="employees"
          item-title="name"
          item-value="id"
          label="Employee"
          density="compact"
          variant="outlined"
          :rules="[v => !!v || 'Required']"
          class="mb-3"
        />
        <VSelect
          v-model="sForm.coach_id"
          :items="employees"
          item-title="name"
          item-value="id"
          label="Coach"
          density="compact"
          variant="outlined"
          :rules="[v => !!v || 'Required']"
          class="mb-3"
        />
        <VSelect
          v-model="sForm.cycle_id"
          :items="cycles"
          item-title="name"
          item-value="id"
          label="Cycle (optional)"
          density="compact"
          variant="outlined"
          clearable
          class="mb-3"
        />
        <VTextField
          v-model="sForm.session_date"
          label="Session Date"
          type="date"
          density="compact"
          variant="outlined"
          :rules="[v => !!v || 'Required']"
          class="mb-3"
        />
        <VTextField
          v-model.number="sForm.duration_minutes"
          label="Duration (minutes)"
          type="number"
          density="compact"
          variant="outlined"
          class="mb-3"
        />
        <VTextarea
          v-model="sForm.objectives"
          label="Objectives"
          density="compact"
          variant="outlined"
          rows="3"
          class="mb-3"
        />
        <template v-if="editSession">
          <VTextarea
            v-model="sForm.outcomes"
            label="Outcomes"
            density="compact"
            variant="outlined"
            rows="3"
            class="mb-3"
          />
          <VTextarea
            v-model="sForm.next_steps"
            label="Next Steps"
            density="compact"
            variant="outlined"
            rows="3"
            class="mb-3"
          />
          <VSelect
            v-model="sForm.status"
            :items="['scheduled', 'completed', 'cancelled']"
            label="Status"
            density="compact"
            variant="outlined"
            class="mb-3"
          />
        </template>
        <VBtn type="submit" color="primary" block :loading="savingS">
          {{ editSession ? 'Save Changes' : 'Create Session' }}
        </VBtn>
      </VForm>
    </VNavigationDrawer>

    <!-- Delete check-in -->
    <VDialog v-model="delCiOpen" max-width="400">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('pa.common.delete') }}</VCardTitle>
        <VCardText>{{ t('pa.common.cannot_undo') }}</VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="delCiOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" :loading="deletingCi" @click="confirmDelCheckin">{{ t('pa.common.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete session -->
    <VDialog v-model="delSOpen" max-width="400">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('pa.common.delete') }}</VCardTitle>
        <VCardText>{{ t('pa.common.cannot_undo') }}</VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="delSOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" :loading="deletingS" @click="confirmDelSession">{{ t('pa.common.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- 1:1 Workspace: collaborative talking points + action items -->
    <VDialog v-model="wsOpen" max-width="640" scrollable>
      <VCard v-if="wsCheckin">
        <VCardTitle class="d-flex align-center justify-space-between pa-4">
          <span class="text-body-1 font-weight-bold">1:1 Workspace — {{ wsCheckin.title }}</span>
          <VBtn icon="tabler-x" variant="text" size="small" @click="wsOpen = false" />
        </VCardTitle>
        <VDivider />
        <VCardText style="max-height: 70vh">
          <!-- Talking points -->
          <div class="text-subtitle-2 mb-2 d-flex align-center ga-2"><VIcon size="18" color="primary">tabler-message-2</VIcon> Talking Points</div>
          <div v-for="tp in wsCheckin.talking_points" :key="tp.id" class="d-flex align-center ga-2 py-1">
            <VCheckbox :model-value="tp.is_discussed" density="compact" hide-details @update:model-value="toggleTp(tp)" />
            <span :class="{ 'text-decoration-line-through text-medium-emphasis': tp.is_discussed }" class="flex-grow-1 text-body-2">{{ tp.body }}</span>
            <VChip size="x-small" variant="tonal">{{ tp.author_role }}</VChip>
            <VBtn icon="tabler-trash" size="x-small" variant="text" @click="deleteTp(tp)" />
          </div>
          <div class="d-flex ga-2 mt-2 mb-4">
            <VTextField v-model="tpDraft" density="compact" variant="outlined" hide-details placeholder="Add a talking point…" @keyup.enter="addTp" />
            <VBtn icon="tabler-plus" variant="tonal" @click="addTp" />
          </div>

          <VDivider class="mb-3" />

          <!-- Action items -->
          <div class="text-subtitle-2 mb-2 d-flex align-center ga-2"><VIcon size="18" color="success">tabler-checklist</VIcon> Action Items</div>
          <div v-for="ai in wsCheckin.action_item_records" :key="ai.id" class="d-flex align-center ga-2 py-1">
            <VCheckbox :model-value="ai.status === 'done'" density="compact" hide-details @update:model-value="toggleAi(ai)" />
            <div class="flex-grow-1">
              <span :class="{ 'text-decoration-line-through text-medium-emphasis': ai.status === 'done' }" class="text-body-2">{{ ai.description }}</span>
              <span v-if="ai.due_date" class="text-caption text-medium-emphasis ms-2">due {{ ai.due_date }}</span>
            </div>
            <VChip v-if="ai.status === 'carried_over'" size="x-small" color="warning" variant="tonal">carried</VChip>
            <VBtn icon="tabler-trash" size="x-small" variant="text" @click="deleteAi(ai)" />
          </div>
          <div class="d-flex ga-2 mt-2">
            <VTextField v-model="aiDraft.description" density="compact" variant="outlined" hide-details placeholder="New action…" style="flex:2" />
            <VTextField v-model="aiDraft.due_date" type="date" density="compact" variant="outlined" hide-details style="flex:1" />
            <VBtn icon="tabler-plus" variant="tonal" @click="addAi" />
          </div>
        </VCardText>
      </VCard>
    </VDialog>
  </VContainer>
</template>

<script setup lang="ts">
import { ref, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'

const { t } = useI18n()

interface TalkingPoint { id: number; body: string; author_role: string; is_discussed: boolean }
interface ActionItemRec { id: number; description: string; due_date: string | null; status: string; owner_name: string | null }
interface Checkin {
  id: number; title: string; employee_name: string; manager_name: string
  cycle_name: string | null; scheduled_at: string; completed_at: string | null
  duration_minutes: number | null; location: string | null; status: string
  agenda: string | null; notes: string | null; action_items: any[]
  employee_id: number; manager_id: number; cycle_id: number | null
  talking_points?: TalkingPoint[]; action_item_records?: ActionItemRec[]
}

interface Session {
  id: number; title: string; employee_name: string; coach_name: string
  session_date: string; duration_minutes: number | null; objectives: string | null
  outcomes: string | null; next_steps: string | null; status: string
  cycle_name: string | null; employee_id: number; coach_id: number; cycle_id: number | null
}

interface Employee { id: number; name: string }
interface Cycle    { id: number; name: string }

const props = defineProps<{
  checkins: Checkin[]
  sessions: Session[]
  cycles: Cycle[]
  employees: Employee[]
  selectedCycleId: number | null
}>()

const tab = ref('checkins')
const selectedCycleId = ref<number | null>(props.selectedCycleId)

// ── 1:1 Workspace ──
const wsOpen = ref(false)
const wsCheckin = ref<Checkin | null>(null)
const tpDraft = ref('')
const aiDraft = reactive({ description: '', due_date: '' })

function openWorkspace(ci: Checkin) {
  wsCheckin.value = ci
  wsOpen.value = true
}
function addTp() {
  if (!tpDraft.value.trim() || !wsCheckin.value) return
  router.post(route('performance.checkins.talking-points.add', wsCheckin.value.id), { body: tpDraft.value }, {
    preserveScroll: true, onSuccess: () => (tpDraft.value = ''),
  })
}
function toggleTp(tp: TalkingPoint) {
  router.patch(route('performance.talking-points.toggle', tp.id), {}, { preserveScroll: true })
}
function deleteTp(tp: TalkingPoint) {
  router.delete(route('performance.talking-points.delete', tp.id), { preserveScroll: true })
}
function addAi() {
  if (!aiDraft.description.trim() || !wsCheckin.value) return
  router.post(route('performance.checkins.action-items.add', wsCheckin.value.id),
    { description: aiDraft.description, due_date: aiDraft.due_date || null }, {
    preserveScroll: true, onSuccess: () => { aiDraft.description = ''; aiDraft.due_date = '' },
  })
}
function toggleAi(ai: ActionItemRec) {
  router.patch(route('performance.action-items.update', ai.id), { status: ai.status === 'done' ? 'open' : 'done' }, { preserveScroll: true })
}
function deleteAi(ai: ActionItemRec) {
  router.delete(route('performance.action-items.delete', ai.id), { preserveScroll: true })
}

function applyFilter() {
  router.get(route('performance.checkins.index'), {
    cycle_id: selectedCycleId.value ?? undefined,
  }, { preserveState: true, replace: true })
}

// ── Check-In ────────────────────────────────────────────────────────────────
const checkinOpen = ref(false)
const savingCi    = ref(false)
const checkinForm = ref()
const editCheckin = ref<Checkin | null>(null)

const ciForm = reactive({
  title: '', employee_id: null as number | null, manager_id: null as number | null,
  cycle_id: null as number | null, scheduled_at: '', duration_minutes: 60,
  location: '', agenda: '', notes: '', status: 'scheduled',
})

function openCreateCheckin() {
  editCheckin.value = null
  Object.assign(ciForm, {
    title: '', employee_id: null, manager_id: null, cycle_id: null,
    scheduled_at: '', duration_minutes: 60, location: '', agenda: '', notes: '', status: 'scheduled',
  })
  checkinOpen.value = true
}

function openEditCheckin(ci: Checkin) {
  editCheckin.value = ci
  Object.assign(ciForm, {
    title:            ci.title,
    employee_id:      ci.employee_id,
    manager_id:       ci.manager_id,
    cycle_id:         ci.cycle_id,
    scheduled_at:     ci.scheduled_at,
    duration_minutes: ci.duration_minutes ?? 60,
    location:         ci.location ?? '',
    agenda:           ci.agenda ?? '',
    notes:            ci.notes ?? '',
    status:           ci.status,
  })
  checkinOpen.value = true
}

async function submitCheckin() {
  const { valid } = await checkinForm.value.validate()
  if (!valid) return
  savingCi.value = true

  const payload = { ...ciForm }

  if (editCheckin.value) {
    router.patch(route('performance.checkins.update', editCheckin.value.id), payload, {
      onSuccess: () => { checkinOpen.value = false },
      onFinish:  () => { savingCi.value = false },
    })
  } else {
    router.post(route('performance.checkins.store'), payload, {
      onSuccess: () => { checkinOpen.value = false },
      onFinish:  () => { savingCi.value = false },
    })
  }
}

const delCiOpen  = ref(false)
const delCiItem  = ref<Checkin | null>(null)
const deletingCi = ref(false)

function openDelCheckin(ci: Checkin) { delCiItem.value = ci; delCiOpen.value = true }
function confirmDelCheckin() {
  if (!delCiItem.value) return
  deletingCi.value = true
  router.delete(route('performance.checkins.destroy', delCiItem.value.id), {
    onSuccess: () => { delCiOpen.value = false },
    onFinish:  () => { deletingCi.value = false },
  })
}

// ── Coaching Sessions ────────────────────────────────────────────────────────
const sessionOpen = ref(false)
const savingS     = ref(false)
const sessionForm = ref()
const editSession = ref<Session | null>(null)

const sForm = reactive({
  title: '', employee_id: null as number | null, coach_id: null as number | null,
  cycle_id: null as number | null, session_date: '', duration_minutes: 60,
  objectives: '', outcomes: '', next_steps: '', status: 'scheduled',
})

function openCreateSession() {
  editSession.value = null
  Object.assign(sForm, {
    title: '', employee_id: null, coach_id: null, cycle_id: null,
    session_date: '', duration_minutes: 60, objectives: '', outcomes: '', next_steps: '', status: 'scheduled',
  })
  sessionOpen.value = true
}

function openEditSession(s: Session) {
  editSession.value = s
  Object.assign(sForm, {
    title:            s.title,
    employee_id:      s.employee_id,
    coach_id:         s.coach_id,
    cycle_id:         s.cycle_id,
    session_date:     s.session_date,
    duration_minutes: s.duration_minutes ?? 60,
    objectives:       s.objectives ?? '',
    outcomes:         s.outcomes ?? '',
    next_steps:       s.next_steps ?? '',
    status:           s.status,
  })
  sessionOpen.value = true
}

async function submitSession() {
  const { valid } = await sessionForm.value.validate()
  if (!valid) return
  savingS.value = true

  const payload = { ...sForm }

  if (editSession.value) {
    router.patch(route('performance.checkins.sessions.update', editSession.value.id), payload, {
      onSuccess: () => { sessionOpen.value = false },
      onFinish:  () => { savingS.value = false },
    })
  } else {
    router.post(route('performance.checkins.sessions.store'), payload, {
      onSuccess: () => { sessionOpen.value = false },
      onFinish:  () => { savingS.value = false },
    })
  }
}

const delSOpen  = ref(false)
const delSItem  = ref<Session | null>(null)
const deletingS = ref(false)

function openDelSession(s: Session) { delSItem.value = s; delSOpen.value = true }
function confirmDelSession() {
  if (!delSItem.value) return
  deletingS.value = true
  router.delete(route('performance.checkins.sessions.destroy', delSItem.value.id), {
    onSuccess: () => { delSOpen.value = false },
    onFinish:  () => { deletingS.value = false },
  })
}

// Helpers
function statusColor(status: string) {
  return { scheduled: 'blue', completed: 'green', cancelled: 'grey' }[status] ?? 'grey'
}

function truncate(text: string, len = 120) {
  return text.length > len ? text.slice(0, len) + '…' : text
}
</script>
