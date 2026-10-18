<script setup lang="ts">
import { ref, computed } from 'vue'
import axios from 'axios'

type Employee = { id: number; first_name: string; surname: string }

const props = defineProps<{ employees: Employee[] }>()

const subNav           = ref('hub')

// ── Q&A ──────────────────────────────────────────────────────────────────────
const selectedEmployee = ref<number | null>(null)
const question         = ref('')
const periodStart      = ref(new Date(Date.now() - 30 * 86400000).toISOString().split('T')[0])
const periodEnd        = ref(new Date().toISOString().split('T')[0])
const qaAnswer         = ref<string | null>(null)
const qaLoading        = ref(false)
const qaError          = ref<string | null>(null)
const messages         = ref<{ role: 'user' | 'ai'; text: string }[]>([])

// ── Team Report ───────────────────────────────────────────────────────────────
const teamReportPeriodStart = ref(new Date(Date.now() - 30 * 86400000).toISOString().split('T')[0])
const teamReportPeriodEnd   = ref(new Date().toISOString().split('T')[0])
const teamReport            = ref<string | null>(null)
const teamLoading           = ref(false)

const tab = ref('qa')

async function ask() {
  if (!selectedEmployee.value || !question.value.trim()) return

  const q   = question.value.trim()
  question.value = ''
  messages.value.push({ role: 'user', text: q })
  qaLoading.value = true
  qaError.value   = null

  try {
    const res = await axios.post('/attendance/intelligence/ask', {
      employee_id:  selectedEmployee.value,
      question:     q,
      period_start: periodStart.value,
      period_end:   periodEnd.value,
    })
    messages.value.push({ role: 'ai', text: res.data.answer })
  } catch (e: any) {
    qaError.value = e.response?.data?.message ?? 'AI request failed.'
  } finally {
    qaLoading.value = false
  }
}

async function generateTeamReport() {
  teamLoading.value = true
  teamReport.value  = null

  try {
    const res = await axios.post('/attendance/intelligence/team-report', {
      period_start: teamReportPeriodStart.value,
      period_end:   teamReportPeriodEnd.value,
    })
    teamReport.value = res.data.report
  } catch (e: any) {
    teamReport.value = 'Failed to generate report: ' + (e.response?.data?.message ?? e.message)
  } finally {
    teamLoading.value = false
  }
}

const employeeLabel = computed(() => {
  if (!selectedEmployee.value) return null
  const emp = props.employees.find(e => e.id === selectedEmployee.value)
  return emp ? `${emp.first_name} ${emp.surname}` : null
})

