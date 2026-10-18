<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import { useCorporateBrandingStore } from '../Store/Store'
import BrandingForm from '../Partials/Brandingform.vue'
import type { BrandingFormFields } from '../Store/type'

const route        = useRoute()
const router       = useRouter()
const store        = useCorporateBrandingStore()
const loading      = ref(true)
const companyUnits = ref<{ id: number; name: string }[]>([])

onMounted(async () => {
  const id = Number(route.params.id)
  await Promise.allSettled([
    store.fetchOne(id),
    axios.get('/api/company-units').then(({ data }) => {
      companyUnits.value = data.data ?? data
    }),
  ])
  loading.value = false
})

async function handleSubmit(payload: {
  fields: BrandingFormFields
  logoLight: File | null
  logoDark: File | null
  favicon: File | null
}) {
  const id = store.current?.id
  if (!id) return
  await store.submit(
    { ...payload.fields, logo_light: payload.logoLight, logo_dark: payload.logoDark, favicon: payload.favicon },
    id,
  )
}
</script>

<template>
  <section class="page-root">
    <VContainer fluid class="pa-4 pa-sm-6">

      <!-- Loading -->
      <div v-if="loading" class="page-loading">
        <div class="page-spinner" />
        <p>Loading…</p>
      </div>

      <!-- Not found -->
      <div v-else-if="!store.current" class="page-notfound">
        <i class="fa-solid fa-triangle-exclamation page-notfound__icon" />
        <h3>Branding not found</h3>
        <router-link :to="{ name: 'corporate-branding.index' }" class="page-back-btn">
          Back to list
        </router-link>
      </div>

      <!-- Form -->
      <template v-else>
        <div class="page-header mb-6">
          <router-link :to="{ name: 'corporate-branding.index' }" class="page-back">
            <i class="fa-solid fa-arrow-left me-2" />
            Back
          </router-link>
          <div>
            <h2 class="page-title">
              <i class="fa-solid fa-pen-to-square me-2" style="color:rgb(var(--v-theme-primary))" />
              Edit branding
            </h2>
            <p class="page-sub">{{ store.current.name }}</p>
          </div>
        </div>

        <VCard elevation="0" border rounded="xl">
          <VCardText class="pa-5 pa-sm-6">
            <BrandingForm
              :branding="store.current"
              :is-saving="store.isSaving"
              :is-loading="store.isLoading"
              :company-units="companyUnits"
              @submit="handleSubmit"
              @cancel="router.push({ name: 'corporate-branding.index' })"
            />
          </VCardText>
        </VCard>
      </template>

    </VContainer>
  </section>
</template>

<style scoped>
.page-root { min-height: 100vh; background: #f7f8fc; }
.page-header { display: flex; flex-direction: column; gap: .5rem; }

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

/* Loading */
.page-loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 300px;
  gap: 1rem;
  color: rgba(var(--v-theme-on-surface), .45);
  font-size: .85rem;
}
.page-spinner {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  border: 2px solid rgba(var(--v-border-color), .3);
  border-top-color: rgb(var(--v-theme-primary));
  animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* Not found */
.page-notfound {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
  min-height: 300px;
  justify-content: center;
  text-align: center;
}
.page-notfound__icon {
  font-size: 2.5rem;
  color: rgba(var(--v-theme-on-surface), .3);
}
.page-back-btn {
  padding: .6rem 1.25rem;
  border-radius: 8px;
  background: rgb(var(--v-theme-primary));
  color: #fff;
  text-decoration: none;
  font-weight: 600;
  font-size: .875rem;
}
</style>
