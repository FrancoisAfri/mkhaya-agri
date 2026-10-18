<script setup lang="ts">
import { computed, ref } from 'vue'
import { useFitToViewport } from '@/composables/useFitToViewport'
import { router, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import VueApexCharts from 'vue3-apexcharts'
import type { ApexOptions } from 'apexcharts'
import TaskDrawer from './TaskDrawer.vue'
import AssigneeStack from '@/Components/Shared/AssigneeStack.vue'

const { t } = useI18n()
const route = ziggyRoute

type Step = {
  id: string | null; key: string
  // Null on a built-in column: the module names those, in twelve languages, in the browser. Only a
  // column the project invented carries a label of its own.
  label: string | null
  category: string
  is_default: boolean; colour: string | null; custom: boolean
}

/** What to call a column: the project's own name for it, or the module's. */
const stepLabel = (step: Step) => step.label || t(`project_management.task_status.${step.key}`)

type Card = {
  id: string; code: string; name: string; status: string; priority: string
  start: string | null; end: string | null; progress: number
  is_critical: boolean; is_milestone: boolean; is_occurrence: boolean
  float_days: number | null; group: string | null; group_label: string | null
  column: string
  assignees: Array<{ id: string | null; name: string | null; is_lead: boolean; role: string | null }>
}

const props = defineProps<{
  project: { id: string; code: string; name: string; planned_start: string | null; planned_end: string | null }
  tasks: Card[]
  groups: Array<{ id: string; label: string }>
  members: Array<{ id: string; name: string | null; role: string | null }>
  // The board's columns, which belong to the project rather than to the module (T-0008).
  steps: Step[]
  stepCounts: Record<string, number>
  categories: string[]
  statuses: string[]
  priorities: string[]
  taskDetail: Record<string, any> | null
  showArchived: boolean
  archivedCount: number
  can: { editTasks: boolean; createTasks: boolean; manageSteps: boolean }
}>()

// ── Opening a card ───────────────────────────────────────────────────────────

const drawer = ref(false)

/**
 * The same drawer the work list uses, over the board.
 *
 * Only `taskDetail` is refetched, so the columns, the scroll position and a half-typed name in an
 * open composer all survive opening a task — and so does posting a comment inside it, which reloads
 * the conversation without redrawing the board behind it.
 */
function openDetail(card: Card) {
  router.get(route('project-management.board', props.project.id),
    { task: card.id, ...(props.showArchived ? { archived: 1 } : {}) },
    {
      only: ['taskDetail'],
      preserveState: true,
      preserveScroll: true,
      replace: true,
      onSuccess: () => { drawer.value = true },
    })
}

/**
 * Archived work, on request.
 *
 * A full page visit rather than a partial one: which tasks exist is the server's answer, and asking
 * it again is the only way to get the archived ones back.
 */
function toggleArchived() {
  router.get(route('project-management.board', props.project.id),
    props.showArchived ? {} : { archived: 1 },
    { preserveScroll: true })
}

const view = ref<'board' | 'calendar' | 'timeline'>('board')

/** Validation coming back from the server, for the two places on this page that can be refused. */
const page = usePage()

/** One filter across all three views — switching how you look at the work should not reset it. */
const groupFilter = ref<string | null>(null)

/**
 * Whose work to look at. Empty means everybody, which is the state most people want most days.
 *
 * Held beside the breakdown filter and applied in the same place, so it holds across board,
 * calendar and timeline: "show me Thandi and Sipho" is a question about the work, not about the
 * shape you happen to be looking at it in.
 */
const assigneeFilter = ref<string[]>([])

/**
 * Everybody with work on this project, from the tasks themselves.
 *
 * Not from the member list: somebody on the project with nothing assigned adds a name to the
 * filter that can only ever return an empty board, and a filter option that is always empty reads
 * as a fault.
 */
const assignees = computed(() => {
  const seen = new Map<string, string>()

  for (const task of props.tasks) {
    for (const person of task.assignees ?? []) {
      if (person.id && person.name && !seen.has(person.id)) seen.set(person.id, person.name)
    }
  }

  return [...seen].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name))
})

const visible = computed(() => {
  let rows = groupFilter.value
    ? props.tasks.filter(t => t.group === groupFilter.value)
    : props.tasks

  if (assigneeFilter.value.length) {
    // Any of the selected people, not all of them: a task shared between two of the three you
    // picked is still work you asked to see.
    const wanted = new Set(assigneeFilter.value)
    rows = rows.filter(t => (t.assignees ?? []).some(p => p.id && wanted.has(p.id)))
  }

  return rows
})

const statusColor: Record<string, string> = {
  not_started: 'grey', in_progress: 'info', blocked: 'error', done: 'success', cancelled: 'grey',
  archived: 'grey',
}

// ── Board ────────────────────────────────────────────────────────────────────

/**
 * The board's columns.
 *
 * The project's own steps where it has named any, the built-in five otherwise — and archived only
 * when somebody has asked for it, which is what archiving is for. Cards are matched on the column
 * they report rather than on their status: on a project with its own steps, three columns can share
 * one meaning, and matching on meaning would draw the same card in all three.
 */
const columns = computed(() => [
  ...props.steps,
  ...(props.showArchived
    ? [{ id: null, key: 'archived', label: null,
         category: 'archived', is_default: false, colour: null, custom: false } as Step]
    : []),
].map(step => ({
  step,
  cards: visible.value.filter(c => c.column === step.key),
})))

const dragging = ref<string | null>(null)

function drop(stepKey: string) {
  const id = dragging.value
  dragging.value = null

  if (!id) return

  const card = props.tasks.find(t => t.id === id)
  if (!card || card.column === stepKey) return

  // The column's key, not a status. The server resolves what it means against this project — three
  // columns may legitimately share one meaning, and only the key says which one the card is in.
  router.patch(route('project-management.board.move', [props.project.id, id]), { status: stepKey }, {
    preserveScroll: true,
    preserveState: true,
  })
}

// ── The board's own columns ──────────────────────────────────────────────────

/**
 * Naming the steps a project's work actually moves through.
 *
 * Every project used to have the same five columns. Real work does not divide that way: a
 * construction job wants "awaiting materials" and "ready for inspection", a design team wants "in
 * review", and a board that cannot say those things gets worked around in the task title instead.
 *
 * Each step carries a meaning drawn from the five built-in states, and that is what the rest of the
 * module reads — the evidence ledger, progress, claims, the scheduler. Without it, a project whose
 * columns were "Blue" and "Green" would silently record no completions and roll up no progress.
 */
const stepsDialog = ref(false)
const draftSteps = ref<Step[]>([])
const savingSteps = ref(false)

function openSteps() {
  /*
   * A copy, so cancelling really cancels and a half-finished rename never reaches the board.
   *
   * Built-in columns arrive unlabelled — the module names those — so they are given their current
   * names on the way in. Saving turns the five defaults into five real columns this project owns,
   * which is exactly what somebody opening this dialog is asking for.
   */
  draftSteps.value = props.steps.map(s => ({ ...s, label: stepLabel(s) }))
  stepsDialog.value = true
}

function addStep() {
  draftSteps.value.push({
    id: null, key: '', label: '', category: 'in_progress',
    is_default: false, colour: null, custom: true,
  })
}

