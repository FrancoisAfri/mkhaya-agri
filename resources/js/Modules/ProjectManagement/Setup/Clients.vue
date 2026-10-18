<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute

type Client = {
  id: string; name: string | null; source: string; status: string
  email: string | null; phone: string | null; city: string | null
  projects: number; synced_at: string | null
}

const props = defineProps<{
  clients: Client[]
  /** True when CRM owns customers on this tenant, so PM should link rather than keep its own. */
  integrated: boolean
  /** How many PM-local clients are waiting to be matched into CRM. */
  pending: number
  /** The platform's town list — picked rather than typed, so grouping by city means something. */
  cities: string[]
}>()

const search = ref('')

const visible = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.clients
  return props.clients.filter(c =>
    (c.name ?? '').toLowerCase().includes(q) || (c.email ?? '').toLowerCase().includes(q))
})

const statusColor: Record<string, string> = {
  ok: 'success', stale: 'warning', orphaned: 'error', conflict: 'error',
}

const dialog = ref(false)
/*
 * The details an invoice is addressed with, asked for once.
 *
 * This form took a name, an email and a town, and the client's first invoice then printed an empty
 * "To:" block and no VAT number — a document that is not a valid tax invoice. The server has
 * accepted these fields all along; the form simply never offered them, so they were retyped on the
 * first account of every project instead.
 */
const form = useForm({
  name: '', email: '', phone: '', city: '', notes: '',
  address: '', vat_number: '', attention: '', client_project_number: '',
})

function save() {
  form.post(route('project-management.setup.clients.store'), {
    preserveScroll: true,
    onSuccess: () => { dialog.value = false; form.reset() },
  })
}

const confirmer = ref()
const syncing = ref(false)

function sync() {
  confirmer.value.open({
    title: t('project_management.setup_clients.confirm_sync_title'),
    message: t('project_management.setup_clients.confirm_sync_body', { count: props.pending }),
    confirmText: t('project_management.setup_clients.sync'),
    confirmColor: 'primary',
    icon: 'tabler-refresh',
  }, () => {
    syncing.value = true
    router.post(route('project-management.setup.clients.sync'), {}, {
      preserveScroll: true,
      onFinish: () => { syncing.value = false },
    })
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('project_management.setup_clients.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('project_management.setup_clients.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn
          v-if="integrated && pending" variant="tonal" prepend-icon="tabler-refresh"
          :loading="syncing" @click="sync"
        >
          {{ t('project_management.setup_clients.adopt_count', { count: pending }) }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="dialog = true">
          {{ t('project_management.setup_clients.add') }}
        </VBtn>
      </div>
    </div>

    <!--
      Says which system owns the customer list, because it changes what "add a client" means and
      where the record will be found afterwards.
    -->
    <VAlert :type="integrated ? 'success' : 'info'" variant="tonal" density="compact" class="mb-4">
      {{ integrated
        ? t('project_management.setup_clients.owned_by_crm')
        : t('project_management.setup_clients.owned_by_pm') }}
    </VAlert>

    <VCard border>
      <VCardItem>
        <VCardTitle class="text-body-1">
          {{ t('project_management.setup_clients.list', { count: clients.length }) }}
        </VCardTitle>
        <template #append>
          <VTextField
            v-model="search" density="compact" variant="outlined" hide-details
            prepend-inner-icon="tabler-search" style="min-width:260px"
            :label="t('project_management.setup_clients.search')"
          />
        </template>
      </VCardItem>
      <VDivider />

      <VTable v-if="visible.length" density="comfortable">
        <thead>
          <tr>
            <th>{{ t('project_management.setup_clients.col_name') }}</th>
            <th>{{ t('project_management.setup_clients.col_contact') }}</th>
            <th>{{ t('project_management.setup_clients.col_source') }}</th>
            <th class="text-right">{{ t('project_management.setup_clients.col_projects') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="client in visible" :key="client.id">
            <td>
              {{ client.name }}
              <div v-if="client.city" class="text-caption text-medium-emphasis">{{ client.city }}</div>
            </td>
            <td class="text-caption">
              <div v-if="client.email">{{ client.email }}</div>
              <div v-if="client.phone">{{ client.phone }}</div>
              <span v-if="!client.email && !client.phone">—</span>
            </td>
            <td>
              <VChip size="x-small" label :color="client.source === 'local' ? 'grey' : 'primary'">
                {{ client.source === 'local'
                  ? t('project_management.setup_clients.source_local')
                  : client.source }}
              </VChip>
              <VChip
                v-if="client.status !== 'ok'" size="x-small" label class="ms-1"
                :color="statusColor[client.status] ?? 'grey'"
              >
                {{ t(`project_management.setup_clients.status_${client.status}`) }}
              </VChip>
            </td>
            <td class="text-right">{{ client.projects }}</td>
          </tr>
        </tbody>
      </VTable>

      <VCardText v-else class="text-center text-medium-emphasis py-8">
        {{ t('project_management.setup_clients.none') }}
      </VCardText>
    </VCard>

    <VDialog v-model="dialog" max-width="560">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.setup_clients.add') }}</VCardTitle></VCardItem>
        <VDivider />
        <VCardText>
          <VAlert v-if="integrated" type="info" variant="tonal" density="compact" class="mb-3">
            {{ t('project_management.setup_clients.will_go_to_crm') }}
          </VAlert>
          <VRow dense>
            <VCol cols="12">
              <VTextField v-model="form.name" :label="t('project_management.setup_clients.field_name')"
                          :error-messages="form.errors.name" variant="outlined" density="compact" autofocus />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.email" type="email"
                          :label="t('project_management.setup_clients.field_email')"
                          :hint="t('project_management.setup_clients.hint_email')" persistent-hint
                          :error-messages="form.errors.email" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.phone" :label="t('project_management.setup_clients.field_phone')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VAutocomplete
                v-model="form.city" :items="cities"
                :label="t('project_management.setup_clients.field_city')"
                :no-data-text="t('project_management.setup_clients.no_city_match')"
                variant="outlined" density="compact" clearable
              />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="form.address" rows="2"
                         :label="t('project_management.setup_clients.field_address')"
                         :hint="t('project_management.setup_clients.hint_address')" persistent-hint
                         :error-messages="form.errors.address" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.vat_number"
                          :label="t('project_management.setup_clients.field_vat_number')"
                          :hint="t('project_management.setup_clients.hint_vat_number')" persistent-hint
                          :error-messages="form.errors.vat_number" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.attention"
                          :label="t('project_management.setup_clients.field_attention')"
                          :hint="t('project_management.setup_clients.hint_attention')" persistent-hint
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.client_project_number"
                          :label="t('project_management.setup_clients.field_client_project_number')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="form.notes" rows="2" :label="t('project_management.setup_clients.field_notes')"
                         variant="outlined" density="compact" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.name" @click="save">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmer" />
  </div>
</template>
