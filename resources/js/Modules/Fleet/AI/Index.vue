<script setup lang="ts">
import { ref, computed, nextTick } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
type Provider  = { value: string; label: string; model: string }
type Session   = { session_id: string; title: string; created_at: string }
type ChatMsg   = { id?: number; role: 'user' | 'assistant'; content: string; timestamp?: string }
type Prediction = {
  vehicle: string; vehicle_id: number; vehicle_uuid: string | null; risk_score: number; risk_level: string
  predicted_service_date: string; top_risk_factors: { factor: string; label: string; contribution: number }[]
}
type Anomaly   = { id: number; vehicle: string; anomaly_type: string; description: string; severity: string; created_at: string }

const props = defineProps<{
  providers:   Provider[]
  sessions:    Session[]
  predictions: Prediction[]
  anomalies:   Anomaly[]
}>()

// ── State ─────────────────────────────────────────────────────────────────────
const selectedProvider = ref<string>(props.providers[0]?.value ?? 'claude')
const currentSessionId = ref<string | null>(null)
const sessions         = ref<Session[]>(props.sessions)
const messages         = ref<ChatMsg[]>([])
const input            = ref('')
const loading          = ref(false)
const chatEl           = ref<HTMLElement | null>(null)

const anomalyList   = ref(props.anomalies)
const reviewingId   = ref<number | null>(null)
const resolution    = ref<string>('')

const sideTab = ref<'predictions' | 'anomalies'>('predictions')

const suggestions = [
  t('fleet_ai_index.suggestions.available_vehicles'),
  t('fleet_ai_index.suggestions.service_needed'),
  t('fleet_ai_index.suggestions.fuel_costs'),
  t('fleet_ai_index.suggestions.expiring_docs'),
  t('fleet_ai_index.suggestions.pending_bookings'),
  t('fleet_ai_index.suggestions.top_driver_spend'),
]

// ── Chat helpers ──────────────────────────────────────────────────────────────
async function sendMessage(text?: string) {
  const msg = (text ?? input.value).trim()
  if (!msg || loading.value) return

  input.value = ''
  messages.value.push({ role: 'user', content: msg })
  loading.value = true
  await scrollToBottom()

  try {
    const { data } = await axios.post(route('fleet.ai.chat'), {
      message:    msg,
      session_id: currentSessionId.value,
      provider:   selectedProvider.value,
    })

    currentSessionId.value = data.session_id
    messages.value.push(data.message)

    const { data: sess } = await axios.get(route('fleet.ai.sessions'))
    sessions.value = sess
  } catch (e: any) {
    messages.value.push({
      role:    'assistant',
      content: e?.response?.data?.message ?? t('fleet_ai_index.errors.request_failed'),
    })
  } finally {
    loading.value = false
    await scrollToBottom()
  }
}

async function loadSession(sessionId: string) {
  currentSessionId.value = sessionId
  messages.value = []
  try {
    const { data } = await axios.get(route('fleet.ai.history', { sessionId }))
    messages.value = data
    await scrollToBottom()
  } catch { /* ignore */ }
}

async function newSession() {
  currentSessionId.value = null
  messages.value = []
}

async function deleteSession(sessionId: string) {
  await axios.delete(route('fleet.ai.session.delete', { sessionId }))
  sessions.value = sessions.value.filter(s => s.session_id !== sessionId)
  if (currentSessionId.value === sessionId) newSession()
}

function scrollToBottom() {
  return nextTick(() => {
    if (chatEl.value) chatEl.value.scrollTop = chatEl.value.scrollHeight
  })
}

// ── Anomaly review ─────────────────────────────────────────────────────────────
async function reviewAnomaly(id: number, res: string) {
  await axios.patch(route('fleet.ai.anomalies.review', { anomaly: id }), { resolution: res })
  anomalyList.value = anomalyList.value.filter(a => a.id !== id)
  reviewingId.value = null
}