function removeStep(index: number) {
  draftSteps.value.splice(index, 1)
}

function moveStep(index: number, by: number) {
  const to = index + by
  if (to < 0 || to >= draftSteps.value.length) return

  const [step] = draftSteps.value.splice(index, 1)
  draftSteps.value.splice(to, 0, step)
}

/** Exactly one, always — a board where a new task lands nowhere in particular is worse than one with an opinion. */
function makeDefault(index: number) {
  draftSteps.value.forEach((s, i) => { s.is_default = i === index })
}

/** What removing a column would move, so nobody discovers it afterwards. */
function tasksIn(step: Step): number {
  return props.stepCounts[step.key] ?? 0
}

const orphaned = computed(() => props.steps
  .filter(s => !draftSteps.value.some(d => d.id && d.id === s.id))
  .reduce((n, s) => n + tasksIn(s), 0))

const stepsValid = computed(() =>
  draftSteps.value.length > 0
  && draftSteps.value.every(s => s.label.trim())
  && draftSteps.value.some(s => s.category === 'done'))

function saveSteps() {
  if (!stepsValid.value || savingSteps.value) return

  savingSteps.value = true

  router.put(route('project-management.board.steps', props.project.id), {
    steps: draftSteps.value.map(s => ({
      id: s.id, label: (s.label ?? '').trim(), category: s.category,
      is_default: s.is_default, colour: s.colour,
    })),
  }, {
    preserveScroll: true,
    onSuccess: () => { stepsDialog.value = false },
    onFinish: () => { savingSteps.value = false },
  })
}

const stepErrors = computed(() => {
  const e = (page.props.errors ?? {}) as Record<string, string>

  return e.steps ? [e.steps] : []
})

// ── Adding work ──────────────────────────────────────────────────────────────

/**
 * One composer, opened against whichever slot the user clicked.
 *
 * Keyed by slot rather than held per column so only one can ever be open: two half-typed task names
 * on screen at once is a way to lose one of them. The key is the column's status on the board and
 * the ISO date in the calendar, which is also exactly what the new task should inherit.
 */
const composer = ref<{ slot: string; kind: 'status' | 'date' } | null>(null)
const draftName = ref('')
const saving = ref(false)

/**
 * Who it is for, when it is due, and what it is about — asked at the moment the task is typed.
 *
 * All three were previously only reachable by creating the task, finding it again and opening it,
 * which is why the board filled up with unassigned, undated cards and the photograph of the thing
 * being described stayed on somebody's phone.
 *
 * They stay optional. The one-line capture is the reason this composer exists, and a form that
 * demands an owner before it will accept "chase the steel delivery" is a form people stop using.
 */
const draftMember = ref<string | null>(null)
const draftDue = ref('')
const draftFiles = ref<File[]>([])
const showExtras = ref(false)

function openComposer(slot: string, kind: 'status' | 'date') {
  composer.value = { slot, kind }
  resetDraft()
}

function closeComposer() {
  composer.value = null
  resetDraft()
}

function resetDraft() {
  draftName.value = ''
  draftMember.value = null
  draftDue.value = ''
  draftFiles.value = []
  showExtras.value = false
}

/**
 * Why the last attempt was refused.
 *
 * The composer could not fail before — a name is a name — so it never showed anything. It can now:
 * the person picked is on leave, the file is too large, the due date is before the day that was
 * clicked. Without this the button would appear to do nothing, and the honest reading of a button
 * that does nothing is that the feature is broken.
 */
const composerErrors = computed(() => {
  const e = (page.props.errors ?? {}) as Record<string, string>

  return ['name', 'member_id', 'attachments', 'planned_end'].map(k => e[k]).filter(Boolean)
})

function isComposing(slot: string) {
  return composer.value?.slot === slot
}

/**
 * Create the task where it was asked for.
 *
 * The slot supplies the one field that makes the click meaningful — the column's status, or the
 * day's date on both ends so a calendar entry lands on the day you pointed at rather than floating
 * undated. The active breakdown filter comes along too: adding a task while looking at one work
 * package and having it appear outside that filter reads as the click having failed.
 *
 * The composer stays open on success, the way a physical list does. Someone adding tasks is usually
 * adding several, and reopening the box between each is the difference between capturing a stand-up
 * and giving up halfway.
 */
function addTask() {
  const name = draftName.value.trim()

  if (!name || !composer.value || saving.value) return

  const { slot, kind } = composer.value

  saving.value = true

  router.post(route('project-management.tasks.store', props.project.id), {
    name,
    ...(kind === 'status' ? { status: slot } : { planned_start: slot, planned_end: slot }),
    // A due date typed into the composer wins over the day that was clicked: someone who filled the
    // field in meant it, and silently keeping the click would make the field look broken.
    ...(draftDue.value ? { planned_end: draftDue.value } : {}),
    ...(draftMember.value ? { member_id: draftMember.value } : {}),
    ...(groupFilter.value ? { wbs_node_id: groupFilter.value } : {}),
    ...(draftFiles.value.length ? { attachments: draftFiles.value } : {}),
  }, {
    // Only when there is something to send: forcing it on every capture would turn the fast path
    // into a multipart round-trip for no reason.
    forceFormData: draftFiles.value.length > 0,
    preserveScroll: true,
    preserveState: true,
    // The name clears and the rest stays. Somebody adding tasks is usually adding several for the
    // same person on the same day, and making them re-pick both each time is the difference
    // between capturing a stand-up and giving up halfway. Files do clear — the same photograph on
    // the next five tasks is nobody's intention.
    onSuccess: () => { draftName.value = ''; draftFiles.value = [] },
    onFinish: () => { saving.value = false },
  })
}

/**
 * The days a card occupies, for the calendar and the timeline both.
 *
 * A task needs only one date to be placed. Most have just a due date — that is what the composer
 * asks for and what the drawer sets — and both views used to require `start` *and* `end`, so every
 * one of those was silently missing from a calendar and a timeline that had been given exactly the
 * information needed to draw it. The board looked full and the other two views looked empty.
 *
 * With one date it is a single day; with two it is the range between them, whichever way round they
 * were entered.
 */
function cardSpan(card: Card): { from: string; to: string } | null {
  const from = card.start ?? card.end
  const to = card.end ?? card.start

  if (!from || !to) return null

  return from <= to ? { from, to } : { from: to, to: from }
}

// ── Calendar ─────────────────────────────────────────────────────────────────

const monthCursor = ref(new Date())

const monthLabel = computed(() => monthCursor.value.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }))

function shiftMonth(by: number) {
  const next = new Date(monthCursor.value)
  next.setMonth(next.getMonth() + by)
  monthCursor.value = next
}

/** Whole weeks covering the month, Monday first, so the grid is always rectangular. */
const monthDays = computed(() => {
  const first = new Date(monthCursor.value.getFullYear(), monthCursor.value.getMonth(), 1)
  const last = new Date(monthCursor.value.getFullYear(), monthCursor.value.getMonth() + 1, 0)

  const start = new Date(first)
  start.setDate(first.getDate() - ((first.getDay() + 6) % 7))

  const end = new Date(last)
  end.setDate(last.getDate() + (7 - ((last.getDay() + 6) % 7) - 1))

  const days: Array<{ date: string; inMonth: boolean; cards: Card[] }> = []

  for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
    const iso = d.toISOString().slice(0, 10)

    days.push({
      date: iso,
      inMonth: d.getMonth() === monthCursor.value.getMonth(),
      // A task appears on every day it runs, not only the day it starts — a three-week task that
      // shows up once is invisible on the week anyone actually looks at.
      cards: visible.value.filter(c => {
        const s = cardSpan(c)

        return s !== null && iso >= s.from && iso <= s.to
      }),
    })
  }

  return days
})

