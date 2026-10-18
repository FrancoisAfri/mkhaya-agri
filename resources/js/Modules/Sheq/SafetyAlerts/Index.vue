<script setup lang="ts">
/**
 * Safety alerts: carrying a finding to the people exposed to the same hazard.
 *
 * The page leads with what has *not* been passed on — approved findings with no alert, and drafts
 * that were never issued. A list of alerts already sent is a record; the queue above it is the
 * work, and it is the half where the learning gets lost.
 */
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t } = useI18n()

type Alert = {
  uuid: string; reference: string; title: string; severity: string; status: string
  issued_at: string | null; what_happened: string; why_it_happened: string | null
  what_we_changed: string | null; acknowledgement_required: boolean
  incident_reference: string | null; incident_uuid: string | null; reads: number
}

type Awaiting = {
  uuid: string; reference: string; description: string
  potential_severity: number | null; approved_at: string | null
}

const props = defineProps<{
  alerts: Alert[]
  summary: { draft: number; issued: number }
  awaiting: Awaiting[]
  can: { manage: boolean }
  noCompany?: boolean
}>()

const drafts = computed(() => props.alerts.filter(a => a.status === 'DRAFT'))
const issued = computed(() => props.alerts.filter(a => a.status === 'ISSUED'))

const editing = ref<Alert | null>(null)
const form = ref({ title: '', what_happened: '', why_it_happened: '', what_we_changed: '' })
const busy = ref(false)

function draftFrom(incidentUuid: string) {
  busy.value = true
  router.post(`/sheq/safety-alerts/from-incident/${incidentUuid}`, {}, {
    preserveScroll: true,
    onFinish: () => { busy.value = false },
  })
}

function edit(a: Alert) {
  editing.value = a
  form.value = {
    title: a.title,
    what_happened: a.what_happened,
    why_it_happened: a.why_it_happened ?? '',
    what_we_changed: a.what_we_changed ?? '',
  }
}

function save() {
  if (!editing.value) return
  busy.value = true
  router.patch(`/sheq/safety-alerts/${editing.value.uuid}`, form.value, {
    preserveScroll: true,
    onFinish: () => { busy.value = false; editing.value = null },
  })
}

function issue(a: Alert) {
  busy.value = true
  router.post(`/sheq/safety-alerts/${a.uuid}/issue`, {}, {
    preserveScroll: true,
    onFinish: () => { busy.value = false },
  })
}

function markRead(a: Alert) {
  router.post(`/sheq/safety-alerts/${a.uuid}/read`, {}, { preserveScroll: true })
}

/**
 * An alert that cannot be issued yet, and why.
 *
 * Shown on the card rather than thrown on submit: the two questions that change behaviour are why
 * it happened and what was done, and somebody should see that before they press the button.
 */
