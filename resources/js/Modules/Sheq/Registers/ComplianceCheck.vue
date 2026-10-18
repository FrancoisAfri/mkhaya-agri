<script setup lang="ts">
/**
 * May this person do this work, at this site, right now (COM-1)?
 *
 * The screen exists because an answer nobody can see the working of is an answer nobody acts on.
 * A status column says NON_COMPLIANT; it cannot say which certificate. A boom says no; it cannot
 * say what to bring tomorrow. So this shows every check the engine ran — the passes and the
 * not-applicables as well as the failures, because a list of only failures cannot tell you
 * whether something was checked and fine or never looked at.
 */
import { computed, ref } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'
import { useReasonLabel } from '@/Modules/Sheq/Partials/useReasonLabel'

type Check = {
  key: string
  state: 'PASS' | 'FAIL' | 'UNVERIFIED' | 'NOT_APPLICABLE'
  note: string
  evidence: Record<string, unknown>
  findings: { code: string; severity: string; detail: string }[]
}

type Decision = {
  outcome: 'ALLOWED' | 'DENIED' | 'REVIEW_REQUIRED' | 'UNKNOWN'
  allowed: boolean
  codes: string[]
  reasons: string[]
  checks: Check[]
  advisories: { code: string; detail: string }[]
  unverified: string[]
  subject: { worker_name: string | null; contractor_name: string | null }
  question: { site_id: number | null; activity: string | null; at: string }
}

const props = defineProps<{
  sites: { id: number; name: string }[]
  activities: { code: string; name: string; is_high_risk: boolean }[]
  workers: { uuid: string; name: string; trade: string | null; contractor_name: string }[]
  checkCatalogue: { key: string; description: string }[]
  unverifiedOptions: string[]
  unverifiedOutcome: string
  defaultUnverified: string
  contractorFilter?: string | null
  canManage?: boolean
  noCompany?: boolean
}>()

const reasonLabel = useReasonLabel()

const page = usePage()
const decision = computed(() => (page.props.flash as any)?.decision as Decision | undefined)

const form = useForm({
  worker_uuid: null as string | null,
  site_id: null as number | null,
  activity: null as string | null,
  at: '',
})

const workerItems = computed(() => props.workers.map(w => ({
  value: w.uuid,
  // The employer belongs in the label, not a second column: two contractors on one site
  // routinely send a Thabo Mokoena each, and picking the wrong one answers the wrong question.
  title: `${w.name} — ${w.contractor_name}${w.trade ? ` · ${w.trade}` : ''}`,
})))

function run() {
  form.post('/sheq/compliance/check', { preserveScroll: true, preserveState: true })
}

const failSafe = useForm({ outcome: props.unverifiedOutcome })

function saveFailSafe() {
  failSafe.post('/sheq/compliance/fail-safe', { preserveScroll: true })
}

const band = computed(() => {
  switch (decision.value?.outcome) {
    case 'ALLOWED': return { colour: 'success', icon: 'mdi-check-circle-outline', label: 'May work' }
    case 'DENIED': return { colour: 'error', icon: 'mdi-close-circle-outline', label: 'May not work' }
    default: return { colour: 'warning', icon: 'mdi-help-circle-outline', label: 'Cannot be answered' }
  }
})

const stateIcon = (state: Check['state']) => ({
  PASS: 'mdi-check',
  FAIL: 'mdi-close',
  UNVERIFIED: 'mdi-alert',
  NOT_APPLICABLE: 'mdi-minus',
}[state])

const stateColour = (state: Check['state']) => ({
  PASS: 'success',
  FAIL: 'error',
  UNVERIFIED: 'warning',
  NOT_APPLICABLE: 'grey',
}[state])

const label = (key: string) =>
  key.toLowerCase().replace(/_/g, ' ').replace(/^./, c => c.toUpperCase())

