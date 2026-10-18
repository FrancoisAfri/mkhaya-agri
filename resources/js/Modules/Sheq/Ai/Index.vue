<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { computed, nextTick, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t, d } = useI18n()

type Assertion = { claim: string; citation: string | null; verified: boolean }

type Suggestion = {
  uuid: string; agent: string; title: string
  content: Record<string, unknown>; assertions: Assertion[]
  confidence: number | null; has_unverified_assertions: boolean
  subject_type: string | null; subject_id: number | null; created_at: string
}

type Quality = {
  agent: string; verdict: string; reasons: string[]
  sample: number; decided: number
  hallucination_rate: number; citation_precision: number
  acceptance_rate: number; edit_rate: number; rejection_rate: number
}

const props = defineProps<{
  pending: Suggestion[]
  recent: Array<Record<string, any>>
  guardrails: Record<string, string>
  quality: Quality[]
  canReview?: boolean
  noCompany?: boolean
}>()

const decide = useForm({ decision: '', note: '', edited: null as Record<string, unknown> | null })

/*
 * ── Asking the assistant a question ─────────────────────────────────────────
 *
 * Held in the component rather than on the server: a conversation is a working aid, not a record,
 * and persisting every half-formed question into the tenant's data would make a filing decision
 * out of something nobody asked to keep. It travels back with each turn so the assistant can
 * follow "and what about the second one", and it goes when the page does.
 */
type Turn = { role: 'user' | 'assistant'; content: string; unverified?: boolean }

const conversation = ref<Turn[]>([])
const question = ref('')
const asking = ref(false)
const thread = ref<HTMLElement | null>(null)

async function ask() {
  const message = question.value.trim()
  if (!message || asking.value) return

  conversation.value.push({ role: 'user', content: message })
  question.value = ''
  asking.value = true
  await scrollThread()

  try {
    const response = await fetch(route('sheq.ai.chat'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name=\'csrf-token\']') as HTMLMetaElement)?.content ?? '',
      },
      body: JSON.stringify({
        message,
        // The turn just pushed is the question itself, so it is not also history.
        history: conversation.value.slice(0, -1).slice(-8)
          .map(turn => ({ role: turn.role, content: turn.content })),
      }),
    })

    const data = await response.json()

    conversation.value.push({
      role: 'assistant',
      content: data.answer ?? '',
      unverified: !!data.has_unverified,
    })
  } catch {
    // A failed request must not lose the thread above it; the assistant is assistive and the
    // practitioner's work carries on without an answer.
    conversation.value.push({ role: 'assistant', content: t('sheq.ai.chat_failed') })
  } finally {
    asking.value = false
    await scrollThread()
  }
}

async function scrollThread() {
  await nextTick()
  if (thread.value) thread.value.scrollTop = thread.value.scrollHeight
}

const open = ref<string | null>(null)
const editing = ref<string | null>(null)
const editText = ref('')

/**
 * Drafts making unsupported claims come first.
 *
 * The natural sort — newest first — buries exactly the drafts that need the most scrutiny under a
 * pile of routine ones, and a reviewer working down a long queue reaches them tired.
 */
const queue = computed(() =>
  [...props.pending].sort((a, b) =>
    Number(b.has_unverified_assertions) - Number(a.has_unverified_assertions)))

const unverifiedCount = computed(() =>
  props.pending.filter(s => s.has_unverified_assertions).length)

const bodyText = (s: Suggestion): string => {
  const c = s.content ?? {}
  return String(c.summary ?? c.answer ?? c.talk ?? c.raw ?? '')
}

const controls = (s: Suggestion): Array<[string, string]> => {
  const c = (s.content?.controls ?? {}) as Record<string, string>
  return Object.entries(c).filter(([, v]) => !!v)
}

const startEdit = (s: Suggestion) => {
  editing.value = s.uuid
  editText.value = bodyText(s)
}

const submit = (uuid: string, decision: 'ACCEPT' | 'EDIT' | 'REJECT') => {
  decide.decision = decision
  decide.edited = decision === 'EDIT' ? { text: editText.value } : null

  decide.post(`/sheq/ai/${uuid}/decide`, {
    preserveScroll: true,
    onSuccess: () => {
      decide.reset()
      open.value = null
      editing.value = null
    },
  })
}

const agentLabel = (agent: string) =>
  t(`sheq.ai.agents.${agent.toLowerCase()}`, agent.replace(/_/g, ' ').toLowerCase())

const pct = (v: number) => `${(v * 100).toFixed(1)}%`

