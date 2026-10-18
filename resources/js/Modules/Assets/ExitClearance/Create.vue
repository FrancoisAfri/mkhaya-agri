<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  users: { id: number; name: string; email: string }[]
}>()

const form = useForm({
  user_id: null as number | null,
  target_exit_date: '',
  notes: '',
})

function submit() {
  form.post(route('assets.exit-clearances.store'))
}
</script>

<template>
  <div class="pa-6" style="max-width:640px">
    <div class="mb-6">
      <h4 class="text-h4 font-weight-bold mb-1">{{ t('assets_exit_clearances_create.page_title') }}</h4>
      <p class="text-medium-emphasis mb-0">{{ t('assets_exit_clearances_create.description') }}</p>
    </div>

    <VCard elevation="0" border>
      <VCardText class="pa-6">
        <VForm @submit.prevent="submit">
          <VRow>
            <VCol cols="12">
              <VAutocomplete
                v-model="form.user_id"
                :items="users"
                item-title="name"
                item-value="id"
                :label="t('assets_exit_clearances_create.form.employee')"
                :error-messages="form.errors.user_id"
              >
                <template #item="{ item, props: itemProps }">
                  <VListItem v-bind="itemProps" :subtitle="item.raw.email" />
                </template>
              </VAutocomplete>
            </VCol>
            <VCol cols="12">
              <VTextField
                v-model="form.target_exit_date"
                :label="t('assets_exit_clearances_create.form.target_exit_date')"
                type="date"
                :error-messages="form.errors.target_exit_date"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.notes"
                :label="t('assets_exit_clearances_create.form.notes')"
                rows="3"
                :error-messages="form.errors.notes"
              />
            </VCol>
          </VRow>
          <div class="d-flex gap-3 mt-4">
            <VBtn type="submit" color="primary" :loading="form.processing">{{ t('assets_exit_clearances_create.form.submit') }}</VBtn>
            <VBtn variant="text" :href="route('assets.exit-clearances.index')">{{ t('assets_exit_clearances_create.form.cancel') }}</VBtn>
          </div>
        </VForm>
      </VCardText>
    </VCard>
  </div>
</template>
