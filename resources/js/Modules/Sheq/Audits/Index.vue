<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t, d } = useI18n()

const props = defineProps<{
  audits: Array<Record<string, any>>
  sites: Array<{ id: number; name: string }>
  openFindings: number
  canManage?: boolean
  noCompany?: boolean
}>()

const planning = ref(false)
const form = useForm({ title: '', audit_type: 'INTERNAL', standard: '', scope: '',
  site_id: null as number | null, planned_start: '', planned_end: '' })

const TYPES = ['INTERNAL', 'SUPPLIER', 'CERTIFICATION', 'CUSTOMER', 'REGULATORY']
const STANDARDS = ['ISO 9001', 'ISO 14001', 'ISO 45001', 'Internal']

const submit = () => form.post('/sheq/audits', { onSuccess: () => { form.reset(); planning.value = false } })

const statusColour = (s: string) => ({
  PLANNED: 'grey', IN_PROGRESS: 'info', FIELDWORK_COMPLETE: 'warning',
  REPORTED: 'primary', CLOSED: 'success', CANCELLED: 'grey',
}[s] ?? 'grey')

const formatDate = (v: string | null) => { if (!v) return '—'; try { return d(new Date(v), 'short') } catch { return v } }
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.audits.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.audits.subtitle') }}</p>
      </div>
      <VBtn v-if="canManage" color="primary" variant="flat" prepend-icon="mdi-plus"
        @click="planning = true">
        {{ t('sheq.audits.new') }}
      </VBtn>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VAlert v-if="openFindings" type="warning" variant="tonal" class="mb-4"
        role="status" aria-live="polite">
        {{ openFindings }} {{ t('sheq.audits.open').toLowerCase() }} {{ t('sheq.audits.findings').toLowerCase() }}
      </VAlert>

      <VExpandTransition>
        <VDialog v-model="planning" max-width="900">
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <div class="pa-4">
            <VRow dense>
              <VCol cols="12" md="4">
                <VTextField v-model="form.title" variant="outlined" density="compact"
                  label="Title" :error-messages="form.errors.title" />
              </VCol>
              <VCol cols="6" md="2">
                <VSelect v-model="form.audit_type" :items="TYPES" variant="outlined"
                  density="compact" label="Type" />
              </VCol>
              <VCol cols="6" md="2">
                <VCombobox v-model="form.standard" :items="STANDARDS" variant="outlined"
                  density="compact" label="Standard" />
              </VCol>
              <VCol cols="6" md="2">
                <VSelect v-model="form.site_id" :items="sites" item-title="name" item-value="id"
                  variant="outlined" density="compact" label="Site" clearable />
              </VCol>
              <VCol cols="6" md="2">
                <VTextField v-model="form.planned_start" type="date" variant="outlined"
                  density="compact" label="Start" />
              </VCol>
              <VCol cols="12">
                <VTextarea v-model="form.scope" rows="2" auto-grow variant="outlined"
                  density="compact" label="Scope" />
              </VCol>
            </VRow>
            <div class="d-flex ga-2 mt-3">
              <VBtn color="primary" variant="flat" size="small" :loading="form.processing" @click="submit">
                {{ t('sheq.audits.new') }}
              </VBtn>
              <VBtn variant="text" size="small" @click="planning = false">Cancel</VBtn>
            </div>
          </div>
        </VCard>
      </VDialog>
      </VExpandTransition>

      <VCard elevation="0" border rounded="lg">
        <div v-if="!audits.length" class="text-center py-10 text-medium-emphasis">
          <VIcon icon="mdi-clipboard-check-multiple-outline" size="32" class="mb-2" />
          <div class="text-body-2">{{ t('sheq.audits.none') }}</div>
        </div>
        <VTable v-else density="compact">
          <thead><tr>
            <th>Reference</th><th>Title</th><th>Standard</th><th>Status</th>
            <th>{{ t('sheq.audits.findings') }}</th><th>{{ t('sheq.audits.score') }}</th>
          </tr></thead>
          <tbody>
            <tr v-for="a in audits" :key="a.uuid" class="row-hover">
              <td>
                <Link :href="`/sheq/audits/${a.uuid}`" class="text-decoration-none">
                  <span class="text-caption font-weight-medium">{{ a.reference }}</span>
                </Link>
              </td>
              <td class="text-body-2">{{ a.title }}</td>
              <td class="text-caption text-medium-emphasis">{{ a.standard || '—' }}</td>
              <td>
                <VChip size="x-small" :color="statusColour(a.status)" variant="tonal">
                  {{ a.status.replace('_', ' ').toLowerCase() }}
                </VChip>
              </td>
              <td class="text-caption">
                <!-- Majors called out separately: they block certification, minors do not. -->
                <span v-if="a.major" class="text-error font-weight-medium">
                  {{ a.major }} {{ t('sheq.audits.major').toLowerCase() }}
                </span>
                <span v-if="a.major && a.minor"> · </span>
                <span v-if="a.minor" class="text-warning">
                  {{ a.minor }} {{ t('sheq.audits.minor').toLowerCase() }}
                </span>
                <span v-if="!a.major && !a.minor" class="text-medium-emphasis">—</span>
              </td>
              <td class="text-caption">{{ a.score !== null ? `${a.score}%` : '—' }}</td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </template>
  </div>
</template>

<style scoped>
.row-hover { cursor: pointer; transition: background-color 200ms ease; }
.row-hover:hover { background-color: rgba(var(--v-theme-on-surface), 0.04); }
@media (prefers-reduced-motion: reduce) { .row-hover { transition: none; } }
</style>
