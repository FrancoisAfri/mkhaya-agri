<script setup lang="ts">
/**
 * Every version of every document a contractor has filed (CON-24).
 *
 * The chain was recorded and unreadable: each renewal wrote its version number and a pointer back
 * to what it replaced, and no screen showed any of it. "What did they have on file in March" was
 * a question whose answer sat in the database with no way to ask it.
 */
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

type Version = {
  uuid: string
  document_type: string
  title: string | null
  reference: string | null
  issuing_body: string | null
  version: number
  issued_on: string | null
  expires_on: string | null
  status: string | null
  created_at: string
  superseded_at: string | null
  supersede_reason: string | null
  superseded_by_name: string | null
  in_force: boolean
  has_file: boolean
}

const props = defineProps<{
  contractors: { uuid: string; name: string }[]
  selected: { uuid: string; name: string } | null
  versions: Record<string, Version[]>
  asAt: string
  inForceThen: {
    document_type: string; title: string | null; version: number
    expires_on: string | null; expired_then: boolean; status: string | null
  }[]
  noCompany?: boolean
}>()

const contractor = ref(props.selected?.uuid ?? null)
const asAt = ref(props.asAt)

watch([contractor, asAt], () => {
  router.get('/sheq/document-versions',
    { contractor: contractor.value, as_at: asAt.value },
    { preserveState: true, preserveScroll: true, replace: true })
})

const types = computed(() => Object.keys(props.versions).sort())

const label = (value: string) =>
  value.toLowerCase().replace(/_/g, ' ').replace(/^./, c => c.toUpperCase())

const today = new Date().toISOString().slice(0, 10)
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">Document versions</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">
        What replaced what, when it stopped counting, and what was on file on any given day
      </p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VCard elevation="0" border rounded="lg" class="mb-4">
        <VCardText class="d-flex flex-wrap align-center ga-3">
          <VSelect
            v-model="contractor"
            :items="contractors.map(c => ({ value: c.uuid, title: c.name }))"
            density="compact" variant="outlined" hide-details style="max-width: 320px"
            label="Contractor"
          />
          <VTextField
            v-model="asAt" type="date" :max="today" density="compact" variant="outlined"
            hide-details style="max-width: 200px" label="As at"
          />
        </VCardText>
      </VCard>

      <VRow>
        <!-- ── What was in force on the chosen day ──────────────────────────────────────── -->
        <VCol cols="12" md="5">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              On file on {{ asAt }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pb-2">
              <p class="text-body-2 text-medium-emphasis mb-0">
                The versions actually in force that day, with expiry judged as at that day —
                a certificate that expired last week was perfectly valid in March.
              </p>
            </VCardText>

            <div v-if="!inForceThen.length" class="text-center py-8 text-medium-emphasis">
              <div class="text-body-2">Nothing was on file on that date.</div>
            </div>

            <VTable v-else density="compact">
              <tbody>
                <tr v-for="(doc, i) in inForceThen" :key="i">
                  <td class="py-2">
                    <div class="text-body-2">
                      {{ doc.title || label(doc.document_type) }}
                      <span class="text-caption text-medium-emphasis">v{{ doc.version }}</span>
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      <template v-if="doc.expires_on">
                        Expired {{ doc.expires_on }}
                      </template>
                      <template v-else>No expiry</template>
                      <template v-if="doc.status"> · {{ label(doc.status) }}</template>
                    </div>
                  </td>
                  <td style="width: 100px" class="text-end">
                    <VChip size="x-small" variant="tonal"
                           :color="doc.expired_then ? 'error' : 'success'">
                      {{ doc.expired_then ? 'Expired' : 'Valid' }}
                    </VChip>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>

        <!-- ── The chain ────────────────────────────────────────────────────────────────── -->
        <VCol cols="12" md="7">
          <div v-if="!types.length">
            <VCard elevation="0" border rounded="lg">
              <div class="text-center py-10 text-medium-emphasis">
                <VIcon icon="mdi-file-multiple-outline" size="32" class="mb-2" />
                <div class="text-body-2">This contractor has filed no documents.</div>
              </div>
            </VCard>
          </div>

          <VCard v-for="type in types" :key="type" elevation="0" border rounded="lg"
                 class="mb-4">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ label(type) }}
              <span class="text-caption text-medium-emphasis font-weight-regular">
                · {{ versions[type].length }}
                {{ versions[type].length === 1 ? 'version' : 'versions' }}
              </span>
            </VCardTitle>
            <VDivider />
            <VTable density="compact">
              <tbody>
                <tr v-for="doc in versions[type]" :key="doc.uuid">
                  <td style="width: 62px" class="text-body-2 font-weight-medium">
                    v{{ doc.version }}
                  </td>
                  <td class="py-2">
                    <div class="d-flex align-center flex-wrap ga-2">
                      <VChip size="x-small" variant="tonal"
                             :color="doc.in_force ? 'success' : 'grey'">
                        {{ doc.in_force ? 'In force' : 'Replaced' }}
                      </VChip>
                      <span class="text-body-2">{{ doc.title || label(doc.document_type) }}</span>
                      <VChip v-if="doc.status && doc.status !== 'ACCEPTED'" size="x-small"
                             variant="tonal" color="warning">
                        {{ label(doc.status) }}
                      </VChip>
                      <VIcon v-if="!doc.has_file" icon="mdi-file-remove-outline" size="14"
                             class="text-medium-emphasis" />
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      Filed {{ doc.created_at }}
                      <template v-if="doc.reference"> · {{ doc.reference }}</template>
                      <template v-if="doc.issuing_body"> · {{ doc.issuing_body }}</template>
                      <template v-if="doc.expires_on"> · expires {{ doc.expires_on }}</template>
                    </div>
                    <!-- Why it stopped counting. A certificate set aside for a mistyped expiry
                         and one revoked by its issuer look identical in a flat list and are
                         completely different facts. -->
                    <div v-if="doc.superseded_at" class="text-caption mt-1">
                      Replaced {{ doc.superseded_at }}
                      <template v-if="doc.superseded_by_name">
                        by {{ doc.superseded_by_name }}
                      </template>
                      <template v-if="doc.supersede_reason">
                        — {{ doc.supersede_reason }}
                      </template>
                    </div>
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
