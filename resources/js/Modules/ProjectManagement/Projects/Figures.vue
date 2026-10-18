<script setup lang="ts">
import { computed, ref } from 'vue'
import { useDisplay } from 'vuetify'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t, te } = useI18n()
const route = ziggyRoute

// A dialog capped at 820px is a usable form on a laptop and a letterbox on a phone. The people
// this module is for are frequently holding one, so every dialog here goes full-screen below the
// `sm` breakpoint rather than shrinking a multi-column layout into a column of clipped fields.
const { smAndDown } = useDisplay()

type Figure = {
  id: string; task: string | null; task_code: string | null; metric: string
  value: number | null; text: string | null; confidence: number; version: string
  count: number; computed_at: string | null; overridden: boolean; reason: string | null
}

const props = defineProps<{
  project: { id: string; code: string; name: string }
  figures: Figure[]
  rules: Record<string, Array<{
    kind: string; events: string[]; confidence: number
    because: string; because_key: string
  }>>
  version: string
  metrics: string[]
  can: { override: boolean }
}>()

const metricFilter = ref<string>('progress_pct')

const visible = computed(() => props.figures.filter(f => f.metric === metricFilter.value))

/** Low confidence is not a failure — it is the module being honest about what it is standing on. */
function confidenceColor(value: number) {
  if (value >= 0.9) return 'success'
  if (value >= 0.7) return 'warning'
  return 'error'
}

/**
 * Why a rule exists, in the reader's language.
 *
 * This page is the answer to "why does this task say 62%?", and the person asking is a quantity
 * surveyor arguing about a payment certificate — not the developer who wrote the rule. The English
 * sentence from DerivationRules stays as the fallback so a locale that has not caught up degrades
 * to English rather than to nothing.
 */
function because(rule: { because: string; because_key: string }) {
  const key = `project_management.figures.because.${rule.because_key}`

  return te(key) ? t(key) : rule.because
}

const kindColor: Record<string, string> = {
  verified: 'success', definitive: 'info', measured: 'primary', claimed: 'warning',
}

const recalculating = ref(false)

function recalculate() {
  recalculating.value = true

  router.post(route('project-management.figures.recalculate', props.project.id), {}, {
    preserveScroll: true,
    // Deriving a whole project is not instant; a button that looks idle invites a second click,
    // and the second click is a second full recomputation.
    onFinish: () => { recalculating.value = false },
  })
}

const overrideDialog = ref(false)
const overriding = ref<Figure | null>(null)
const overrideForm = useForm({ value: 0, reason: '' })

function openOverride(figure: Figure) {
  overriding.value = figure
  overrideForm.reset()
  overrideForm.value = figure.value ?? 0
  overrideDialog.value = true
}

function saveOverride() {
  overrideForm.post(route('project-management.figures.override', [props.project.id, overriding.value!.id]), {
    preserveScroll: true,
    onSuccess: () => { overrideDialog.value = false },
  })
}

function clearOverride(figure: Figure) {
  router.delete(route('project-management.figures.override.clear', [props.project.id, figure.id]), {
    preserveScroll: true,
  })
}

