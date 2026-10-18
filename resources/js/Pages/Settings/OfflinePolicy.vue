<script setup lang="ts">
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  policy: {
    enabled: boolean
    max_offline_minutes: number
    warn_before_minutes: number
    lockout_message: string | null
  }
}>()

const form = useForm({
  enabled:             props.policy.enabled,
  max_offline_minutes: props.policy.max_offline_minutes,
  warn_before_minutes: props.policy.warn_before_minutes,
  lockout_message:     props.policy.lockout_message ?? '',
})

const submit = () => {
  form.post('/offline-policy', {
    preserveScroll: true,
  })
}

// Preset options for quick selection
const presets = computed(() => [
  { label: t('offline-policy_page.presets.30_min'),   value: 30   },
  { label: t('offline-policy_page.presets.1_hour'),   value: 60   },
  { label: t('offline-policy_page.presets.2_hours'),  value: 120  },
  { label: t('offline-policy_page.presets.4_hours'),  value: 240  },
  { label: t('offline-policy_page.presets.8_hours'),  value: 480  },
  { label: t('offline-policy_page.presets.24_hours'), value: 1440 },
])

const warnPresets = computed(() => [
  { label: t('offline-policy_page.presets.5_min'),   value: 5  },
  { label: t('offline-policy_page.presets.10_min'),  value: 10 },
  { label: t('offline-policy_page.presets.15_min'),  value: 15 },
  { label: t('offline-policy_page.presets.30_min'),  value: 30 },
])