const verdictColour = (v: string) =>
  ({ PASS: 'success', FAIL: 'error' }[v] ?? 'warning')

const statusColour = (s: string) =>
  ({ ACCEPTED: 'success', EDITED: 'info', REJECTED: 'error' }[s] ?? 'grey')

const formatDate = (v: string) => { try { return d(new Date(v), 'short') } catch { return v } }
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('sheq.ai.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.ai.subtitle') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <!--
        The boundary, stated on the page rather than in a manual.

        A practitioner needs to know what this thing cannot do before they trust anything it says,
        and they will not go looking for that in documentation.
      -->
      <VAlert
        type="info" variant="tonal" class="mb-4" role="note"
        :icon="'mdi-shield-check-outline'"
      >
        <div class="text-body-2 font-weight-medium mb-1">{{ t('sheq.ai.boundary_title') }}</div>
        <div class="text-body-2">{{ t('sheq.ai.boundary_body') }}</div>
      </VAlert>

      <VAlert
        v-if="unverifiedCount" type="warning" variant="tonal" class="mb-4"
        role="status" aria-live="polite"
      >
        {{ t('sheq.ai.unverified_waiting', { count: unverifiedCount }) }}
      </VAlert>

      <VRow>
        <VCol cols="12" md="8">
          <!--
            Asking, above the queue.

            The queue is what a reviewer works through; a question is what somebody arrives with.
            Putting the conversation first matches why people open this page, and it is the part
            that files nothing — so it costs the reviewer below it nothing either.
          -->
          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ t('sheq.ai.chat_title') }}
            </VCardTitle>
            <VCardSubtitle class="pb-3">{{ t('sheq.ai.chat_scope') }}</VCardSubtitle>
            <VDivider />

            <div ref="thread" class="pa-4 sheq-thread">
              <div v-if="!conversation.length" class="text-center text-medium-emphasis py-6">
                <VIcon icon="mdi-message-question-outline" size="32" class="mb-2" />
                <div class="text-body-2">{{ t('sheq.ai.chat_empty') }}</div>
              </div>

              <div
                v-for="(turn, i) in conversation" :key="i"
                class="d-flex mb-3" :class="turn.role === 'user' ? 'justify-end' : 'justify-start'"
              >
                <div
                  class="rounded-lg px-3 py-2 sheq-bubble"
                  :class="turn.role === 'user' ? 'bg-primary text-white' : 'bg-surface-variant'"
                >
                  <div class="text-body-2" style="white-space:pre-wrap">{{ turn.content }}</div>
                  <!--
                    Said on the answer itself, not in a footnote. An answer the system could not
                    ground in its own material is exactly the one a practitioner must check before
                    acting, and a badge somewhere else is a badge nobody reads.
                  -->
                  <VChip
                    v-if="turn.role === 'assistant' && turn.unverified"
                    size="x-small" label color="warning" variant="tonal" class="mt-2"
                    prepend-icon="mdi-alert-outline"
                  >
                    {{ t('sheq.ai.chat_unverified') }}
                  </VChip>
                </div>
              </div>

              <div v-if="asking" class="d-flex justify-start">
                <div class="rounded-lg px-3 py-2 bg-surface-variant">
                  <VProgressCircular indeterminate size="16" width="2" class="me-2" />
                  <span class="text-caption">{{ t('sheq.ai.chat_thinking') }}</span>
                </div>
              </div>
            </div>

            <VDivider />
            <div class="pa-3 d-flex gap-2 align-end">
              <VTextarea
                v-model="question" rows="1" auto-grow max-rows="5" hide-details
                variant="outlined" density="compact"
                :placeholder="t('sheq.ai.chat_placeholder')"
                :disabled="asking || noCompany"
                @keydown.enter.exact.prevent="ask"
              />
              <VBtn
                color="primary" :loading="asking" :disabled="!question.trim() || noCompany"
                icon="mdi-send" :aria-label="t('sheq.ai.chat_send')" @click="ask"
              />
            </div>
          </VCard>

          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ t('sheq.ai.queue') }}
              <VChip v-if="queue.length" size="small" class="ms-2" variant="tonal">
                {{ queue.length }}
              </VChip>
            </VCardTitle>
            <VDivider />

            <div v-if="!queue.length" class="text-center py-10 text-medium-emphasis">
              <VIcon icon="mdi-inbox-outline" size="32" class="mb-2" />
              <div class="text-body-2">{{ t('sheq.ai.queue_empty') }}</div>
            </div>

            <div v-for="s in queue" :key="s.uuid" class="border-b">
              <div
                class="pa-4 d-flex align-start ga-3 suggestion-row"
                role="button" tabindex="0"
                :aria-expanded="open === s.uuid"
                @click="open = open === s.uuid ? null : s.uuid"
                @keydown.enter.prevent="open = open === s.uuid ? null : s.uuid"
                @keydown.space.prevent="open = open === s.uuid ? null : s.uuid"
              >
                <VIcon
                  :icon="s.has_unverified_assertions ? 'mdi-alert-outline' : 'mdi-robot-outline'"
                  :color="s.has_unverified_assertions ? 'warning' : 'grey'"
                  size="20" class="mt-1"
                />

                <div class="flex-grow-1 min-width-0">
                  <div class="d-flex align-center flex-wrap ga-2">
                    <span class="text-body-1 font-weight-medium">{{ s.title }}</span>
                    <VChip size="x-small" variant="tonal">{{ agentLabel(s.agent) }}</VChip>
                    <!-- Colour is never the only signal — the chip carries its own words. -->
                    <VChip
                      v-if="s.has_unverified_assertions"
                      size="x-small" color="warning" variant="tonal"
                    >
                      {{ t('sheq.ai.has_unverified') }}
                    </VChip>
                  </div>
                  <div class="text-caption text-medium-emphasis">
                    {{ t('sheq.ai.drafted') }} {{ formatDate(s.created_at) }}
                  </div>
                </div>

                <VIcon
                  :icon="open === s.uuid ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                  size="20" class="mt-1"
                />
              </div>

              <VExpandTransition>
                <div v-if="open === s.uuid" class="px-4 pb-4">
                  <!-- Hierarchy-of-controls output reads as a ladder, not a paragraph. -->
                  <VTable v-if="controls(s).length" density="compact" class="mb-4 border rounded">
                    <tbody>
                      <tr v-for="[level, text] in controls(s)" :key="level">
                        <td class="text-caption text-uppercase font-weight-medium" style="width: 140px">
                          {{ t(`sheq.ai.controls.${level}`, level) }}
                        </td>
                        <td class="text-body-2">{{ text }}</td>
                      </tr>
                    </tbody>
                  </VTable>

                  <VTextarea
                    v-if="editing === s.uuid"
                    v-model="editText" auto-grow rows="6" variant="outlined"
                    density="compact" class="mb-3"
                    :label="t('sheq.ai.your_version')"
                  />
                  <p v-else-if="bodyText(s)" class="text-body-2 mb-4" style="white-space: pre-wrap">
                    {{ bodyText(s) }}
                  </p>

                  <!--
                    Claims and their sources, side by side.

                    Putting citations behind a click is what produces rubber-stamping: the reviewer
                    accepts on the strength of fluent prose without ever checking what it rests on.
                  -->
                  <div v-if="s.assertions.length" class="mb-4">
                    <div class="text-caption text-uppercase text-medium-emphasis mb-2">
                      {{ t('sheq.ai.claims') }}
                    </div>
                    <div
                      v-for="(a, i) in s.assertions" :key="i"
                      class="d-flex ga-2 py-2 border-b align-start"
                    >
                      <VIcon
                        :icon="a.verified ? 'mdi-check-circle-outline' : 'mdi-help-circle-outline'"
                        :color="a.verified ? 'success' : 'warning'" size="16" class="mt-1"
                      />
                      <div class="flex-grow-1">
                        <div class="text-body-2">{{ a.claim }}</div>
                        <div class="text-caption" :class="a.verified ? 'text-medium-emphasis' : 'text-warning'">
                          {{ a.verified ? a.citation : t('sheq.ai.no_source') }}
                        </div>
                      </div>
                    </div>
                  </div>

                  <VTextarea
                    v-model="decide.note" rows="2" auto-grow variant="outlined" density="compact"
                    class="mb-3" :label="t('sheq.ai.note')"
                    :hint="t('sheq.ai.note_hint')" persistent-hint
                  />

                  <div class="d-flex flex-wrap ga-2">
                    <VBtn
                      color="success" variant="flat" size="small"
                      :disabled="!canReview || decide.processing"
                      :loading="decide.processing && decide.decision === 'ACCEPT'"
                      @click="submit(s.uuid, 'ACCEPT')"
                    >
                      {{ t('sheq.ai.accept') }}
                    </VBtn>

                    <VBtn
                      v-if="editing !== s.uuid"
                      variant="outlined" size="small" :disabled="!canReview"
                      @click="startEdit(s)"
                    >
                      {{ t('sheq.ai.edit') }}
                    </VBtn>
                    <VBtn
                      v-else color="info" variant="flat" size="small"
                      :disabled="!canReview || decide.processing"
                      @click="submit(s.uuid, 'EDIT')"
                    >
                      {{ t('sheq.ai.save_edit') }}
                    </VBtn>

                    <VBtn
                      color="error" variant="outlined" size="small"
                      :disabled="!canReview || decide.processing || !decide.note.trim()"
                      @click="submit(s.uuid, 'REJECT')"
                    >
                      {{ t('sheq.ai.reject') }}
                    </VBtn>

                    <span
                      v-if="!decide.note.trim()"
                      class="text-caption text-medium-emphasis align-self-center"
                    >
                      {{ t('sheq.ai.reject_needs_note') }}
                    </span>
                  </div>
                </div>
              </VExpandTransition>
            </div>
          </VCard>

          <!-- What people decided, so the review itself is auditable. -->
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ t('sheq.ai.decided') }}
            </VCardTitle>
            <VDivider />
            <div v-if="!recent.length" class="text-center py-8 text-medium-emphasis">
              <div class="text-body-2">{{ t('sheq.ai.none_decided') }}</div>
            </div>
            <VTable v-else density="compact">
              <tbody>
                <tr v-for="r in recent" :key="r.uuid">
                  <td class="text-body-2">{{ r.title }}</td>
                  <td style="width: 110px">
                    <VChip size="x-small" :color="statusColour(r.status)" variant="tonal">
                      {{ t(`sheq.ai.status.${r.status.toLowerCase()}`, r.status) }}
                    </VChip>
                  </td>
                  <td class="text-caption text-medium-emphasis" style="width: 160px">
                    {{ r.decided_by }}
                  </td>
                  <td class="text-caption text-medium-emphasis" style="width: 120px">
                    {{ formatDate(r.decided_at) }}
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>

        <VCol cols="12" md="4">
          <!-- §7.4's gate, shown to the people who have to trust the output. -->
          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ t('sheq.ai.quality') }}
            </VCardTitle>
            <VDivider />
            <div v-if="!quality.length" class="pa-4 text-body-2 text-medium-emphasis">
              {{ t('sheq.ai.quality_empty') }}
            </div>
            <div v-for="q in quality" :key="q.agent" class="pa-4 border-b">
              <div class="d-flex align-center justify-space-between mb-2">
                <span class="text-body-2 font-weight-medium">{{ agentLabel(q.agent) }}</span>
                <VChip size="x-small" :color="verdictColour(q.verdict)" variant="tonal">
                  {{ t(`sheq.ai.verdict.${q.verdict.toLowerCase()}`, q.verdict) }}
                </VChip>
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ t('sheq.ai.hallucination') }}: {{ pct(q.hallucination_rate) }} ·
                {{ t('sheq.ai.precision') }}: {{ pct(q.citation_precision) }}
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ t('sheq.ai.reviewed_n', { n: q.decided, total: q.sample }) }}
              </div>
              <div v-for="(reason, i) in q.reasons" :key="i" class="text-caption text-warning mt-1">
                {{ reason }}
              </div>
            </div>
          </VCard>

          <!-- SHEQ-AI-7, in the open. -->
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ t('sheq.ai.never_title') }}
            </VCardTitle>
            <VDivider />
            <div class="pa-4">
              <p class="text-body-2 text-medium-emphasis mb-3">{{ t('sheq.ai.never_body') }}</p>
              <div
                v-for="(why, capability) in guardrails" :key="capability"
                class="d-flex ga-2 py-2 align-start"
              >
                <VIcon icon="mdi-close-circle-outline" color="error" size="16" class="mt-1" />
                <div>
                  <div class="text-body-2">
                    {{ t(`sheq.ai.forbidden.${capability}`, String(capability).replace(/_/g, ' ')) }}
                  </div>
                  <div class="text-caption text-medium-emphasis">{{ why }}</div>
                </div>
              </div>
            </div>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>

<style scoped>
/* Bounded so the queue below stays reachable; the thread scrolls inside itself. */
.sheq-thread {
  max-block-size: 380px;
  min-block-size: 180px;
  overflow-y: auto;
}

.sheq-bubble { max-inline-size: 80%; }

.suggestion-row {
  cursor: pointer;
  transition: background-color 200ms ease;
}

.suggestion-row:hover {
  background-color: rgba(var(--v-theme-on-surface), 0.04);
}

.suggestion-row:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: -2px;
}

.min-width-0 {
  min-width: 0;
}

@media (prefers-reduced-motion: reduce) {
  .suggestion-row {
    transition: none;
  }
}
</style>
