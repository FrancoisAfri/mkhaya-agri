<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t, te } = useI18n()

type Subject = {
  uuid: string
  name: string
  type: string
  id: number
}

type Consent = {
  uuid: string
  purpose: string
  status: string
  consent_version: string | null
  granted_at: string | null
  expires_at: string | null
  revoked_at: string | null
  is_active: boolean
  ip_address: string | null
  notes: string | null
}

type PhiEntry = {
  id: number
  field: string
  accessed_by: string
  ip_address: string | null
  accessed_at: string
}

const props = defineProps<{
  subject: Subject | null
  consents: Consent[]
  phiLog: PhiEntry[]
  filters: { search: string; type: string }
}>()

const search     = ref(props.filters.search)
const subjectType = ref(props.filters.type || 'employee')

function doSearch() {
  router.get(route('compliance.consents.index'), {
    search:  search.value || undefined,
    type:    subjectType.value,
  }, { preserveState: true, replace: true })
}

/*
 * `purpose` is a free-text column, so there is no fixed set of keys to translate it by — it
 * printed the dotted key for whatever the data happened to hold. Translate where somebody has
 * written a term for it, and otherwise show the value, which is at least the truth.
 */
function label(key: string, fallback: string | null): string {
  return te(key) ? t(key) : (fallback ?? '')
}

function statusColor(s: string) {
  return { granted: 'success', declined: 'error', revoked: 'warning' }[s] ?? 'default'
}

function fmt(iso: string | null) {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('en-ZA', { dateStyle: 'medium', timeStyle: 'short' })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="mb-6">
      <h4 class="text-h4">{{ t('compliance_consents.page_title') }}</h4>
      <p class="text-medium-emphasis mb-0">{{ t('compliance_consents.subtitle') }}</p>
    </div>

    <VCard class="mb-4">
      <VCardText class="pa-4">
        <VRow align="center">
          <VCol cols="12" md="4">
            <VSelect
              v-model="subjectType"
              :items="[
                { title: t('compliance_consents.search.employee'), value: 'employee' },
                { title: t('compliance_consents.search.candidate'), value: 'candidate' },
              ]"
              item-title="title"
              item-value="value"
              :label="t('compliance_consents.search.subject_type')"
              density="compact"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField
              v-model="search"
              :label="t('compliance_consents.search.search_placeholder')"
              density="compact"
              prepend-inner-icon="tabler-search"
              clearable
              @keyup.enter="doSearch"
            />
          </VCol>
          <VCol cols="12" md="2">
            <VBtn color="primary" block @click="doSearch">{{ t('compliance_consents.search.search_button') }}</VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VAlert v-if="filters.search && !subject" type="info" class="mb-4">
      {{ t('compliance_consents.search.no_result', { type: t(`compliance_consents.search.${filters.type}`), search: filters.search }) }}
    </VAlert>

    <template v-if="subject">
      <VCard class="mb-4">
        <VCardText class="pa-4 d-flex align-center gap-4">
          <VAvatar color="primary" variant="tonal" size="52">
            <VIcon icon="tabler-user" size="28" />
          </VAvatar>
          <div>
            <div class="text-h6">{{ subject.name }}</div>
            <div class="text-caption text-medium-emphasis text-uppercase">
              {{ t('compliance_consents.subject_banner.type_label', { type: subject.type, uuid: subject.uuid }) }}
            </div>
          </div>
        </VCardText>
      </VCard>

      <VRow>
        <VCol cols="12" lg="7">
          <VCard>
            <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('compliance_consents.consent_records.title') }}</VCardTitle>
            <VDivider />
            <VCardText class="pa-0">
              <VTable density="compact" class="text-no-wrap">
                <thead>
                  <tr>
                    <th>{{ t('compliance_consents.consent_records.table.purpose') }}</th>
                    <th>{{ t('compliance_consents.consent_records.table.status') }}</th>
                    <th>{{ t('compliance_consents.consent_records.table.active') }}</th>
                    <th>{{ t('compliance_consents.consent_records.table.granted') }}</th>
                    <th>{{ t('compliance_consents.consent_records.table.expires') }}</th>
                    <th>{{ t('compliance_consents.consent_records.table.revoked') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="consents.length === 0">
                    <td colspan="6" class="text-center text-medium-emphasis pa-4">{{ t('compliance_consents.consent_records.table.no_records') }}</td>
                  </tr>
                  <tr v-for="c in consents" :key="c.uuid">
                    <td class="py-2 font-weight-medium">
                      {{ label(`compliance_consents.purposes.${c.purpose}`, c.purpose) }}
                      <div v-if="c.consent_version" class="text-caption text-medium-emphasis">v{{ c.consent_version }}</div>
                    </td>
                    <td>
                      <VChip :color="statusColor(c.status)" size="x-small" label class="text-capitalize">
                        {{ t(`compliance_consents.statuses.${c.status}`) }}
                      </VChip>
                    </td>
                    <td>
                      <VIcon :icon="c.is_active ? 'tabler-circle-check' : 'tabler-circle-x'" :color="c.is_active ? 'success' : 'error'" size="18" />
                    </td>
                    <td class="text-caption text-medium-emphasis">{{ fmt(c.granted_at) }}</td>
                    <td class="text-caption text-medium-emphasis">{{ fmt(c.expires_at) }}</td>
                    <td class="text-caption text-medium-emphasis">{{ fmt(c.revoked_at) }}</td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12" lg="5">
          <VCard>
            <VCardTitle class="pa-4 pb-2 text-subtitle-1">
              {{ t('compliance_consents.phi_access_log.title') }}
              <span class="text-caption text-medium-emphasis font-weight-regular ms-2">{{ t('compliance_consents.phi_access_log.subtitle') }}</span>
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-0">
              <VTable density="compact" class="text-no-wrap">
                <thead>
                  <tr>
                    <th>{{ t('compliance_consents.phi_access_log.table.field') }}</th>
                    <th>{{ t('compliance_consents.phi_access_log.table.accessed_by') }}</th>
                    <th>{{ t('compliance_consents.phi_access_log.table.when') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="phiLog.length === 0">
                    <td colspan="3" class="text-center text-medium-emphasis pa-4">{{ t('compliance_consents.phi_access_log.table.no_records') }}</td>
                  </tr>
                  <tr v-for="e in phiLog" :key="e.id">
                    <td class="py-1"><code class="text-caption">{{ e.field }}</code></td>
                    <td class="text-body-2">{{ e.accessed_by }}</td>
                    <td class="text-caption text-medium-emphasis">{{ fmt(e.accessed_at) }}</td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <template v-else-if="!filters.search">
      <VCard>
        <VCardText class="pa-12 text-center">
          <VIcon icon="tabler-clipboard-check" size="64" color="secondary" class="mb-4 d-block mx-auto" />
          <h6 class="text-h6 mb-2">{{ t('compliance_consents.empty_state.title') }}</h6>
          <p class="text-medium-emphasis mb-0">{{ t('compliance_consents.empty_state.description') }}</p>
        </VCardText>
      </VCard>
    </template>
  </VContainer>
</template>