const weekdayLabels = ['mo', 'tu', 'we', 'th', 'fr', 'sa', 'su']

// ── Timeline ─────────────────────────────────────────────────────────────────

/** Cards with at least one date — a due date alone is enough to draw a bar. */
const dated = computed(() => visible.value.filter(c => cardSpan(c) !== null))

/** The window the bars are drawn in: the work itself, not the project's nominal dates. */
const span = computed(() => {
  if (!dated.value.length) return null

  const spans = dated.value.map(c => cardSpan(c)!)
  const from = Math.min(...spans.map(s => +new Date(s.from)))
  const to = Math.max(...spans.map(s => +new Date(s.to)))

  // A single-day project would divide by zero; give it a day of width.
  return { from, to: to > from ? to : from + 86_400_000 }
})

function bar(card: Card) {
  const s = cardSpan(card)

  if (!span.value || !s) return null

  const total = span.value.to - span.value.from
  const left = ((+new Date(s.from) - span.value.from) / total) * 100
  const width = ((+new Date(s.to) - +new Date(s.from)) / total) * 100

  // A single-day task has no width of its own; give it enough to be visible and clickable.
  return { left: `${left}%`, width: `${Math.max(width, 0.8)}%` }
}

const DAY = 86_400_000

/**
 * Ticks at a granularity the span can actually carry.
 *
 * It was months, always. A project whose work all falls inside one month therefore got a single
 * "Sep" and a row of dots — a chart with one gridline says nothing about when anything happens,
 * which is the only question a timeline is asked. Short spans now get days, medium ones weeks, and
 * only genuinely long ones get months.
 */
const ticks = computed(() => {
  if (!span.value) return []

  const days = (span.value.to - span.value.from) / DAY
  const out: Array<{ label: string; left: string; strong: boolean }> = []
  const place = (at: number) => `${Math.max(0, ((at - span.value!.from) / (span.value!.to - span.value!.from)) * 100)}%`

  const cursor = new Date(span.value.from)
  cursor.setHours(0, 0, 0, 0)

  if (days <= 21) {
    // Every day, with the start of each week emphasised so a fortnight still reads as weeks.
    while (+cursor <= span.value.to) {
      out.push({
        label: cursor.toLocaleDateString(undefined, { day: 'numeric', month: days <= 7 ? 'short' : undefined }),
        left: place(+cursor),
        strong: cursor.getDay() === 1,
      })
      cursor.setDate(cursor.getDate() + 1)
    }
  } else if (days <= 120) {
    // Weeks, from the first Monday at or before the start.
    cursor.setDate(cursor.getDate() - ((cursor.getDay() + 6) % 7))
    while (+cursor <= span.value.to) {
      out.push({
        label: cursor.toLocaleDateString(undefined, { day: 'numeric', month: 'short' }),
        left: place(+cursor),
        strong: cursor.getDate() <= 7,
      })
      cursor.setDate(cursor.getDate() + 7)
    }
  } else {
    cursor.setDate(1)
    while (+cursor <= span.value.to) {
      out.push({
        label: cursor.toLocaleDateString(undefined, { month: 'short', year: '2-digit' }),
        left: place(+cursor),
        strong: cursor.getMonth() === 0,
      })
      cursor.setMonth(cursor.getMonth() + 1)
    }
  }

  return out
})

/** Where today falls, when it falls inside the span at all. */
const todayMark = computed(() => {
  if (!span.value) return null

  const now = Date.now()
  if (now < span.value.from || now > span.value.to) return null

  return `${((now - span.value.from) / (span.value.to - span.value.from)) * 100}%`
})

/** How many days a task covers, inclusive — one day of work is a day, not zero. */
function durationOf(card: Card): number | null {
  const s = cardSpan(card)
  if (!s) return null

  return Math.round((+new Date(s.to) - +new Date(s.from)) / DAY) + 1
}

/**
 * Late, and by how much.
 *
 * A timeline that draws where work was planned and says nothing about whether it happened is a
 * picture of intentions. This is the one comparison that makes it a status report.
 */
function overdueDays(card: Card): number {
  if (!card.end || card.column === 'done' || card.column === 'cancelled') return 0

  const days = Math.floor((Date.now() - +new Date(card.end)) / DAY)

  return days > 0 ? days : 0
}

/** The header line: what this plan actually amounts to. */
const timelineSummary = computed(() => {
  const rows = dated.value

  return {
    tasks: rows.length,
    overdue: rows.filter(c => overdueDays(c) > 0).length,
    critical: rows.filter(c => c.is_critical).length,
    milestones: rows.filter(c => c.is_milestone).length,
    done: rows.filter(c => c.column === 'done').length,
    // Weighted by nothing: an average of the progress the board already knows about, which is the
    // number people expect when they see a percentage next to a plan.
    progress: rows.length
      ? Math.round(rows.reduce((n, c) => n + (c.progress ?? 0), 0) / rows.length)
      : 0,
    from: span.value ? new Date(span.value.from) : null,
    to: span.value ? new Date(span.value.to) : null,
  }
})

// ── The picture of the work ──────────────────────────────────────────────────

/*
 * Charts read the same rows the views do — `visible`, not `props.tasks`.
 *
 * That is what makes the assignee filter mean something: pick two people and the donuts describe
 * those two people's work. Charts fed from the unfiltered set beside a filtered board is a screen
 * that quietly contradicts itself.
 */

/** The theme's own colours, so a chart slice and a status chip never disagree about "done". */
const STATUS_HEX: Record<string, string> = {
  not_started: '#9e9e9e', in_progress: '#2196f3', blocked: '#f44336',
  done: '#4caf50', cancelled: '#bdbdbd', archived: '#78909c',
}

/**
 * Where the work stands, by column.
 *
 * Built from the project's own steps rather than the five built-in statuses: a project that has
 * named its columns "Awaiting materials" and "Ready for inspection" should see those words here,
 * and a donut labelled with meanings the board does not use is a chart of a different project.
 */
const phaseMix = computed(() => {
  const counts = new Map<string, { label: string; count: number; colour: string }>()

  for (const column of columns.value) {
    const step = column.step

    counts.set(step.key, {
      label: step.label || t(`project_management.task_status.${step.key}`),
      count: column.cards.length,
      colour: step.colour || STATUS_HEX[step.category] || STATUS_HEX[step.key] || '#9e9e9e',
    })
  }

  // Empty columns are dropped: apex draws a legend entry for a zero slice and it reads as a
  // category that exists but cannot be seen.
  return [...counts.values()].filter(row => row.count > 0)
})

const phaseSeries = computed(() => phaseMix.value.map(row => row.count))

