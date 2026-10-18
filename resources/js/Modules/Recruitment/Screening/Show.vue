<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Chip = { value: string | null; label: string | null; color: string | null }
type Finding = {
  type: string; category: string; severity: string; confidence: number | null
  source: string | null; title: string; excerpt: string | null
  evidence_url: string | null; ai_explanation: string | null; detected_at: string | null
}
type Question = { question: string; rationale: string | null; based_on: string | null }
type Screening = {
  uuid: string; status: Chip
  overall_score: number | null; reputation_score: number | null; professionalism_score: number | null
  communication_score: number | null; leadership_score: number | null; community_score: number | null
  risk_level: Chip | null; ai_summary: string | null; recommendation: string | null
  possible_misidentification: boolean; discovered_profiles: any[]; analyzed_at: string | null
  findings: Finding[]; questions: Question[]
}
type Candidate = { id: number; uuid: string; full_name: string; email: string; has_consent: boolean }

const props = defineProps<{ candidate: Candidate; screening: Screening | null }>()

const scoreCards = computed(() => props.screening ? [
  { key: 'overall',        icon: 'tabler-gauge',        value: props.screening.overall_score },
  { key: 'reputation',     icon: 'tabler-shield-check', value: props.screening.reputation_score },
  { key: 'professionalism',icon: 'tabler-briefcase',    value: props.screening.professionalism_score },
  { key: 'communication',  icon: 'tabler-messages',     value: props.screening.communication_score },
  { key: 'leadership',     icon: 'tabler-flag',         value: props.screening.leadership_score },
  { key: 'community',      icon: 'tabler-users-group',  value: props.screening.community_score },
] : [])

function fmtScore(v: number | null) { return v === null || v === undefined ? '—' : Math.round(Number(v)).toString() }
function scoreColor(v: number | null) { if (v === null) return 'default'; const n = Number(v); return n >= 70 ? 'success' : n >= 40 ? 'warning' : 'error' }
function findingColor(type: string) { return type === 'risk' ? 'error' : type === 'professional' ? 'success' : 'info' }
function severityColor(s: string) { return ['high', 'critical'].includes(s) ? 'error' : s === 'moderate' ? 'warning' : s === 'low' ? 'info' : 'default' }

