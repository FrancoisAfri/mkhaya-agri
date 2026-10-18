<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
defineProps<{ trialDays: number }>()

const form = useForm({ company_name: '', admin_name: '', email: '', phone: '', password: '', password_confirmation: '' })
function submit() { form.post(route('tenancy.signup.store')) }
</script>

<template>
  <VContainer class="fill-height" style="max-width: 560px">
    <VRow justify="center" class="w-100">
      <VCol cols="12">
        <div class="text-center mb-6">
          <h1 class="text-h4 font-weight-bold">Start your free trial</h1>
          <p class="text-medium-emphasis">Create your company workspace — {{ trialDays }} days free, no card required.</p>
        </div>
        <VCard border>
          <VCardText class="pa-6">
            <VTextField v-model="form.company_name" label="Company name" variant="outlined" class="mb-3" :error-messages="form.errors.company_name" />
            <div class="d-flex gap-3 mb-3">
              <VTextField v-model="form.admin_name" label="Your name" variant="outlined" :error-messages="form.errors.admin_name" />
              <VTextField v-model="form.phone" label="Phone (optional)" variant="outlined" :error-messages="form.errors.phone" />
            </div>
            <VTextField v-model="form.email" type="email" label="Work email" variant="outlined" class="mb-3" :error-messages="form.errors.email" />
            <div class="d-flex gap-3 mb-4">
              <VTextField v-model="form.password" type="password" label="Password" variant="outlined" :error-messages="form.errors.password" />
              <VTextField v-model="form.password_confirmation" type="password" label="Confirm password" variant="outlined" />
            </div>
            <VBtn color="primary" size="large" block :loading="form.processing" @click="submit">Create workspace</VBtn>
            <div class="text-center mt-4 text-caption">
              Already have an account? <a :href="route('login')">Sign in</a>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>