// ── Action confirmation (FR-4.4.6) ────────────────────────────────────────────
const pendingAction   = ref<{ action: string; params: Record<string, any> } | null>(null)
const actionConfirmDialog = ref(false)

async function executeConfirmedAction() {
  if (!pendingAction.value) return
  try {
    const { data } = await axios.post(route('fleet.ai.actions'), pendingAction.value)
    messages.value.push({ role: 'assistant', content: `✅ ${t('fleet_ai_index.action_completed')}: ${JSON.stringify(data)}` })
    actionConfirmDialog.value = false
    pendingAction.value = null
  } catch {
    messages.value.push({ role: 'assistant', content: `❌ ${t('fleet_ai_index.errors.action_failed')}` })
  }
}

// ── Helpers ────────────────────────────────────────────────────────────────────
const riskColor = (level: string) => ({ high: 'error', medium: 'warning', low: 'success' })[level] ?? 'default'
const severityColor = (s: string) => ({ high: 'error', medium: 'warning', low: 'info' })[s] ?? 'default'

const providerIcon = (p: string) => ({
  claude:   'ri-anthropic-line',
  openai:   'ri-openai-line',
  gemini:   'ri-google-line',
  deepseek: 'ri-braces-line',
})[p] ?? 'ri-robot-line'

function formatContent(content: string): string {
  return content
    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
    .replace(/\n/g, '<br>')
}

const highRiskCount = computed(() => props.predictions.filter(p => p.risk_level === 'high').length)
</script>

