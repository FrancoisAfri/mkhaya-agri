<script lang="ts" setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Question { id: number; type: string; label: string; required?: boolean }
interface Template {
  id: number; key: string; name: string; category: string
  description: string; questions: Question[]; question_count: number
}

const props = defineProps<{ templates: Template[] }>()

// Group templates by category for a tidy library view.
const grouped = computed(() => {
  const map: Record<string, Template[]> = {}
  for (const tpl of props.templates) {
    const cat = tpl.category || 'general'
    ;(map[cat] ??= []).push(tpl)
  }
  return Object.entries(map).sort((a, b) => a[0].localeCompare(b[0]))
})

const busy = ref<number | null>(null)

function useTemplate(tpl: Template) {
  busy.value = tpl.id
  router.post(route('crm.surveys.store'), {
    name: tpl.name,
    category: tpl.category,
    template_key: tpl.key,
  }, {
    onFinish: () => { busy.value = null },
  })
}

const typeLabel = computed<Record<string, string>>(() => ({
  nps: t('crm_surveys_templates.type_nps'),
  csat: t('crm_surveys_templates.type_csat'),
  rating: t('crm_surveys_templates.type_rating'),
  scale: t('crm_surveys_templates.type_scale'),
  choice: t('crm_surveys_templates.type_choice'),
  multichoice: t('crm_surveys_templates.type_multichoice'),
  text: t('crm_surveys_templates.type_text'),
}))
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('crm_surveys_templates.crm') }}</div>
        <h2 class="text-h5 mb-1">{{ t('crm_surveys_templates.page_title') }}</h2>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t('crm_surveys_templates.page_subtitle') }}
        </p>
      </div>
      <VBtn color="primary" variant="tonal" prepend-icon="tabler-plus" :href="route('crm.surveys')">
        {{ t('crm_surveys_templates.back_to_surveys') }}
      </VBtn>
    </div>

    <VAlert v-if="!templates.length" type="info" variant="tonal" class="mb-4">
      {{ t('crm_surveys_templates.no_templates_found') }}
    </VAlert>

    <div v-for="[category, items] in grouped" :key="category" class="mb-6">
      <div class="text-overline text-medium-emphasis mb-2 text-capitalize">{{ category }}</div>
      <VRow>
        <VCol v-for="tpl in items" :key="tpl.id" cols="12" sm="6" md="4">
          <VCard border class="h-100 d-flex flex-column">
            <VCardText class="flex-grow-1">
              <div class="d-flex align-center justify-space-between mb-1">
                <div class="font-weight-bold">{{ tpl.name }}</div>
                <VChip size="x-small" variant="tonal" class="text-capitalize">{{ tpl.category }}</VChip>
              </div>
              <div class="text-caption text-medium-emphasis mb-3">{{ tpl.description }}</div>
              <div class="text-caption text-disabled mb-1">{{ t('crm_surveys_templates.question_count', { count: tpl.question_count }) }}</div>
              <ul class="ps-4 mb-0">
                <li v-for="q in tpl.questions.slice(0, 4)" :key="q.id" class="text-caption text-medium-emphasis">
                  {{ q.label }} <span class="text-disabled">· {{ typeLabel[q.type] ?? q.type }}</span>
                </li>
                <li v-if="tpl.questions.length > 4" class="text-caption text-disabled">{{ t('crm_surveys_templates.more_count', { count: tpl.questions.length - 4 }) }}</li>
              </ul>
            </VCardText>
            <VCardActions class="px-4 pb-4 pt-0">
              <VSpacer />
              <VBtn color="primary" size="small" :loading="busy === tpl.id" prepend-icon="tabler-wand" @click="useTemplate(tpl)">
                {{ t('crm_surveys_templates.use_this_template') }}
              </VBtn>
            </VCardActions>
          </VCard>
        </VCol>
      </VRow>
    </div>
  </div>
</template>
