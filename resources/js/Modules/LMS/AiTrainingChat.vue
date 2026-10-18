<template>
  <div class="d-flex bg-surface" style="height: calc(100vh - 64px); overflow: hidden;">
    <ConfirmDialog ref="confirmDialog" />

    <!-- ── Sidebar: sessions + artefacts ── -->
    <div class="d-flex flex-column border-e bg-surface" style="width:280px; min-width:220px; overflow-y:auto;">
      <div class="pa-3 border-b d-flex align-center justify-space-between">
        <span class="text-subtitle-2 font-weight-bold">{{ t('lms_ai_assistant.page_title') }}</span>
        <VBtn icon="mdi-plus" size="small" variant="text" @click="newSession" :title="t('lms_ai_assistant.new_chat')" />
      </div>

      <!-- Context selector (persona) — only show tabs the user is authorised for -->
      <div v-if="allowed.length > 1" class="pa-2">
        <VBtnToggle v-model="activePersona" density="compact" rounded="lg" class="w-100 mb-2" mandatory>
          <VBtn v-if="allowed.includes('employee')" value="employee" size="small" style="flex:1">
            {{ t('lms_ai_assistant.persona.employee') }}
          </VBtn>
          <VBtn v-if="allowed.includes('manager')" value="manager" size="small" style="flex:1">
            {{ t('lms_ai_assistant.persona.manager') }}
          </VBtn>
          <VBtn v-if="allowed.includes('hr_admin')" value="hr_admin" size="small" style="flex:1">
            {{ t('lms_ai_assistant.persona.hr_admin') }}
          </VBtn>
        </VBtnToggle>
      </div>

      <!-- Quick action buttons -->
      <div class="px-2 pb-2">
        <p class="text-caption text-medium-emphasis mb-1">{{ t('lms_ai_assistant.quick_actions.title') }}</p>
        <div class="d-flex flex-column gap-1">
          <VBtn size="x-small" variant="tonal" prepend-icon="mdi-book-open-outline"
            @click="openQuickAction('summarise')" class="justify-start">
            {{ t('lms_ai_assistant.quick_actions.summarise') }}
          </VBtn>
          <VBtn size="x-small" variant="tonal" prepend-icon="mdi-notebook-outline"
            @click="openQuickAction('study_guide')" class="justify-start">
            {{ t('lms_ai_assistant.quick_actions.study_guide') }}
          </VBtn>
          <VBtn size="x-small" variant="tonal" prepend-icon="mdi-help-circle-outline"
            @click="openQuickAction('practice_quiz')" class="justify-start">
            {{ t('lms_ai_assistant.quick_actions.practice_quiz') }}
          </VBtn>
          <VBtn size="x-small" variant="tonal" prepend-icon="mdi-file-document-outline"
            @click="openQuickAction('policy')" class="justify-start">
            {{ t('lms_ai_assistant.quick_actions.policy') }}
          </VBtn>
        </div>
      </div>

      <VDivider />

      <!-- Session list -->
      <div class="flex-1 overflow-y-auto pa-2">
        <p class="text-caption text-medium-emphasis mb-1 mt-1">{{ t('lms_ai_assistant.recent_chats') }}</p>
        <div v-if="sessions.length === 0" class="text-caption text-medium-emphasis pa-2">
          {{ t('lms_ai_assistant.no_chats') }}
        </div>
        <div v-for="s in sessions" :key="s.session_id"
          class="rounded pa-2 mb-1 cursor-pointer hover-bg d-flex align-center justify-space-between"
          :class="{ 'bg-primary-lighten-4': currentSessionId === s.session_id }"
          @click="loadSession(s.session_id)">
          <div class="flex-1 overflow-hidden">
            <div class="text-body-2 text-truncate">{{ s.title || 'Chat' }}</div>
            <div class="text-caption text-medium-emphasis">
              <VChip :color="contextColor(s.context_type)" size="x-small" variant="tonal" class="mr-1">
                {{ s.context_type }}
              </VChip>
              {{ formatDate(s.updated_at) }}
            </div>
          </div>
          <VBtn icon="mdi-archive-outline" size="x-small" variant="text"
            @click.stop="archiveSession(s.session_id)" :title="t('lms_ai_assistant.saved_artefacts')" />
        </div>
      </div>

      <VDivider />

      <!-- Artefacts toggle -->
      <div class="pa-2">
        <VBtn variant="text" size="small" prepend-icon="mdi-file-multiple-outline"
          class="w-100 justify-start" @click="artefactsDrawer = true">
          {{ t('lms_ai_assistant.saved_artefacts') }}
        </VBtn>
      </div>
    </div>

    <!-- ── Main chat area ── -->
    <div class="d-flex flex-column flex-1 overflow-hidden">

      <!-- Chat header -->
      <div class="pa-3 border-b d-flex align-center gap-3">
        <VIcon :icon="personaIcon" :color="personaColor" />
        <div>
          <div class="text-subtitle-2 font-weight-bold">{{ personaLabel }}</div>
          <div class="text-caption text-medium-emphasis">
            {{ contextLabel }}
          </div>
        </div>
        <VSpacer />
        <VSelect
          v-if="activePersona === 'employee'"
          v-model="selectedCourseId"
          :items="courses"
          item-title="title"
          item-value="id"
          :label="t('lms_ai_assistant.course_context')"
          clearable
          density="compact"
          hide-details
          style="max-width:260px"
          @update:model-value="onCourseChange"
        />
      </div>

      <!-- Messages -->
      <div ref="messagesEl" class="flex-1 overflow-y-auto pa-4 d-flex flex-column gap-3 bg-surface">
        <!-- Empty state -->
        <div v-if="messages.length === 0" class="d-flex flex-column align-center justify-center h-100 text-center">
          <VIcon icon="mdi-robot" size="64" color="primary" class="mb-3 opacity-40" />
          <div class="text-h6 text-medium-emphasis mb-2">{{ t('lms_ai_assistant.page_title') }}</div>
          <div class="text-body-2 text-medium-emphasis mb-4" style="max-width:420px">
            {{ emptyStateText }}
          </div>
          <div class="d-flex flex-wrap gap-2 justify-center">
            <VChip v-for="hint in starterHints" :key="hint" @click="sendMessage(hint)"
              variant="outlined" color="primary" class="cursor-pointer" size="small">
              {{ hint }}
            </VChip>
          </div>
        </div>

        <!-- Message bubbles -->
        <div v-for="(msg, i) in messages" :key="i"
          class="d-flex" :class="msg.role === 'user' ? 'justify-end' : 'justify-start'">
          <div v-if="msg.role === 'assistant'" class="mr-2 mt-1">
            <VAvatar size="28" color="primary" variant="tonal">
              <VIcon icon="mdi-robot" size="16" />
            </VAvatar>
          </div>
          <div
            class="rounded-lg pa-3"
            style="max-width:72%; white-space: pre-wrap; word-break: break-word; line-height: 1.6"
            :style="msg.role === 'user'
              ? 'background: rgb(var(--v-theme-primary)); color: white;'
              : 'background: rgb(var(--v-theme-surface-variant)); color: rgb(var(--v-theme-on-surface-variant));'"
          >
            <div v-html="renderMarkdown(msg.content)" />
            <div class="text-caption opacity-60 mt-1 text-right">
              {{ formatDate(msg.created_at) }}
            </div>
          </div>
        </div>

        <!-- Typing indicator -->
        <div v-if="loading" class="d-flex justify-start">
          <VAvatar size="28" color="primary" variant="tonal" class="mr-2 mt-1">
            <VIcon icon="mdi-robot" size="16" />
          </VAvatar>
          <div class="rounded-lg pa-3" style="background: rgb(var(--v-theme-surface-variant)); color: rgb(var(--v-theme-on-surface-variant));">
            <span class="typing-dot" /><span class="typing-dot" /><span class="typing-dot" />
          </div>
        </div>
      </div>

      <!-- Input area -->
      <div class="pa-3 border-t">
        <div class="d-flex align-end gap-2">
          <VTextarea
            v-model="inputText"
            :placeholder="t('lms_ai_assistant.input_placeholder')"
            rows="1"
            auto-grow
            max-rows="6"
            hide-details
            density="compact"
            variant="outlined"
            class="flex-1"
            :disabled="loading"
            @keydown.enter.exact.prevent="onEnterSend"
            @keydown.enter.shift.exact="() => {}"
          />
          <VBtn
            icon="mdi-send"
            color="primary"
            :loading="loading"
            :disabled="!inputText.trim()"
            @click="onSend"
          />
        </div>
        <div class="text-caption text-medium-emphasis mt-1">
          {{ t('lms_ai_assistant.input_hint') }}
        </div>
      </div>
    </div>

    <!-- ── Quick action dialog ── -->
    <VDialog v-model="quickActionDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-4">{{ quickActionTitle }}</VCardTitle>
        <VCardText>
          <VSelect
            v-model="quickActionCourseId"
            :items="courses"
            item-title="title"
            item-value="id"
            :label="t('lms_ai_assistant.quick_action_dialog.select_course')"
            :error-messages="quickActionErrors.course_id"
            class="mb-3"
          />
          <template v-if="quickActionType === 'practice_quiz'">
            <VTextField
              v-model.number="quizCount"
              :label="t('lms_ai_assistant.quick_action_dialog.question_count')"
              type="number"
              :min="3"
              :max="20"
              class="mb-3"
            />
          </template>
          <template v-if="quickActionType === 'policy'">
            <p class="text-body-2 text-medium-emphasis">
              {{ t('lms_ai_assistant.quick_action_dialog.policy_info') }}
            </p>
          </template>
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="quickActionDialog = false">{{ t('lms_ai_assistant.quick_action_dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="quickActionLoading" @click="executeQuickAction">
            {{ t('lms_ai_assistant.quick_action_dialog.generate') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Artefacts drawer ── -->
    <VNavigationDrawer v-model="artefactsDrawer" location="right" width="420" temporary>
      <div class="pa-3 d-flex align-center justify-space-between">
        <span class="text-subtitle-2 font-weight-bold">{{ t('lms_ai_assistant.saved_artefacts') }}</span>
        <VBtn icon="mdi-close" size="small" variant="text" @click="artefactsDrawer = false" />
      </div>
      <VDivider />
      <div class="pa-3">
        <VBtnToggle v-model="artefactFilter" density="compact" rounded="lg" mandatory class="mb-3">
          <VBtn value="all" size="small">{{ t('lms_ai_assistant.artefacts.all') }}</VBtn>
          <VBtn value="summary" size="small">{{ t('lms_ai_assistant.artefacts.summaries') }}</VBtn>
          <VBtn value="study_guide" size="small">{{ t('lms_ai_assistant.artefacts.guides') }}</VBtn>
          <VBtn value="practice_quiz" size="small">{{ t('lms_ai_assistant.artefacts.quizzes') }}</VBtn>
        </VBtnToggle>

        <div v-if="filteredArtefacts.length === 0" class="text-body-2 text-medium-emphasis text-center py-4">
          {{ t('lms_ai_assistant.artefacts.no_artefacts') }}
        </div>

        <VCard v-for="a in filteredArtefacts" :key="a.id" variant="outlined" class="mb-3">
          <VCardTitle class="pa-3 pb-1 text-body-2 font-weight-bold d-flex align-center">
            <VChip :color="artefactColor(a.artefact_type)" size="x-small" variant="tonal" class="mr-2">
              {{ t(`lms_ai_assistant.artefact_types.${a.artefact_type}`) }}
            </VChip>
            <span class="text-truncate">{{ a.title }}</span>
          </VCardTitle>
          <VCardText class="pa-3 pt-1">
            <div v-if="a.artefact_type === 'practice_quiz' && a.structured_data?.length">
              <p class="text-caption text-medium-emphasis">
                {{ a.structured_data.length }} {{ t('lms_ai_assistant.artefacts.questions') }}
              </p>
              <div v-for="(q, qi) in a.structured_data.slice(0, 2)" :key="qi" class="mb-2">
                <div class="text-body-2 font-weight-medium">{{ qi + 1 }}. {{ q.question }}</div>
              </div>
              <VBtn v-if="expandedArtefact !== a.id" size="x-small" variant="text"
                @click="expandedArtefact = a.id">
                {{ t('lms_ai_assistant.artefacts.view_all') }}
              </VBtn>
              <div v-if="expandedArtefact === a.id">
                <div v-for="(q, qi) in a.structured_data" :key="qi" class="mb-3 pa-2 border rounded">
                  <div class="text-body-2 font-weight-medium mb-1">{{ qi + 1 }}. {{ q.question }}</div>
                  <div v-for="(opt, oi) in q.options" :key="oi"
                    class="text-body-2 px-2"
                    :class="oi === q.answer ? 'text-success font-weight-bold' : ''">
                    {{ String.fromCharCode(65 + oi) }}. {{ opt }}
                  </div>
                  <div class="text-caption text-success mt-1">{{ q.explanation }}</div>
                </div>
              </div>
            </div>
            <div v-else class="text-body-2" style="max-height:200px; overflow-y:auto; white-space: pre-wrap;">
              {{ a.content.slice(0, 400) }}{{ a.content.length > 400 ? '...' : '' }}
            </div>
          </VCardText>
          <VCardActions class="pa-2 pt-0">
            <VBtn size="x-small" variant="text" prepend-icon="mdi-chat-outline"
              @click="viewArtefactInChat(a)">
              {{ t('lms_ai_assistant.artefacts.open_in_chat') }}
            </VBtn>
            <VSpacer />
            <VBtn size="x-small" variant="text" icon="mdi-delete" color="error"
              @click="deleteArtefact(a.id)" />
          </VCardActions>
        </VCard>
      </div>
    </VNavigationDrawer>

  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted } from 'vue'
import axios from 'axios'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()

// ── Types ──────────────────────────────────────────────────────────────────────
interface Session {
  session_id: string
  title: string | null
  context_type: string
  updated_at: string
  created_at: string
}

interface Message {
  role: 'user' | 'assistant'
  content: string
  created_at?: string
}

interface Course {
  id: number
  title: string
}

interface Artefact {
  id: number
  artefact_type: string
  type_label: string
  title: string
  content: string
  structured_data: any[] | null
  created_at: string
}

interface ConfirmDialogInstance {
  open: (options: any, callback: () => void) => void
}

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps<{
  sessions: Session[]
  courses: Course[]
  persona: 'employee' | 'manager' | 'hr_admin'
  allowedPersonas: string[]
  sessionId?: string | null
  history?: Message[]
}>()

// ── State ─────────────────────────────────────────────────────────────────────
const confirmDialog = ref<ConfirmDialogInstance | null>(null)
const allowed = props.allowedPersonas ?? ['employee']
const activePersona = ref<'employee' | 'manager' | 'hr_admin'>(
  (allowed.includes(props.persona) ? props.persona : allowed[0] as any ?? 'employee') as 'employee' | 'manager' | 'hr_admin'
)
const sessions = ref<Session[]>([...(props.sessions ?? [])])
const messages = ref<Message[]>([...(props.history ?? [])])
const currentSessionId = ref<string | null>(props.sessionId ?? null)
const inputText = ref('')
const loading = ref(false)
const selectedCourseId = ref<number | null>(null)

const messagesEl = ref<HTMLElement | null>(null)
const artefactsDrawer = ref(false)
const artefacts = ref<Artefact[]>([])
const artefactFilter = ref('all')
const expandedArtefact = ref<number | null>(null)

const quickActionDialog = ref(false)
const quickActionType = ref<string>('')
const quickActionCourseId = ref<number | null>(null)
const quickActionErrors = ref<Record<string, string>>({})
const quickActionLoading = ref(false)
const quizCount = ref(5)

// ── Computed ──────────────────────────────────────────────────────────────────
const personaLabel = computed(() => {
  const labels = {
    employee: t('lms_ai_assistant.persona_label.employee'),
    manager: t('lms_ai_assistant.persona_label.manager'),
    hr_admin: t('lms_ai_assistant.persona_label.hr_admin')
  }
  return labels[activePersona.value] || labels.employee
})

const personaIcon = computed(() => ({
  employee: 'mdi-robot',
  manager: 'mdi-account-supervisor',
  hr_admin: 'mdi-account-group',
}[activePersona.value] || 'mdi-robot'))

const personaColor = computed(() => ({
  employee: 'primary',
  manager: 'warning',
  hr_admin: 'success',
}[activePersona.value] || 'primary'))

const contextLabel = computed(() => {
  if (selectedCourseId.value) {
    const c = props.courses.find(c => c.id === selectedCourseId.value)
    return c ? `${t('lms_ai_assistant.course_context')}: ${c.title}` : t('lms_ai_assistant.course_context')
  }
  return t('lms_ai_assistant.course_context')
})

const emptyStateText = computed(() => {
  const texts = {
    employee: t('lms_ai_assistant.empty_state.employee'),
    manager: t('lms_ai_assistant.empty_state.manager'),
    hr_admin: t('lms_ai_assistant.empty_state.hr_admin')
  }
  return texts[activePersona.value] || texts.employee
})

const starterHints = computed(() => {
  const hints = {
    employee: t('lms_ai_assistant.starter_hints.employee'),
    manager: t('lms_ai_assistant.starter_hints.manager'),
    hr_admin: t('lms_ai_assistant.starter_hints.hr_admin')
  }
  return Array.isArray(hints[activePersona.value]) ? hints[activePersona.value] : hints.employee
})

const quickActionTitle = computed(() => {
  const titles = {
    summarise: t('lms_ai_assistant.quick_action_dialog.title_summarise'),
    study_guide: t('lms_ai_assistant.quick_action_dialog.title_study_guide'),
    practice_quiz: t('lms_ai_assistant.quick_action_dialog.title_practice_quiz'),
    policy: t('lms_ai_assistant.quick_action_dialog.title_policy')
  }
  return titles[quickActionType.value as keyof typeof titles] || ''
})

const filteredArtefacts = computed(() =>
  artefactFilter.value === 'all'
    ? artefacts.value
    : artefacts.value.filter(a => a.artefact_type === artefactFilter.value)
)

// ── Messaging ─────────────────────────────────────────────────────────────────
async function onSend() {
  const text = inputText.value.trim()
  if (!text || loading.value) return
  await sendMessage(text)
}

function onEnterSend() {
  onSend()
}

async function sendMessage(text: string) {
  inputText.value = ''
  messages.value.push({ role: 'user', content: text, created_at: new Date().toISOString() })
  loading.value = true
  await scrollBottom()

  try {
    const endpoint = chatEndpoint()
    const payload = buildPayload(text)

    const { data } = await axios.post(endpoint, payload)

    messages.value.push({
      role: 'assistant',
      content: data.answer,
      created_at: new Date().toISOString(),
    })

    if (!currentSessionId.value && data.session_id) {
      currentSessionId.value = data.session_id
    }

    await refreshSessions()
  } catch (err: any) {
    const errMsg = err?.response?.data?.message
      || err?.response?.data?.errors?.question?.[0]
      || t('lms_ai_assistant.errors.general')
    messages.value.push({ role: 'assistant', content: errMsg, created_at: new Date().toISOString() })
  } finally {
    loading.value = false
    await scrollBottom()
  }
}

function chatEndpoint(): string {
  if (activePersona.value === 'manager') {
    return route('lms.ai.compliance-chat')
  }
  if (activePersona.value === 'hr_admin') {
    return route('lms.ai.skills-gap-chat')
  }
  return route('lms.ai.chat')
}

function buildPayload(question: string): Record<string, any> {
  const base: Record<string, any> = {
    question,
    session_id: currentSessionId.value ?? undefined,
    persona: activePersona.value,
  }
  if (activePersona.value === 'employee' && selectedCourseId.value) {
    base.course_id = selectedCourseId.value
  }
  return base
}

// ── Session management ────────────────────────────────────────────────────────
function newSession() {
  messages.value = []
  currentSessionId.value = null
  selectedCourseId.value = null
}

async function loadSession(sessionId: string) {
  currentSessionId.value = sessionId
  loading.value = true
  try {
    const { data } = await axios.get(route('lms.ai.history'), { params: { session_id: sessionId } })
    messages.value = data
    await scrollBottom()
  } finally {
    loading.value = false
  }
}

async function archiveSession(sessionId: string) {
  await axios.post(route('lms.ai.archive'), { session_id: sessionId })
  sessions.value = sessions.value.filter(s => s.session_id !== sessionId)
  if (currentSessionId.value === sessionId) newSession()
}

async function refreshSessions() {
  try {
    const { data } = await axios.get(route('lms.ai.sessions'))
    sessions.value = data
  } catch {
    // Ignore
  }
}

function onCourseChange() {
  if (messages.value.length > 0) {
    messages.value = []
    currentSessionId.value = null
  }
}

// ── Quick actions ─────────────────────────────────────────────────────────────
function openQuickAction(type: string) {
  quickActionType.value = type
  quickActionCourseId.value = null
  quickActionErrors.value = {}
  quickActionDialog.value = true
}

async function executeQuickAction() {
  if (!quickActionCourseId.value && quickActionType.value !== 'policy') {
    quickActionErrors.value = { course_id: t('lms_ai_assistant.errors.course_required') }
    return
  }

  quickActionLoading.value = true
  try {
    let data: any

    if (quickActionType.value === 'summarise') {
      const res = await axios.post(route('lms.ai.summarise'), { course_id: quickActionCourseId.value })
      data = res.data
      artefacts.value.unshift({
        id: data.artefact_id,
        artefact_type: 'summary',
        type_label: t('lms_ai_assistant.artefact_types.summary'),
        title: data.title,
        content: data.content,
        structured_data: null,
        created_at: new Date().toISOString(),
      })
    } else if (quickActionType.value === 'study_guide') {
      const res = await axios.post(route('lms.ai.study-guide'), { course_id: quickActionCourseId.value })
      data = res.data
      artefacts.value.unshift({
        id: data.artefact_id,
        artefact_type: 'study_guide',
        type_label: t('lms_ai_assistant.artefact_types.study_guide'),
        title: data.title,
        content: data.content,
        structured_data: null,
        created_at: new Date().toISOString(),
      })
    } else if (quickActionType.value === 'practice_quiz') {
      const res = await axios.post(route('lms.ai.practice-quiz'), {
        course_id: quickActionCourseId.value,
        count: quizCount.value,
      })
      data = res.data
      artefacts.value.unshift({
        id: data.artefact_id,
        artefact_type: 'practice_quiz',
        type_label: t('lms_ai_assistant.artefact_types.practice_quiz'),
        title: data.title,
        content: '',
        structured_data: data.questions,
        created_at: new Date().toISOString(),
      })
    }

    quickActionDialog.value = false
    artefactsDrawer.value = true
  } catch (err: any) {
    const errs = err?.response?.data?.errors ?? {}
    quickActionErrors.value = Object.fromEntries(
      Object.entries(errs).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v as string])
    )
  } finally {
    quickActionLoading.value = false
  }
}

// ── Artefacts ─────────────────────────────────────────────────────────────────
onMounted(async () => {
  try {
    const { data } = await axios.get(route('lms.ai.artefacts'))
    artefacts.value = data
  } catch {
    // Ignore
  }
})

watch(artefactsDrawer, async (open) => {
  if (open) {
    try {
      const { data } = await axios.get(route('lms.ai.artefacts'))
      artefacts.value = data
    } catch {
      // Ignore
    }
  }
})

function deleteArtefact(id: number) {
  confirmDialog.value?.open(
    {
      title: t('lms_ai_assistant.confirm_delete.title'),
      message: t('lms_ai_assistant.confirm_delete.message'),
      confirmText: t('lms_ai_assistant.confirm_delete.confirm'),
      confirmColor: 'error',
      icon: 'tabler-trash'
    },
    async () => {
      await axios.delete(route('lms.ai.artefacts.destroy', id))
      artefacts.value = artefacts.value.filter(a => a.id !== id)
    }
  )
}

function viewArtefactInChat(a: Artefact) {
  artefactsDrawer.value = false
  messages.value.push({
    role: 'assistant',
    content: a.content || JSON.stringify(a.structured_data, null, 2),
    created_at: a.created_at,
  })
  nextTick(scrollBottom)
}

// ── Helpers ───────────────────────────────────────────────────────────────────
async function scrollBottom() {
  await nextTick()
  if (messagesEl.value) {
    messagesEl.value.scrollTop = messagesEl.value.scrollHeight
  }
}

function renderMarkdown(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/`([^`]+)`/g, '<code class="text-mono text-body-2 px-1 rounded" style="background:rgba(0,0,0,.08)">$1</code>')
    .replace(/^#{1,3} (.+)$/gm, '<div class="text-subtitle-2 font-weight-bold mt-2">$1</div>')
    .replace(/^- (.+)$/gm, '<div class="ml-2">• $1</div>')
    .replace(/^(\d+)\. (.+)$/gm, '<div class="ml-2">$1. $2</div>')
    .replace(/\n/g, '<br>')
}

function formatDate(dt?: string): string {
  if (!dt) return ''
  const d = new Date(dt)
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

function contextColor(ct: string): string {
  const map: Record<string, string> = {
    course: 'primary', lesson: 'secondary', policy: 'warning',
    compliance: 'error', skills_gap: 'success', general: 'grey',
  }
  return map[ct] ?? 'grey'
}

function artefactColor(type: string): string {
  const map: Record<string, string> = {
    summary: 'primary', study_guide: 'secondary', practice_quiz: 'warning',
    policy_explanation: 'info', compliance_report: 'error',
  }
  return map[type] ?? 'grey'
}
</script>

<style scoped>
.hover-bg:hover { background: rgba(var(--v-theme-primary), 0.06); }
.cursor-pointer { cursor: pointer; }

.typing-dot {
  display: inline-block;
  width: 8px; height: 8px;
  border-radius: 50%;
  background: rgb(var(--v-theme-primary));
  margin: 0 2px;
  animation: typing-bounce 1.2s infinite;
}
.typing-dot:nth-child(2) { animation-delay: 0.2s; }
.typing-dot:nth-child(3) { animation-delay: 0.4s; }

@keyframes typing-bounce {
  0%, 80%, 100% { transform: translateY(0); opacity: .5; }
  40%           { transform: translateY(-6px); opacity: 1; }
}
</style>
