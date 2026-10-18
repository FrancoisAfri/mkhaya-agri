<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import AppTextField from '@/@core/components/app-form-elements/AppTextField.vue'
import AppSelect from '@/@core/components/app-form-elements/AppSelect.vue'

const route = ziggyRoute

const props = defineProps<{
  violations: {
    data: Array<any>
    links?: Array<{ url: string | null; label: string; active: boolean }>
  }
  filters?: Record<string, any>
  statuses?: string[]
  severities?: string[]
}>()

const search = ref(props.filters?.search ?? '')
const status = ref(props.filters?.status ?? '')
const severity = ref(props.filters?.severity ?? '')

const rows = computed(() => props.violations?.data ?? [])
const openCount = computed(() => rows.value.filter((row: any) => row.status === 'open').length)
const criticalCount = computed(() => rows.value.filter((row: any) => row.severity === 'critical').length)
const focusViolation = computed(() => rows.value[0] ?? null)

function violationMessage(item: any) {
  if (!item) return 'No violation selected.'
  return `[${item.severity?.toUpperCase() || 'INFO'}] ${item.policy?.name || 'Policy'} - ${item.rule?.name || 'Rule'} breached by ${item.user?.name || 'unknown user'}. Outcome: ${item.outcome || 'pending'}.`
}

function violationExplanation(item: any) {
  if (!item) return 'Filter violations to load enforcement explanations.'
  return item.explanation || 'No AI explanation stored for this violation yet. Investigators can add notes and evidence in the detail view.'
}

function applyFilters() {
  const filters: Record<string, any> = {
    search: search.value || undefined,
    status: status.value || undefined,
    severity: severity.value || undefined,
  }
  
  // Preserve policy_uuid filter if it exists
  if (props.filters?.policy_uuid) {
    filters.policy_uuid = props.filters.policy_uuid
  }
  
  router.get(
    route('policy-violations.index'),
    filters,
    { preserveState: true, preserveScroll: true },
  )
}

function resetFilters() {
  search.value = ''
  status.value = ''
  severity.value = ''
  
  const filters: Record<string, any> = {}
  
  // Preserve policy_uuid filter if it exists
  if (props.filters?.policy_uuid) {
    filters.policy_uuid = props.filters.policy_uuid
  }
  
  router.get(
    route('policy-violations.index'),
    filters,
    { preserveState: true, preserveScroll: true },
  )
}

function goToView(id: number) {
  router.visit(route('policy-violations.show', id))
}

function chipColor(value: string) {
  if (value === 'critical' || value === 'dismissed') return 'error'
  if (value === 'high' || value === 'under_investigation') return 'warning'
  if (value === 'resolved') return 'success'
  return 'info'
}
</script>