function handleKey(e: KeyboardEvent) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault()
    ask()
  }
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12" class="pb-0">
        <VCard variant="outlined">
          <VTabs v-model="subNav" color="primary" density="compact">
            <VTab value="hub" href="/attendance/intelligence"><VIcon start size="16" icon="tabler-robot" />AI Hub</VTab>
            <VTab value="anomalies" href="/attendance/intelligence/anomalies"><VIcon start size="16" icon="tabler-bug" />Anomaly Detection</VTab>
            <VTab value="risk" href="/attendance/intelligence/risk-scores"><VIcon start size="16" icon="tabler-chart-bar" />Risk Scores</VTab>
            <VTab value="patterns" href="/attendance/intelligence/patterns"><VIcon start size="16" icon="tabler-chart-timeline-variant" />Behaviour Patterns</VTab>
          </VTabs>
        </VCard>
      </VCol>
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">Attendance Intelligence</div>
              <h4 class="text-h4 mb-1">Context-Aware AI Intelligence</h4>
              <p class="mb-0 text-medium-emphasis">Ask questions about attendance in plain English. The AI explains behaviour — it doesn't just flag it.</p>
            </div>
            <div class="d-flex gap-2">
              <VBtn variant="text" color="primary" href="/attendance/intelligence/anomalies">
                <i class="fas fa-bug me-2"></i>Anomalies
              </VBtn>
              <VBtn variant="text" color="secondary" href="/attendance/intelligence/risk-scores">
                <i class="fas fa-chart-bar me-2"></i>Risk Scores
              </VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VTabs v-model="tab" color="primary" class="">
          <VTab value="qa"><i class="fas fa-comments me-2"></i>Employee Q&A</VTab>
          <VTab value="team"><i class="fas fa-users me-2"></i>Team Report</VTab>
        </VTabs>
        <VDivider />

        <VWindow v-model="tab">
          <!-- Employee Q&A -->
          <VWindowItem value="qa">
            <VCard>
              <VCardText class="py-5">
                <VRow>
                  <VCol cols="12" md="4">
                    <VSelect
                      v-model="selectedEmployee"
                      :items="employees.map(e => ({ title: `${e.first_name} ${e.surname}`, value: e.id }))"
                      label="Select Employee *"
                      density="compact"
                      class="mb-3"
                    />
                    <VTextField v-model="periodStart" label="Period Start" type="date" density="compact" class="mb-3" />
                    <VTextField v-model="periodEnd" label="Period End" type="date" density="compact" class="mb-3" />

                    <VCard variant="tonal" color="info" class="pa-3">
                      <div class="text-caption font-weight-medium mb-2">Example questions:</div>
                      <div
                        v-for="q in [
                          'Why is this employee flagged as high risk?',
                          'Explain the repeated lateness pattern.',
                          'Is the absenteeism rate concerning given leave records?',
                          'What actions should a manager take?',
                          'Is there any context that explains the overtime?',
                        ]"
                        :key="q"
                        class="text-caption text-medium-emphasis mb-1 cursor-pointer"
                        style="text-decoration:underline dotted"
                        @click="question = q"
                      >{{ q }}</div>
                    </VCard>
                  </VCol>

                  <VCol cols="12" md="8">
                    <!-- Chat messages -->
                    <div
                      class="d-flex flex-column gap-3 mb-4 pa-3 rounded"
                      style="min-height:280px;max-height:400px;overflow-y:auto;background:rgba(0,0,0,0.03)"
                    >
                      <div v-if="!messages.length" class="text-center text-medium-emphasis py-8">
                        <i class="fas fa-robot" style="font-size:2rem;opacity:0.3"></i>
                        <div class="mt-2">Select an employee and ask a question to start.</div>
                      </div>
                      <template v-for="(msg, idx) in messages" :key="idx">
                        <div :class="msg.role === 'user' ? 'd-flex justify-end' : 'd-flex justify-start'">
                          <VCard
                            :color="msg.role === 'user' ? 'primary' : 'surface'"
                            :variant="msg.role === 'user' ? 'flat' : 'outlined'"
                            class="pa-3"
                            style="max-width:85%"
                          >
                            <div v-if="msg.role === 'ai'" class="text-caption text-medium-emphasis mb-1">
                              <i class="fas fa-robot me-1"></i>AI Intelligence
                            </div>
                            <div style="white-space:pre-wrap;font-size:0.88rem">{{ msg.text }}</div>
                          </VCard>
                        </div>
                      </template>
                      <div v-if="qaLoading" class="d-flex justify-start">
                        <VCard variant="outlined" class="pa-3">
                          <VProgressLinear indeterminate color="primary" rounded />
                          <div class="text-caption text-medium-emphasis mt-1">Analysing attendance data…</div>
                        </VCard>
                      </div>
                    </div>

                    <VAlert v-if="qaError" type="error" variant="tonal" density="compact" class="mb-3">{{ qaError }}</VAlert>

                    <!-- Input -->
                    <div class="d-flex gap-2">
                      <VTextarea
                        v-model="question"
                        label="Ask about this employee's attendance…"
                        rows="2"
                        density="compact"
                        :disabled="!selectedEmployee || qaLoading"
                        hide-details
                        class="flex-grow-1"
                        @keydown="handleKey"
                      />
                      <VBtn
                        color="primary"
                        icon
                        :loading="qaLoading"
                        :disabled="!selectedEmployee || !question.trim()"
                        @click="ask"
                        style="align-self:flex-end;height:56px;width:56px"
                      >
                        <i class="fas fa-paper-plane"></i>
                      </VBtn>
                    </div>
                    <div class="text-caption text-medium-emphasis mt-1">Press Enter to send, Shift+Enter for new line.</div>
                  </VCol>
                </VRow>
              </VCardText>
            </VCard>
          </VWindowItem>

          <!-- Team Report -->
          <VWindowItem value="team">
            <VCard>
              <VCardText class="py-5">
                <VRow>
                  <VCol cols="12" md="6" lg="4">
                    <VTextField v-model="teamReportPeriodStart" label="Period Start" type="date" density="compact" class="mb-3" />
                    <VTextField v-model="teamReportPeriodEnd" label="Period End" type="date" density="compact" class="mb-3" />
                    <VBtn color="primary" :loading="teamLoading" block @click="generateTeamReport">
                      <i class="fas fa-file-alt me-2"></i>Generate Team Report
                    </VBtn>
                    <div class="text-caption text-medium-emphasis mt-2">
                      Generates an AI-written attendance intelligence report for the entire workforce, highlighting risk employees and recommending management actions.
                    </div>
                  </VCol>
                  <VCol cols="12" md="8" lg="8">
                    <VProgressLinear v-if="teamLoading" indeterminate color="primary" class="mb-4" />
                    <VCard v-if="teamReport" variant="outlined" class="pa-4">
                      <div class="d-flex align-center gap-2 mb-3">
                        <VAvatar color="primary" variant="tonal" size="32">
                          <i class="fas fa-robot" style="font-size:14px"></i>
                        </VAvatar>
                        <span class="font-weight-medium">AI Team Attendance Report</span>
                        <VChip size="x-small" color="info" variant="tonal">{{ teamReportPeriodStart }} – {{ teamReportPeriodEnd }}</VChip>
                      </div>
                      <div style="white-space:pre-wrap;font-size:0.9rem;line-height:1.7">{{ teamReport }}</div>
                    </VCard>
                    <div v-else-if="!teamLoading" class="text-center text-medium-emphasis py-12">
                      <i class="fas fa-file-alt" style="font-size:2.5rem;opacity:0.2"></i>
                      <div class="mt-3">Select a period and click Generate to produce an AI team report.</div>
                    </div>
                  </VCol>
                </VRow>
              </VCardText>
            </VCard>
          </VWindowItem>
        </VWindow>
      </VCol>
    </VRow>
  </VContainer>
</template>
