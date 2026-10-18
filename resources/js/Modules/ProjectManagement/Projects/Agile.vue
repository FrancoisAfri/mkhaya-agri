<script setup lang="ts">
import { computed, ref } from 'vue'
import { useDisplay } from 'vuetify'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import AssigneeStack from '@/Components/Shared/AssigneeStack.vue'

const { t } = useI18n()
const route = ziggyRoute
const { smAndDown } = useDisplay()

type Assignee = { id: string | null; name: string | null; is_lead: boolean; role: string | null }

type Story = {
  id: string; name: string; status: string; points: number | null
  epic_id: number | null; rank: number | null; assignees: Assignee[]
}
type Sprint = {
  id: string; name: string; number: number; goal: string | null; starts_on: string; ends_on: string
  status: string; committed: number | null; completed: number | null; retrospective: string | null
}
type Epic = { id: string; key: string; name: string; colour: string | null; status: string; target: string | null; stories: number; done: number }
type Release = {
  id: string; version: string; name: string | null; status: string; target: string | null
  released_at: string | null; revision: string | null; environment: string | null; notes: string | null; items: number
}

const props = defineProps<{
  project: { id: string; code: string; name: string }
  sprints: Sprint[]
  active: null | {
    id: string; name: string; goal: string | null; day: number | null; days: number
    committed: number; burndown: { days: number[]; ideal: number[]; actual: Array<number | null> }
    stories: Story[]
  }
  backlog: Story[]
  epics: Epic[]
  releases: Release[]
  velocity: number | null
  members: Array<{ id: number; name: string }>
  can: { manage: boolean }
}>()

const tab = ref<'board' | 'backlog' | 'epics' | 'releases'>(props.active ? 'board' : 'backlog')

const backlogPoints = computed(() => props.backlog.reduce((s, x) => s + (x.points ?? 0), 0))

// How many sprints the backlog represents at the team's measured pace. Null when there is no
// velocity yet, because dividing by a number nobody has measured is a guess with a decimal point.
const sprintsToClear = computed(() => props.velocity && props.velocity > 0
  ? Math.ceil(backlogPoints.value / props.velocity)
  : null)

const remaining = computed(() => props.active
  ? props.active.stories.filter(s => s.status !== 'done').reduce((s, x) => s + (x.points ?? 0), 0)
  : 0)

function statusColour(s: string) {
  return ({ done: 'success', in_progress: 'info', blocked: 'error', planned: 'default', active: 'primary', closed: 'default', released: 'success', rolled_back: 'error' } as Record<string, string>)[s] ?? 'default'
}

// ── Sprints ─────────────────────────────────────────────────────────────────
const sprintDialog = ref(false)
const sprintForm = useForm({ name: '', goal: '', starts_on: '', ends_on: '' })

function saveSprint() {
  sprintForm.post(route('project-management.sprints.store', props.project.id), {
    preserveScroll: true, onSuccess: () => { sprintDialog.value = false; sprintForm.reset() },
  })
}

function startSprint(s: Sprint) {
  router.post(route('project-management.sprints.start', [props.project.id, s.id]), {}, { preserveScroll: true })
}

const closeDialog = ref(false)
const closingSprint = ref<Sprint | null>(null)
const closeForm = useForm({ retrospective: '' })

function openClose(s: Sprint) {
  closingSprint.value = s
  closeForm.reset()
  closeDialog.value = true
}

function submitClose() {
  if (!closingSprint.value) return
  closeForm.post(route('project-management.sprints.close', [props.project.id, closingSprint.value.id]), {
    preserveScroll: true, onSuccess: () => { closeDialog.value = false },
  })
}

// ── Stories ─────────────────────────────────────────────────────────────────
function moveToSprint(story: Story, sprintId: string | null) {
  router.patch(route('project-management.stories.update', [props.project.id, story.id]),
    { sprint_id: sprintId }, { preserveScroll: true })
}

const pointsDialog = ref(false)
const pointing = ref<Story | null>(null)
const pointsForm = useForm({ story_points: null as number | null, epic_id: null as string | null })

function openPoints(s: Story) {
  pointing.value = s
  pointsForm.reset()
  pointsForm.story_points = s.points
  pointsDialog.value = true
}