function blocker(a: Alert): string | null {
  if (!a.why_it_happened && !a.what_we_changed) return t('sheq.alert.needs_why')
  return null
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('sheq.alert.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.alert.subtitle') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <!-- The learnings nobody has passed on yet. Deliberately above the sent ones. -->
    <VCard v-if="awaiting.length" elevation="0" border rounded="lg" class="mb-4" color="warning" variant="tonal">
      <VCardItem>
        <VCardTitle class="text-subtitle-1">{{ t('sheq.alert.awaiting_title') }}</VCardTitle>
        <VCardSubtitle>{{ t('sheq.alert.awaiting_subtitle') }}</VCardSubtitle>
      </VCardItem>
      <VCardText>
        <VList density="compact" bg-color="transparent">
          <VListItem v-for="a in awaiting" :key="a.uuid">
            <VListItemTitle>
              <Link :href="`/sheq/incidents/${a.uuid}`" class="text-primary">{{ a.reference }}</Link>
              <span class="text-medium-emphasis"> — {{ a.description }}</span>
            </VListItemTitle>
            <template #append>
              <VBtn v-if="can.manage" size="small" variant="tonal" :loading="busy"
                    @click="draftFrom(a.uuid)">
                {{ t('sheq.alert.draft_from') }}
              </VBtn>
            </template>
          </VListItem>
        </VList>
      </VCardText>
    </VCard>

    <!-- Drafts: written and never sent, which is the finding reaching a form and stopping. -->
    <template v-if="drafts.length">
      <h2 class="text-subtitle-1 font-weight-bold mb-2">{{ t('sheq.alert.drafts') }}</h2>
      <VRow class="mb-4">
        <VCol v-for="a in drafts" :key="a.uuid" cols="12" md="6">
          <VCard elevation="0" border rounded="lg" class="h-100">
            <VCardItem>
              <VCardTitle class="text-subtitle-1">{{ a.title }}</VCardTitle>
              <VCardSubtitle>{{ a.reference }}</VCardSubtitle>
              <template #append>
                <VChip size="small" :color="a.severity === 'CRITICAL' ? 'error' : 'info'" variant="tonal">
                  {{ a.severity }}
                </VChip>
              </template>
            </VCardItem>
            <VCardText>
              <div class="text-caption text-medium-emphasis">{{ t('sheq.alert.what_happened') }}</div>
              <p class="text-body-2">{{ a.what_happened }}</p>

              <div class="text-caption text-medium-emphasis">{{ t('sheq.alert.why') }}</div>
              <p class="text-body-2" :class="{ 'text-disabled': !a.why_it_happened }">
                {{ a.why_it_happened || t('sheq.alert.blank') }}
              </p>

              <div class="text-caption text-medium-emphasis">{{ t('sheq.alert.what_changed') }}</div>
              <p class="text-body-2 mb-0" style="white-space: pre-line"
                 :class="{ 'text-disabled': !a.what_we_changed }">
                {{ a.what_we_changed || t('sheq.alert.blank') }}
              </p>

              <VAlert v-if="blocker(a)" type="warning" variant="tonal" density="compact" class="mt-3">
                {{ blocker(a) }}
              </VAlert>
            </VCardText>
            <VCardActions v-if="can.manage">
              <VBtn variant="text" @click="edit(a)">{{ t('sheq.alert.edit') }}</VBtn>
              <VSpacer />
              <VBtn color="primary" variant="flat" :disabled="!!blocker(a)" :loading="busy"
                    @click="issue(a)">
                {{ t('sheq.alert.issue') }}
              </VBtn>
            </VCardActions>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <h2 class="text-subtitle-1 font-weight-bold mb-2">{{ t('sheq.alert.issued') }}</h2>
    <VCard elevation="0" border rounded="lg">
      <VTable density="comfortable">
        <thead>
          <tr>
            <th>{{ t('sheq.alert.col_reference') }}</th>
            <th>{{ t('sheq.alert.col_title') }}</th>
            <th>{{ t('sheq.alert.col_from') }}</th>
            <th class="text-center">{{ t('sheq.alert.col_reads') }}</th>
            <th class="text-right">{{ t('sheq.alert.col_action') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="a in issued" :key="a.uuid">
            <td class="text-no-wrap">{{ a.reference }}</td>
            <td>
              {{ a.title }}
              <VChip v-if="a.severity === 'CRITICAL'" size="x-small" color="error" variant="tonal" class="ms-1">
                {{ a.severity }}
              </VChip>
            </td>
            <td class="text-no-wrap">
              <Link v-if="a.incident_uuid" :href="`/sheq/incidents/${a.incident_uuid}`" class="text-primary">
                {{ a.incident_reference }}
              </Link>
              <span v-else class="text-medium-emphasis">—</span>
            </td>
            <td class="text-center">{{ a.reads }}</td>
            <td class="text-right">
              <VBtn size="small" variant="text" @click="markRead(a)">{{ t('sheq.alert.mark_read') }}</VBtn>
            </td>
          </tr>
          <tr v-if="!issued.length">
            <td colspan="5" class="text-center text-medium-emphasis py-8">
              {{ t('sheq.alert.none_issued') }}
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog :model-value="editing !== null" max-width="760" @update:model-value="editing = null">
      <VCard v-if="editing" rounded="lg">
        <VCardItem>
          <VCardTitle>{{ t('sheq.alert.edit_title') }}</VCardTitle>
          <VCardSubtitle>{{ editing.reference }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VTextField v-model="form.title" :label="t('sheq.alert.field_title')" variant="outlined"
                      density="comfortable" class="mb-3" />
          <VTextarea v-model="form.what_happened" :label="t('sheq.alert.what_happened')"
                     variant="outlined" density="comfortable" rows="3" class="mb-3" />
          <VTextarea v-model="form.why_it_happened" :label="t('sheq.alert.why')"
                     :hint="t('sheq.alert.why_hint')" persistent-hint
                     variant="outlined" density="comfortable" rows="3" class="mb-3" />
          <VTextarea v-model="form.what_we_changed" :label="t('sheq.alert.what_changed')"
                     :hint="t('sheq.alert.what_changed_hint')" persistent-hint
                     variant="outlined" density="comfortable" rows="3" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="editing = null">{{ t('sheq.alert.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="busy" @click="save">
            {{ t('sheq.alert.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
