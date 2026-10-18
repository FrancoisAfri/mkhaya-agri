<script setup lang="ts">
/**
 * Principal contractor, subcontractor, worker (CON-25).
 *
 * The register was flat, so the party Construction Regulation 7 holds responsible for the firms
 * it appoints had no way to see whether those firms were in order — and the host had no way to
 * show them except by email, which is out of date before it arrives.
 *
 * The visibility control is the careful part. Being accountable for a firm's compliance does not
 * make its staff's medicals yours to read, so the host chooses what each principal is shown, and
 * sees exactly what that produces before saving it.
 */
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'
import { useReasonLabel } from '@/Modules/Sheq/Partials/useReasonLabel'

type Contractor = {
  uuid: string
  name: string
  trade: string | null
  access_status: string | null
  is_active: boolean
  principal_name: string | null
  principal_uuid: string | null
}

type Rollup = {
  visibility: string[]
  counts: { total: number; not_compliant: number }
  subcontractors: {
    uuid: string; name: string; trade: string | null; depth: number; is_active: boolean
    outcome?: string; allowed?: boolean; outstanding?: Record<string, number>
    documents?: string[]; safety_file?: string | null; workers?: number
    approved_sites?: string[]; incidents?: number
  }[]
}

const props = defineProps<{
  contractors: Contractor[]
  selected: { uuid: string; name: string } | null
  rollup: Rollup | null
  visibilityOptions: { key: string; note: string; required: boolean }[]
  visibility: string[]
  canManage?: boolean
  noCompany?: boolean
}>()

const reasonLabel = useReasonLabel()

const selected = ref(props.selected?.uuid ?? null)

watch(selected, value => {
  router.get('/sheq/subcontractors', { contractor: value },
    { preserveState: true, preserveScroll: true, replace: true })
})

const appoint = useForm({ principal: props.selected?.uuid ?? null, subcontractor: null as string | null })

watch(selected, value => { appoint.principal = value })

function submitAppointment() {
  appoint.post('/sheq/subcontractors/appoint', {
    preserveScroll: true,
    onSuccess: () => { appoint.subcontractor = null },
  })
}

const visibilityForm = useForm({ visibility: [...props.visibility] })

const visibilityDirty = computed(() =>
  JSON.stringify([...visibilityForm.visibility].sort())
    !== JSON.stringify([...props.visibility].sort()))

function saveVisibility() {
  visibilityForm.post(`/sheq/subcontractors/${selected.value}/visibility`,
    { preserveScroll: true })
}

function release(uuid: string) {
  router.post(`/sheq/subcontractors/${uuid}/release`, {}, { preserveScroll: true })
}

// Candidates for appointment: not the principal itself, and not a firm already beneath it.
const candidates = computed(() => props.contractors
  .filter(c => c.uuid !== selected.value)
  .filter(c => !props.rollup?.subcontractors.some(s => s.uuid === c.uuid))
  .map(c => ({
    value: c.uuid,
    title: c.principal_name
      ? `${c.name} — currently under ${c.principal_name}`
      : c.name,
  })))

const independents = computed(() => props.contractors.filter(c => !c.principal_uuid))

const label = (value: string) =>
  value.toLowerCase().replace(/_/g, ' ').replace(/^./, c => c.toUpperCase())

