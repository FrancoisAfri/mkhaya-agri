<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

type Setting = { key: string; label: string; value: string; cast: string }

const props = defineProps<{ settings: Setting[] }>()

const form = useForm({
  settings: props.settings.map(s => ({ key: s.key, value: s.value })),
})

function findIdx(key: string) {
  return form.settings.findIndex(s => s.key === key)
}

function save() {
  form.patch(route('fleet.settings.update'), {
    preserveScroll: true,
  })
}

function getCast(key: string): string {
  return props.settings.find(s => s.key === key)?.cast ?? 'string'
}
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center mb-5">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('fleet_settings_index.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('fleet_settings_index.description') }}</p>
      </div>
      <VSpacer />
      <VBtn color="primary" :loading="form.processing" @click="save">{{ t('fleet_settings_index.save') }}</VBtn>
    </div>

    <VCard>
      <VCardText>
        <VRow>
          <VCol v-for="s in settings" :key="s.key" cols="12" md="6">
            <div class="mb-4">
              <label class="text-body-2 font-weight-medium d-block mb-1">{{ s.label }}</label>

              <!-- Boolean → Switch -->
              <template v-if="s.cast === 'boolean'">
                <VSwitch
                  :model-value="form.settings[findIdx(s.key)]?.value === 'true'"
                  @update:model-value="(v: boolean) => { form.settings[findIdx(s.key)].value = String(v) }"
                  color="primary" hide-details density="compact"
                />
              </template>

              <!-- Integer/Float → Number input -->
              <template v-else-if="['integer','float'].includes(s.cast)">
                <VTextField
                  :model-value="form.settings[findIdx(s.key)]?.value"
                  @update:model-value="(v: string) => { form.settings[findIdx(s.key)].value = v }"
                  type="number" density="compact" variant="outlined" hide-details
                />
              </template>

              <!-- String → Text input -->
              <template v-else>
                <VTextField
                  :model-value="form.settings[findIdx(s.key)]?.value"
                  @update:model-value="(v: string) => { form.settings[findIdx(s.key)].value = v }"
                  density="compact" variant="outlined" hide-details
                />
              </template>
            </div>
          </VCol>
        </VRow>
      </VCardText>
      <VCardActions class="px-4 pb-4">
        <VSpacer />
        <VBtn color="primary" :loading="form.processing" @click="save">{{ t('fleet_settings_index.save') }}</VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>