const phaseOptions = computed<ApexOptions>(() => ({
  chart: { type: 'donut' },
  labels: phaseMix.value.map(row => row.label),
  colors: phaseMix.value.map(row => row.colour),
  legend: { position: 'bottom' },
  dataLabels: { enabled: true, formatter: (value: number) => `${Math.round(value)}%` },
  plotOptions: {
    pie: {
      donut: {
        labels: {
          show: true,
          total: {
            show: true,
            label: t('project_management.views.tl_task'),
            formatter: () => String(visible.value.length),
          },
        },
      },
    },
  },
}))

/**
 * How far along each person's own work is.
 *
 * Deliberately one small donut each rather than a single donut of everybody's share. A share chart
 * answers "who has the most tasks", which nobody asks; this answers "how is each person going",
 * which is the question a manager opens the page with.
 *
 * A task shared between two people counts for both. Splitting it would understate both of them,
 * and there is nothing in the data that says how the work divided.
 */
const perPerson = computed(() => {
  const rows = new Map<string, { name: string; total: number; done: number; progress: number }>()

  for (const task of visible.value) {
    for (const person of task.assignees ?? []) {
      if (!person.id || !person.name) continue

      const row = rows.get(person.id) ?? { name: person.name, total: 0, done: 0, progress: 0 }

      row.total += 1
      if (task.column === 'done') row.done += 1
      row.progress += task.progress ?? 0
      rows.set(person.id, row)
    }
  }

  return [...rows]
    .map(([id, row]) => ({
      id,
      name: row.name,
      total: row.total,
      done: row.done,
      // The board's own progress figure averaged, not a done/total ratio: a task at 90% is most of
      // a task finished, and counting it as zero tells somebody their week produced nothing.
      percent: row.total ? Math.round(row.progress / row.total) : 0,
    }))
    .sort((a, b) => b.total - a.total)
})

/*
 * One options object for every dial: they differ only in their series, and rebuilding the object
 * per person would allocate a fresh one on each render for no difference in what is drawn.
 *
 * The name is not in here. It sits under the dial as real text and in the row's title attribute,
 * where a screen reader and a mouse can both reach it — inside the chart it would be neither.
 */
const personDialOptions = computed<ApexOptions>(() => ({
  chart: { type: 'donut', sparkline: { enabled: true } },
  labels: [t('project_management.views.tl_complete'), t('project_management.views.tl_remaining')],
  colors: ['#4caf50', 'rgba(158,158,158,0.25)'],
  legend: { show: false },
  tooltip: { enabled: false },
  dataLabels: { enabled: false },
  stroke: { width: 0 },
  plotOptions: {
    pie: {
      donut: {
        size: '72%',
        labels: {
          show: true,
          name: { show: false },
          value: { show: true, offsetY: 5, fontSize: '15px', formatter: (v: string) => `${v}%` },
        },
      },
    },
  },
}))

/** Ordered by when work starts: a plan read top to bottom should move left to right. */
const timelineRows = computed(() =>
  [...dated.value].sort((a, b) => (cardSpan(a)!.from).localeCompare(cardSpan(b)!.from)))

const undated = computed(() => visible.value.length - dated.value.length)

/*
 * The page is exactly the room left on the screen, measured rather than assumed.
 *
 * A `calc(100dvh - 260px)` was right on the screen it was written on and a scrollbar everywhere
 * else: this sits below the shell's navbar and above its footer, and both change height with the
 * theme, the density and whether the window is narrow enough to make the navbar wrap.
 */
const { el: pageEl, height: pageHeight } = useFitToViewport()
</script>

