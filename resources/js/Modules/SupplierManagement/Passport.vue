<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t, te } = useI18n()
const route = ziggyRoute

interface Tile {
  key: string; label: string; label_key: string; state: 'clear' | 'problem' | 'review' | 'unchecked'
  detail: string | null; as_at: string | null; age_days: number | null
  stale: boolean; source: string | null; has_source: boolean
}
interface CaseAction {
  action: string; label: string; to: string; to_label: string
  slot: string; slot_label: string; requires_reason: boolean
  allowed: boolean; reasons: string[]
}

const props = defineProps<{
  supplier: { id: number; uuid: string; name: string; trading_name: string | null; registration_number: string | null; segment: string | null; is_active: number }
  risk: { score: number; band: string; band_label: string; requires: string; movement: number | null; tier: number | null; auto_approvable: boolean; blockers: string[]; edd_triggers: string[]; calculated_at: string | null } | null
  ddCase: any | null
  tiles: Tile[]
  reputation: { score: number; band: string; coverage: boolean; unreviewed: number; drivers: any[] }
  blacklist: { blocked: boolean; matches: { kind: string; matched: string; reason: string }[] }
  ownership: { ubos: any[]; related: any[] }
  health: { score: number; band: string; covered: boolean; components: Record<string, any> }
  questionnaires: any[]
  questionnairesExpected: string[]
}>()

// Four states, each with its own word and icon. The pair that must never be confused is "clear" and
// "unchecked", so they differ in glyph and label as well as colour.
const stateMeta: Record<string, { colour: string; icon: string; label: string }> = {
  clear: { colour: 'success', icon: 'tabler-circle-check', label: 'Verified' },
  problem: { colour: 'error', icon: 'tabler-alert-triangle', label: 'Problem' },
  review: { colour: 'warning', icon: 'tabler-alert-circle', label: 'Needs review' },
  unchecked: { colour: 'default', icon: 'tabler-circle-dashed', label: 'Never checked' },
}

const bandColour = (band: string) =>
  ({ excellent: 'success', low: 'success', moderate: 'warning', high: 'error', critical: 'error' }[band] ?? 'default')

/*
 * The screen's own wording for a check, falling back to the server's English label. A check added to
 * the engine before anybody writes its twelve translations should read as its English name rather
 * than as a missing-key string down the middle of the page.
 */
function tileLabel(tile: Tile): string {
  const key = `supplier_management.passport.checks.${tile.label_key}`
  return te(key) ? t(key) : tile.label
}

/*
 * Running a check from the tile that says it has not been run.
 *
 * The tiles reported state and offered nothing: a tile reading "never checked · can be run" with no
 * way to run it sends somebody to a different screen to do the thing they were just told was
 * possible. Only offered where a data source is configured — where none is, the honest answer is
 * still that a person has to look.
 */
const running = ref<string | null>(null)
function runCheck(tile: Tile): void {
  running.value = tile.key
  router.post(route('suppliers.verifications.run', props.supplier.uuid), { check_type: tile.key }, {
    preserveScroll: true,
    onFinish: () => (running.value = null),
  })
}

const unchecked = computed(() => props.tiles.filter(x => x.state === 'unchecked'))
const problems = computed(() => props.tiles.filter(x => x.state === 'problem'))

// ── Case actions ─────────────────────────────────────────────────────────────
const acting = ref<CaseAction | null>(null)
const moveForm = useForm<any>({ action: '', reason: '', conditions: [] as string[], conditions_due: '', override: false })
const conditionRows = ref<string[]>([''])

