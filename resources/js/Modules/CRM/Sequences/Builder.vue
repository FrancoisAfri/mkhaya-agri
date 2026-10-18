<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Variant { subject: string; body: string }
interface Step { channel: string; day_offset: number; subject: string; body: string; body_format?: 'text' | 'html'; variants?: Variant[]; conditions?: any[]; match_type?: string }
interface Enrollment { id: number; name: string | null; email: string | null; step: number; status: string; reply_intent: string | null; next_step_at: string | null; replied_at: string | null; last_reply: string | null; lead_id: number | null; contact_id: number | null }

const props = defineProps<{
  sequence: { id: number; name: string; goal: string | null; steps: Step[]; active: boolean; stop_on_reply: boolean; send_window_start: string | null; send_window_end: string | null; send_days: number[]; daily_cap: number | null; goal_event: string | null; track_opens: boolean; track_clicks: boolean }
  enrollments: Enrollment[]
  /** How many of them this page carries — the list is the most recent few hundred. */
  enrollmentsShown: number
  stats: { total: number; active: number; replied: number; completed: number; interested: number }
  analytics: { byStep: any[]; byVariant: any[] }
  channels: Record<string, string>
  goalEvents: Record<string, string>
  conditionFields: string[]
  aiEnabled: boolean
  industries: string[]
  products: { id: number; name: string; type: string }[]
  /** The address these emails go out as, why that one, and where replies land. */
  sender: {
    address: string | null
    name: string | null
    reply_to: string | null
    source: 'mailbox' | 'mailbox_reply_to' | 'creator' | 'creator_reply_to' | 'integration' | 'application'
    /** The registered mailbox this sends from, when one could be resolved. */
    mailbox: string | null
    /** Whether a registered CRM mailbox is watching wherever the replies will land. */
    replies_polled: boolean
  }
}>()

const fieldOps = ['=', '!=', '>', '>=', '<', '<=', 'contains', 'in', 'is_empty', 'not_empty']
function onChannelChange(s: Step) {
  if (s.channel === 'condition') {
    if (!(s as any).conditions) (s as any).conditions = []
    if (!(s as any).match_type) (s as any).match_type = 'all'
  }
}
function addStepCond(s: any) { (s.conditions ||= []).push({ field: props.conditionFields[0] ?? 'status', operator: '=', value: '' }) }
function removeStepCond(s: any, j: number) { s.conditions.splice(j, 1) }

const tab = ref('steps')
const snack = ref<{ show: boolean; text: string; color: string }>({ show: false, text: '', color: 'success' })
const weekdays = [{ title: 'Mon', value: 1 }, { title: 'Tue', value: 2 }, { title: 'Wed', value: 3 }, { title: 'Thu', value: 4 }, { title: 'Fri', value: 5 }, { title: 'Sat', value: 6 }, { title: 'Sun', value: 7 }]

const form = useForm({
  name: props.sequence.name, goal: props.sequence.goal ?? '',
  // Steps written before body_format existed carry no format at all, which would leave the
  // text/HTML toggle showing neither option selected on every seeded cadence. They are prose, so
  // they are text — stated here rather than left to the control to guess.
  steps: (JSON.parse(JSON.stringify(props.sequence.steps)) as Step[])
    .map(step => ({ ...step, body_format: step.body_format ?? 'text' })),
  active: props.sequence.active, stop_on_reply: props.sequence.stop_on_reply,
  send_window_start: props.sequence.send_window_start ?? '', send_window_end: props.sequence.send_window_end ?? '',
  send_days: props.sequence.send_days ?? [], daily_cap: props.sequence.daily_cap,
  goal_event: props.sequence.goal_event ?? '', track_opens: props.sequence.track_opens, track_clicks: props.sequence.track_clicks,
})
function addStep() { form.steps.push({ channel: 'email', day_offset: (form.steps.at(-1)?.day_offset ?? 0) + 2, subject: '', preview_text: '', body: '', body_format: 'text', cta_label: '', cta_url: '' }) }
function removeStep(i: number) { form.steps.splice(i, 1) }
function toggleAB(s: Step) {
  if (s.variants) s.variants = undefined
  else s.variants = [{ subject: s.subject, body: s.body }, { subject: s.subject, body: s.body }]
}
function pct(n: number, d: number) { return d > 0 ? Math.round((n / d) * 100) + '%' : '—' }
function save() { form.patch(route('crm.sequences.update', props.sequence.id), { preserveScroll: true, onSuccess: () => snack.value = { show: true, text: 'Saved.', color: 'success' } }) }

