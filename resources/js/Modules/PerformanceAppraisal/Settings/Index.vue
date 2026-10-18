<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()

const props = defineProps<{
  settings: Record<string, string>
}>()

const form = ref({
  default_cycle_type:            props.settings.default_cycle_type            ?? 'annual',
  allow_self_review:             props.settings.allow_self_review             === '1',
  allow_peer_review:             props.settings.allow_peer_review             === '1',
  require_manager_approval:      props.settings.require_manager_approval      === '1',
  enable_ai_insights:            props.settings.enable_ai_insights            === '1',
  notify_reviewee_on_submission: props.settings.notify_reviewee_on_submission === '1',
  notify_manager_on_self_review: props.settings.notify_manager_on_self_review === '1',
})

function save() {
  router.patch(route('performance.settings.update'), form.value, { preserveScroll: true })
}

const cycleTypes = [
  { value: 'annual', title: t('performace_dashboard.cycle_type_annual') },
  { value: 'semi_annual', title: t('performace_dashboard.cycle_type_semi_annual') },
  { value: 'quarterly', title: t('performace_dashboard.cycle_type_quarterly') },
  { value: 'monthly', title: t('performace_dashboard.cycle_type_monthly') },
  { value: 'probation', title: t('performace_dashboard.cycle_type_probation') },
  { value: 'custom', title: t('performace_dashboard.cycle_type_custom') },
]
</script>

<template>
  <div class="pa-6">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('performace_dashboard.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1 mb-0">
          {{ t('performace_dashboard.subtitle') }}
        </p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-device-floppy" @click="save">{{ t('performace_dashboard.save_button') }}</VBtn>
    </div>

    <VRow>
      <!-- Cycle defaults -->
      <VCol cols="12" md="6">
        <VCard border class="h-100">
          <VCardItem>
            <template #prepend><VAvatar color="primary" variant="tonal" rounded><VIcon icon="tabler-refresh" /></VAvatar></template>
            <VCardTitle class="text-subtitle-1">{{ t('performace_dashboard.cycle_defaults') }}</VCardTitle>
          </VCardItem>
          <VCardText>
            <VSelect
              v-model="form.default_cycle_type"
              :items="cycleTypes"
              item-title="title" item-value="value"
              :label="t('performace_dashboard.default_cycle_type')"
              variant="outlined" density="comfortable" hide-details
            />
          </VCardText>
        </VCard>
      </VCol>

      <!-- AI features -->
      <VCol cols="12" md="6">
        <VCard border class="h-100">
          <VCardItem>
            <template #prepend><VAvatar color="deep-purple" variant="tonal" rounded><VIcon icon="tabler-brain" /></VAvatar></template>
            <VCardTitle class="text-subtitle-1">{{ t('performace_dashboard.ai_features') }}</VCardTitle>
          </VCardItem>
          <VCardText>
            <VSwitch v-model="form.enable_ai_insights" :label="t('performace_dashboard.enable_ai_insights')" inset color="deep-purple" hide-details />
          </VCardText>
        </VCard>
      </VCol>

      <!-- Review options -->
      <VCol cols="12" md="6">
        <VCard border class="h-100">
          <VCardItem>
            <template #prepend><VAvatar color="warning" variant="tonal" rounded><VIcon icon="tabler-checkbox" /></VAvatar></template>
            <VCardTitle class="text-subtitle-1">{{ t('performace_dashboard.review_options') }}</VCardTitle>
          </VCardItem>
          <VCardText>
            <VSwitch v-model="form.allow_self_review" :label="t('performace_dashboard.allow_self_review')" inset color="primary" hide-details class="mb-1" />
            <VSwitch v-model="form.allow_peer_review" :label="t('performace_dashboard.allow_peer_review')" inset color="primary" hide-details class="mb-1" />
            <VSwitch v-model="form.require_manager_approval" :label="t('performace_dashboard.require_manager_approval')" inset color="warning" hide-details />
          </VCardText>
        </VCard>
      </VCol>

      <!-- Notifications -->
      <VCol cols="12" md="6">
        <VCard border class="h-100">
          <VCardItem>
            <template #prepend><VAvatar color="info" variant="tonal" rounded><VIcon icon="tabler-bell" /></VAvatar></template>
            <VCardTitle class="text-subtitle-1">{{ t('performace_dashboard.notifications') }}</VCardTitle>
          </VCardItem>
          <VCardText>
            <VSwitch v-model="form.notify_reviewee_on_submission" :label="t('performace_dashboard.notify_reviewee')" inset color="info" hide-details class="mb-1" />
            <VSwitch v-model="form.notify_manager_on_self_review" :label="t('performace_dashboard.notify_manager')" inset color="info" hide-details />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <div class="d-flex justify-end mt-6">
      <VBtn color="primary" size="large" prepend-icon="tabler-device-floppy" @click="save">{{ t('performace_dashboard.save_button') }}</VBtn>
    </div>
  </div>
</template>