function rescreen() {
  if (!confirm(t('recruitment_screening_show.confirm_rescreen'))) return
  router.post(route('recruitment.candidates.screening.rescreen', props.candidate.id), {}, { preserveScroll: true })
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center gap-2 mb-4">
      <VBtn icon="tabler-arrow-left" variant="text" size="small" @click="router.visit(route('recruitment.candidates.show', candidate.id))" />
      <span class="text-medium-emphasis text-body-2">{{ t('recruitment_screening_show.breadcrumb_candidates') }}</span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span class="text-body-2 font-weight-medium">{{ candidate.full_name }}</span>
    </div>

    <div class="d-flex align-start justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <h4 class="font-weight-bold mb-1" style="font-size: 1.5rem;">{{ t('recruitment_screening_show.title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ candidate.full_name }} · {{ candidate.email }}</p>
      </div>
      <VBtn color="primary" variant="flat" prepend-icon="tabler-refresh" size="small" :disabled="!candidate.has_consent" @click="rescreen">
        {{ t('recruitment_screening_show.rescreen') }}
      </VBtn>
    </div>

    <!-- Prominent human-review disclaimer -->
    <VAlert type="info" variant="tonal" density="comfortable" class="mb-4" icon="tabler-scale">
      <p class="font-weight-medium mb-1">{{ t('recruitment_screening_show.disclaimer_title') }}</p>
      <p class="text-body-2 mb-0">{{ t('recruitment_screening_show.disclaimer_body') }}</p>
    </VAlert>

    <!-- No screening yet -->
    <VCard v-if="!screening" elevation="0" border style="border-radius: 12px;">
      <VCardText class="pa-8 text-center">
        <VIcon icon="tabler-search" size="40" class="text-medium-emphasis mb-3" />
        <p class="text-body-1 font-weight-medium mb-1">{{ t('recruitment_screening_show.empty_title') }}</p>
        <p class="text-body-2 text-medium-emphasis mb-4">
          {{ candidate.has_consent ? t('recruitment_screening_show.empty_body') : t('recruitment_screening_show.empty_no_consent') }}
        </p>
        <VBtn v-if="candidate.has_consent" color="primary" variant="flat" prepend-icon="tabler-search" size="small" @click="rescreen">
          {{ t('recruitment_screening_show.run_screening') }}
        </VBtn>
      </VCardText>
    </VCard>

    <template v-else>
      <!-- Consent-skipped state -->
      <VAlert v-if="screening.status.value === 'skipped_no_consent'" type="warning" variant="tonal" class="mb-4">
        {{ t('recruitment_screening_show.status_skipped_no_consent') }}
      </VAlert>

      <!-- Misidentification caveat -->
      <VAlert v-if="screening.possible_misidentification" type="warning" variant="tonal" density="comfortable" class="mb-4" icon="tabler-user-question">
        {{ t('recruitment_screening_show.misidentification_caveat') }}
      </VAlert>

      <!-- Status + risk header -->
      <div class="d-flex align-center gap-2 mb-4 flex-wrap">
        <VChip v-if="screening.status.color" :color="screening.status.color" size="small" label variant="tonal">{{ screening.status.label }}</VChip>
        <VChip v-if="screening.risk_level" :color="screening.risk_level.color" size="small" label variant="tonal" prepend-icon="tabler-alert-triangle">
          {{ t('recruitment_screening_show.risk_label') }}: {{ screening.risk_level.label }}
        </VChip>
        <span v-if="screening.analyzed_at" class="text-caption text-medium-emphasis">{{ t('recruitment_screening_show.analyzed_at') }} {{ screening.analyzed_at }}</span>
      </div>

      <!-- Score cards -->
      <VRow class="mb-2">
        <VCol v-for="card in scoreCards" :key="card.key" cols="6" sm="4" md="2">
          <VCard elevation="0" border style="border-radius: 12px;">
            <VCardText class="pa-4 text-center">
              <VIcon :icon="card.icon" size="22" :color="scoreColor(card.value)" class="mb-2" />
              <p class="text-h5 font-weight-bold mb-0" :class="`text-${scoreColor(card.value)}`">{{ fmtScore(card.value) }}</p>
              <p class="text-caption text-medium-emphasis mb-0">{{ t(`recruitment_screening_show.score_${card.key}`) }}</p>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <VRow>
        <VCol cols="12" lg="8">
          <!-- AI summary -->
          <VCard elevation="0" border class="mb-4" style="border-radius: 12px;">
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
              <VIcon icon="tabler-robot" size="18" color="primary" class="me-2" />{{ t('recruitment_screening_show.section_summary') }}
            </VCardTitle>
            <VCardText class="pa-5 pt-0">
              <p class="text-body-2 mb-3" style="white-space: pre-wrap;">{{ screening.ai_summary || t('recruitment_screening_show.no_summary') }}</p>
              <template v-if="screening.recommendation">
                <VDivider class="mb-3" />
                <p class="text-caption text-medium-emphasis mb-1">{{ t('recruitment_screening_show.context_note') }}</p>
                <VChip color="info" size="small" label variant="tonal">{{ screening.recommendation }}</VChip>
              </template>
            </VCardText>
          </VCard>

          <!-- Findings -->
          <VCard elevation="0" border class="mb-4" style="border-radius: 12px;">
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
              <VIcon icon="tabler-list-search" size="18" color="primary" class="me-2" />{{ t('recruitment_screening_show.section_findings') }}
            </VCardTitle>
            <VCardText class="pa-5 pt-2">
              <p v-if="!screening.findings.length" class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_screening_show.no_findings') }}</p>
              <div v-for="(f, i) in screening.findings" :key="i" class="mb-4">
                <div class="d-flex align-center gap-2 mb-1 flex-wrap">
                  <VChip :color="findingColor(f.type)" size="x-small" label variant="tonal">{{ t(`recruitment_screening_show.type_${f.type}`) }}</VChip>
                  <VChip :color="severityColor(f.severity)" size="x-small" label variant="outlined">{{ f.severity }}</VChip>
                  <span class="text-body-2 font-weight-medium">{{ f.title }}</span>
                  <span v-if="f.confidence !== null" class="text-caption text-medium-emphasis">· {{ t('recruitment_screening_show.confidence') }} {{ Math.round(Number(f.confidence)) }}%</span>
                </div>
                <p v-if="f.excerpt" class="text-body-2 text-medium-emphasis mb-1" style="white-space: pre-wrap;">{{ f.excerpt }}</p>
                <p v-if="f.ai_explanation" class="text-caption mb-1">{{ f.ai_explanation }}</p>
                <div class="d-flex align-center gap-2 text-caption text-medium-emphasis">
                  <span v-if="f.source"><VIcon icon="tabler-world" size="14" /> {{ f.source }}</span>
                  <a v-if="f.evidence_url" :href="f.evidence_url" target="_blank" rel="noopener noreferrer" class="text-primary">{{ t('recruitment_screening_show.view_source') }}</a>
                  <span v-if="f.detected_at">· {{ f.detected_at }}</span>
                </div>
                <VDivider v-if="i < screening.findings.length - 1" class="mt-3" />
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12" lg="4">
          <!-- Interview questions -->
          <VCard elevation="0" border class="mb-4" style="border-radius: 12px;">
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
              <VIcon icon="tabler-message-question" size="18" color="primary" class="me-2" />{{ t('recruitment_screening_show.section_questions') }}
            </VCardTitle>
            <VCardText class="pa-5 pt-2">
              <p class="text-caption text-medium-emphasis mb-3">{{ t('recruitment_screening_show.questions_hint') }}</p>
              <p v-if="!screening.questions.length" class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_screening_show.no_questions') }}</p>
              <div v-for="(q, i) in screening.questions" :key="i" class="mb-3">
                <p class="text-body-2 font-weight-medium mb-1">{{ i + 1 }}. {{ q.question }}</p>
                <p v-if="q.rationale" class="text-caption text-medium-emphasis mb-0">{{ q.rationale }}</p>
                <VChip v-if="q.based_on" size="x-small" label variant="text" class="px-0">{{ q.based_on }}</VChip>
              </div>
            </VCardText>
          </VCard>

          <!-- Discovered sources -->
          <VCard elevation="0" border class="mb-4" style="border-radius: 12px;">
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
              <VIcon icon="tabler-link" size="18" color="primary" class="me-2" />{{ t('recruitment_screening_show.section_sources') }}
            </VCardTitle>
            <VCardText class="pa-5 pt-2">
              <p v-if="!screening.discovered_profiles.length" class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_screening_show.no_sources') }}</p>
              <div v-for="(p, i) in screening.discovered_profiles" :key="i" class="d-flex align-center gap-2 mb-2">
                <VIcon icon="tabler-world" size="14" class="text-medium-emphasis" />
                <a v-if="p.url" :href="p.url" target="_blank" rel="noopener noreferrer" class="text-primary text-body-2">{{ p.source || p.url }}</a>
                <span v-else class="text-body-2">{{ p.source }}</span>
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>
