<script setup lang="ts">
import { computed } from 'vue'
import { useForm, router, Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Sub { id: number; uuid: string; reference: string; tax_type: string; period: string; status: string; version: number; totals: any; payload: any; validation: any; notes: string | null; has_file: boolean; generated_at: string | null; submitted_at: string | null }
interface Event {
  id: number; description: string; from_status: string | null; to_status: string
  note: string | null; actor: string | null; at: string | null
}

const props = defineProps<{
  submission: Sub
  // Only the moves this return can legitimately make next — not all six statuses.
  statuses: string[]
  isTerminal: boolean
  events: Event[]
  versions: { id: number; reference: string; version: number; status: string; generated_at: string | null }[]
}>()

const statusColor: Record<string, string> = { draft: 'grey', generated: 'info', validated: 'primary', submitted: 'warning', accepted: 'success', rejected: 'error' }
const typeLabel = (v: string) => t('sars.type_' + v)
const money = (n: any) => 'R ' + Number(n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2 })

// The first move available, not the status it is already on: the current status is deliberately
// not offered, so defaulting to it would leave the control empty and the button doing nothing.
const statusForm = useForm({ status: props.statuses[0] ?? '', notes: props.submission.notes ?? '' })
const canMove = computed(() => !props.isTerminal && props.statuses.length > 0)
function saveStatus() { statusForm.post(route('finance.accounting.sars.submissions.status', props.submission.id), { preserveScroll: true }) }
</script>

<template>
  <div class="pa-4" style="max-width: 1100px; margin: 0 auto">
    <Link :href="route('finance.accounting.sars.submissions')"><VBtn size="small" variant="text" prepend-icon="tabler-arrow-left" class="mb-2">{{ t('sars.back') }}</VBtn></Link>

    <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
      <div class="d-flex align-center gap-3">
        <h1 class="text-h5 font-weight-bold mb-0">{{ submission.reference }}</h1>
        <VChip size="small" variant="tonal">{{ typeLabel(submission.tax_type) }}</VChip>
        <VChip size="small" :color="statusColor[submission.status]" variant="tonal">{{ t('sars.st_' + submission.status) }}</VChip>
        <span class="text-caption text-medium-emphasis">{{ submission.period }} · v{{ submission.version }}</span>
      </div>
      <VBtn v-if="submission.has_file" :href="route('finance.accounting.sars.submissions.download', submission.id)" color="primary" variant="tonal" prepend-icon="tabler-download">{{ t('sars.download') }}</VBtn>
    </div>

    <VRow>
      <!-- Figures -->
      <VCol cols="12" md="7">
        <VCard border rounded="lg" class="mb-4">
          <VCardTitle class="text-subtitle-1 font-weight-semibold">{{ t('sars.figures') }}</VCardTitle>
          <VCardText>
            <div v-for="(val, key) in (submission.totals || {})" :key="key" class="d-flex justify-space-between py-1 border-b">
              <span class="text-medium-emphasis text-capitalize">{{ String(key).replace(/_/g, ' ') }}</span>
              <span class="font-weight-medium">{{ typeof val === 'number' ? money(val) : val }}</span>
            </div>
            <div v-if="!submission.totals || !Object.keys(submission.totals).length" class="text-caption text-medium-emphasis py-4 text-center">{{ t('sars.no_figures') }}</div>
          </VCardText>
        </VCard>

        <VCard v-if="submission.validation?.errors?.length" border rounded="lg">
          <VCardTitle class="text-subtitle-1 font-weight-semibold text-error">{{ t('sars.validation_at_gen') }}</VCardTitle>
          <VCardText>
            <div v-for="(e, i) in submission.validation.errors" :key="i" class="text-body-2 py-1">
              <strong>{{ e.entity }}</strong> — {{ e.field }}: {{ e.message }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Status + version history -->
      <VCol cols="12" md="5">
        <VCard border rounded="lg" class="mb-4">
          <VCardTitle class="text-subtitle-1 font-weight-semibold">{{ t('sars.status') }}</VCardTitle>
          <VCardText>
            <template v-if="canMove">
              <VSelect
                v-model="statusForm.status"
                :items="statuses.map(s => ({ title: t('sars.st_' + s), value: s }))"
                :label="t('sars.move_to')"
                variant="outlined" density="comfortable" class="mb-2"
                :error-messages="statusForm.errors.status"
              />
              <VTextarea v-model="statusForm.notes" :label="t('sars.notes')" variant="outlined" density="comfortable" rows="2" auto-grow />
              <VBtn color="primary" block :loading="statusForm.processing" class="mt-2" @click="saveStatus">{{ t('sars.update_status') }}</VBtn>
            </template>
            <VAlert v-else type="info" variant="tonal" density="compact">
              {{ submission.status === 'accepted' ? t('sars.accepted_final') : t('sars.rejected_final') }}
            </VAlert>
          </VCardText>
        </VCard>

        <VCard v-if="events.length" border rounded="lg" class="mb-4">
          <VCardTitle class="text-subtitle-1 font-weight-semibold">{{ t('sars.trail') }}</VCardTitle>
          <VList density="compact">
            <VListItem v-for="e in events" :key="e.id">
              <VListItemTitle class="text-body-2">
                {{ e.from_status ? t('sars.moved', { from: t('sars.st_' + e.from_status), to: t('sars.st_' + e.to_status) }) : t('sars.st_' + e.to_status) }}
              </VListItemTitle>
              <VListItemSubtitle class="text-caption">
                {{ [e.actor, e.at].filter(Boolean).join(' · ') || '—' }}
              </VListItemSubtitle>
              <div v-if="e.note" class="text-caption text-medium-emphasis mt-1">{{ e.note }}</div>
            </VListItem>
          </VList>
        </VCard>

        <VCard border rounded="lg">
          <VCardTitle class="text-subtitle-1 font-weight-semibold">{{ t('sars.version_history') }}</VCardTitle>
          <VList density="compact">
            <VListItem v-for="v in versions" :key="v.id" :active="v.id === submission.id" @click="router.get(route('finance.accounting.sars.submissions.show', v.id))">
              <VListItemTitle class="text-body-2">v{{ v.version }} · {{ v.reference }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">{{ t('sars.st_' + v.status) }} · {{ v.generated_at ?? '—' }}</VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>

<style scoped>
.border-b { border-bottom: 1px solid rgba(127,127,127,.12); }
.border-b:last-child { border-bottom: none; }
</style>