function startAction(action: CaseAction) {
  acting.value = action
  moveForm.clearErrors()
  moveForm.action = action.action
  moveForm.reason = ''
  moveForm.override = false
  moveForm.conditions_due = ''
  conditionRows.value = ['']
}
function submitAction() {
  if (!acting.value || !props.ddCase) return
  moveForm.conditions = conditionRows.value.filter(c => c.trim() !== '')
  moveForm.post(route('suppliers.cases.transition', [props.supplier.uuid, props.ddCase.id ?? 0]), {
    preserveScroll: true,
    onSuccess: () => { acting.value = null },
  })
}
function openCase() {
  useForm({}).post(route('suppliers.cases.open', props.supplier.uuid), { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <!-- Identity and the headline -->
    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('supplier_management.passport.eyebrow') }}</div>
        <h2 class="text-h5 mb-0">{{ supplier.name }}</h2>
        <div class="text-caption text-medium-emphasis">
          {{ supplier.registration_number || '—' }}
          <span v-if="supplier.trading_name"> · {{ supplier.trading_name }}</span>
        </div>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" size="small" prepend-icon="tabler-file-text"
              @click="router.visit(route('suppliers.show', supplier.uuid))">
          {{ t('supplier_management.passport.full_record') }}
        </VBtn>
      </div>
    </div>

    <!-- A bar is the first thing anybody needs to see -->
    <VAlert v-if="blacklist.blocked" type="error" variant="tonal" class="mb-4" prepend-icon="tabler-ban">
      <div class="font-weight-medium">{{ t('supplier_management.passport.blacklisted') }}</div>
      <div v-for="(m, i) in blacklist.matches" :key="i" class="text-caption">
        {{ m.matched }} — {{ m.reason }}
      </div>
    </VAlert>

    <VRow class="mb-2">
      <!-- Overall risk -->
      <VCol cols="12" sm="6" md="3">
        <VCard border height="100%">
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('supplier_management.passport.overall_risk') }}</div>
            <template v-if="risk">
              <div class="d-flex align-center gap-2 mt-1">
                <span class="text-h4" style="font-variant-numeric: tabular-nums">{{ risk.score.toFixed(0) }}</span>
                <VChip size="small" variant="tonal" :color="bandColour(risk.band)">{{ risk.band_label }}</VChip>
                <VChip v-if="risk.movement" size="x-small" variant="tonal" :color="risk.movement > 0 ? 'error' : 'success'">
                  {{ risk.movement > 0 ? '+' : '' }}{{ risk.movement }}
                </VChip>
              </div>
              <div class="text-caption text-medium-emphasis mt-1">
                {{ t('supplier_management.passport.assessed', { at: risk.calculated_at }) }}
                <span v-if="risk.tier"> · {{ t('supplier_management.passport.tier', { n: risk.tier }) }}</span>
              </div>
            </template>
            <div v-else class="text-body-2 mt-2">{{ t('supplier_management.passport.never_assessed') }}</div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Reputation -->
      <VCol cols="12" sm="6" md="3">
        <VCard border height="100%">
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('supplier_management.passport.reputation') }}</div>
            <template v-if="reputation.coverage">
              <div class="d-flex align-center gap-2 mt-1">
                <span class="text-h4" style="font-variant-numeric: tabular-nums">{{ reputation.score.toFixed(0) }}</span>
                <VChip size="small" variant="tonal" :color="bandColour(reputation.band)">
                  {{ t(`supplier_management.reputation.bands.${reputation.band}`) }}
                </VChip>
              </div>
              <div v-if="reputation.unreviewed" class="text-caption text-warning mt-1">
                {{ t('supplier_management.passport.reputation_unreviewed', { n: reputation.unreviewed }) }}
              </div>
            </template>
            <div v-else class="text-body-2 mt-2">{{ t('supplier_management.passport.not_searched') }}</div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Health: how the relationship is going, which is a different question from risk -->
      <VCol cols="12" sm="6" md="3">
        <VCard border height="100%">
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('supplier_management.passport.health') }}</div>
            <template v-if="health.covered">
              <div class="d-flex align-center gap-2 mt-1">
                <span class="text-h4" style="font-variant-numeric: tabular-nums">{{ health.score.toFixed(0) }}%</span>
                <VChip size="small" variant="tonal"
                       :color="health.score >= 70 ? 'success' : (health.score >= 50 ? 'warning' : 'error')">
                  {{ t(`supplier_management.passport.health_bands.${health.band}`) }}
                </VChip>
              </div>
              <div class="text-caption text-medium-emphasis mt-1">{{ t('supplier_management.passport.health_help') }}</div>
            </template>
            <div v-else class="text-body-2 mt-2">{{ t('supplier_management.passport.health_unknown') }}</div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Coverage: the honest headline -->
      <VCol cols="12" sm="6" md="3">
        <VCard border height="100%">
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('supplier_management.passport.coverage') }}</div>
            <div class="text-h4 mt-1" style="font-variant-numeric: tabular-nums">
              {{ tiles.length - unchecked.length }} / {{ tiles.length }}
            </div>
            <div class="text-caption text-medium-emphasis mt-1">
              {{ unchecked.length
                ? t('supplier_management.passport.unchecked', { n: unchecked.length })
                : t('supplier_management.passport.all_checked') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- The tiles -->
    <VCard border class="mb-4">
      <VCardText>
        <VRow dense>
          <VCol v-for="tile in tiles" :key="tile.key" cols="6" sm="4" md="3">
            <div class="pa-3 rounded" style="border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); height: 100%">
              <div class="d-flex align-center gap-2">
                <VIcon :icon="stateMeta[tile.state].icon" :color="stateMeta[tile.state].colour" size="18" />
                <span class="text-body-2 font-weight-medium">{{ tileLabel(tile) }}</span>
              </div>
              <!-- The word matters as much as the colour: "never checked" must not read as "clear" -->
              <div class="text-caption mt-1" :class="`text-${stateMeta[tile.state].colour}`">
                {{ t(`supplier_management.passport.states.${tile.state}`) }}
                <span v-if="tile.stale"> · {{ t('supplier_management.passport.out_of_date') }}</span>
              </div>
              <div v-if="tile.age_days !== null" class="text-caption text-medium-emphasis">
                {{ t('supplier_management.passport.days_ago', { n: tile.age_days }) }}
              </div>
              <div v-else-if="tile.as_at" class="text-caption text-medium-emphasis">{{ tile.as_at }}</div>
              <div v-else-if="tile.state === 'unchecked'" class="text-caption text-medium-emphasis">
                {{ tile.has_source
                  ? t('supplier_management.passport.can_be_run')
                  : t('supplier_management.passport.needs_a_person') }}
              </div>
              <div v-if="tile.detail" class="text-caption text-medium-emphasis mt-1" style="max-width: 30ch">
                {{ tile.detail }}
              </div>
              <!--
                Run it from here. A tile that says a check can be run, with no way to run it, sends
                somebody to another screen to do what they were just told was possible.
              -->
              <VBtn v-if="tile.has_source" size="x-small" variant="tonal" color="primary" class="mt-2"
                    :loading="running === tile.key" @click="runCheck(tile)">
                {{ tile.state === 'unchecked'
                  ? t('supplier_management.passport.run_check')
                  : t('supplier_management.passport.run_again') }}
              </VBtn>
            </div>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VRow>
      <!-- Left column: the case, then what the supplier told us -->
      <VCol cols="12" md="7">
        <VCard border height="100%">
          <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-2">
            <span class="text-subtitle-1">{{ t('supplier_management.passport.case') }}</span>
            <VBtn v-if="!ddCase" size="small" variant="tonal" prepend-icon="tabler-folder-plus" @click="openCase">
              {{ t('supplier_management.passport.open_case') }}
            </VBtn>
          </VCardTitle>
          <VCardText>
            <div v-if="!ddCase" class="text-caption text-medium-emphasis">
              {{ t('supplier_management.passport.no_case') }}
            </div>

            <template v-else>
              <div class="d-flex align-center gap-2 flex-wrap mb-2">
                <VChip size="small" variant="tonal">{{ ddCase.reference }}</VChip>
                <VChip size="small" variant="tonal" color="primary">{{ ddCase.state_label }}</VChip>
                <VChip v-if="ddCase.overdue" size="x-small" variant="tonal" color="error">
                  {{ t('supplier_management.passport.overdue') }}
                </VChip>
                <span v-if="ddCase.due_at" class="text-caption text-medium-emphasis">
                  {{ t('supplier_management.passport.due', { date: ddCase.due_at }) }}
                </span>
              </div>

              <VAlert v-if="ddCase.conditions_lapsed" type="error" variant="tonal" density="compact" class="mb-3">
                {{ t('supplier_management.passport.conditions_lapsed', { date: ddCase.conditions_due }) }}
              </VAlert>
              <VAlert v-else-if="ddCase.conditions?.length" type="info" variant="tonal" density="compact" class="mb-3">
                <div class="font-weight-medium">{{ t('supplier_management.passport.conditions', { date: ddCase.conditions_due }) }}</div>
                <ul class="ps-4 mb-0">
                  <li v-for="(c, i) in ddCase.conditions" :key="i" class="text-caption">{{ c }}</li>
                </ul>
              </VAlert>

              <VAlert v-if="ddCase.outstanding_edd" type="warning" variant="tonal" density="compact" class="mb-3">
                {{ t('supplier_management.passport.outstanding_edd', { n: ddCase.outstanding_edd }) }}
              </VAlert>

              <!-- Moves. A disabled button says why, because one that does not gets reported as broken -->
              <div class="d-flex flex-wrap gap-2 mb-3">
                <VTooltip v-for="a in ddCase.actions" :key="a.action" location="top" :disabled="a.allowed">
                  <template #activator="{ props: tip }">
                    <div v-bind="tip">
                      <VBtn size="small" :variant="a.allowed ? 'tonal' : 'outlined'" :disabled="!a.allowed"
                            :color="a.allowed ? 'primary' : undefined" @click="startAction(a)">
                        {{ a.label }}
                      </VBtn>
                    </div>
                  </template>
                  <span>{{ a.reasons.join(' ') }}</span>
                </VTooltip>
              </div>

              <VExpansionPanels v-if="ddCase.history?.length" variant="accordion">
                <VExpansionPanel>
                  <VExpansionPanelTitle>{{ t('supplier_management.passport.history', { n: ddCase.history.length }) }}</VExpansionPanelTitle>
                  <VExpansionPanelText>
                    <VTimeline side="end" density="compact" truncate-line="both">
                      <VTimelineItem v-for="(h, i) in ddCase.history" :key="i" size="x-small" dot-color="primary">
                        <div class="text-body-2">{{ h.summary }}</div>
                        <div class="text-caption text-medium-emphasis">{{ h.at }}</div>
                      </VTimelineItem>
                    </VTimeline>
                  </VExpansionPanelText>
                </VExpansionPanel>
              </VExpansionPanels>
            </template>
          </VCardText>
        </VCard>
        <!-- What the supplier told us in writing -->
        <VCard v-if="questionnaires.length || questionnairesExpected.length" border class="mt-4">
          <VCardTitle class="text-subtitle-1">{{ t('supplier_management.passport.questionnaires') }}</VCardTitle>
          <VCardText>
            <div v-for="q in questionnaires" :key="q.id" class="mb-3">
              <div class="d-flex align-center justify-space-between flex-wrap gap-2">
                <span class="font-weight-medium">{{ q.title }}</span>
                <div class="d-flex align-center gap-2">
                  <VChip v-if="q.overdue" size="x-small" variant="tonal" color="error">
                    {{ t('supplier_management.passport.overdue') }}
                  </VChip>
                  <VChip size="x-small" variant="tonal">{{ q.completeness }}%</VChip>
                </div>
              </div>
              <VProgressLinear :model-value="q.completeness" height="4" rounded class="mt-1"
                               :color="q.completeness === 100 ? 'success' : 'warning'" />
              <div v-for="(f, i) in q.flags" :key="i" class="text-caption text-error mt-1">
                · {{ f.question }} — {{ t('supplier_management.passport.answered', { answer: f.answer }) }}
              </div>
            </div>

            <!-- Packs this tier calls for that nobody has sent: a gap, stated -->
            <VAlert v-if="questionnairesExpected.length" type="warning" variant="tonal" density="compact">
              {{ t('supplier_management.passport.questionnaires_missing', { packs: questionnairesExpected.join(', ') }) }}
            </VAlert>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Right column: what is standing in the way, and who is behind the company -->
      <VCol cols="12" md="5">
        <VCard border class="mb-4">
          <VCardTitle class="text-subtitle-1">{{ t('supplier_management.passport.standing_in_the_way') }}</VCardTitle>
          <VCardText>
            <div v-if="risk?.auto_approvable" class="text-body-2 text-success">
              <VIcon icon="tabler-circle-check" size="16" /> {{ t('supplier_management.passport.gates_clear') }}
            </div>
            <template v-else-if="risk">
              <div v-for="(b, i) in risk.blockers" :key="i" class="text-caption d-flex gap-2 mb-1">
                <VIcon icon="tabler-point" size="14" color="error" /> <span>{{ b }}</span>
              </div>
            </template>
            <div v-else class="text-caption text-medium-emphasis">{{ t('supplier_management.passport.never_assessed') }}</div>

            <template v-if="risk?.edd_triggers?.length">
              <VDivider class="my-3" />
              <div class="text-caption text-medium-emphasis mb-1">{{ t('supplier_management.passport.edd_because') }}</div>
              <div v-for="(e, i) in risk.edd_triggers" :key="i" class="text-caption">· {{ e }}</div>
            </template>
          </VCardText>
        </VCard>

        <VCard border>
          <VCardTitle class="text-subtitle-1">{{ t('supplier_management.passport.who_is_behind_it') }}</VCardTitle>
          <VCardText>
            <div v-if="!ownership.ubos.length" class="text-caption text-medium-emphasis">
              {{ t('supplier_management.passport.no_ubos') }}
            </div>
            <div v-for="u in ownership.ubos" :key="u.person_id" class="d-flex justify-space-between text-body-2">
              <span>{{ u.name }}</span>
              <span style="font-variant-numeric: tabular-nums">{{ u.effective_percentage }}%</span>
            </div>

            <template v-if="ownership.related.length">
              <VDivider class="my-3" />
              <div class="text-caption text-medium-emphasis mb-1">{{ t('supplier_management.passport.connected') }}</div>
              <div v-for="r in ownership.related" :key="r.supplier_id" class="text-caption">
                · {{ r.supplier }}
              </div>
            </template>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Make a move -->
    <VDialog :model-value="!!acting" max-width="560" @update:model-value="v => { if (!v) acting = null }">
      <VCard v-if="acting">
        <VCardTitle>{{ acting.label }}</VCardTitle>
        <VCardText>
          <div class="text-caption text-medium-emphasis mb-3">
            {{ t('supplier_management.passport.moves_to', { state: acting.to_label, slot: acting.slot_label }) }}
          </div>

          <template v-if="acting.action === 'approve_conditionally'">
            <div class="text-subtitle-2 mb-1">{{ t('supplier_management.passport.conditions_label') }}</div>
            <div v-for="(c, i) in conditionRows" :key="i" class="d-flex gap-2 mb-2">
              <VTextField v-model="conditionRows[i]" density="compact" variant="outlined" hide-details
                          :label="t('supplier_management.passport.condition')" />
              <VBtn icon="tabler-plus" size="small" variant="text"
                    :aria-label="t('supplier_management.passport.add_condition')"
                    @click="conditionRows.push('')" />
            </div>
            <VTextField v-model="moveForm.conditions_due" type="date" density="comfortable" variant="outlined" class="mb-3"
                        :label="t('supplier_management.passport.conditions_due')"
                        :hint="t('supplier_management.passport.conditions_due_help')" persistent-hint
                        :error-messages="moveForm.errors.conditions_due" />
          </template>

          <VTextarea v-model="moveForm.reason" rows="3" density="comfortable" variant="outlined"
                     :label="acting.requires_reason ? t('supplier_management.passport.reason_required') : t('supplier_management.passport.reason')"
                     :error-messages="moveForm.errors.reason" />

          <VCheckbox v-if="!acting.allowed" v-model="moveForm.override" density="compact"
                     :label="t('supplier_management.passport.override')" :error-messages="moveForm.errors.action" />
          <div v-if="!acting.allowed" class="text-caption text-medium-emphasis">
            {{ acting.reasons.join(' ') }} {{ t('supplier_management.passport.override_help') }}
          </div>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="acting = null">{{ t('common.cancel', 'Cancel') }}</VBtn>
          <VBtn color="primary" :loading="moveForm.processing" @click="submitAction">
            {{ acting.label }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