<template>
  <div class="pa-4" style="height: calc(100vh - 100px); display:flex; flex-direction:column;">
    <!-- Header -->
    <div class="d-flex align-center mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold d-flex align-center gap-2">
          <VIcon icon="ri-robot-2-line" color="primary" />
          {{ t('fleet_ai_index.page_title') }}
        </h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('fleet_ai_index.description') }}</p>
      </div>
      <VSpacer />
      <VSelect
        v-model="selectedProvider"
        :items="providers"
        item-title="label"
        item-value="value"
        density="compact"
        variant="outlined"
        hide-details
        style="max-width: 200px"
        prepend-inner-icon="ri-cpu-line"
      >
        <template #item="{ item, props: ip }">
          <VListItem v-bind="ip">
            <template #prepend>
              <VIcon :icon="providerIcon(item.raw.value)" size="16" class="mr-2" />
            </template>
            <template #subtitle>{{ item.raw.model }}</template>
          </VListItem>
        </template>
      </VSelect>
    </div>

    <div class="d-flex gap-4 flex-1" style="min-height: 0;">

      <!-- Left sidebar: sessions -->
      <VCard width="220" class="d-flex flex-column" style="overflow:hidden;">
        <VCardText class="pa-2 flex-1" style="overflow-y:auto;">
          <div class="d-flex align-center justify-space-between mb-2">
            <span class="text-caption font-weight-bold text-uppercase text-disabled">{{ t('fleet_ai_index.sessions') }}</span>
            <VBtn size="x-small" variant="text" icon @click="newSession" :title="t('fleet_ai_index.new_chat')">
              <VIcon icon="ri-add-line" size="16" />
            </VBtn>
          </div>

          <VList density="compact" nav>
            <VListItem
              v-for="s in sessions"
              :key="s.session_id"
              :active="currentSessionId === s.session_id"
              color="primary"
              rounded
              @click="loadSession(s.session_id)"
            >
              <VListItemTitle class="text-body-2" style="white-space:normal; line-height:1.3;">
                {{ s.title }}
              </VListItemTitle>
              <template #append>
                <VBtn size="x-small" variant="text" icon color="error"
                  @click.stop="deleteSession(s.session_id)">
                  <VIcon icon="ri-delete-bin-line" size="13" />
                </VBtn>
              </template>
            </VListItem>
            <div v-if="!sessions.length" class="text-caption text-disabled text-center py-4">
              {{ t('fleet_ai_index.no_sessions') }}
            </div>
          </VList>
        </VCardText>
      </VCard>

      <!-- Main chat area -->
      <VCard class="flex-1 d-flex flex-column" style="min-width:0; overflow:hidden;">
        <div ref="chatEl" class="flex-1 pa-4" style="overflow-y:auto;">
          <!-- Welcome state -->
          <div v-if="!messages.length" class="text-center py-8">
            <VIcon icon="ri-robot-2-line" size="56" color="primary" class="mb-4" />
            <p class="text-h6 mb-2">{{ t('fleet_ai_index.welcome_title') }}</p>
            <p class="text-body-2 text-medium-emphasis mb-6">{{ t('fleet_ai_index.welcome_description') }}</p>
            <div class="d-flex flex-wrap gap-2 justify-center">
              <VChip
                v-for="s in suggestions"
                :key="s"
                size="small"
                color="primary"
                variant="outlined"
                clickable
                @click="sendMessage(s)"
              >
                {{ s }}
              </VChip>
            </div>
          </div>

          <!-- Message bubbles -->
          <div v-for="(msg, i) in messages" :key="i" class="mb-4">
            <div :class="msg.role === 'user' ? 'd-flex justify-end' : 'd-flex justify-start'">
              <VAvatar v-if="msg.role === 'assistant'" size="28" color="primary" class="mr-2 mt-1 flex-shrink-0">
                <VIcon icon="ri-robot-2-line" size="16" />
              </VAvatar>

              <VCard
                :color="msg.role === 'user' ? 'primary' : undefined"
                :variant="msg.role === 'user' ? 'flat' : 'outlined'"
                style="max-width: 80%;"
              >
                <VCardText class="pa-3">
                  <div
                    v-if="msg.role === 'assistant'"
                    class="text-body-2"
                    style="white-space: pre-wrap; line-height: 1.6;"
                    v-html="formatContent(msg.content)"
                  />
                  <div v-else class="text-body-2 text-white">{{ msg.content }}</div>
                </VCardText>
              </VCard>

              <VAvatar v-if="msg.role === 'user'" size="28" color="secondary" class="ml-2 mt-1 flex-shrink-0">
                <VIcon icon="ri-user-line" size="16" />
              </VAvatar>
            </div>
          </div>

          <!-- Loading -->
          <div v-if="loading" class="d-flex align-center gap-2 mt-2">
            <VAvatar size="28" color="primary">
              <VIcon icon="ri-robot-2-line" size="16" />
            </VAvatar>
            <VCard variant="outlined">
              <VCardText class="pa-3">
                <VProgressCircular indeterminate size="16" width="2" class="mr-2" />
                <span class="text-caption text-disabled">{{ t('fleet_ai_index.thinking') }}</span>
              </VCardText>
            </VCard>
          </div>
        </div>

        <!-- Input area -->
        <VDivider />
        <div class="pa-3 d-flex gap-2 align-end">
          <VTextField
            v-model="input"
            :placeholder="t('fleet_ai_index.input_placeholder')"
            variant="outlined"
            density="compact"
            hide-details
            class="flex-1"
            :disabled="loading"
            @keydown.enter.prevent="sendMessage()"
          />
          <VBtn
            color="primary"
            icon
            :loading="loading"
            :disabled="!input.trim()"
            @click="sendMessage()"
          >
            <VIcon icon="ri-send-plane-fill" />
          </VBtn>
        </div>
      </VCard>

      <!-- Right sidebar: predictions & anomalies -->
      <VCard width="280" class="d-flex flex-column" style="overflow:hidden;">
        <VTabs v-model="sideTab" density="compact" class="flex-shrink-0">
          <VTab value="predictions">
            {{ t('fleet_ai_index.predictions') }}
            <VChip v-if="highRiskCount" size="x-small" color="error" class="ml-1">{{ highRiskCount }}</VChip>
          </VTab>
          <VTab value="anomalies">
            {{ t('fleet_ai_index.anomalies') }}
            <VChip v-if="anomalyList.length" size="x-small" color="warning" class="ml-1">{{ anomalyList.length }}</VChip>
          </VTab>
        </VTabs>
        <VDivider />

        <div class="flex-1 pa-2" style="overflow-y:auto;">
          <VTabsWindow v-model="sideTab">
            <VTabsWindowItem value="predictions">
              <div v-if="!predictions.length" class="text-caption text-disabled text-center py-6">
                {{ t('fleet_ai_index.no_predictions') }}
              </div>
              <VCard
                v-for="p in predictions"
                :key="p.vehicle_id"
                variant="outlined"
                class="mb-2"
                rounded
              >
                <VCardText class="pa-3">
                  <div class="d-flex align-center justify-space-between mb-1">
                    <span class="text-body-2 font-weight-medium" style="font-size:12px;">{{ p.vehicle }}</span>
                    <VChip :color="riskColor(p.risk_level)" size="x-small" label>
                      {{ p.risk_score }}
                    </VChip>
                  </div>
                  <div class="text-caption text-disabled mb-1">
                    {{ t('fleet_ai_index.service_by') }} {{ p.predicted_service_date }}
                  </div>
                  <div v-for="f in p.top_risk_factors.slice(0,2)" :key="f.factor" class="text-caption d-flex align-center gap-1 mb-1">
                    <VIcon icon="ri-error-warning-line" size="12" :color="riskColor(p.risk_level)" />
                    {{ f.label }}
                  </div>
                  <VBtn
                    size="x-small"
                    variant="tonal"
                    color="primary"
                    class="mt-1"
                    :href="p.vehicle_uuid ? route('fleet.vehicles.show', p.vehicle_uuid) : '#'"
                  >
                    {{ t('fleet_ai_index.view_vehicle') }}
                  </VBtn>
                </VCardText>
              </VCard>
            </VTabsWindowItem>

            <VTabsWindowItem value="anomalies">
              <div v-if="!anomalyList.length" class="text-caption text-disabled text-center py-6">
                {{ t('fleet_ai_index.no_anomalies') }}
              </div>
              <VCard
                v-for="a in anomalyList"
                :key="a.id"
                variant="outlined"
                class="mb-2"
                rounded
              >
                <VCardText class="pa-3">
                  <div class="d-flex align-center gap-1 mb-1">
                    <VChip :color="severityColor(a.severity)" size="x-small" label class="text-uppercase">{{ a.severity }}</VChip>
                    <span class="text-caption text-disabled">{{ a.vehicle }}</span>
                  </div>
                  <div class="text-caption mb-2" style="line-height:1.4;">{{ a.description }}</div>
                  <div class="d-flex gap-1 flex-wrap">
                    <VBtn size="x-small" color="success" variant="tonal" @click="reviewAnomaly(a.id, 'legitimate')">{{ t('fleet_ai_index.legitimate') }}</VBtn>
                    <VBtn size="x-small" color="secondary" variant="tonal" @click="reviewAnomaly(a.id, 'false_positive')">{{ t('fleet_ai_index.false_positive') }}</VBtn>
                    <VBtn size="x-small" color="warning" variant="tonal" @click="reviewAnomaly(a.id, 'under_investigation')">{{ t('fleet_ai_index.investigate') }}</VBtn>
                  </div>
                </VCardText>
              </VCard>
            </VTabsWindowItem>
          </VTabsWindow>
        </div>
      </VCard>

    </div>

    <!-- Action confirmation dialog -->
    <VDialog v-model="actionConfirmDialog" max-width="420">
      <VCard :title="t('fleet_ai_index.confirm_action')">
        <VCardText>
          <p>{{ t('fleet_ai_index.confirm_action_message') }}</p>
          <VCode class="mt-2">{{ JSON.stringify(pendingAction?.params, null, 2) }}</VCode>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="actionConfirmDialog = false">{{ t('fleet_ai_index.cancel') }}</VBtn>
          <VBtn color="primary" @click="executeConfirmedAction">{{ t('fleet_ai_index.confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