// Enrollment
const enrollForm = useForm({ source: 'leads', mode: 'all', industries: [] as string[], min_spend: null as number | null, account_ids: [] as number[], product_id: null as number | null })
function enroll() { enrollForm.post(route('crm.sequences.enroll', props.sequence.id), { preserveScroll: true, onSuccess: () => snack.value = { show: true, text: t('crm_sequences_edit.snackbar.enrolled'), color: 'success' } }) }
function runNow() { router.post(route('crm.sequences.run', props.sequence.id), {}, { preserveScroll: true }) }

// Preview — the step rendered exactly as the client will receive it, from the same composition the
// test send uses. Reading a cadence before it goes out should not require mailing yourself a copy.
//
// Saved wording, not the textarea: the server renders what it holds, so a preview of unsaved edits
// would show something nobody could actually receive.
const previewDialog = ref(false)
const previewLoading = ref(false)
const previewSteps = ref<{ index: number; label: string; subject: string; html: string; warnings: string[] }[]>([])

async function openPreview(stepIndex: number | null = null) {
  previewDialog.value = true
  previewLoading.value = true
  previewSteps.value = []
  try {
    const res = await fetch(route('crm.sequences.preview', props.sequence.id), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
      },
      body: JSON.stringify({ step: stepIndex }),
    })
    previewSteps.value = (await res.json()).steps ?? []
  } finally {
    previewLoading.value = false
  }
}

// Test send — the whole cadence to one inbox, before anybody real is enrolled. `step` empty means
// every step; a single step is for when you have changed one and don't want five more emails.
const testForm = useForm({ to: '', step: null as number | null })
const testDialog = ref(false)
function sendTest() {
  testForm.post(route('crm.sequences.test', props.sequence.id), {
    preserveScroll: true,
    onSuccess: () => { testDialog.value = false; snack.value = { show: true, text: t('crm_sequences_edit.test.sent'), color: 'success' } },
  })
}

// Reply classification
const replyDialog = ref(false)
const replyTarget = ref<Enrollment | null>(null)
const replyForm = useForm({ reply: '' })
function openReply(e: Enrollment) { replyTarget.value = e; replyForm.reset(); replyDialog.value = true }
function submitReply() { if (replyTarget.value) replyForm.post(route('crm.sequences.reply', replyTarget.value.id), { preserveScroll: true, onSuccess: () => replyDialog.value = false }) }

const statusColor: Record<string, string> = { active: 'primary', replied: 'success', completed: 'blue-grey', stopped: 'grey', bounced: 'error' }
const intentColor: Record<string, string> = { interested: 'success', not_interested: 'error', ooo: 'warning', unsubscribe: 'error', other: 'blue-grey' }
const headers = [
  { title: 'Contact', key: 'name' }, { title: 'Step', key: 'step', align: 'end' as const },
  { title: 'Status', key: 'status' }, { title: 'Reply', key: 'reply_intent' },
  { title: 'Next', key: 'next_step_at' }, { title: '', key: 'actions', sortable: false },
]
// "Who replied" is the question this tab is opened for, so it is one switch away.
const repliesOnly = ref(false)
const shownEnrollments = computed(() => repliesOnly.value ? props.enrollments.filter(e => e.replied_at) : props.enrollments)
const channelIcon = (c: string) => ({ whatsapp: 'tabler-brand-whatsapp', task: 'tabler-checkbox', call: 'tabler-phone' }[c] ?? 'tabler-mail')
const canEnroll = computed(() => enrollForm.source === 'leads' || enrollForm.mode)