const showRules = ref(false)
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-3"
          @click="router.get(route('project-management.projects.show', project.id))">
      {{ project.code }} · {{ project.name }}
    </VBtn>

    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('project_management.figures.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('project_management.figures.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="text" prepend-icon="tabler-list-details" @click="showRules = !showRules">
          {{ t('project_management.figures.how_it_works') }}
        </VBtn>
        <VBtn
          v-if="can.override" color="primary" prepend-icon="tabler-refresh"
          :loading="recalculating" @click="recalculate"
        >
          {{ t('project_management.figures.recalculate') }}
        </VBtn>
      </div>
    </div>

    <!-- The rules, readable. -->
    <VExpandTransition>
      <VCard v-if="showRules" border class="mb-4">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.figures.rules_title') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.figures.rules_intro', { version }) }}</VCardSubtitle>
        </VCardItem>
        <VDivider />
        <VCardText>
          <div v-for="(rules, metric) in rules" :key="metric" class="mb-4">
            <div class="text-body-2 font-weight-medium mb-1">
              {{ t(`project_management.figures.metric_${metric}`) }}
            </div>
            <VTable density="compact">
              <tbody>
                <tr v-for="(rule, i) in rules" :key="i">
                  <td style="width:120px">
                    <VChip size="x-small" label :color="kindColor[rule.kind] ?? 'grey'">
                      {{ t(`project_management.figures.kind_${rule.kind}`) }}
                    </VChip>
                  </td>
                  <td class="text-caption">
                    <!-- Event types are identifiers, shared with the API and the ledger, so they
                         stay as they are in every language — but what each one *means* is
                         translated beside it. -->
                    <VChip v-for="event in rule.events" :key="event" size="x-small" label class="me-1">
                      {{ event }}
                    </VChip>
                  </td>
                  <td class="text-caption">{{ because(rule) }}</td>
                  <td class="text-caption text-right">{{ Math.round(rule.confidence * 100) }}%</td>
                </tr>
              </tbody>
            </VTable>
          </div>
          <p class="text-caption text-medium-emphasis mb-0">{{ t('project_management.figures.precedence_note') }}</p>
        </VCardText>
      </VCard>
    </VExpandTransition>

    <VCard border>
      <VCardItem>
        <VCardTitle class="text-body-1">{{ t('project_management.figures.figures') }}</VCardTitle>
        <template #append>
          <VAutocomplete
            v-model="metricFilter"
            :items="metrics.map(m => ({ title: t(`project_management.figures.metric_${m}`), value: m }))"
            :aria-label="t('project_management.figures.figures')"
            density="compact" variant="outlined" hide-details style="min-width:220px"
          />
        </template>
      </VCardItem>
      <VDivider />

      <VTable v-if="visible.length" density="comfortable">
        <thead>
          <tr>
            <th>{{ t('project_management.tasks.col_name') }}</th>
            <th class="text-right">{{ t('project_management.figures.col_value') }}</th>
            <th class="text-right">{{ t('project_management.figures.col_confidence') }}</th>
            <th class="text-right">{{ t('project_management.figures.col_evidence') }}</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="figure in visible" :key="figure.id">
            <td>
              <span class="text-medium-emphasis me-1">{{ figure.task_code }}</span>{{ figure.task }}
              <div v-if="figure.overridden" class="text-caption text-warning">
                {{ t('project_management.figures.overridden_because', { reason: figure.reason }) }}
              </div>
            </td>
            <td class="text-right">
              {{ figure.value !== null ? figure.value : (figure.text ?? '—') }}
              <VIcon v-if="figure.overridden" icon="tabler-hand-stop" size="14" color="warning" class="ms-1"
                     :title="t('project_management.figures.overridden')" />
            </td>
            <td class="text-right">
              <VChip size="x-small" label :color="confidenceColor(figure.confidence)">
                {{ Math.round(figure.confidence * 100) }}%
              </VChip>
            </td>
            <td class="text-right text-caption">{{ figure.count }}</td>
            <td class="text-right text-no-wrap">
              <VBtn
                size="small" variant="text" icon="tabler-zoom-in"
                :title="t('project_management.figures.drill_down')"
                @click="router.get(route('project-management.figures.show', [project.id, figure.id]))"
              />
              <VBtn
                v-if="can.override && !figure.overridden" size="small" variant="text" icon="tabler-hand-stop"
                :title="t('project_management.figures.override')" @click="openOverride(figure)"
              />
              <VBtn
                v-if="can.override && figure.overridden" size="small" variant="text" icon="tabler-restore"
                :title="t('project_management.figures.clear_override')" @click="clearOverride(figure)"
              />
            </td>
          </tr>
        </tbody>
      </VTable>

      <VCardText v-else class="text-center text-medium-emphasis py-8">
        {{ t('project_management.figures.nothing_yet') }}
      </VCardText>
    </VCard>

    <VDialog :fullscreen="smAndDown" v-model="overrideDialog" max-width="560">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.figures.override') }}</VCardTitle></VCardItem>
        <VDivider />
        <VCardText>
          <VAlert type="warning" variant="tonal" density="compact" class="mb-3">
            {{ t('project_management.figures.override_warning') }}
          </VAlert>
          <VTextField
            v-model.number="overrideForm.value" type="number" inputmode="decimal"
            :label="t('project_management.figures.col_value')"
            :error-messages="overrideForm.errors.value"
            variant="outlined" density="compact" class="mb-3"
          />
          <VTextarea
            v-model="overrideForm.reason" rows="3"
            :label="t('project_management.figures.override_reason')"
            :hint="t('project_management.figures.override_reason_hint')" persistent-hint
            :error-messages="overrideForm.errors.reason"
            variant="outlined" density="compact"
          />
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="overrideDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="warning" :loading="overrideForm.processing" @click="saveOverride">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