function savePoints() {
  if (!pointing.value) return
  pointsForm.patch(route('project-management.stories.update', [props.project.id, pointing.value.id]), {
    preserveScroll: true, onSuccess: () => { pointsDialog.value = false },
  })
}

// ── Epics and releases ──────────────────────────────────────────────────────
const epicDialog = ref(false)
const epicForm = useForm({ name: '', description: '', colour: '', target_date: '' })

function saveEpic() {
  epicForm.post(route('project-management.epics.store', props.project.id), {
    preserveScroll: true, onSuccess: () => { epicDialog.value = false; epicForm.reset() },
  })
}

const releaseDialog = ref(false)
const releaseForm = useForm({ version: '', name: '', target_date: '', environment: 'production', notes: '' })

function saveRelease() {
  releaseForm.post(route('project-management.releases.store', props.project.id), {
    preserveScroll: true, onSuccess: () => { releaseDialog.value = false; releaseForm.reset() },
  })
}

const shipDialog = ref(false)
const shipping = ref<Release | null>(null)
const shipForm = useForm({ revision: '', notes: '' })

function openShip(r: Release) {
  shipping.value = r
  shipForm.reset()
  shipForm.notes = r.notes ?? ''
  shipDialog.value = true
}

function submitShip() {
  if (!shipping.value) return
  shipForm.post(route('project-management.releases.ship', [props.project.id, shipping.value.id]), {
    preserveScroll: true, onSuccess: () => { shipDialog.value = false },
  })
}

