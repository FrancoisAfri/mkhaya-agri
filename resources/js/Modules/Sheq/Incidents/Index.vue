<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t } = useI18n()

type Incident = {
  uuid: string; reference: string; occurred_at: string; incident_type: string
  description: string | null; outcome: string | null; statutory_category: string | null
  is_reportable: boolean; is_confidential: boolean; status: string
  severity: number | null; potential: number | null; site_name: string | null
  obligations_pending: number
}

const props = defineProps<{ incidents: Incident[]; noCompany?: boolean; can: { report: boolean; read_confidential: boolean } }>()

const search = ref('')
const onlyReportable = ref(false)

const filtered = computed(() => props.incidents.filter(i => {
  if (onlyReportable.value && !i.is_reportable) return false
  if (!search.value) return true
  const q = search.value.toLowerCase()
  return i.reference.toLowerCase().includes(q) || (i.description ?? '').toLowerCase().includes(q)
}))

const severityColour = (s: number | null) =>
  s === null ? 'grey' : s >= 5 ? 'error' : s >= 4 ? 'deep-orange' : s >= 3 ? 'warning' : 'success'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-5 gap-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.incident.list_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.incident.list_subtitle') }}</p>
      </div>
      <Link v-if="can.report" href="/sheq/incidents/create">
        <VBtn color="primary" prepend-icon="mdi-alert-plus">{{ t('sheq.incident.report_button') }}</VBtn>
      </Link>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <VRow class="mb-2">
      <VCol cols="12" sm="6" md="4">
        <VTextField v-model="search" :label="t('sheq.incident.search')" density="compact"
                    variant="outlined" prepend-inner-icon="mdi-magnify" hide-details clearable />
      </VCol>
      <VCol cols="12" sm="6" md="4" class="d-flex align-center">
        <VSwitch v-model="onlyReportable" color="error" density="compact" hide-details
                 :label="t('sheq.incident.only_reportable')" />
      </VCol>
    </VRow>

    <VCard elevation="0" border rounded="lg">
      <VTable density="comfortable">
        <thead>
          <tr>
            <th scope="col">{{ t('sheq.incident.reference') }}</th>
            <th scope="col">{{ t('sheq.incident.occurred') }}</th>
            <th scope="col">{{ t('sheq.incident.type') }}</th>
            <th scope="col">{{ t('sheq.incident.what_happened') }}</th>
            <th scope="col">{{ t('sheq.incident.classification') }}</th>
            <th scope="col" class="text-center">{{ t('sheq.incident.severity_short') }}</th>
            <th scope="col">{{ t('sheq.incident.obligations') }}</th>
          </tr>
        </thead>
        <tbody>
          <!-- No row-level cursor: only the reference cell navigates, and a pointer across the
               full row promises a click target that is not there. -->
          <tr v-for="i in filtered" :key="i.uuid">
            <td>
              <Link :href="`/sheq/incidents/${i.uuid}`" class="text-primary text-decoration-none font-weight-medium">
                {{ i.reference }}
              </Link>
              <div v-if="i.site_name" class="text-caption text-medium-emphasis">{{ i.site_name }}</div>
            </td>
            <td class="text-body-2">{{ new Date(i.occurred_at).toLocaleString() }}</td>
            <td><VChip size="x-small" variant="tonal">{{ i.incident_type }}</VChip></td>
            <td class="text-body-2" style="max-width: 320px">
              <!-- SHEQ-TEN-4: a confidential record's existence is visible; its content is not. -->
              <span v-if="i.description">{{ i.description }}</span>
              <span v-else class="text-medium-emphasis font-italic d-flex align-center gap-1">
                <VIcon size="14">mdi-lock-outline</VIcon>{{ t('sheq.incident.restricted') }}
              </span>
            </td>
            <td>
              <VChip v-if="i.statutory_category" :color="i.is_reportable ? 'error' : 'grey'"
                     size="x-small" variant="tonal">
                {{ i.statutory_category }}
              </VChip>
              <span v-else class="text-medium-emphasis">—</span>
            </td>
            <td class="text-center">
              <VChip :color="severityColour(i.severity)" size="x-small" variant="tonal">
                {{ i.severity ?? '—' }}<template v-if="i.potential"> / {{ i.potential }}</template>
              </VChip>
            </td>
            <td>
              <VChip v-if="i.obligations_pending" color="warning" size="x-small" variant="tonal">
                {{ t('sheq.incident.pending_count', { count: i.obligations_pending }) }}
              </VChip>
              <span v-else class="text-medium-emphasis">—</span>
            </td>
          </tr>
          <tr v-if="!filtered.length">
            <td colspan="7" class="text-center py-10 text-medium-emphasis">
              {{ t('sheq.incident.empty') }}
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
