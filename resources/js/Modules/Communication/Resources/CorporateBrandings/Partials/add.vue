<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'
import { useCorporateBrandingStore } from '../Store/Store'
import BrandingForm from '../Partials/Brandingform.vue'
import type { BrandingFormFields } from '../Store/type'

const router       = useRouter()
const store        = useCorporateBrandingStore()
const companyUnits = ref<{ id: number; name: string }[]>([])

onMounted(async () => {
  try {
    const { data } = await axios.get('/api/company-units')
    companyUnits.value = data.data ?? data
  } catch { /* silencieux */ }
})

async function handleSubmit(payload: {
  fields: BrandingFormFields
  logoLight: File | null
  logoDark: File | null
  favicon: File | null
}) {
  await store.submit({
    ...payload.fields,
    logo_light: payload.logoLight,
    logo_dark:  payload.logoDark,
    favicon:    payload.favicon,
  })
}
</script>

<template>
  <section class="page-root">
    <VContainer fluid class="pa-4 pa-sm-6">

      <div class="page-header mb-6">
        <router-link :to="{ name: 'corporate-branding.index' }" class="page-back">
          <i class="fa-solid fa-arrow-left me-2" />
          Back
        </router-link>
        <div>
          <h2 class="page-title">
            <i class="fa-solid fa-plus-circle me-2" style="color:rgb(var(--v-theme-primary))" />
            New corporate branding
          </h2>
          <p class="page-sub">Configure your company visual identity</p>
        </div>
      </div>

      <VCard elevation="0" border rounded="xl">
        <VCardText class="pa-5 pa-sm-6">
          <BrandingForm
            :is-saving="store.isSaving"
            :is-loading="store.isLoading"
            :company-units="companyUnits"
            @submit="handleSubmit"
            @cancel="router.push({ name: 'corporate-branding.index' })"
          />
        </VCardText>
      </VCard>
    </VContainer>
  </section>
</template>

<style scoped>
.page-root { min-height: 100vh; background: #f7f8fc; }

.page-header { display: flex; align-items: flex-start; flex-direction: column; gap: .5rem; }

.page-back {
  display: inline-flex;
  align-items: center;
  font-size: .82rem;
  font-weight: 600;
  color: rgba(var(--v-theme-on-surface), .5);
  text-decoration: none;
  transition: color .15s;
}
.page-back:hover { color: rgb(var(--v-theme-primary)); }

.page-title { font-size: 1.2rem; font-weight: 700; margin: 0 0 .25rem; }
.page-sub   { font-size: .85rem; color: rgba(var(--v-theme-on-surface), .45); margin: 0; }
</style>
