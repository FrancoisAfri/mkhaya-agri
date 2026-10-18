<script lang="ts" setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface SurveyRow { id: number; name: string; status: string; responses: number }
interface Analysis { summary?: string; sentiment?: string; themes?: string[]; recommendations?: string[]; quick_wins?: string[]; error?: string }

const props = defineProps<{ surveys: SurveyRow[]; selected: number[]; analysis: Analysis | null }>()

const chosen = ref<number[]>([...props.selected])
const running = ref(false)

function analyze() {
  running.value = true
  router.get(route('crm.surveys.recommendations'), { ids: chosen.value.join(',') }, {
    preserveScroll: true, onFinish: () => running.value = false,
  })
}
const sentimentColor = (s?: string) => s === 'positive' ? 'success' : s === 'negative' ? 'error' : 'blue-grey'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center ga-2 mb-4">
      <TipBtn icon="tabler-arrow-left" variant="text" :tip="t('crm_survey_recommendations.back')" :href="route('crm.surveys')" />
      <div>
        <h5 class="text-h5 font-weight-semibold mb-0">{{ t('crm_survey_recommendations.page_title') }}</h5>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_survey_recommendations.description') }}</p>
      </div>
    </div>

    <VRow>
      <VCol cols="12" md="4">
        <VCard :title="t('crm_survey_recommendations.choose_surveys')">
          <VCardText>
            <VList v-if="surveys.length" density="compact">
              <VListItem v-for="s in surveys" :key="s.id">
                <template #prepend>
                  <VCheckbox :model-value="chosen.includes(s.id)" hide-details density="compact"
                    @update:model-value="chosen.includes(s.id) ? chosen = chosen.filter(x => x !== s.id) : chosen.push(s.id)" />
                </template>
                <VListItemTitle>{{ s.name }}</VListItemTitle>
                <VListItemSubtitle>{{ t('crm_survey_recommendations.responses_status', { count: s.responses, status: s.status }) }}</VListItemSubtitle>
              </VListItem>
            </VList>
            <p v-else class="text-medium-emphasis text-body-2">{{ t('crm_survey_recommendations.no_surveys') }}</p>
            <VBtn block color="primary" class="mt-2" prepend-icon="tabler-sparkles" :loading="running" :disabled="!chosen.length" @click="analyze">{{ t('crm_survey_recommendations.analyse') }} {{ chosen.length || '' }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="8">
        <VCard v-if="analysis && !analysis.error">
          <VCardText>
            <div class="d-flex align-center ga-2 mb-2">
              <VIcon icon="tabler-sparkles" color="primary" />
              <span class="text-h6">{{ t('crm_survey_recommendations.ai_analysis') }}</span>
              <VChip v-if="analysis.sentiment" :color="sentimentColor(analysis.sentiment)" size="small" label class="text-capitalize">{{ analysis.sentiment }}</VChip>
            </div>
            <p class="text-body-1 mb-4">{{ analysis.summary }}</p>

            <div v-if="analysis.themes?.length" class="mb-4">
              <div class="text-overline text-medium-emphasis">{{ t('crm_survey_recommendations.key_themes') }}</div>
              <VChip v-for="(t, i) in analysis.themes" :key="i" size="small" variant="tonal" class="me-1 mb-1">{{ t }}</VChip>
            </div>

            <VRow>
              <VCol cols="12" md="7">
                <div class="text-overline text-medium-emphasis">{{ t('crm_survey_recommendations.recommendations') }}</div>
                <ol class="text-body-2 ps-4"><li v-for="(r, i) in analysis.recommendations" :key="i" class="mb-1">{{ r }}</li></ol>
              </VCol>
              <VCol cols="12" md="5">
                <div class="text-overline text-medium-emphasis">{{ t('crm_survey_recommendations.quick_wins') }}</div>
                <ul class="text-body-2 ps-4"><li v-for="(w, i) in analysis.quick_wins" :key="i" class="mb-1">{{ w }}</li></ul>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>
        <VAlert v-else-if="analysis?.error" type="warning" variant="tonal">{{ analysis.error }}</VAlert>
        <VCard v-else><VCardText class="text-medium-emphasis text-center py-10">{{ t('crm_survey_recommendations.placeholder', { strong: t('crm_survey_recommendations.placeholder_strong') }) }}</VCardText></VCard>
      </VCol>
    </VRow>
  </div>
</template>