<template>
  <section class="bg-[#f8f8ff]">
    <VContainer fluid class="pa-4 pa-sm-6">
      <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
        <div>
          <h3 class="text-h5 font-weight-semibold mb-1">Policy Violations</h3>
          <p class="text-medium-emphasis mb-0">Review and investigate policy breach events</p>
        </div>
      </div>

      <VCard elevation="0" border rounded="lg" class="mb-4">
        <VCardText>
          <VAlert
            v-if="criticalCount > 0"
            type="error"
            variant="tonal"
            class="mb-4"
          >
            Real-time warning: {{ criticalCount }} critical violation(s) need immediate review.
          </VAlert>

          <VRow class="mb-2">
            <VCol cols="12" sm="6">
              <VCard color="warning" variant="tonal">
                <VCardText>
                  <div class="text-caption text-medium-emphasis">Open Violations</div>
                  <div class="text-h5 font-weight-semibold">{{ openCount }}</div>
                </VCardText>
              </VCard>
            </VCol>
            <VCol cols="12" sm="6">
              <VCard color="error" variant="tonal">
                <VCardText>
                  <div class="text-caption text-medium-emphasis">Critical Violations</div>
                  <div class="text-h5 font-weight-semibold">{{ criticalCount }}</div>
                </VCardText>
              </VCard>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <VCard elevation="0" border rounded="lg" class="mb-4">
        <VCardText class="pa-4">
          <div class="d-flex flex-wrap gap-3 align-end">
            <AppTextField v-model="search" label="Search" density="comfortable" hide-details style="max-width: 280px" />

            <AppSelect
              v-model="status"
              :items="props.statuses ?? []"
              label="Status"
              density="comfortable"
              hide-details
              clearable
              style="max-width: 220px"
            />

            <AppSelect
              v-model="severity"
              :items="props.severities ?? []"
              label="Severity"
              density="comfortable"
              hide-details
              clearable
              style="max-width: 220px"
            />

            <VBtn color="primary" @click="applyFilters">Apply</VBtn>
            <VBtn variant="text" @click="resetFilters">Reset</VBtn>
          </div>
        </VCardText>
      </VCard>

      <VCard elevation="0" border rounded="lg">
        <VDataTable
          :headers="[
            { title: 'Policy', key: 'policy' },
            { title: 'Rule', key: 'rule' },
            { title: 'User', key: 'user' },
            { title: 'Severity', key: 'severity' },
            { title: 'Status', key: 'status' },
            { title: 'Outcome', key: 'outcome' },
            { title: 'Actions', key: 'actions', sortable: false, align: 'center' },
          ]"
          :items="rows"
          item-value="id"
          hover
        >
          <template #item.policy="{ item }">{{ item.policy?.name || '-' }}</template>
          <template #item.rule="{ item }">{{ item.rule?.name || '-' }}</template>
          <template #item.user="{ item }">{{ item.user?.name || '-' }}</template>

          <template #item.severity="{ item }">
            <VChip :color="chipColor(item.severity)" size="small" variant="tonal" label>
              {{ item.severity }}
            </VChip>
          </template>

          <template #item.status="{ item }">
            <VChip :color="chipColor(item.status)" size="small" variant="tonal" label>
              {{ item.status }}
            </VChip>
          </template>

          <template #item.actions="{ item }">
            <div class="d-flex justify-center">
              <VMenu location="bottom end">
                <template #activator="{ props: menuProps }">
                  <VBtn v-bind="menuProps" icon size="small" variant="text">
                    <i class="fa-solid fa-ellipsis-vertical" />
                  </VBtn>
                </template>

                <VList density="compact" min-width="170">
                  <VListItem @click="goToView(item.id)">
                    <template #prepend>
                      <i class="fa-solid fa-eye me-2" style="font-size:13px;color:rgb(var(--v-theme-primary))" />
                    </template>
                    <template #title>
                      <span>View</span>
                    </template>
                  </VListItem>
                </VList>
              </VMenu>
            </div>
          </template>
        </VDataTable>

        <VCardText v-if="violations?.links?.length" class="pt-0">
          <div class="d-flex flex-wrap gap-2">
            <template v-for="(link, idx) in violations.links" :key="idx">
              <VBtn
                v-if="link.url"
                size="small"
                :variant="link.active ? 'flat' : 'text'"
                :color="link.active ? 'primary' : undefined"
                @click="router.visit(link.url, { preserveState: true, preserveScroll: true })"
                v-html="link.label"
              />
            </template>
          </div>
        </VCardText>
      </VCard>

      <VRow class="mt-4">
        <VCol cols="12" md="6">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle>Violation Message Center</VCardTitle>
            <VCardText>
              <pre class="text-caption px-3 py-2 mb-0 rounded bg-grey-lighten-4" style="white-space: pre-wrap;">{{ violationMessage(focusViolation) }}</pre>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12" md="6">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle>Explanation Panel</VCardTitle>
            <VCardText>
              <p class="mb-0">{{ violationExplanation(focusViolation) }}</p>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </VContainer>
  </section>
</template>