<template>
  <!--
    The page is exactly the height of the viewport and never taller.

    Two lines of chrome — a back button on one, the title and the controls on another — plus a
    board strip sized by a guessed `100dvh - 260px` meant the total came to more than the screen,
    so the whole page scrolled vertically. That is the one thing a board must not do: scrolling the
    page takes every column's heading off the top, and comparing "Blocked" with "In progress" is
    the comparison the layout exists to make.

    So: one line of chrome, and the strip takes what is left through `flex: 1` with `min-height: 0`
    rather than through arithmetic about how tall the header happens to be today.
  -->
  <div ref="pageEl" class="pa-4 d-flex flex-column views-page" :style="{ blockSize: pageHeight }">
    <div class="d-flex align-center gap-2 mb-3 flex-wrap views-bar">
      <VBtn
        variant="text" size="small" prepend-icon="tabler-arrow-left" class="flex-shrink-0"
        @click="router.get(route('project-management.projects.show', project.id))"
      >
        {{ project.code }}
      </VBtn>

      <!--
        Centred between the way back and the controls, with a flexible gap either side. Its own
        line said nothing the project name was not already saying, and cost a line of a page whose
        entire job is to fit the board on the screen.
      -->
      <VSpacer />
      <h4 class="text-h5 mb-0 text-truncate">
        {{ project.name }}
        <span class="text-medium-emphasis font-weight-regular">— {{ t('project_management.views.title') }}</span>
      </h4>
      <VSpacer />

      <div class="d-flex align-center gap-2 flex-shrink-0">
        <!--
          Whose work, across all three views. Chips rather than a count, because "Thandi, Sipho"
          tells you what you are looking at and "2 selected" makes you open the menu to find out.
        -->
        <VAutocomplete
          v-if="assignees.length > 1"
          v-model="assigneeFilter"
          :items="assignees.map(a => ({ title: a.name, value: a.id }))"
          :label="t('project_management.views.filter_people')"
          multiple chips closable-chips clearable
          density="compact" variant="outlined" hide-details style="min-width:240px;max-width:340px"
        />

        <VAutocomplete
          v-if="groups.length"
          v-model="groupFilter"
          :items="[{ title: t('project_management.work.all_work'), value: null },
                   ...groups.map(g => ({ title: g.label, value: g.id }))]"
          :aria-label="t('project_management.work.breakdown')"
          density="compact" variant="outlined" hide-details style="min-width:220px"
        />
        <!--
          Offered only when there is something archived to look at: a control that reveals nothing
          reads as broken, and on a new project it would always read that way.
        -->
        <VBtn
          v-if="archivedCount || showArchived"
          size="small" variant="tonal"
          :prepend-icon="showArchived ? 'tabler-archive-off' : 'tabler-archive'"
          @click="toggleArchived"
        >
          {{ showArchived
            ? t('project_management.views.hide_archived')
            : t('project_management.views.show_archived', { count: archivedCount }) }}
        </VBtn>

        <!--
          Present on all three views, not only the board.

          It was hidden outside the board, on the reasoning that columns belong to the board. What
          that produced was a control disappearing and the whole toolbar shifting sideways every
          time somebody switched to Calendar — which reads as something breaking rather than as
          something being irrelevant. The columns still belong to the board; the dialog that edits
          them does not care which view is open.

          Labelled, and kept clearly out of the view switcher.

          It was an icon alone because a labelled button sitting beside the toggle read as a fourth
          view — "Columns, Board, Calendar, Timeline" — when it edits the board's columns rather
          than showing anything. The label is back because an unexplained icon is worse; `tonal`
          against the toggle's `outlined`, plus the divider, is what keeps it from reading as a tab.
        -->
        <VBtn
          v-if="can.manageSteps"
          size="small" variant="tonal" prepend-icon="tabler-columns-3"
          :title="t('project_management.steps.edit')"
          @click="openSteps"
        >
          {{ t('project_management.steps.edit') }}
        </VBtn>

        <VDivider v-if="can.manageSteps" vertical class="mx-1 my-2" />

        <VBtnToggle v-model="view" mandatory density="compact" variant="outlined" divided>
          <VBtn value="board" prepend-icon="tabler-layout-columns">{{ t('project_management.views.board') }}</VBtn>
          <VBtn value="calendar" prepend-icon="tabler-calendar">{{ t('project_management.views.calendar') }}</VBtn>
          <VBtn value="timeline" prepend-icon="tabler-timeline">{{ t('project_management.views.timeline') }}</VBtn>
        </VBtnToggle>
      </div>
    </div>

    <!-- Board -->
    <!--
      The board fills the screen and no more.

      It used to grow with its longest column, so a project with forty tasks in one status pushed
      the page metres down and took every other column's header off screen with it. You could not
      see what was in "Blocked" while reading "In progress", which is the one comparison a board
      exists to make.

      Now the strip is bounded to what is left of the viewport and each column scrolls inside
      itself, so the headers and the add box stay put however much work is in any of them.
    -->
    <div v-if="view === 'board'" class="d-flex gap-3 overflow-x-auto pb-2 board-strip">
      <div
        v-for="column in columns" :key="column.step.key"
        class="flex-shrink-0 board-column" style="width:280px"
        @dragover.prevent @drop="drop(column.step.key)"
      >
        <VCard border class="d-flex flex-column h-100">
          <VCardItem class="pb-2">
            <VCardTitle class="text-body-2 d-flex align-center gap-2">
              <!-- The step's own colour where somebody chose one, else the colour of what it
                   means: a custom "Awaiting materials" reads as blocked without being told. -->
              <VChip size="x-small" label :color="column.step.colour || statusColor[column.step.category] || 'grey'">
                {{ stepLabel(column.step) }}
              </VChip>
              <span class="text-medium-emphasis">{{ column.cards.length }}</span>
            </VCardTitle>
          </VCardItem>
          <VDivider />
          <VCardText class="pa-2 board-column__cards" style="min-height:200px">
            <!--
              Draggable and clickable both. Dragging moves the work; clicking opens it — which is
              what somebody wants nine times out of ten and, until now, the board could not do at
              all. `role`/`tabindex` because a VCard is a div: without them the whole board is
              unreachable from a keyboard.
            -->
            <VCard
              v-for="card in column.cards" :key="card.id"
              border flat class="mb-2 pa-2 cursor-pointer"
              :draggable="can.editTasks"
              role="button"
              tabindex="0"
              :aria-label="t('project_management.views.open_task', { name: card.name })"
              @dragstart="dragging = card.id"
              @click="openDetail(card)"
              @keydown.enter.prevent="openDetail(card)"
              @keydown.space.prevent="openDetail(card)"
            >
              <div class="d-flex align-center justify-space-between">
                <span class="text-caption text-medium-emphasis">{{ card.code }}</span>
                <div>
                  <VIcon v-if="card.is_critical" icon="tabler-alert-triangle" size="14" color="error"
                         :title="t('project_management.schedule.on_critical_path')" />
                  <VIcon v-if="card.is_milestone" icon="tabler-flag" size="14" class="ms-1" />
                  <VIcon v-if="card.is_occurrence" icon="tabler-repeat" size="14" class="ms-1" />
                </div>
              </div>
              <div class="text-body-2">{{ card.name }}</div>

              <!--
                Who it is for, on the card. Without this an unowned task and one with a foreman on
                it look identical, which is the first question anybody asks of a board. Drawn even
                when empty, because a missing face is a fact worth seeing.
              -->
              <div class="d-flex align-center justify-space-between mt-1">
                <AssigneeStack :people="card.assignees" :size="22" show-empty />
                <span v-if="card.end" class="text-caption text-medium-emphasis">{{ card.end }}</span>
              </div>
              <VProgressLinear
                v-if="card.progress > 0" :model-value="card.progress"
                height="3" rounded class="mt-1" color="primary"
              />
            </VCard>

            <p v-if="!column.cards.length && !isComposing(column.step.key)"
               class="text-caption text-disabled text-center py-4">
              {{ t('project_management.views.column_empty') }}
            </p>

            <!--
              The composer sits inside the column it will file into, so the status the task gets is
              the one the user pointed at rather than something they pick again from a dropdown.
            -->
            <div v-if="isComposing(column.step.key)">
              <VTextarea
                v-model="draftName"
                :placeholder="t('project_management.views.add_task_placeholder')"
                :aria-label="t('project_management.views.add_task_to', {
                  column: stepLabel(column.step),
                })"
                variant="outlined"
                density="compact"
                rows="2"
                auto-grow
                autofocus
                hide-details
                :disabled="saving"
                @keydown.enter.exact.prevent="addTask"
                @keydown.esc="closeComposer"
              />

              <!--
                Folded away by default. The one-line capture is the point of this box, and three
                more fields permanently open would make adding a task feel like filing a form.
              -->
              <VBtn
                v-if="!showExtras" size="x-small" variant="text" class="mt-1 px-1"
                prepend-icon="tabler-adjustments-horizontal" @click="showExtras = true"
              >
                {{ t('project_management.views.more_options') }}
              </VBtn>

              <div v-else class="mt-2 d-flex flex-column ga-2">
                <VAutocomplete
                  v-model="draftMember"
                  :items="members.map(m => ({ title: m.name, value: m.id }))"
                  :label="t('project_management.views.assign_to')"
                  variant="outlined" density="compact" hide-details clearable
                />
                <VTextField
                  v-model="draftDue" type="date"
                  :label="t('project_management.views.due')"
                  variant="outlined" density="compact" hide-details
                />
                <VFileInput
                  v-model="draftFiles" multiple chips
                  :label="t('project_management.views.attach')"
                  prepend-icon="" prepend-inner-icon="tabler-paperclip"
                  variant="outlined" density="compact" hide-details
                />
              </div>
              <VAlert
                v-for="message in composerErrors" :key="message"
                type="error" variant="tonal" density="compact" class="mt-2 text-caption"
              >{{ message }}</VAlert>

              <div class="d-flex align-center gap-2 mt-2">
                <VBtn size="small" color="primary" :loading="saving"
                      :disabled="!draftName.trim()" @click="addTask">
                  {{ t('project_management.views.add_task') }}
                </VBtn>
                <VBtn size="small" variant="text" icon="tabler-x"
                      :aria-label="t('project_management.views.cancel_add')" @click="closeComposer" />
              </div>
            </div>

          </VCardText>

          <!--
            Pinned to the foot of the column rather than trailing the cards.
            
            Inside the scroll area it moved with them, so on a column of forty tasks the way to add
            the forty-first was to scroll to the bottom to find the button. Here it is in the same
            place in every column however much work is in any of them — which is what makes a board
            quick to add to rather than merely quick to read.
          -->
          <template v-if="can.createTasks && !isComposing(column.step.key)">
            <VDivider />
            <VBtn
              variant="text" size="small" block
              prepend-icon="tabler-plus"
              class="justify-start text-medium-emphasis board-column__add"
              @click="openComposer(column.step.key, 'status')"
            >
              {{ t('project_management.views.add_task') }}
            </VBtn>
          </template>
        </VCard>
      </div>
    </div>

    <!-- Calendar -->
    <VCard v-else-if="view === 'calendar'" border class="views-scroll">
      <VCardItem>
        <VCardTitle class="text-body-1">{{ monthLabel }}</VCardTitle>
        <template #append>
          <VBtn icon="tabler-chevron-left" variant="text" size="small"
                :aria-label="t('project_management.views.previous_month')" @click="shiftMonth(-1)" />
          <VBtn icon="tabler-chevron-right" variant="text" size="small"
                :aria-label="t('project_management.views.next_month')" @click="shiftMonth(1)" />
        </template>
      </VCardItem>
      <VDivider />
      <VCardText>
        <div class="calendar-grid">
          <div v-for="day in weekdayLabels" :key="day" class="text-caption text-medium-emphasis text-center pb-1">
            {{ t(`project_management.weekday.${day}`).slice(0, 3) }}
          </div>

          <div
            v-for="day in monthDays" :key="day.date"
            class="calendar-cell pa-1"
            :class="{ 'calendar-cell--muted': !day.inMonth, 'calendar-cell--composing': isComposing(day.date) }"
          >
            <div class="d-flex align-center justify-space-between">
              <span class="text-caption text-medium-emphasis">{{ day.date.slice(-2) }}</span>
              <!--
                Revealed on hover rather than always drawn: a month grid with forty-two permanent
                buttons in it reads as a form, not a calendar. It stays reachable by keyboard because
                focus makes it visible too — see the `:focus-within` rule below.
              -->
              <VBtn
                v-if="can.createTasks && !isComposing(day.date)"
                class="calendar-add"
                icon="tabler-plus"
                size="x-small"
                variant="text"
                density="compact"
                :aria-label="t('project_management.views.add_task_on', { date: day.date })"
                :title="t('project_management.views.add_task_on', { date: day.date })"
                @click="openComposer(day.date, 'date')"
              />
            </div>

            <div v-if="isComposing(day.date)" class="mt-1">
              <VTextarea
                v-model="draftName"
                :placeholder="t('project_management.views.add_task_placeholder')"
                :aria-label="t('project_management.views.add_task_on', { date: day.date })"
                variant="outlined"
                density="compact"
                rows="2"
                auto-grow
                autofocus
                hide-details
                :disabled="saving"
                @keydown.enter.exact.prevent="addTask"
                @keydown.esc="closeComposer"
              />

              <!-- The day is already carried by the cell that was clicked, so only the owner and
                   the paperwork are worth asking for here. -->
              <VBtn
                v-if="!showExtras" size="x-small" variant="text" class="mt-1 px-1"
                prepend-icon="tabler-adjustments-horizontal" @click="showExtras = true"
              >
                {{ t('project_management.views.more_options') }}
              </VBtn>

              <div v-else class="mt-1 d-flex flex-column ga-1">
                <VAutocomplete
                  v-model="draftMember"
                  :items="members.map(m => ({ title: m.name, value: m.id }))"
                  :label="t('project_management.views.assign_to')"
                  variant="outlined" density="compact" hide-details clearable
                />
                <VFileInput
                  v-model="draftFiles" multiple
                  :label="t('project_management.views.attach')"
                  prepend-icon="" prepend-inner-icon="tabler-paperclip"
                  variant="outlined" density="compact" hide-details
                />
              </div>

              <VAlert
                v-for="message in composerErrors" :key="message"
                type="error" variant="tonal" density="compact" class="mt-1 text-caption"
              >{{ message }}</VAlert>

              <div class="d-flex align-center gap-1 mt-1">
                <VBtn size="x-small" color="primary" :loading="saving"
                      :disabled="!draftName.trim()" @click="addTask">
                  {{ t('project_management.views.add_task') }}
                </VBtn>
                <VBtn size="x-small" variant="text" icon="tabler-x"
                      :aria-label="t('project_management.views.cancel_add')" @click="closeComposer" />
              </div>
            </div>
            <div
              v-for="card in day.cards.slice(0, 3)" :key="card.id"
              class="text-caption text-truncate rounded px-1 cursor-pointer"
              :class="card.is_critical ? 'bg-error-lighten-1' : 'bg-surface-variant'"
              :title="`${card.code} ${card.name}`"
              role="button"
              tabindex="0"
              :aria-label="t('project_management.views.open_task', { name: card.name })"
              @click="openDetail(card)"
              @keydown.enter.prevent="openDetail(card)"
            >
              {{ card.name }}
            </div>
            <div v-if="day.cards.length > 3" class="text-caption text-disabled">
              {{ t('project_management.views.and_more', { count: day.cards.length - 3 }) }}
            </div>
          </div>
        </div>
      </VCardText>
    </VCard>

    <!-- Timeline -->
    <VCard v-else border class="views-scroll">
      <!--
        A plan that says what it amounts to.

        The chart alone showed where bars sit and nothing else — not how much of it is done, not
        how much of it is late, not even what stretch of time is on screen. Those are the questions
        somebody opens a timeline to answer, and answering them in a strip above the chart costs
        one line and saves reading forty bars.
      -->
      <VCardItem>
        <VCardTitle class="text-body-1 d-flex align-center flex-wrap ga-2">
          {{ t('project_management.views.timeline') }}
          <span v-if="timelineSummary.from" class="text-caption text-medium-emphasis font-weight-regular">
            {{ timelineSummary.from.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) }}
            →
            {{ timelineSummary.to?.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) }}
          </span>
        </VCardTitle>
      </VCardItem>

      <div v-if="span" class="px-4 pb-3 d-flex align-center flex-wrap ga-4">
        <div>
          <div class="text-h6 mb-0">{{ timelineSummary.progress }}%</div>
          <div class="text-caption text-medium-emphasis">{{ t('project_management.views.tl_complete') }}</div>
        </div>
        <VDivider vertical class="my-1" />
        <div>
          <div class="text-h6 mb-0">{{ timelineSummary.done }} / {{ timelineSummary.tasks }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('project_management.views.tl_tasks_done') }}</div>
        </div>
        <VDivider vertical class="my-1" />
        <div>
          <!-- Coloured only when it is not zero: a red 0 teaches people to ignore the colour. -->
          <div class="text-h6 mb-0" :class="timelineSummary.overdue ? 'text-error' : ''">
            {{ timelineSummary.overdue }}
          </div>
          <div class="text-caption text-medium-emphasis">{{ t('project_management.views.tl_overdue') }}</div>
        </div>
        <VDivider vertical class="my-1" />
        <div>
          <div class="text-h6 mb-0">{{ timelineSummary.critical }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('project_management.views.tl_critical') }}</div>
        </div>
        <VDivider v-if="timelineSummary.milestones" vertical class="my-1" />
        <div v-if="timelineSummary.milestones">
          <div class="text-h6 mb-0">{{ timelineSummary.milestones }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('project_management.views.tl_milestones') }}</div>
        </div>
      </div>
      <VDivider />

      <!--
        Two questions the numbers above cannot answer on their own: where the work is sitting, and
        how each person is going. Both read the filtered set, so picking two people in the bar
        redraws these as those two people's work rather than leaving them describing everybody.
      -->
      <VRow v-if="phaseMix.length" class="pa-4 ma-0" dense>
        <VCol cols="12" md="5">
          <div class="text-body-2 font-weight-medium mb-1">{{ t('project_management.views.tl_by_phase') }}</div>
          <VueApexCharts type="donut" :height="240" :options="phaseOptions" :series="phaseSeries" />
        </VCol>

        <VCol cols="12" md="7">
          <div class="text-body-2 font-weight-medium mb-1">{{ t('project_management.views.tl_by_person') }}</div>

          <div v-if="perPerson.length" class="d-flex flex-wrap ga-4">
            <div
              v-for="person in perPerson" :key="person.id"
              class="text-center pm-person-dial"
              :title="t('project_management.views.tl_person_detail', {
                name: person.name, done: person.done, total: person.total, percent: person.percent })"
            >
              <VueApexCharts
                type="donut" :height="92" :width="92"
                :options="personDialOptions"
                :series="[person.percent, 100 - person.percent]"
              />
              <div class="text-caption text-truncate mt-1" :title="person.name">{{ person.name }}</div>
              <div class="text-caption text-disabled">
                {{ t('project_management.views.tl_person_tasks', { done: person.done, total: person.total }) }}
              </div>
            </div>
          </div>

          <!-- Unassigned work is a real state, and saying so beats an empty box. -->
          <div v-else class="text-caption text-medium-emphasis py-6">
            {{ t('project_management.views.tl_nobody_assigned') }}
          </div>
        </VCol>
      </VRow>
      <VDivider v-if="phaseMix.length" />

      <VCardText v-if="span">
        <div class="timeline-scroll">
          <!-- Column headings, so the numbers beside each bar are not left to be guessed at. -->
          <div class="timeline-head text-caption text-medium-emphasis">
            <div class="timeline-label">{{ t('project_management.views.tl_task') }}</div>
            <div class="timeline-meta text-end">{{ t('project_management.views.tl_dates') }}</div>
            <div class="timeline-ticks">
              <span v-for="tick in ticks" :key="tick.left" class="timeline-tick"
                    :class="tick.strong ? 'font-weight-medium text-high-emphasis' : ''"
                    :style="{ left: tick.left }">{{ tick.label }}</span>
            </div>
          </div>

          <div
            v-for="card in timelineRows" :key="card.id"
            class="timeline-row cursor-pointer"
            role="button"
            tabindex="0"
            :aria-label="t('project_management.views.open_task', { name: card.name })"
            @click="openDetail(card)"
            @keydown.enter.prevent="openDetail(card)"
          >
            <div class="timeline-label d-flex align-center ga-2">
              <AssigneeStack :people="card.assignees" :size="20" />
              <div class="min-width-0">
                <div class="text-caption text-truncate" :title="card.name">
                  <span class="text-medium-emphasis me-1">{{ card.code }}</span>{{ card.name }}
                </div>
                <div class="d-flex align-center ga-1 mt-1">
                  <VChip size="x-small" label variant="tonal" :color="statusColor[card.status] ?? 'grey'">
                    {{ t(`project_management.task_status.${card.status}`) }}
                  </VChip>
                  <VChip v-if="card.is_critical" size="x-small" label variant="tonal" color="error">
                    {{ t('project_management.views.tl_critical_short') }}
                  </VChip>
                  <!-- Said in days, because "late" without a number is a feeling, not a fact. -->
                  <VChip v-if="overdueDays(card)" size="x-small" label variant="flat" color="error">
                    {{ t('project_management.views.tl_late_by', { days: overdueDays(card) }) }}
                  </VChip>
                </div>
              </div>
            </div>

            <div class="timeline-meta text-caption text-medium-emphasis text-end">
              <div>{{ card.start ?? '—' }}</div>
              <div>{{ card.end ?? '—' }}</div>
              <div v-if="durationOf(card)" class="text-disabled">
                {{ t('project_management.views.tl_days', { days: durationOf(card) }) }}
              </div>
            </div>

            <div class="timeline-track">
              <!-- Gridlines behind the bars: a bar's position is only readable against something. -->
              <span
                v-for="tick in ticks" :key="`g-${tick.left}`" class="timeline-grid"
                :class="tick.strong ? 'timeline-grid--strong' : ''" :style="{ left: tick.left }"
              />
              <span v-if="todayMark" class="timeline-today" :style="{ left: todayMark }" />

              <!-- A milestone has no duration; drawn as a mark rather than a bar a pixel wide. -->
              <div
                v-if="card.is_milestone" class="timeline-milestone"
                :style="{ left: bar(card)?.left ?? '0%' }"
                :title="`${card.code} · ${card.end ?? card.start}`"
              />
              <div
                v-else
                class="timeline-bar rounded"
                :class="overdueDays(card) ? 'bg-error' : (card.is_critical ? 'bg-warning' : 'bg-primary')"
                :style="bar(card) ?? {}"
                :title="`${card.start} → ${card.end} · ${card.progress}%`"
              >
                <!-- Progress inside the bar, so planned and actual are read in one glance. -->
                <div v-if="card.progress > 0" class="timeline-progress rounded-s"
                     :style="{ width: `${card.progress}%` }" />
              </div>
            </div>

            <div class="timeline-pct text-caption text-medium-emphasis text-end">
              {{ card.progress }}%
            </div>
          </div>
        </div>

        <p v-if="undated" class="text-caption text-medium-emphasis mt-3">
          {{ t('project_management.views.undated_notice', { count: undated }) }}
        </p>
      </VCardText>

      <VCardText v-else class="text-center text-medium-emphasis py-8">
        {{ t('project_management.views.nothing_dated') }}
      </VCardText>
    </VCard>

    <TaskDrawer
      v-model="drawer" :project-id="project.id" :detail="taskDetail as any"
      :can="{ editTasks: can.editTasks, createTasks: can.createTasks }"
    />

    <!-- ── The board's columns ─────────────────────────────────────────────── -->
    <VDialog v-model="stepsDialog" max-width="720" scrollable>
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.steps.title') }}</VCardTitle>
          <VCardSubtitle class="text-wrap">{{ t('project_management.steps.subtitle') }}</VCardSubtitle>
        </VCardItem>
        <VDivider />

        <VCardText>
          <VAlert
            v-for="message in stepErrors" :key="message"
            type="error" variant="tonal" density="compact" class="mb-3"
          >{{ message }}</VAlert>

          <VTable density="compact">
            <thead>
              <tr>
                <th style="width:40px" />
                <th>{{ t('project_management.steps.col_name') }}</th>
                <th style="width:190px">{{ t('project_management.steps.col_meaning') }}</th>
                <th style="width:90px" class="text-center">{{ t('project_management.steps.col_first') }}</th>
                <th style="width:110px" />
              </tr>
            </thead>
            <tbody>
              <tr v-for="(step, i) in draftSteps" :key="i">
                <td class="text-caption text-medium-emphasis">{{ i + 1 }}</td>
                <td>
                  <VTextField
                    v-model="step.label" density="compact" variant="outlined" hide-details
                    :placeholder="t('project_management.steps.name_placeholder')"
                  />
                </td>
                <td>
                  <!--
                    The meaning, not decoration. Everything downstream of this board — completion
                    events, progress rollup, claims, the scheduler — reads this and never the name,
                    so a step called "Awaiting materials" still counts as blocked.
                  -->
                  <VSelect
                    v-model="step.category"
                    :items="categories.map(c => ({ title: t(`project_management.task_status.${c}`), value: c }))"
                    density="compact" variant="outlined" hide-details
                  />
                </td>
                <td class="text-center">
                  <VRadio
                    :model-value="step.is_default" :value="true" density="compact" hide-details
                    :aria-label="t('project_management.steps.first_for', { name: step.label })"
                    @click="makeDefault(i)"
                  />
                </td>
                <td class="text-right text-no-wrap">
                  <VBtn
                    icon="tabler-chevron-up" size="x-small" variant="text" :disabled="i === 0"
                    :aria-label="t('project_management.steps.move_up')" @click="moveStep(i, -1)"
                  />
                  <VBtn
                    icon="tabler-chevron-down" size="x-small" variant="text"
                    :disabled="i === draftSteps.length - 1"
                    :aria-label="t('project_management.steps.move_down')" @click="moveStep(i, 1)"
                  />
                  <VBtn
                    icon="tabler-trash" size="x-small" variant="text" color="error"
                    :disabled="draftSteps.length === 1"
                    :aria-label="t('project_management.steps.remove', { name: step.label })"
                    @click="removeStep(i)"
                  />
                </td>
              </tr>
            </tbody>
          </VTable>

          <VBtn
            variant="text" size="small" prepend-icon="tabler-plus" class="mt-2"
            :disabled="draftSteps.length >= 12" @click="addStep"
          >
            {{ t('project_management.steps.add') }}
          </VBtn>

          <!-- A board with nowhere to finish records no completions at all, which is what drives
               claims, progress and the ledger. Said before saving, not after it is refused. -->
          <VAlert
            v-if="draftSteps.length && !draftSteps.some(s => s.category === 'done')"
            type="warning" variant="tonal" density="compact" class="mt-3"
          >{{ t('project_management.steps.needs_done') }}</VAlert>

          <!-- What a removal would move. Discovering this afterwards is discovering it too late. -->
          <VAlert v-if="orphaned" type="info" variant="tonal" density="compact" class="mt-3">
            {{ t('project_management.steps.will_move', { count: orphaned }) }}
          </VAlert>
        </VCardText>

        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="stepsDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn
            color="primary" :loading="savingSteps" :disabled="!stepsValid" @click="saveSteps"
          >{{ t('project_management.steps.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
/*
  Bounded to the viewport rather than to the tallest column.

  `100dvh` rather than `100vh` because on a phone the browser chrome comes and goes, and a board
  measured against the larger viewport hides its last row behind the address bar.
*/
/* Height comes from useFitToViewport — see the composable for why it is measured, not calculated. */
.views-page {
  overflow: hidden;
}

.views-bar {
  flex: 0 0 auto;
}

/*
  Whatever is left below the bar, and not a pixel more. `min-block-size: 0` is the part that
  matters: without it a flex child refuses to shrink below its content, the strip grows to its
  tallest column, and the page scrolls again — which is the bug this replaced.
*/
.board-strip {
  flex: 1 1 auto;
  min-block-size: 0;
}

/* Calendar and timeline scroll inside themselves too, so the page never does. */
.views-scroll {
  flex: 1 1 auto;
  min-block-size: 0;
  overflow-y: auto;
}

.board-column {
  block-size: 100%;
  min-block-size: 0; /* lets the card shrink inside the flex row so its child can scroll */
}

.board-column__cards {
  overflow-y: auto;
  /* The header, its count and the add button stay in place; only the cards move. */
  flex: 1 1 auto;
  min-block-size: 0;
}

/* Never squeezed by the scrolling body above it. */
.board-column__add {
  flex: 0 0 auto;
}

.calendar-grid {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 4px;
}

.calendar-cell {
  min-height: 92px;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 4px;
  overflow: hidden;
}

.calendar-cell--muted { opacity: 0.45; }

/* A cell being typed into grows to fit the box and stops clipping it. */
.calendar-cell--composing { overflow: visible; min-height: 150px; z-index: 1; }

/*
  The add button is hidden until the cell is hovered or something inside it has focus. Tying it to
  :focus-within as well as :hover is what keeps it usable without a mouse — hover alone would make
  every one of these forty-two buttons invisible to a keyboard, while still being in the tab order.
*/
.calendar-add { opacity: 0; transition: opacity 120ms ease; }
.calendar-cell:hover .calendar-add,
.calendar-cell:focus-within .calendar-add { opacity: 1; }

@media (hover: none) {
  /* Nothing hovers on a touch screen, so the affordance has to be permanent there. */
  .calendar-add { opacity: 1; }
}

/* Wide plans scroll inside the card rather than pushing the page sideways. */
.timeline-scroll { overflow-x: auto; min-width: 100%; }

.timeline-head,
.timeline-row {
  display: flex;
  align-items: center;
  gap: 12px;
}

/*
  Rows are taller than they were because each one now carries a status, a duration and a name that
  is allowed to be read. A timeline of forty bars nobody can identify is a decoration.
*/
.timeline-row {
  min-block-size: 46px;
  border-block-end: 1px solid rgba(var(--v-border-color), 0.12);
}

.timeline-row:hover { background: rgba(var(--v-theme-on-surface), 0.03); }

.timeline-head { min-block-size: 26px; }

.timeline-label { inline-size: 280px; flex: 0 0 280px; min-inline-size: 0; }

/* Lets the name truncate instead of forcing the row wider than the card. */
.min-width-0 { min-inline-size: 0; }

.timeline-meta { inline-size: 96px; flex: 0 0 96px; line-height: 1.25; }

.timeline-pct { inline-size: 44px; flex: 0 0 44px; }

.timeline-ticks { position: relative; flex: 1 1 auto; block-size: 18px; min-inline-size: 320px; }

.timeline-tick { position: absolute; transform: translateX(-50%); white-space: nowrap; }

.timeline-track { position: relative; flex: 1 1 auto; block-size: 16px; min-inline-size: 320px; }

/* Behind the bars, and faint: a reference the eye uses without the eye going to it. */
.timeline-grid {
  position: absolute;
  inset-block: -14px;
  inline-size: 1px;
  background: rgba(var(--v-border-color), 0.16);
}

.timeline-grid--strong { background: rgba(var(--v-border-color), 0.34); }

/* Today is the one line worth seeing, so it is the only coloured one. */
.timeline-today {
  position: absolute;
  inset-block: -14px;
  inline-size: 2px;
  background: rgb(var(--v-theme-error));
  opacity: 0.55;
}

/* Fixed so a row of dials lines up whatever the length of the names under them. */
.pm-person-dial { inline-size: 104px; }

.timeline-bar { position: absolute; block-size: 16px; inset-block-start: 0; }

.timeline-progress {
  height: 100%;
  background: rgba(255, 255, 255, 0.45);
}

/* A milestone is an instant, not a duration — a diamond says that and a one-pixel bar does not. */
.timeline-milestone {
  position: absolute;
  inset-block-start: 1px;
  inline-size: 14px;
  block-size: 14px;
  margin-inline-start: -7px;
  background: rgb(var(--v-theme-secondary));
  transform: rotate(45deg);
}
</style>
