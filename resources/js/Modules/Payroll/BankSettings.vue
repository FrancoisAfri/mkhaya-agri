<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute
const props = defineProps<{ config: Record<string, any>; banks: { value: string; title: string }[] }>()

const form = useForm({
  bank: props.config.bank ?? '',
  account_name: props.config.account_name ?? '',
  account_number: props.config.account_number ?? '',
  branch_code: props.config.branch_code ?? '',
  user_code: props.config.user_code ?? '',
})
function save() { form.post(route('payroll.bank-settings.save'), { preserveScroll: true }) }
</script>

<template>
  <div class="pa-6" style="max-width:720px;margin:auto">
    <h4 class="text-h4 font-weight-bold mb-1">Bank settings</h4>
    <p class="text-medium-emphasis mb-4">
      Choose your company's bank — the salary file you download from a released run is generated in that bank's format,
      ready to upload into your internet banking.
    </p>

    <VCard border>
      <VCardText class="d-flex flex-column gap-3">
        <VSelect v-model="form.bank" :items="banks" item-title="title" item-value="value" label="Company bank *" :error-messages="form.errors.bank" />
        <VTextField v-model="form.account_name" label="Account name" />
        <div class="d-flex gap-2">
          <VTextField v-model="form.account_number" label="Account number *" :error-messages="form.errors.account_number" />
          <VTextField v-model="form.branch_code" label="Branch code *" :error-messages="form.errors.branch_code" />
        </div>
        <VTextField v-model="form.user_code" label="ACB user code (bank-issued, optional)" />
      </VCardText>
      <VCardActions class="pa-3"><VSpacer /><VBtn color="primary" :loading="form.processing" @click="save">Save</VBtn></VCardActions>
    </VCard>

    <VAlert type="info" variant="tonal" class="mt-4" density="comfortable">
      On a released pay run, use “Download bank file”. The file is delivered over your authenticated session; capture each
      employee's bank account on their HR profile so they're included.
    </VAlert>
  </div>
</template>