const failSafeExplanation: Record<string, string> = {
  REVIEW_REQUIRED: 'Neither approved nor refused. Whoever is at the gate is told to ask somebody — the safe answer when nobody actually knows.',
  DENIED: 'Refused. Safest on paper, and it means an unreachable database turns a crew away: expect that to happen on the morning you least want it to.',
  UNKNOWN: 'Recorded and not refused. For an unstaffed reader with nobody to escalate to, where the honest choice is to say what happened rather than pretend it was checked.',
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">Compliance check</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">
        May this person do this work, at this site, right now — and exactly why not
      </p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VRow>
        <!-- ── The question ─────────────────────────────────────────────────────────────── -->
        <VCol cols="12" md="4">
          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">The question</VCardTitle>
            <VDivider />
            <VCardText class="d-flex flex-column ga-3">
              <VAutocomplete
                v-model="form.worker_uuid" :items="workerItems" density="compact"
                variant="outlined" hide-details="auto" label="Person"
                :error-messages="form.errors.worker_uuid" clearable
                :hint="contractorFilter ? `Showing ${contractorFilter}'s roster only.` : undefined"
                :persistent-hint="!!contractorFilter"
              />
              <VSelect
                v-model="form.site_id"
                :items="sites.map(s => ({ value: s.id, title: s.name }))"
                density="compact" variant="outlined" hide-details clearable label="Site"
                hint="Leave empty and no site approval is checked — the answer says so."
                persistent-hint
              />
              <VSelect
                v-model="form.activity"
                :items="activities.map(a => ({
                  value: a.code,
                  title: a.is_high_risk ? `${a.name} — high risk` : a.name,
                }))"
                density="compact" variant="outlined" hide-details clearable label="Work"
              />
              <VTextField
                v-model="form.at" type="datetime-local" density="compact" variant="outlined"
                hide-details label="At"
                hint="Empty means now. A past moment answers an audit."
                persistent-hint
              />
              <VBtn
                color="primary" variant="flat" block :loading="form.processing"
                :disabled="!form.worker_uuid" @click="run"
              >
                Check
              </VBtn>
            </VCardText>
          </VCard>

          <!-- ── COM-3 ─────────────────────────────────────────────────────────────────── -->
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              When a check cannot be run
            </VCardTitle>
            <VDivider />
            <VCardText>
              <p class="text-body-2 text-medium-emphasis">
                Evidence that cannot be read is not evidence that is missing, and it is never a
                pass. What it does instead is your decision.
              </p>

              <VRadioGroup v-model="failSafe.outcome" hide-details :disabled="!canManage">
                <VRadio v-for="option in unverifiedOptions" :key="option" :value="option">
                  <template #label>
                    <div>
                      <div class="text-body-2 font-weight-medium">
                        {{ label(option) }}
                        <span v-if="option === defaultUnverified"
                              class="text-caption text-medium-emphasis">· shipped default</span>
                      </div>
                      <div class="text-caption text-medium-emphasis">
                        {{ failSafeExplanation[option] }}
                      </div>
                    </div>
                  </template>
                </VRadio>
              </VRadioGroup>

              <div v-if="canManage && failSafe.outcome !== unverifiedOutcome"
                   class="d-flex justify-end mt-2">
                <VBtn color="primary" variant="flat" size="small"
                      :loading="failSafe.processing" @click="saveFailSafe">
                  Save
                </VBtn>
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <!-- ── The answer ───────────────────────────────────────────────────────────────── -->
        <VCol cols="12" md="8">
          <VCard v-if="decision" elevation="0" border rounded="lg" class="mb-4">
            <VAlert :type="band.colour === 'success' ? 'success'
                      : band.colour === 'error' ? 'error' : 'warning'"
                    variant="tonal" rounded="0" prominent :icon="band.icon">
              <div class="text-h6 font-weight-bold">{{ band.label }}</div>
              <div class="text-body-2">
                {{ decision.subject.worker_name }}
                <template v-if="decision.subject.contractor_name">
                  · {{ decision.subject.contractor_name }}
                </template>
                <template v-if="decision.question.activity">
                  · {{ decision.question.activity.toLowerCase().replace(/_/g, ' ') }}
                </template>
                · {{ decision.question.at }}
              </div>
            </VAlert>

            <VCardText v-if="decision.reasons.length">
              <!-- The sentence a guard reads aloud. "Denied" at a boom with a queue behind it
                   starts an argument; a date and a certificate name ends one. -->
              <div class="text-subtitle-2 font-weight-bold mb-2">Refused because</div>
              <ul class="pl-5">
                <li v-for="(reason, i) in decision.reasons" :key="i" class="text-body-2 mb-1">
                  {{ reason }}
                </li>
              </ul>
              <div class="d-flex flex-wrap ga-1 mt-3">
                <!-- The codes, for whatever has to count, chart or route this (COM-2). -->
                <!-- The words for a reader, the code in the tooltip for anybody who has to
                     quote it into a ticket or match it against a log. -->
                <VTooltip v-for="code in decision.codes" :key="code" :text="code">
                  <template #activator="{ props: tip }">
                    <VChip v-bind="tip" size="x-small" variant="tonal" color="error">
                      {{ reasonLabel(code) }}
                    </VChip>
                  </template>
                </VTooltip>
              </div>
            </VCardText>

            <VCardText v-if="decision.unverified.length" class="pt-0">
              <VAlert type="warning" variant="tonal" density="compact">
                Could not be checked: {{ decision.unverified.map(label).join(', ') }}.
                This is not a pass and not a refusal.
              </VAlert>
            </VCardText>

            <VDivider />
            <VCardText class="pb-1">
              <div class="text-subtitle-2 font-weight-bold">How this was decided</div>
              <div class="text-caption text-medium-emphasis">
                Every check, including the ones that passed and the ones that did not apply —
                because "checked and fine" and "never looked" are different facts.
              </div>
            </VCardText>

            <VTable density="compact">
              <tbody>
                <tr v-for="check in decision.checks" :key="check.key">
                  <td style="width: 40px">
                    <VIcon :icon="stateIcon(check.state)" :color="stateColour(check.state)"
                           size="20" />
                  </td>
                  <td style="width: 190px" class="text-body-2">{{ label(check.key) }}</td>
                  <td class="text-body-2 text-medium-emphasis">
                    {{ check.note }}
                    <div v-if="check.findings.length" class="d-flex flex-wrap ga-1 mt-1">
                      <VChip v-for="(f, i) in check.findings" :key="i" size="x-small"
                             variant="tonal" :title="f.code"
                             :color="f.severity === 'BLOCKING' ? 'error' : 'grey'">
                        {{ reasonLabel(f.code) }}
                        <span v-if="f.severity !== 'BLOCKING'">· not refusing</span>
                      </VChip>
                    </div>
                  </td>
                </tr>
              </tbody>
            </VTable>

            <template v-if="decision.advisories.length">
              <VDivider />
              <VCardText>
                <div class="text-subtitle-2 font-weight-bold mb-2">
                  Noted, not refusing entry
                </div>
                <ul class="pl-5">
                  <li v-for="(a, i) in decision.advisories" :key="i"
                      class="text-body-2 text-medium-emphasis mb-1">
                    {{ a.detail }}
                  </li>
                </ul>
              </VCardText>
            </template>
          </VCard>

          <!-- Before anybody has asked anything, the page still has something to say: what this
               system actually looks at. That list is the argument for trusting the answer. -->
          <VCard v-else elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              What a check looks at
            </VCardTitle>
            <VDivider />
            <VTable density="compact">
              <tbody>
                <tr v-for="item in checkCatalogue" :key="item.key">
                  <td style="width: 210px" class="text-body-2">{{ label(item.key) }}</td>
                  <td class="text-body-2 text-medium-emphasis">{{ item.description }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>
