<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t, d } = useI18n()

const props = defineProps<{
  equipment: Array<Record<string, any>>
  due: Array<Record<string, any>>
  types: string[]
  sites: Array<{ id: number; name: string }>
  canManage?: boolean
  noCompany?: boolean
}>()

const adding = ref(false)
const form = useForm({ asset_tag: '', name: '', equipment_type: 'FIRE_EXTINGUISHER',
  site_id: null as number | null, location_text: '', serial_number: '',
  inspection_frequency_days: null as number | null, certificate_expires_on: '' })

const check = useForm({ passed: true, note: '' })
const overdue = computed(() => props.due.filter(e => e.overdue).length)

const submit = () => form.post('/sheq/equipment', {
  preserveScroll: true, onSuccess: () => { form.reset(); adding.value = false },
})

const inspect = (uuid: string, passed: boolean) => {
  check.passed = passed
  check.post(`/sheq/equipment/${uuid}/inspect`, { preserveScroll: true, onSuccess: () => check.reset() })
}

const statusColour = (s: string) =>
  ({ IN_SERVICE: 'success', OUT_OF_SERVICE: 'error', CONDEMNED: 'grey', MISSING: 'warning' }[s] ?? 'grey')

const formatDate = (v: string | null) => { if (!v) return '—'; try { return d(new Date(v), 'short') } catch { return v } }
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.equipment.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.equipment.subtitle') }}</p>
      </div>
      <VBtn v-if="canManage" color="primary" variant="flat" prepend-icon="mdi-plus" @click="adding = true">
        {{ t('sheq.equipment.add') }}
      </VBtn>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VAlert v-if="overdue" type="warning" variant="tonal" class="mb-4" role="status" aria-live="polite">
        {{ overdue }} {{ t('sheq.equipment.due').toLowerCase() }}
      </VAlert>

      <VExpandTransition>
        <VDialog v-model="adding" max-width="900">
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <div class="pa-4">
            <VRow dense>
              <VCol cols="6" md="2">
                <VTextField v-model="form.asset_tag" variant="outlined" density="compact"
                  :label="t('sheq.equipment.tag')" :error-messages="form.errors.asset_tag" />
              </VCol>
              <VCol cols="12" md="3">
                <VTextField v-model="form.name" variant="outlined" density="compact" label="Name"
                  :error-messages="form.errors.name" />
              </VCol>
              <VCol cols="12" md="3">
                <VSelect v-model="form.equipment_type" :items="types" variant="outlined"
                  density="compact" label="Type" />
              </VCol>
              <VCol cols="12" md="2">
                <VSelect v-model="form.site_id" :items="sites" item-title="name" item-value="id"
                  variant="outlined" density="compact" label="Site" clearable />
              </VCol>
              <VCol cols="12" md="2">
                <VTextField v-model="form.location_text" variant="outlined" density="compact"
                  label="Location" />
              </VCol>
            </VRow>
            <div class="d-flex ga-2 mt-3">
              <VBtn color="primary" variant="flat" size="small" :loading="form.processing" @click="submit">
                {{ t('sheq.equipment.add') }}
              </VBtn>
              <VBtn variant="text" size="small" @click="adding = false">Cancel</VBtn>
            </div>
          </div>
        </VCard>
      </VDialog>
      </VExpandTransition>

      <VCard elevation="0" border rounded="lg">
        <div v-if="!equipment.length" class="text-center py-10 text-medium-emphasis">
          <VIcon icon="mdi-fire-extinguisher" size="32" class="mb-2" />
          <div class="text-body-2">{{ t('sheq.equipment.none') }}</div>
        </div>
        <VTable v-else density="compact">
          <thead><tr>
            <th>{{ t('sheq.equipment.tag') }}</th><th>Name</th><th>Location</th>
            <th>Status</th><th>{{ t('sheq.equipment.due') }}</th><th></th>
          </tr></thead>
          <tbody>
            <tr v-for="e in equipment" :key="e.uuid">
              <td class="text-caption font-weight-medium">{{ e.asset_tag }}</td>
              <td class="text-body-2">{{ e.name }}</td>
              <td class="text-caption text-medium-emphasis">{{ e.location_text || e.site_name || '—' }}</td>
              <td>
                <VChip size="x-small" :color="statusColour(e.status)" variant="tonal">
                  {{ e.status.replace('_', ' ').toLowerCase() }}
                </VChip>
              </td>
              <td class="text-caption">
                <span :class="e.overdue ? 'text-error font-weight-medium' : ''">
                  {{ formatDate(e.next_inspection_due) }}
                </span>
              </td>
              <td style="width: 150px">
                <VBtn size="x-small" variant="text" color="success" :disabled="check.processing"
                  @click="inspect(e.uuid, true)">
                  {{ t('sheq.equipment.passed') }}
                </VBtn>
                <VBtn size="x-small" variant="text" color="error" :disabled="check.processing"
                  @click="inspect(e.uuid, false)">
                  {{ t('sheq.equipment.failed') }}
                </VBtn>
              </td>
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
.row-hover:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: -2px; }
@media (prefers-reduced-motion: reduce) { .row-hover { transition: none; } }
</style>