const channelOptions = computed(() => [
  { title: t('crm_sequences_edit.steps.channel_email'), value: 'email' },
  { title: t('crm_sequences_edit.steps.channel_whatsapp'), value: 'whatsapp' },
])
const sourceOptions = computed(() => [
  { title: t('crm_sequences_edit.enroll.sources.leads'), value: 'leads' },
  { title: t('crm_sequences_edit.enroll.sources.audience'), value: 'audience' },
])
const modeOptions = computed(() => [
  { title: t('crm_sequences_edit.enroll.modes.all'), value: 'all' },
  { title: t('crm_sequences_edit.enroll.modes.industry'), value: 'industry' },
  { title: t('crm_sequences_edit.enroll.modes.spend'), value: 'spend' },
  { title: t('crm_sequences_edit.enroll.modes.specific'), value: 'specific' },
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3">
      <div class="d-flex align-center ga-2">
        <TipBtn icon="tabler-arrow-left" variant="text" :tip="t('crm_sequences_edit.back')" :href="route('crm.sequences')" />
        <h5 class="text-h5 font-weight-semibold mb-0">{{ form.name }}</h5>
      </div>
      <div class="d-flex ga-2">
        <VBtn variant="tonal" prepend-icon="tabler-eye" @click="openPreview(null)">Preview</VBtn>
        <VBtn variant="tonal" color="warning" prepend-icon="tabler-flask" @click="testDialog = true">{{ t('crm_sequences_edit.test.send') }}</VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-player-play" @click="runNow">{{ t('crm_sequences_edit.run_now') }}</VBtn>
        <VBtn color="primary" prepend-icon="tabler-device-floppy" :loading="form.processing" @click="save">{{ t('crm_sequences_edit.save') }}</VBtn>
      </div>
    </div>

    <VRow class="mb-1">
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-h5 font-weight-bold">{{ stats.total }}</div><div class="text-caption text-medium-emphasis">{{ t('crm_sequences_edit.stats.enrolled') }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-h5 font-weight-bold text-primary">{{ stats.active }}</div><div class="text-caption text-medium-emphasis">{{ t('crm_sequences_edit.stats.active') }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-h5 font-weight-bold text-success">{{ stats.replied }}</div><div class="text-caption text-medium-emphasis">{{ t('crm_sequences_edit.stats.replied') }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-h5 font-weight-bold text-success">{{ stats.interested }}</div><div class="text-caption text-medium-emphasis">{{ t('crm_sequences_edit.stats.interested') }}</div></VCardText></VCard></VCol>
    </VRow>

    <!--
      Who this sends as.
      
      Not a detail, and not something the page can leave unsaid. A cadence is written to read as a
      person; whether that person's own address survives into the From header depends on whether it
      can be authenticated, and the two outcomes look identical from here. So the alert names the
      address, says which of the four cases produced it, and — when the From header had to stay the
      authenticated one — says where the replies go instead.
    -->
    <VAlert
      :type="props.sender.source === 'application' ? 'warning' : 'info'"
      variant="tonal" density="compact" class="mb-3"
    >
      <span v-if="props.sender.address">
        {{ t('crm_sequences_edit.sender.sends_as') }}
        <strong>{{ props.sender.name ? `${props.sender.name} <${props.sender.address}>` : props.sender.address }}</strong>
        —
        <template v-if="props.sender.source === 'mailbox'">{{ t('crm_sequences_edit.sender.from_mailbox') }}</template>
        <template v-else-if="props.sender.source === 'mailbox_reply_to'">
          {{ t('crm_sequences_edit.sender.from_mailbox_unaligned', { address: props.sender.reply_to }) }}
        </template>
        <template v-else-if="props.sender.source === 'creator'">{{ t('crm_sequences_edit.sender.from_creator') }}</template>
        <template v-else-if="props.sender.source === 'creator_reply_to'">
          {{ t('crm_sequences_edit.sender.from_creator_unaligned', { address: props.sender.reply_to }) }}
        </template>
        <template v-else-if="props.sender.source === 'integration'">{{ t('crm_sequences_edit.sender.from_integration') }}</template>
        <template v-else>{{ t('crm_sequences_edit.sender.from_application') }}</template>
      </span>
      <span v-else>{{ t('crm_sequences_edit.sender.none') }}</span>
    </VAlert>

    <!--
      Replies are only ever seen by polling a registered mailbox. Sending as a person moves the
      replies to that person's inbox, and if nothing polls it then stop-on-reply and the "replied"
      goal simply never fire — while the conversation carries on somewhere the system cannot see.
      Silent from every other screen, so it is said on the one where the sender is decided.
    -->
    <VAlert
      v-if="props.sender.address && !props.sender.replies_polled"
      type="warning" variant="tonal" density="compact" class="mb-3"
    >
      {{ t('crm_sequences_edit.sender.replies_unwatched', { address: props.sender.reply_to || props.sender.address }) }}
      <a :href="route('crm.mailboxes')" class="text-decoration-underline">
        {{ t('crm_sequences_edit.sender.add_mailbox') }}
      </a>
    </VAlert>

    <VTabs v-model="tab" class="mb-3">
      <VTab value="steps">Steps</VTab>
      <VTab value="settings">Settings</VTab>
      <VTab value="analytics">Analytics</VTab>
      <VTab value="enroll">Enroll</VTab>
      <!-- The real total, not the number of rows this page happens to carry. Counting the loaded
           rows read as "only 300 were enrolled" on a sequence holding 2,014. -->
      <VTab value="enrollments">Enrollments ({{ stats.total }})</VTab>
    </VTabs>

    <VWindow v-model="tab">
      <VWindowItem value="steps">
        <VCard class="mb-3"><VCardText class="d-flex ga-4 flex-wrap">
          <VSwitch v-model="form.active" :label="t('crm_sequences_edit.steps.active')" color="primary" hide-details />
          <VSwitch v-model="form.stop_on_reply" :label="t('crm_sequences_edit.steps.stop_on_reply')" color="primary" hide-details />
        </VCardText></VCard>
        <VCard v-for="(s, i) in form.steps" :key="i" class="mb-3" variant="outlined"><VCardText>
          <div class="d-flex ga-2 align-center mb-2">
            <VChip size="small" label><VIcon :icon="channelIcon(s.channel)" size="14" class="me-1" />Step {{ i + 1 }}</VChip>
            <VSelect v-model="s.channel" :items="Object.entries(channels).map(([v, t]) => ({ title: t, value: v }))" density="compact" hide-details style="max-width:170px" @update:model-value="onChannelChange(s)" />
            <VTextField v-model.number="s.day_offset" type="number" label="Day" density="compact" hide-details style="max-width:90px" />
            <VBtn v-if="s.channel === 'email' || s.channel === 'whatsapp'" size="x-small" :variant="s.variants ? 'flat' : 'tonal'" :color="s.variants ? 'purple' : undefined" @click="toggleAB(s)">A/B</VBtn>
            <VSpacer />
            <TipBtn icon="tabler-trash" variant="text" color="error" size="small" :tip="t('crm_sequences_edit.steps.remove_step')" @click="removeStep(i)" />
          </div>

          <!-- Condition gate -->
          <template v-if="s.channel === 'condition'">
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="text-caption text-medium-emphasis">Continue only if…</span>
              <VBtnToggle v-model="s.match_type" density="compact" mandatory variant="outlined">
                <VBtn value="all" size="x-small">ALL</VBtn><VBtn value="any" size="x-small">ANY</VBtn>
              </VBtnToggle>
            </div>
            <div v-for="(c, j) in (s.conditions || [])" :key="j" class="d-flex gap-2 mb-2 align-center">
              <VCombobox v-model="c.field" :items="conditionFields" label="Field" density="compact" hide-details style="max-width:170px" />
              <VSelect v-model="c.operator" :items="fieldOps" density="compact" hide-details style="max-width:120px" />
              <VTextField v-model="c.value" label="Value" density="compact" hide-details />
              <VBtn size="x-small" variant="text" icon="tabler-x" color="error" @click="removeStepCond(s, j)" />
            </div>
            <VBtn size="x-small" variant="tonal" prepend-icon="tabler-plus" @click="addStepCond(s)">Add condition</VBtn>
            <p class="text-caption text-medium-emphasis mt-1">Checks the lead/contact — if it fails, the cadence stops here.</p>
          </template>

          <!-- Single content -->
          <template v-else-if="!s.variants">
            <VTextField v-if="s.channel !== 'whatsapp'" v-model="s.subject" :label="s.channel === 'email' ? 'Subject' : 'Title'" density="comfortable" class="mb-2" />
            <!--
              Preview text is the second line every inbox shows. Left empty the client scrapes the
              first words of the body, which for a cadence is always "Hi {{first_name}}," — so six
              follow-ups preview identically and none of them can be told apart unopened.
            -->
            <VTextField
              v-if="s.channel === 'email'"
              v-model="s.preview_text"
              :label="t('crm_sequences_edit.steps.preview_text')"
              :hint="t('crm_sequences_edit.steps.preview_text_hint')"
              persistent-hint
              density="comfortable"
              class="mb-3"
              counter="140"
            />
            <!-- Plain text is escaped and line-broken on the way out; HTML is sent as written.
                 A step that has never said which it is stays text, because bodies written before
                 this existed are prose and would lose their line breaks if rendered raw. -->
            <div v-if="!['task', 'call'].includes(s.channel)" class="d-flex align-center ga-3 mb-2">
              <VBtnToggle v-model="s.body_format" density="compact" variant="outlined" divided mandatory>
                <VBtn value="text" size="small">Plain text</VBtn>
                <VBtn value="html" size="small">HTML</VBtn>
              </VBtnToggle>
              <VBtn size="small" variant="text" prepend-icon="tabler-eye" @click="openPreview(i)">Preview</VBtn>
            </div>
            <VTextarea v-model="s.body" :label="['task', 'call'].includes(s.channel) ? 'Notes for the rep' : (s.body_format === 'html' ? 'Message (HTML)' : 'Message')" :rows="s.body_format === 'html' ? 8 : 3" density="comfortable" hint="Merge tags: first_name, last_name, company, job_title, rep_name, sender_company" persistent-hint />
            <VRow v-if="s.channel === 'email'" class="mt-1">
              <VCol cols="12" md="5">
                <VTextField v-model="s.cta_label" :label="t('crm_sequences_edit.steps.cta_label')" density="comfortable" hide-details />
              </VCol>
              <VCol cols="12" md="7">
                <VTextField v-model="s.cta_url" :label="t('crm_sequences_edit.steps.cta_url')" placeholder="https://" density="comfortable" :hint="t('crm_sequences_edit.steps.cta_hint')" persistent-hint />
              </VCol>
            </VRow>
            <p v-if="['task', 'call'].includes(s.channel)" class="text-caption text-medium-emphasis mt-1">Creates a {{ s.channel }} task for the rep — no message is sent automatically.</p>
          </template>

          <!-- A/B variants -->
          <VRow v-else>
            <VCol v-for="(v, vi) in s.variants" :key="vi" cols="12" md="6">
              <div class="text-overline" :class="vi === 0 ? 'text-primary' : 'text-purple'">Variant {{ vi === 0 ? 'A' : 'B' }}</div>
              <VTextField v-if="s.channel !== 'whatsapp'" v-model="v.subject" label="Subject" density="compact" class="mb-2" />
              <VTextarea v-model="v.body" label="Message" rows="3" density="compact" />
            </VCol>
          </VRow>
        </VCardText></VCard>
        <div class="d-flex flex-wrap align-center ga-2">
          <VBtn variant="tonal" prepend-icon="tabler-plus" @click="addStep">{{ t('crm_sequences_edit.steps.add_step') }}</VBtn>

          <VSpacer />

          <!--
            The same Save as the header's, at the end of the work.

            The steps run well past a screen — a step is a subject, a body and a set of conditions —
            so somebody who has just finished typing the last one is nowhere near the only button
            that keeps it. Scrolling back to the top to save is where unsaved work gets lost.
          -->
          <VBtn
            color="primary" prepend-icon="tabler-device-floppy"
            :loading="form.processing" @click="save"
          >{{ t('crm_sequences_edit.save') }}</VBtn>
        </div>
      </VWindowItem>

      <VWindowItem value="settings">
        <VCard max-width="680"><VCardText>
          <div class="text-overline mb-2">Sending window (deliverability)</div>
          <VRow>
            <VCol cols="6" md="3"><VTextField v-model="form.send_window_start" type="time" label="From" density="compact" /></VCol>
            <VCol cols="6" md="3"><VTextField v-model="form.send_window_end" type="time" label="To" density="compact" /></VCol>
            <VCol cols="12" md="6"><VSelect v-model="form.send_days" :items="weekdays" label="Send days" multiple chips density="compact" /></VCol>
          </VRow>
          <VRow>
            <VCol cols="12" md="6"><VTextField v-model.number="form.daily_cap" type="number" label="Daily send cap (per sequence)" density="compact" placeholder="No limit" /></VCol>
            <VCol cols="12" md="6"><VSelect v-model="form.goal_event" :items="Object.entries(goalEvents).map(([v, t]) => ({ title: t, value: v }))" label="Exit goal (auto-unenrol when met)" density="compact" /></VCol>
          </VRow>
          <div class="d-flex ga-4 flex-wrap mt-2">
            <VSwitch v-model="form.track_opens" label="Track opens" color="primary" hide-details />
            <VSwitch v-model="form.track_clicks" label="Track clicks" color="primary" hide-details />
          </div>
          <p class="text-caption text-medium-emphasis mt-2">Outside the window/days, sends defer to the next valid time. The daily cap protects sender reputation.</p>
        </VCardText></VCard>
      </VWindowItem>

      <VWindowItem value="analytics">
        <VCard class="mb-3" title="Per-step performance"><VCardText>
          <VTable density="comfortable">
            <thead><tr><th>Step</th><th class="text-end">Sent</th><th class="text-end">Opened</th><th class="text-end">Clicked</th><th class="text-end">Replied</th></tr></thead>
            <tbody>
              <tr v-for="r in analytics.byStep" :key="r.step">
                <td>Step {{ r.step }}</td><td class="text-end">{{ r.sent }}</td>
                <td class="text-end">{{ r.opened }} <span class="text-disabled">({{ pct(r.opened, r.sent) }})</span></td>
                <td class="text-end">{{ r.clicked }} <span class="text-disabled">({{ pct(r.clicked, r.sent) }})</span></td>
                <td class="text-end">{{ r.replied }} <span class="text-disabled">({{ pct(r.replied, r.sent) }})</span></td>
              </tr>
              <tr v-if="!analytics.byStep.length"><td colspan="5" class="text-medium-emphasis">No sends yet.</td></tr>
            </tbody>
          </VTable>
        </VCardText></VCard>
        <VCard title="A/B comparison"><VCardText>
          <VTable density="comfortable">
            <thead><tr><th>Variant</th><th class="text-end">Sent</th><th class="text-end">Open rate</th><th class="text-end">Click rate</th><th class="text-end">Reply rate</th></tr></thead>
            <tbody>
              <tr v-for="r in analytics.byVariant" :key="r.variant">
                <td>{{ r.variant }}</td><td class="text-end">{{ r.sent }}</td>
                <td class="text-end">{{ pct(r.opened, r.sent) }}</td><td class="text-end">{{ pct(r.clicked, r.sent) }}</td><td class="text-end">{{ pct(r.replied, r.sent) }}</td>
              </tr>
              <tr v-if="!analytics.byVariant.length"><td colspan="5" class="text-medium-emphasis">No A/B data yet.</td></tr>
            </tbody>
          </VTable>
        </VCardText></VCard>
      </VWindowItem>

      <VWindowItem value="enroll">
        <!--
          Test sits above enrolment on purpose. A cadence runs unattended for weeks, so a mistake
          in step four is discovered by the recipients; the order of these two cards is the advice.
        -->
        <VAlert type="warning" variant="tonal" density="comfortable" class="mb-4" max-width="640">
          <div class="d-flex align-center justify-space-between flex-wrap ga-2">
            <div>
              <div class="font-weight-medium">{{ t('crm_sequences_edit.test.title') }}</div>
              <div class="text-caption">{{ t('crm_sequences_edit.test.hint') }}</div>
            </div>
            <VBtn color="warning" variant="flat" size="small" prepend-icon="tabler-flask" @click="testDialog = true">
              {{ t('crm_sequences_edit.test.send') }}
            </VBtn>
          </div>
        </VAlert>

        <VCard max-width="640"><VCardText>
          <VSelect v-model="enrollForm.source" :label="t('crm_sequences_edit.enroll.enroll_from')" class="mb-2" :items="sourceOptions" />
          <VSelect v-if="enrollForm.source === 'leads' && products.length" v-model="enrollForm.product_id" label="Product / Service" class="mb-2" :items="products.map(p => ({ title: p.name, value: p.id }))" clearable placeholder="All open leads" hint="Enroll only open leads for this product/service" persistent-hint />
          <template v-if="enrollForm.source === 'audience'">
            <VSelect v-model="enrollForm.mode" :label="t('crm_sequences_edit.enroll.segment')" class="mb-2" :items="modeOptions" />
            <VSelect v-if="enrollForm.mode === 'industry'" v-model="enrollForm.industries" :items="industries" :label="t('crm_sequences_edit.enroll.industries')" multiple chips closable-chips class="mb-2" />
            <VTextField v-if="enrollForm.mode === 'spend'" v-model.number="enrollForm.min_spend" type="number" :label="t('crm_sequences_edit.enroll.min_spend')" :prefix="t('crm_sequences_edit.enroll.min_spend_prefix')" class="mb-2" />
          </template>
          <VBtn color="primary" prepend-icon="tabler-user-plus" :loading="enrollForm.processing" :disabled="!canEnroll" @click="enroll">{{ t('crm_sequences_edit.enroll.enroll_contacts') }}</VBtn>
          <p class="text-caption text-medium-emphasis mt-2">{{ t('crm_sequences_edit.enroll.skipped_note') }}</p>
        </VCardText></VCard>
      </VWindowItem>

      <VWindowItem value="enrollments">
        <VCard>
          <!-- Says what is on screen when it is not everything. Silence here is what made 300 rows
               out of 2,014 read as "only 300 got enrolled". -->
          <VCardText v-if="enrollmentsShown < stats.total" class="text-caption text-medium-emphasis pb-0">
            Showing the {{ enrollmentsShown }} most recently updated of {{ stats.total }} enrolments.
          </VCardText>

          <VCardText class="pb-0">
            <VSwitch v-model="repliesOnly" color="success" density="compact" hide-details inset
              :label="`Only people who replied (${enrollments.filter(e => e.replied_at).length})`" />
          </VCardText>
          <VDataTable :headers="headers" :items="shownEnrollments" :items-per-page="25" density="comfortable">
            <template #item.name="{ item }">{{ item.name || item.email }}<div class="text-caption text-disabled">{{ item.email }}</div></template>
            <template #item.step="{ item }">{{ item.step + 1 }}</template>
            <template #item.status="{ item }"><VChip :color="statusColor[item.status] ?? 'grey'" size="small" label>{{ t(`crm_sequences_edit.enrollments.statuses.${item.status}`) }}</VChip></template>
            <template #item.reply_intent="{ item }">
              <VChip v-if="item.reply_intent" :color="intentColor[item.reply_intent] ?? 'grey'" size="x-small" label>{{ t(`crm_sequences_edit.enrollments.intents.${item.reply_intent}`) }}</VChip>
              <VChip v-else-if="item.replied_at" color="success" size="x-small" label>Replied</VChip>
              <span v-else class="text-disabled">{{ t('crm_sequences_edit.enrollments.table.not_available') }}</span>
              <div v-if="item.replied_at" class="text-caption text-medium-emphasis mt-1">{{ item.replied_at }}</div>
              <div v-if="item.last_reply" class="text-caption mt-1" style="max-width: 340px; white-space: pre-line">“{{ item.last_reply }}”</div>
              <a v-if="item.lead_id" :href="route('crm.leads', { lead: item.lead_id })" class="text-caption">Open lead</a>
            </template>
            <template #item.next_step_at="{ item }">{{ item.next_step_at ?? t('crm_sequences_edit.enrollments.table.not_available') }}</template>
            <template #item.actions="{ item }"><VBtn size="x-small" variant="tonal" prepend-icon="tabler-message" @click="openReply(item)">{{ t('crm_sequences_edit.enrollments.table.log_reply') }}</VBtn></template>
          </VDataTable>
        </VCard>
      </VWindowItem>
    </VWindow>

    <VDialog v-model="testDialog" max-width="520">
      <VCard>
        <VCardTitle>{{ t('crm_sequences_edit.test.title') }}</VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">{{ t('crm_sequences_edit.test.hint') }}</p>
          <VTextField
            v-model="testForm.to"
            type="email"
            :label="t('crm_sequences_edit.test.to')"
            class="mb-3"
            :error-messages="testForm.errors.to"
          />
          <VSelect
            v-model="testForm.step"
            :label="t('crm_sequences_edit.test.step')"
            clearable
            :placeholder="t('crm_sequences_edit.test.all_steps')"
            :items="form.steps.map((_, i) => ({ title: t('crm_sequences_edit.test.step_n', { n: i + 1 }), value: i }))"
          />
          <p class="text-caption text-medium-emphasis mt-2 mb-0">{{ t('crm_sequences_edit.test.note') }}</p>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="tonal" @click="testDialog = false">{{ t('crm_sequences_edit.reply_dialog.cancel') }}</VBtn>
          <VBtn color="warning" :loading="testForm.processing" :disabled="!testForm.to" @click="sendTest">{{ t('crm_sequences_edit.test.send') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="replyDialog" max-width="520">
      <VCard>
        <VCardTitle>{{ t('crm_sequences_edit.reply_dialog.title') }}</VCardTitle>
        <VCardText>
          <VTextarea v-model="replyForm.reply" :label="t('crm_sequences_edit.reply_dialog.label')" rows="4" />
          <p class="text-caption text-medium-emphasis">{{ t('crm_sequences_edit.reply_dialog.hint') }}</p>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="replyDialog = false">{{ t('crm_sequences_edit.reply_dialog.cancel') }}</VBtn><VBtn color="primary" :loading="replyForm.processing" :disabled="!replyForm.reply" @click="submitReply">{{ t('crm_sequences_edit.reply_dialog.classify') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VSnackbar v-model="snack.show" :color="snack.color" location="bottom right" timeout="3000">{{ snack.text }}</VSnackbar>
  </div>

    <!-- Rendered in an iframe rather than inlined: a cadence's own markup must not inherit the
         app's stylesheet, or the preview flatters it and the real thing arrives looking different. -->
    <VDialog v-model="previewDialog" max-width="800" scrollable>
      <VCard>
        <VCardTitle class="d-flex align-center justify-space-between">
          <span>Preview</span>
          <VBtn icon="tabler-x" variant="text" size="small" @click="previewDialog = false" />
        </VCardTitle>
        <VDivider />
        <VCardText style="max-height: 70vh">
          <div v-if="previewLoading" class="d-flex justify-center py-8"><VProgressCircular indeterminate color="primary" /></div>
          <p v-else-if="!previewSteps.length" class="text-body-2 text-medium-emphasis mb-0">
            Nothing to preview yet — save the sequence first, then its saved wording is shown here.
          </p>
          <div v-for="(p, n) in previewSteps" :key="n" class="mb-6">
            <div class="text-overline text-medium-emphasis">Step {{ p.index + 1 }}<span v-if="p.label"> · {{ p.label }}</span></div>
            <div class="font-weight-medium mb-1">{{ p.subject }}</div>
            <VAlert v-if="p.warnings.length" type="warning" variant="tonal" density="compact" class="mb-2">
              Unknown merge tags, which will arrive blank: {{ p.warnings.join(', ') }}
            </VAlert>
            <iframe :srcdoc="p.html" style="width:100%;height:340px;border:1px solid rgba(var(--v-border-color),0.3);border-radius:6px;background:#fff" />
          </div>
        </VCardText>
      </VCard>
    </VDialog>
</template>