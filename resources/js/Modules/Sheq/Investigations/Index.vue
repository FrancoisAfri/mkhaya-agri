<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t, d } = useI18n()

type Row = {
  uuid: string; reference: string; occurred_at: string; incident_type: string
  status: string; actual_severity: number | null; potential_severity: number | null
  statutory_category: string | null; site_name: string | null
  investigation_uuid: string | null; investigation_status: string | null
  method: string | null; revision: number | null
}

const props = defineProps<{ rows: Row[]; canInvestigate?: boolean; noCompany?: boolean }>()

const open = useForm({ incident_uuid: '', method: null as string | null })

const start = (incidentUuid: string) => {
  open.incident_uuid = incidentUuid
  open.post('/sheq/investigations')
}

/** Nothing opened yet, and the potential says it should have been. */
const uninvestigated = computed(() =>
  props.rows.filter(r => !r.investigation_uuid).length)

const statusColour = (status: string | null) => ({
  DRAFT: 'warning', PENDING_APPROVAL: 'info', APPROVED: 'success',
}[status ?? ''] ?? 'grey')

const formatDate = (v: string) => { try { return d(new Date(v), 'short') } catch { return v } }
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('sheq.investigations.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.investigations.subtitle') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VAlert
        v-if="uninvestigated" type="warning" variant="tonal" class="mb-4"
        role="status" aria-live="polite"
      >
        {{ t('sheq.investigations.not_started', { count: uninvestigated }) }}
      </VAlert>

      <VCard elevation="0" border rounded="lg">
        <!-- Empty states say what would fill them, per the guidance. -->
        <div v-if="!rows.length" class="text-center py-10 text-medium-emphasis">
          <VIcon icon="mdi-magnify-scan" size="32" class="mb-2" />
          <div class="text-body-2">{{ t('sheq.investigations.none_open') }}</div>
        </div>

        <VTable v-else density="comfortable">
          <thead>
            <tr>
              <th scope="col">{{ t('sheq.incident.reference') }}</th>
              <th scope="col">{{ t('sheq.incident.occurred') }}</th>
              <th scope="col">{{ t('sheq.registers.col.site') }}</th>
              <th scope="col">{{ t('sheq.investigations.potential') }}</th>
              <th scope="col">{{ t('sheq.investigations.investigation') }}</th>
              <th scope="col" class="text-end">
                <span class="d-sr-only">{{ t('sheq.action.actions') }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.uuid">
              <td>
                <Link :href="`/sheq/incidents/${row.uuid}`"
                      class="text-primary text-decoration-none font-weight-medium">
                  {{ row.reference }}
                </Link>
                <div class="text-caption text-medium-emphasis">{{ row.incident_type }}</div>
              </td>
              <td class="text-body-2">{{ formatDate(row.occurred_at) }}</td>
              <td class="text-body-2 text-medium-emphasis">{{ row.site_name ?? '—' }}</td>
              <td>
                <!-- Potential drives the rigour required, so it is the column that matters here —
                     not the actual outcome (SHEQ-INC-7). -->
                <VChip
                  :color="(row.potential_severity ?? 0) >= 4 ? 'error' : 'grey'"
                  size="x-small" variant="tonal"
                >
                  {{ t('sheq.investigations.potential_n', { n: row.potential_severity ?? '—' }) }}
                </VChip>
                <div v-if="(row.potential_severity ?? 0) >= 4" class="text-caption text-error mt-1">
                  {{ t('sheq.investigations.icam_required') }}
                </div>
              </td>
              <td>
                <template v-if="row.investigation_uuid">
                  <VChip :color="statusColour(row.investigation_status)" size="x-small" variant="tonal">
                    {{ t('sheq.investigations.status_' + (row.investigation_status ?? '').toLowerCase()) }}
                  </VChip>
                  <div class="text-caption text-medium-emphasis mt-1">
                    {{ row.method }}<span v-if="(row.revision ?? 1) > 1"> · r{{ row.revision }}</span>
                  </div>
                </template>
                <span v-else class="text-body-2 text-medium-emphasis">
                  {{ t('sheq.investigations.not_opened') }}
                </span>
              </td>
              <td class="text-end">
                <VBtn
                  v-if="row.investigation_uuid" size="small" variant="tonal"
                  :href="`/sheq/investigations/${row.investigation_uuid}`"
                >{{ t('sheq.investigations.open_it') }}</VBtn>

                <VBtn
                  v-else-if="canInvestigate" size="small" color="primary" variant="tonal"
                  prepend-icon="mdi-play-outline"
                  :loading="open.processing && open.incident_uuid === row.uuid"
                  :disabled="open.processing"
                  @click="start(row.uuid)"
                >{{ t('sheq.investigations.start') }}</VBtn>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </template>
  </div>
</template>