const outcomeColour = (outcome?: string) => ({
  ALLOWED: 'success', DENIED: 'error',
}[outcome ?? ''] ?? 'warning')
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">Subcontractors</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">
        Who appointed whom, whether the firms beneath them are in order, and what each principal
        may see
      </p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VCard elevation="0" border rounded="lg" class="mb-4">
        <VCardText class="d-flex flex-wrap align-center ga-3">
          <VSelect
            v-model="selected"
            :items="contractors.map(c => ({ value: c.uuid, title: c.name }))"
            density="compact" variant="outlined" hide-details style="max-width: 320px"
            label="Principal contractor"
          />
          <VChip v-if="rollup" size="small" variant="tonal">
            {{ rollup.counts.total }}
            {{ rollup.counts.total === 1 ? 'subcontractor' : 'subcontractors' }}
          </VChip>
          <VChip v-if="rollup?.counts.not_compliant" size="small" variant="tonal" color="error">
            {{ rollup.counts.not_compliant }} not compliant
          </VChip>
        </VCardText>
      </VCard>

      <VRow>
        <VCol cols="12" md="7">
          <!-- ── The chain beneath this principal ─────────────────────────────────────────── -->
          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              Firms appointed by {{ selected ? (props.selected?.name ?? '') : '' }}
            </VCardTitle>
            <VDivider />

            <div v-if="!rollup?.subcontractors.length"
                 class="text-center py-10 text-medium-emphasis">
              <VIcon icon="mdi-sitemap-outline" size="32" class="mb-2" />
              <div class="text-body-2">
                This contractor has appointed nobody. Appoint one below.
              </div>
            </div>

            <VTable v-else density="compact">
              <tbody>
                <tr v-for="sub in rollup.subcontractors" :key="sub.uuid">
                  <td class="py-2">
                    <div class="d-flex align-center flex-wrap ga-2"
                         :style="{ paddingLeft: `${(sub.depth - 1) * 20}px` }">
                      <!-- Depth shown by indent: a sub-subcontractor is a real arrangement and
                           a flat list makes it look like a direct appointment. -->
                      <VIcon v-if="sub.depth > 1" icon="mdi-subdirectory-arrow-right" size="14"
                             class="text-medium-emphasis" />
                      <VChip v-if="sub.outcome" size="x-small" variant="tonal"
                             :color="outcomeColour(sub.outcome)">
                        {{ label(sub.outcome) }}
                      </VChip>
                      <span class="text-body-2">{{ sub.name }}</span>
                      <span v-if="sub.trade" class="text-caption text-medium-emphasis">
                        {{ label(sub.trade) }}
                      </span>
                      <VChip v-if="!sub.is_active" size="x-small" variant="tonal" color="grey">
                        inactive
                      </VChip>
                    </div>
                    <div class="text-caption text-medium-emphasis mt-1"
                         :style="{ paddingLeft: `${(sub.depth - 1) * 20}px` }">
                      <template v-if="sub.outstanding && Object.keys(sub.outstanding).length">
                        Outstanding:
                        <span v-for="(n, subject) in sub.outstanding" :key="subject">
                          {{ n }} {{ label(String(subject)) }}<!-- -->;
                        </span>
                      </template>
                      <template v-else-if="sub.outcome === 'ALLOWED'">Nothing outstanding.</template>
                      <template v-if="sub.safety_file">
                        · safety file {{ label(sub.safety_file) }}
                      </template>
                      <template v-if="sub.workers !== undefined">
                        · {{ sub.workers }} on the roster
                      </template>
                      <template v-if="sub.incidents !== undefined">
                        · {{ sub.incidents }} incidents
                      </template>
                      <template v-if="sub.approved_sites?.length">
                        · approved at {{ sub.approved_sites.join(', ') }}
                      </template>
                    </div>
                    <div v-if="sub.documents?.length" class="d-flex flex-wrap ga-1 mt-1"
                         :style="{ paddingLeft: `${(sub.depth - 1) * 20}px` }">
                      <VChip v-for="code in sub.documents" :key="code" size="x-small"
                             variant="text" :title="code">
                        {{ reasonLabel(code) }}
                      </VChip>
                    </div>
                  </td>
                  <td style="width: 100px" class="text-end">
                    <VBtn v-if="canManage && sub.depth === 1" size="x-small" variant="text"
                          @click="release(sub.uuid)">
                      Release
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>

          <VCard v-if="canManage" elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              Appoint a subcontractor
            </VCardTitle>
            <VDivider />
            <VCardText class="d-flex flex-wrap align-end ga-3">
              <VSelect
                v-model="appoint.subcontractor" :items="candidates" density="compact"
                variant="outlined" hide-details="auto" style="min-width: 320px"
                label="Firm being appointed" :error-messages="appoint.errors.subcontractor"
              />
              <VBtn color="primary" variant="flat" :loading="appoint.processing"
                    :disabled="!appoint.subcontractor" @click="submitAppointment">
                Appoint
              </VBtn>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12" md="5">
          <!-- ── The permission boundary ──────────────────────────────────────────────────── -->
          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              What this principal may see
            </VCardTitle>
            <VDivider />
            <VCardText>
              <p class="text-body-2 text-medium-emphasis">
                A principal contractor is accountable for the firms it appoints, so it must be
                able to see the state of their compliance. That does not extend to their workers'
                medical records or to anybody else's commercial affairs.
              </p>

              <VCheckbox
                v-for="option in visibilityOptions" :key="option.key"
                v-model="visibilityForm.visibility" :value="option.key"
                density="compact" hide-details
                :disabled="!canManage || option.required"
              >
                <template #label>
                  <div>
                    <div class="text-body-2">
                      {{ label(option.key) }}
                      <span v-if="option.required" class="text-caption text-medium-emphasis">
                        · always shown
                      </span>
                    </div>
                    <div class="text-caption text-medium-emphasis">{{ option.note }}</div>
                  </div>
                </template>
              </VCheckbox>

              <div v-if="canManage && visibilityDirty" class="d-flex justify-end mt-3">
                <VBtn color="primary" variant="flat" size="small"
                      :loading="visibilityForm.processing" @click="saveVisibility">
                  Save
                </VBtn>
              </div>
            </VCardText>
          </VCard>

          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              Answering directly to you
              <span class="text-caption text-medium-emphasis font-weight-regular">
                · {{ independents.length }}
              </span>
            </VCardTitle>
            <VDivider />
            <VTable density="compact" style="max-height: 420px; overflow-y: auto">
              <tbody>
                <tr v-for="c in independents" :key="c.uuid">
                  <td class="text-body-2">{{ c.name }}</td>
                  <td class="text-end text-caption text-medium-emphasis">
                    {{ c.access_status ? label(c.access_status) : '—' }}
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>
