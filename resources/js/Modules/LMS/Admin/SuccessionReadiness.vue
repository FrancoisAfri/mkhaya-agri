<template>
  <div class="p-3">
    <div class="d-flex align-center gap-3 mb-4 flex-wrap">
      <div>
        <div class="text-h6">{{ t('lms_succession.page_title') }}</div>
        <div class="text-body-2 text-medium-emphasis">{{ t('lms_succession.page_description') }}</div>
      </div>
    </div>

    <VRow>
      <!-- Left: Position selector + gap summary ───────────────────────── -->
      <VCol cols="12" md="4">
        <VCard class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('lms_succession.select_target_position') }}</VCardTitle>
          <VCardText>
            <VSelect
              v-model="selectedPositionId"
              :items="positions"
              item-title="name"
              item-value="id"
              :label="t('lms_succession.position_to_fill')"
              density="compact"
              @update:model-value="loadSuccession"
            />
          </VCardText>
        </VCard>

        <!-- Gap summary across all positions -->
        <VCard>
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('lms_succession.open_gaps_by_role') }}</VCardTitle>
          <div v-if="openGapsSummary.length === 0" class="text-center text-medium-emphasis py-4 text-body-2">
            {{ t('lms_succession.no_open_gaps') }}
          </div>
          <VList density="compact" v-else>
            <VListItem
              v-for="g in openGapsSummary"
              :key="g.position_id"
              rounded="lg"
              class="mx-2 mb-1"
              :class="{ 'bg-primary-subtle': g.position_id === selectedPositionId }"
              @click="selectPosition(g.position_id)"
              style="cursor: pointer"
            >
              <VListItemTitle class="text-body-2 font-weight-medium">{{ g.position_name }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">
                {{ t('lms_succession.gaps_summary', { count: g.gap_count, employees: g.employee_count }) }}
              </VListItemSubtitle>
              <template #append>
                <VChip color="error" size="x-small" variant="tonal">{{ g.gap_count }}</VChip>
              </template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <!-- Right: Succession candidates ──────────────────────────────── -->
      <VCol cols="12" md="8">
        <div v-if="!succession" class="text-center text-medium-emphasis py-10">
          <VIcon size="56" color="grey">mdi-account-arrow-up-outline</VIcon>
          <div class="mt-2">{{ t('lms_succession.no_position_selected') }}</div>
        </div>

        <template v-else>
          <!-- Position requirements summary -->
          <VCard class="mb-4">
            <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2">
              <VIcon color="primary">mdi-briefcase-check</VIcon>
              <span class="text-subtitle-1">{{ succession.position }} — {{ t('lms_succession.required_skills') }}</span>
            </VCardTitle>
            <VCardText class="pt-0">
              <div class="d-flex flex-wrap gap-2">
                <VChip
                  v-for="s in succession.required_skills"
                  :key="s.skill_id"
                  :color="s.is_mandatory ? 'primary' : 'grey'"
                  size="small"
                  variant="tonal"
                >
                  {{ s.skill_name }}
                  <span class="ml-1 font-weight-bold">L{{ s.required_level }}</span>
                  <VIcon v-if="s.is_mandatory" size="12" class="ml-1">mdi-asterisk</VIcon>
                </VChip>
              </div>
              <div class="text-caption text-medium-emphasis mt-2">
                <VIcon size="10">mdi-asterisk</VIcon> = {{ t('lms_succession.mandatory_indicator') }}
              </div>
            </VCardText>
          </VCard>

          <!-- Candidates list -->
          <div v-if="succession.candidates.length === 0" class="text-center text-medium-emphasis py-6">
            {{ t('lms_succession.no_candidates') }}
          </div>

          <VCard v-else>
            <VCardTitle class="pa-4 pb-2 text-subtitle-1">
              {{ t('lms_succession.candidates') }}
              <VChip size="small" color="primary" class="ml-2">
                {{ t('lms_succession.candidate_count', { count: succession.candidates.length }) }}
              </VChip>
            </VCardTitle>

            <VList lines="three">
              <VListItem
                v-for="(candidate, i) in succession.candidates"
                :key="candidate.user_id"
                rounded="lg"
                class="mx-2 mb-2"
                :class="readinessClass(candidate.readiness_pct)"
              >
                <template #prepend>
                  <VAvatar :color="readinessColor(candidate.readiness_pct)" variant="tonal" size="40">
                    <span class="text-body-2 font-weight-bold">{{ t('lms_succession.table.rank') }}{{ i + 1 }}</span>
                  </VAvatar>
                </template>

                <VListItemTitle class="font-weight-medium">{{ candidate.name }}</VListItemTitle>
                <VListItemSubtitle class="text-caption">{{ candidate.current_title }}</VListItemSubtitle>

                <template #append>
                  <div class="d-flex flex-column align-end gap-1" style="min-width: 90px">
                    <div class="text-h6 font-weight-bold" :style="{ color: readinessHex(candidate.readiness_pct) }">
                      {{ candidate.readiness_pct }}%
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      {{ t('lms_succession.gaps', { count: candidate.gap_count }) }}
                    </div>
                    <VBtn size="x-small" variant="tonal" @click="openDetail(candidate)">
                      {{ t('lms_succession.details') }}
                    </VBtn>
                  </div>
                </template>

                <!-- Readiness bar -->
                <div class="mt-2">
                  <VProgressLinear
                    :model-value="candidate.readiness_pct"
                    :color="readinessColor(candidate.readiness_pct)"
                    rounded
                    height="4"
                    bg-color="surface-variant"
                  />
                </div>
              </VListItem>
            </VList>
          </VCard>
        </template>
      </VCol>
    </VRow>

    <!-- Candidate detail dialog -->
    <VDialog v-model="detailDialog" max-width="560" scrollable>
      <VCard v-if="detailCandidate">
        <VCardTitle class="pa-4 d-flex align-center gap-2">
          <VAvatar :color="readinessColor(detailCandidate.readiness_pct)" variant="tonal" size="36">
            <span class="text-body-2 font-weight-bold">{{ detailCandidate.readiness_pct }}%</span>
          </VAvatar>
          <div>
            <div class="text-subtitle-1 font-weight-bold">
              {{ t('lms_succession.detail_dialog.title', { name: detailCandidate.name }) }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('lms_succession.detail_dialog.current_role') }}: {{ detailCandidate.current_title }}
              → {{ t('lms_succession.detail_dialog.target_role') }}: {{ succession?.position }}
            </div>
          </div>
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VList density="compact">
            <VListItem
              v-for="g in detailCandidate.gaps"
              :key="g.skill_id"
              rounded="lg"
              class="mb-1"
            >
              <template #prepend>
                <VAvatar :color="g.meets ? 'success' : 'error'" variant="tonal" size="28">
                  <VIcon size="14">{{ g.meets ? 'mdi-check' : 'mdi-alert' }}</VIcon>
                </VAvatar>
              </template>
              <VListItemTitle class="text-body-2 font-weight-medium">
                {{ g.skill_name }}
                <VIcon v-if="g.is_mandatory" size="12" color="error" class="ml-1">mdi-asterisk</VIcon>
              </VListItemTitle>
              <VListItemSubtitle class="text-caption">
                {{ t('lms_succession.detail_dialog.current_level') }}: <strong>{{ g.current_level }}</strong> /
                {{ t('lms_succession.detail_dialog.required_level') }}: <strong>{{ g.required_level }}</strong>
                <span v-if="g.gap > 0" class="text-error ml-1">
                  ({{ t('lms_succession.detail_dialog.gap_label') }}: {{ g.gap }})
                </span>
              </VListItemSubtitle>

              <!-- Mini progress -->
              <VProgressLinear
                :model-value="g.required_level > 0 ? (g.current_level / g.required_level) * 100 : 0"
                :color="g.meets ? 'success' : 'error'"
                rounded height="3"
                class="mt-1"
              />
            </VListItem>
          </VList>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="detailDialog = false">{{ t('lms_succession.detail_dialog.close') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  succession: any | null
  positions: any[]
  openGapsSummary: any[]
  selectedPosition: number | null
}>()

const selectedPositionId = ref<number | null>(props.selectedPosition)
const detailDialog       = ref(false)
const detailCandidate    = ref<any>(null)

function loadSuccession() {
  router.get(
    route('lms.admin.succession'),
    { position_id: selectedPositionId.value },
    { preserveState: true, replace: true }
  )
}

function selectPosition(id: number) {
  selectedPositionId.value = id
  loadSuccession()
}

function readinessColor(pct: number) {
  return pct >= 80 ? 'success' : pct >= 50 ? 'warning' : 'error'
}

function readinessHex(pct: number) {
  return pct >= 80 ? '#4CAF50' : pct >= 50 ? '#FF9800' : '#F44336'
}

function readinessClass(pct: number) {
  return pct >= 80 ? '' : pct >= 50 ? '' : ''
}

function openDetail(candidate: any) {
  detailCandidate.value = candidate
  detailDialog.value    = true
}
</script>