/** The burn-down as an SVG polyline. Small enough that a charting library would be the heavier option. */
const chart = computed(() => {
  if (!props.active) return null
  const b = props.active.burndown
  const w = 640, h = 180, pad = 24
  const max = Math.max(1, ...b.ideal)
  const x = (i: number) => pad + (i / Math.max(1, b.days.length - 1)) * (w - pad * 2)
  const y = (v: number) => h - pad - (v / max) * (h - pad * 2)

  const ideal = b.ideal.map((v, i) => `${x(i)},${y(v)}`).join(' ')
  const todayIndex = b.actual.findIndex(v => v !== null)
  const actual = todayIndex >= 0 ? { x: x(todayIndex), y: y(b.actual[todayIndex] as number) } : null

  return { w, h, ideal, actual, max }
})
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-3"
          @click="router.get(route('project-management.projects.show', project.id))">
      {{ project.code }} · {{ project.name }}
    </VBtn>

    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('project_management.agile.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('project_management.agile.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn v-if="can.manage" variant="tonal" prepend-icon="tabler-run" @click="sprintDialog = true">
          {{ t('project_management.agile.plan_sprint') }}
        </VBtn>
      </div>
    </div>

    <VRow dense class="mb-2">
      <VCol cols="6" md="3">
        <VCard border><VCardText class="py-3">
          <div class="text-h5">{{ velocity ?? '—' }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('project_management.agile.velocity') }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border><VCardText class="py-3">
          <div class="text-h5">{{ backlogPoints }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('project_management.agile.backlog_points') }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border><VCardText class="py-3">
          <div class="text-h5">{{ sprintsToClear ?? '—' }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('project_management.agile.sprints_to_clear') }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border><VCardText class="py-3">
          <div class="text-h5">{{ active ? `${active.day ?? '—'}/${active.days}` : '—' }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('project_management.agile.sprint_day') }}</div>
        </VCardText></VCard>
      </VCol>
    </VRow>

    <VCard border>
      <VTabs v-model="tab" show-arrows>
        <VTab value="board" prepend-icon="tabler-run">{{ t('project_management.agile.sprint') }}</VTab>
        <VTab value="backlog" prepend-icon="tabler-list-numbers">
          {{ t('project_management.agile.backlog') }} ({{ backlog.length }})
        </VTab>
        <VTab value="epics" prepend-icon="tabler-flag">{{ t('project_management.agile.epics') }} ({{ epics.length }})</VTab>
        <VTab value="releases" prepend-icon="tabler-rocket">{{ t('project_management.agile.releases') }} ({{ releases.length }})</VTab>
      </VTabs>
      <VDivider />

      <VWindow v-model="tab">
        <!-- ── The active sprint ──────────────────────────────────────────── -->
        <VWindowItem value="board">
          <template v-if="active">
            <VCardText>
              <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-2">
                <div>
                  <div class="text-body-1 font-weight-medium">{{ active.name }}</div>
                  <div class="text-body-2 text-medium-emphasis">
                    {{ active.goal || t('project_management.agile.no_goal') }}
                  </div>
                </div>
                <div class="text-end">
                  <div class="text-body-2">
                    {{ t('project_management.agile.remaining_of', { remaining, committed: active.committed }) }}
                  </div>
                  <VBtn v-if="can.manage" size="small" variant="tonal" class="mt-1"
                        @click="openClose(sprints.find(s => s.id === active!.id)!)">
                    {{ t('project_management.agile.close_sprint') }}
                  </VBtn>
                </div>
              </div>

              <!-- Burn-down. The ideal line runs the whole box; the actual point stops at today,
                   because drawing it into the future is a forecast dressed as a measurement. -->
              <svg v-if="chart" :viewBox="`0 0 ${chart.w} ${chart.h}`" width="100%" :height="chart.h"
                   role="img" :aria-label="t('project_management.agile.burndown')">
                <line :x1="24" :y1="chart.h - 24" :x2="chart.w - 24" :y2="chart.h - 24"
                      stroke="currentColor" stroke-opacity="0.2" />
                <polyline :points="chart.ideal" fill="none" stroke="currentColor" stroke-opacity="0.35"
                          stroke-width="2" stroke-dasharray="4 4" />
                <circle v-if="chart.actual" :cx="chart.actual.x" :cy="chart.actual.y" r="5"
                        fill="rgb(var(--v-theme-primary))" />
              </svg>
              <div class="d-flex gap-3 text-caption text-medium-emphasis">
                <span>— — {{ t('project_management.agile.ideal') }}</span>
                <span>● {{ t('project_management.agile.today_remaining') }}</span>
              </div>
            </VCardText>
            <VDivider />
            <VList density="comfortable">
              <VListItem v-for="s in active.stories" :key="s.id">
                <!--
                  This board asked each task for an `assignee_display` attribute the model has never
                  had, so every story in every sprint has rendered ownerless since the screen was
                  written — silently, a missing attribute being null rather than an error.
                -->
                <template #prepend>
                  <AssigneeStack :people="s.assignees" :size="26" show-empty class="me-3" />
                </template>
                <VListItemTitle class="text-body-2">{{ s.name }}</VListItemTitle>
                <VListItemSubtitle class="text-caption">
                  <VChip size="x-small" label :color="statusColour(s.status)">
                    {{ t(`project_management.task_status.${s.status}`) }}
                  </VChip>
                  <span v-if="s.points !== null" class="ms-2">{{ t('project_management.agile.points_n', { n: s.points }) }}</span>
                </VListItemSubtitle>
                <template #append>
                  <VBtn v-if="can.manage" size="small" variant="text" icon="tabler-arrow-back-up"
                        :aria-label="t('project_management.agile.return_to_backlog')"
                        @click="moveToSprint(s, null)" />
                </template>
              </VListItem>
            </VList>
          </template>
          <VCardText v-else class="text-center text-medium-emphasis py-10">
            {{ t('project_management.agile.no_active') }}
            <div class="text-caption mt-1">{{ t('project_management.agile.no_active_hint') }}</div>
          </VCardText>
        </VWindowItem>

        <!-- ── Backlog ────────────────────────────────────────────────────── -->
        <VWindowItem value="backlog">
          <VCardText class="text-body-2 text-medium-emphasis">
            {{ t('project_management.agile.backlog_sub') }}
          </VCardText>
          <VList v-if="backlog.length" density="comfortable">
            <VListItem v-for="s in backlog" :key="s.id">
              <template #prepend>
                <AssigneeStack :people="s.assignees" :size="26" show-empty class="me-3" />
              </template>
              <VListItemTitle class="text-body-2">{{ s.name }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">
                <span v-if="s.points !== null">{{ t('project_management.agile.points_n', { n: s.points }) }}</span>
                <span v-else class="text-warning">{{ t('project_management.agile.unestimated') }}</span>
              </VListItemSubtitle>
              <template #append>
                <VBtn v-if="can.manage" size="small" variant="text" icon="tabler-target-arrow"
                      :aria-label="t('project_management.agile.estimate')" @click="openPoints(s)" />
                <VBtn v-if="can.manage && active" size="small" variant="tonal" class="ms-1"
                      @click="moveToSprint(s, active.id)">
                  {{ t('project_management.agile.add_to_sprint') }}
                </VBtn>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-center text-medium-emphasis py-8">
            {{ t('project_management.agile.no_backlog') }}
          </VCardText>
        </VWindowItem>

        <!-- ── Epics ──────────────────────────────────────────────────────── -->
        <VWindowItem value="epics">
          <VCardText class="d-flex align-center justify-space-between flex-wrap gap-2">
            <span class="text-body-2 text-medium-emphasis">{{ t('project_management.agile.epics_sub') }}</span>
            <VBtn v-if="can.manage" size="small" color="primary" prepend-icon="tabler-plus" @click="epicDialog = true">
              {{ t('project_management.agile.add_epic') }}
            </VBtn>
          </VCardText>
          <VList v-if="epics.length" density="comfortable">
            <VListItem v-for="e in epics" :key="e.id">
              <VListItemTitle>{{ e.key }} · {{ e.name }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">
                {{ t('project_management.agile.stories_done', { done: e.done, total: e.stories }) }}
                <template v-if="e.target"> · {{ e.target }}</template>
              </VListItemSubtitle>
              <template #append>
                <VProgressCircular :model-value="e.stories ? (e.done / e.stories) * 100 : 0"
                                   :size="34" :width="4" color="primary">
                  <span class="text-caption">{{ e.stories ? Math.round((e.done / e.stories) * 100) : 0 }}</span>
                </VProgressCircular>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-center text-medium-emphasis py-8">
            {{ t('project_management.agile.no_epics') }}
          </VCardText>
        </VWindowItem>

        <!-- ── Releases ───────────────────────────────────────────────────── -->
        <VWindowItem value="releases">
          <VCardText class="d-flex align-center justify-space-between flex-wrap gap-2">
            <span class="text-body-2 text-medium-emphasis">{{ t('project_management.agile.releases_sub') }}</span>
            <VBtn v-if="can.manage" size="small" color="primary" prepend-icon="tabler-plus" @click="releaseDialog = true">
              {{ t('project_management.agile.add_release') }}
            </VBtn>
          </VCardText>
          <VList v-if="releases.length" density="comfortable">
            <VListItem v-for="r in releases" :key="r.id">
              <VListItemTitle class="d-flex align-center gap-2 flex-wrap">
                <span class="font-weight-medium">{{ r.version }}</span>
                <span v-if="r.name">{{ r.name }}</span>
                <VChip size="x-small" label :color="statusColour(r.status)">
                  {{ t(`project_management.agile.release_${r.status}`) }}
                </VChip>
              </VListItemTitle>
              <VListItemSubtitle class="text-caption">
                <template v-if="r.released_at">{{ r.released_at }}</template>
                <template v-else-if="r.target">{{ t('project_management.agile.targeting', { date: r.target }) }}</template>
                <template v-if="r.environment"> · {{ r.environment }}</template>
                <!-- The tag actually deployed: without it "what was in 2.4.0" is a guess. -->
                <template v-if="r.revision"> · {{ r.revision }}</template>
                · {{ t('project_management.agile.items_n', { n: r.items }) }}
              </VListItemSubtitle>
              <template #append>
                <VBtn v-if="can.manage && r.status === 'planned'" size="small" variant="tonal" color="primary"
                      @click="openShip(r)">
                  {{ t('project_management.agile.ship') }}
                </VBtn>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-center text-medium-emphasis py-8">
            {{ t('project_management.agile.no_releases') }}
          </VCardText>
        </VWindowItem>
      </VWindow>
    </VCard>

    <!-- ── Dialogs ──────────────────────────────────────────────────────── -->
    <VDialog v-model="sprintDialog" :fullscreen="smAndDown" max-width="560">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.agile.plan_sprint') }}</VCardTitle></VCardItem>
        <VCardText>
          <VTextField v-model="sprintForm.name" :label="t('project_management.agile.field_name')"
                      :error-messages="sprintForm.errors.name" variant="outlined" density="compact" class="mb-3" />
          <VTextField v-model="sprintForm.goal" :label="t('project_management.agile.field_goal')"
                      :hint="t('project_management.agile.goal_hint')" persistent-hint
                      variant="outlined" density="compact" class="mb-3" />
          <VRow dense>
            <VCol cols="6">
              <VTextField v-model="sprintForm.starts_on" type="date"
                          :label="t('project_management.agile.field_starts')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="sprintForm.ends_on" type="date"
                          :label="t('project_management.agile.field_ends')"
                          :error-messages="sprintForm.errors.ends_on"
                          variant="outlined" density="compact" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="sprintDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="sprintForm.processing" :disabled="!sprintForm.name" @click="saveSprint">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="closeDialog" :fullscreen="smAndDown" max-width="560">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.agile.close_sprint') }}</VCardTitle>
          <VCardSubtitle>{{ closingSprint?.name }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <!-- Said before it happens: carrying unfinished work forward automatically is how the
               next sprint starts full before anybody plans it. -->
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            {{ t('project_management.agile.close_note') }}
          </VAlert>
          <VTextarea v-model="closeForm.retrospective" :label="t('project_management.agile.field_retro')"
                     variant="outlined" density="compact" rows="4" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="closeDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="closeForm.processing" @click="submitClose">
            {{ t('project_management.agile.close_sprint') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="pointsDialog" :fullscreen="smAndDown" max-width="420">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.agile.estimate') }}</VCardTitle>
          <VCardSubtitle>{{ pointing?.name }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <!-- Fibonacci, because the point of the scale is that big things are deliberately
               imprecise. -->
          <VChipGroup v-model="pointsForm.story_points" mandatory selected-class="text-primary">
            <VChip v-for="p in [1, 2, 3, 5, 8, 13, 21]" :key="p" :value="p" label size="small">{{ p }}</VChip>
          </VChipGroup>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="pointsDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="pointsForm.processing" @click="savePoints">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="epicDialog" :fullscreen="smAndDown" max-width="520">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.agile.add_epic') }}</VCardTitle></VCardItem>
        <VCardText>
          <VTextField v-model="epicForm.name" :label="t('project_management.agile.field_name')"
                      :error-messages="epicForm.errors.name" variant="outlined" density="compact" class="mb-3" />
          <VTextarea v-model="epicForm.description" :label="t('project_management.agile.field_description')"
                     variant="outlined" density="compact" rows="2" class="mb-3" />
          <VTextField v-model="epicForm.target_date" type="date" :label="t('project_management.agile.field_target')"
                      variant="outlined" density="compact" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="epicDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="epicForm.processing" :disabled="!epicForm.name" @click="saveEpic">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="releaseDialog" :fullscreen="smAndDown" max-width="560">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.agile.add_release') }}</VCardTitle></VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="6">
              <VTextField v-model="releaseForm.version" :label="t('project_management.agile.field_version')"
                          :error-messages="releaseForm.errors.version" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="releaseForm.environment" :label="t('project_management.agile.field_environment')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12">
              <VTextField v-model="releaseForm.name" :label="t('project_management.agile.field_name')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12">
              <VTextField v-model="releaseForm.target_date" type="date"
                          :label="t('project_management.agile.field_target')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="releaseForm.notes" :label="t('project_management.agile.field_notes')"
                         variant="outlined" density="compact" rows="4" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="releaseDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="releaseForm.processing" :disabled="!releaseForm.version" @click="saveRelease">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="shipDialog" :fullscreen="smAndDown" max-width="560">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.agile.ship') }}</VCardTitle>
          <VCardSubtitle>{{ shipping?.version }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VTextField v-model="shipForm.revision" :label="t('project_management.agile.field_revision')"
                      :hint="t('project_management.agile.revision_hint')" persistent-hint
                      variant="outlined" density="compact" class="mb-3" />
          <VTextarea v-model="shipForm.notes" :label="t('project_management.agile.field_notes')"
                     variant="outlined" density="compact" rows="5" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="shipDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="shipForm.processing" @click="submitShip">
            {{ t('project_management.agile.ship') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