// Human-readable summary
const summary = computed(() => {
  if (!form.enabled) return t('offline-policy_page.summary_disabled')
  const h = Math.floor(form.max_offline_minutes / 60)
  const m = form.max_offline_minutes % 60
  let limit: string
  if (h > 0 && m > 0) {
    limit = t('offline-policy_page.duration_format_hm', { hours: h, minutes: m })
  } else if (h > 0) {
    limit = t('offline-policy_page.duration_format_h', { hours: h })
  } else {
    limit = t('offline-policy_page.duration_format_m', { minutes: m })
  }
  return t('offline-policy_page.summary_enabled', {
    duration: limit,
    warning: form.warn_before_minutes,
  })
})
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow justify="center">
      <VCol cols="12" lg="8">

        <!-- Header -->
        <div class="mb-6">
          <div class="text-overline text-medium-emphasis">{{ t('offline-policy_page.subtitle') }}</div>
          <h4 class="text-h4 mb-1">{{ t('offline-policy_page.title') }}</h4>
          <p class="text-medium-emphasis mb-0">
            {{ t('offline-policy_page.header_description') }}
          </p>
        </div>

        <VForm @submit.prevent="submit">
          <VRow>

            <!-- Enable toggle -->
            <VCol cols="12">
              <VCard elevation="0" border rounded="lg" class="pa-5">
                <div class="d-flex align-center justify-space-between">
                  <div>
                    <p class="text-body-1 font-weight-semibold mb-1">{{ t('offline-policy_page.enable_toggle') }}</p>
                    <p class="text-body-2 text-medium-emphasis mb-0">
                      {{ t('offline-policy_page.enable_toggle_description') }}
                    </p>
                  </div>
                  <VSwitch
                    v-model="form.enabled"
                    color="primary"
                    hide-details
                    density="compact"
                  />
                </div>
              </VCard>
            </VCol>

            <!-- Max offline duration -->
            <VCol cols="12" md="6">
              <VCard elevation="0" border rounded="lg" class="pa-5 h-100">
                <p class="text-body-1 font-weight-semibold mb-1">{{ t('offline-policy_page.max_duration') }}</p>
                <p class="text-body-2 text-medium-emphasis mb-4">
                  {{ t('offline-policy_page.max_duration_description') }}
                </p>

                <!-- Quick presets -->
                <div class="d-flex flex-wrap gap-2 mb-4">
                  <VChip
                    v-for="p in presets"
                    :key="p.value"
                    :color="form.max_offline_minutes === p.value ? 'primary' : 'default'"
                    :variant="form.max_offline_minutes === p.value ? 'tonal' : 'outlined'"
                    size="small"
                    class="cursor-pointer"
                    @click="form.max_offline_minutes = p.value"
                  >
                    {{ p.label }}
                  </VChip>
                </div>

                <VTextField
                  v-model.number="form.max_offline_minutes"
                  :label="t('offline-policy_page.minutes_label')"
                  type="number"
                  :min="15"
                  :max="2880"
                  :error-messages="form.errors.max_offline_minutes"
                  variant="outlined"
                  density="comfortable"
                  :suffix="t('offline-policy_page.minutes_suffix')"
                  :disabled="!form.enabled"
                  :hint="t('offline-policy_page.min_hint')"
                  persistent-hint
                />
              </VCard>
            </VCol>

            <!-- Warning period -->
            <VCol cols="12" md="6">
              <VCard elevation="0" border rounded="lg" class="pa-5 h-100">
                <p class="text-body-1 font-weight-semibold mb-1">{{ t('offline-policy_page.warning_period') }}</p>
                <p class="text-body-2 text-medium-emphasis mb-4">
                  {{ t('offline-policy_page.warning_period_description') }}
                </p>

                <!-- Quick presets -->
                <div class="d-flex flex-wrap gap-2 mb-4">
                  <VChip
                    v-for="p in warnPresets"
                    :key="p.value"
                    :color="form.warn_before_minutes === p.value ? 'warning' : 'default'"
                    :variant="form.warn_before_minutes === p.value ? 'tonal' : 'outlined'"
                    size="small"
                    class="cursor-pointer"
                    @click="form.warn_before_minutes = p.value"
                  >
                    {{ p.label }}
                  </VChip>
                </div>

                <VTextField
                  v-model.number="form.warn_before_minutes"
                  :label="t('offline-policy_page.minutes_label')"
                  type="number"
                  :min="5"
                  :max="60"
                  :error-messages="form.errors.warn_before_minutes"
                  variant="outlined"
                  density="comfortable"
                  :suffix="t('offline-policy_page.minutes_suffix')"
                  :disabled="!form.enabled"
                  :hint="t('offline-policy_page.warning_hint')"
                  persistent-hint
                />
              </VCard>
            </VCol>

            <!-- Lockout message -->
            <VCol cols="12">
              <VCard elevation="0" border rounded="lg" class="pa-5">
                <p class="text-body-1 font-weight-semibold mb-1">{{ t('offline-policy_page.lockout_message') }}</p>
                <p class="text-body-2 text-medium-emphasis mb-4">
                  {{ t('offline-policy_page.lockout_message_description') }}
                </p>
                <VTextarea
                  v-model="form.lockout_message"
                  :label="t('offline-policy_page.lockout_message_label')"
                  :placeholder="t('offline-policy_page.lockout_message_placeholder')"
                  :error-messages="form.errors.lockout_message"
                  variant="outlined"
                  density="comfortable"
                  rows="3"
                  :disabled="!form.enabled"
                />
              </VCard>
            </VCol>

            <!-- Live summary -->
            <VCol cols="12">
              <VAlert
                :type="form.enabled ? 'info' : 'warning'"
                variant="tonal"
                rounded="lg"
                :icon="form.enabled ? 'mdi-shield-check-outline' : 'mdi-shield-off-outline'"
              >
                {{ summary }}
              </VAlert>
            </VCol>

            <!-- Actions -->
            <VCol cols="12" class="d-flex justify-end gap-3">
              <VBtn
                type="submit"
                color="primary"
                :loading="form.processing"
                prepend-icon="ri-save-line"
              >
                {{ t('offline-policy_page.save_policy') }}
              </VBtn>
            </VCol>

          </VRow>
        </VForm>

      </VCol>
    </VRow>
  </VContainer>
</template>
