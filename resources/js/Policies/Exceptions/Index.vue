<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import AppTextField from '@/@core/components/app-form-elements/AppTextField.vue'
import AppSelect from '@/@core/components/app-form-elements/AppSelect.vue'
import { formatDate } from '@/utils/policyFormatters'

const route = ziggyRoute

const props = defineProps<{
  exceptions: {
    data: Array<any>
    links?: Array<{ url: string | null; label: string; active: boolean }>
  }
  filters?: Record<string, any>
  statuses?: string[]
}>()

const search = ref(props.filters?.search ?? '')
const status = ref(props.filters?.status ?? '')

const rows = computed(() => props.exceptions?.data ?? [])

function applyFilters() {
  router.get(
    route('policy-exceptions.index'),
    {
      search: search.value || undefined,
      status: status.value || undefined,
    },
    { preserveState: true, preserveScroll: true },
  )
}

function resetFilters() {
  search.value = ''
  status.value = ''
  applyFilters()
}

function goToView(id: number) {
  router.visit(route('policy-exceptions.show', id))
}

function chipColor(value: string) {
  if (value === 'rejected' || value === 'revoked') return 'error'
  if (value === 'approved') return 'success'
  if (value === 'expired') return 'warning'
  return 'info'
}

</script>

<template>
  <section class="bg-[#f8f8ff]">
    <VContainer fluid class="pa-4 pa-sm-6">
      <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
        <div>
          <h3 class="text-h5 font-weight-semibold mb-1">Policy Exceptions</h3>
          <p class="text-medium-emphasis mb-0">Track exception requests and decisions</p>
        </div>
      </div>

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
            { title: 'Requested By', key: 'requested_by' },
            { title: 'Status', key: 'status' },
            { title: 'Valid From', key: 'valid_from' },
            { title: 'Valid Until', key: 'valid_until' },
            { title: 'Actions', key: 'actions', sortable: false, align: 'center' },
          ]"
          :items="rows"
          item-value="id"
          hover
        >
          <template #item.policy="{ item }">{{ item.policy?.name || '-' }}</template>
          <template #item.rule="{ item }">{{ item.rule?.name || '-' }}</template>
          <template #item.requested_by="{ item }">{{ item.requested_by?.name || item.requestedBy?.name || '-' }}</template>

          <template #item.status="{ item }">
            <VChip :color="chipColor(item.status)" size="small" variant="tonal" label>
              {{ item.status }}
            </VChip>
          </template>

          <template #item.valid_from="{ item }">{{ formatDate(item.valid_from) }}</template>
          <template #item.valid_until="{ item }">{{ formatDate(item.valid_until) }}</template>

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

        <VCardText v-if="exceptions?.links?.length" class="pt-0">
          <div class="d-flex flex-wrap gap-2">
            <template v-for="(link, idx) in exceptions.links" :key="idx">
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
    </VContainer>
  </section>
</template>
