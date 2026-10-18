<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

/**
 * Who the account is to, who it is from, and where to pay.
 *
 * Collapsed by default: on a mature project every one of these carries forward from the previous
 * account and nobody touches them again. It is the *first* account on a project that has nothing
 * to carry forward — and without somewhere to type them, its Tax Invoice printed with no client,
 * no consultant and no banking block.
 */
const props = defineProps<{
  invoiceId: string
  invoice: Record<string, any>
  editable: boolean
}>()

const open = ref(!props.invoice.client_name)

/*
 * ── Picking the client rather than typing it ────────────────────────────────
 *
 * The name, the address and the VAT number were three free-text boxes, retyped on the first account
 * of every project. A client's name spelled two ways across two projects is two clients to anybody
 * reading a report, and a VAT number typed from memory is a tax invoice that is wrong.
 *
 * What is picked is copied onto the invoice, not linked to it. That is deliberate and matches the
 * rest of this form: an account already issued must keep the details it was issued with, so a
 * client who moves offices next year cannot restate last year's invoice. Everything below stays
 * editable for the same reason — the snapshot has to be correctable.
 */
const clientOptions = ref<Array<{ title: string; value: string; address: string | null }>>([])
const clientSearch = ref('')
const clientsLoading = ref(false)

async function findClients(query: string) {
  clientsLoading.value = true
  try {
    const response = await fetch(
      route('project-management.clients.search') + '?q=' + encodeURIComponent(query),
      { headers: { Accept: 'application/json' }, credentials: 'same-origin' },
    )
    const data = await response.json()

    clientOptions.value = (data.clients ?? []).map((client: any) => ({
      title: client.label,
      value: client.id,
      address: client.meta?.address ?? null,
    }))
  } finally {
    clientsLoading.value = false
  }
}

let clientDebounce: ReturnType<typeof setTimeout> | undefined

watch(clientSearch, value => {
  clearTimeout(clientDebounce)
  clientDebounce = setTimeout(() => findClients(value ?? ''), 250)
})

onMounted(() => findClients(''))

/**
 * Copy what the picker knows; leave alone what it does not.
 *
 * The address is only written when the source actually has one — overwriting a typed address with
 * a blank because CRM happens to hold none would lose work somebody had already done.
 */
function useClient(id: string | null) {
  const picked = clientOptions.value.find(option => option.value === id)
  if (!picked) return

  form.client_name = picked.title
  if (picked.address) form.client_address = picked.address
}

const form = useForm({
  invoice_date: props.invoice.invoice_date ?? '',
  period_start: props.invoice.period_start ?? '',
  period_end: props.invoice.period_end ?? '',
  description: props.invoice.description ?? '',

  client_name: props.invoice.client_name ?? '',
  client_address: props.invoice.client_address ?? '',
  client_vat_number: props.invoice.client_vat_number ?? '',
  attention: props.invoice.attention ?? '',
  client_project_number: props.invoice.client_project_number ?? '',

  consultant_name: props.invoice.consultant_name ?? '',
  consultant_address: props.invoice.consultant_address ?? '',
  consultant_reference: props.invoice.consultant_reference ?? '',
  consultant_vat_number: props.invoice.consultant_vat_number ?? '',
  vendor_number: props.invoice.vendor_number ?? '',
  file_number: props.invoice.file_number ?? '',

  banking_name: props.invoice.banking_name ?? '',
  banking_account_name: props.invoice.banking_account_name ?? '',
  banking_account_number: props.invoice.banking_account_number ?? '',
  banking_branch_code: props.invoice.banking_branch_code ?? '',
  banking_reference: props.invoice.banking_reference ?? '',
})

function save() {
  form.put(route('project-management.invoicing.details.update', props.invoiceId), { preserveScroll: true })
}
</script>

<template>
  <VCard border>
    <VCardItem style="cursor: pointer" @click="open = !open">
      <VCardTitle class="text-body-1">{{ t('project_management.invoicing.details_title') }}</VCardTitle>
      <VCardSubtitle>{{ t('project_management.invoicing.details_sub') }}</VCardSubtitle>
      <template #append>
        <VIcon :icon="open ? 'tabler-chevron-up' : 'tabler-chevron-down'" />
      </template>
    </VCardItem>

    <VExpandTransition>
      <div v-show="open">
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="4">
              <VTextField
                v-model="form.invoice_date" type="date" :disabled="!props.editable"
                :label="t('project_management.invoicing.col_date')"
                :error-messages="form.errors.invoice_date" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="form.period_start" type="date" :disabled="!props.editable"
                :label="t('project_management.invoicing.period_start')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="form.period_end" type="date" :disabled="!props.editable"
                :label="t('project_management.invoicing.period_end')"
                :error-messages="form.errors.period_end" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.description" rows="2" :disabled="!props.editable"
                :label="t('project_management.invoicing.field_description')"
                :hint="t('project_management.invoicing.field_description_hint')" persistent-hint
                variant="outlined" density="compact"
              />
            </VCol>
          </VRow>

          <!-- ── The client ───────────────────────────────────────────────── -->
          <h6 class="text-subtitle-2 mt-4 mb-2">{{ t('project_management.invoicing.the_client') }}</h6>
          <VRow dense>
            <VCol cols="12" md="6">
              <!--
                Pick the client, or type one. The picked name and address are copied into the
                fields below and stay editable — an issued account keeps the details it was issued
                with, so this is a starting point rather than a link.
              -->
              <VAutocomplete
                v-model:search="clientSearch" :items="clientOptions" :loading="clientsLoading"
                :disabled="!props.editable"
                :label="t('project_management.invoicing.pick_client')"
                :hint="t('project_management.invoicing.pick_client_hint')" persistent-hint
                :no-data-text="t('project_management.invoicing.no_clients')"
                variant="outlined" density="compact" class="mb-3"
                @update:model-value="useClient"
              />
              <VTextField
                v-model="form.client_name" :disabled="!props.editable"
                :label="t('project_management.invoicing.col_client')"
                :error-messages="form.errors.client_name" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="3">
              <VTextField
                v-model="form.client_vat_number" :disabled="!props.editable"
                :label="t('project_management.invoicing.client_vat_number')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="3">
              <VTextField
                v-model="form.attention" :disabled="!props.editable"
                :label="t('project_management.invoicing.attention')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="8">
              <VTextarea
                v-model="form.client_address" rows="2" :disabled="!props.editable"
                :label="t('project_management.invoicing.client_address')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="form.client_project_number" :disabled="!props.editable"
                :label="t('project_management.invoicing.client_project_number')"
                variant="outlined" density="compact"
              />
            </VCol>
          </VRow>

          <!-- ── The consultant ───────────────────────────────────────────── -->
          <h6 class="text-subtitle-2 mt-4 mb-2">{{ t('project_management.invoicing.the_consultant') }}</h6>
          <VRow dense>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.consultant_name" :disabled="!props.editable"
                :label="t('project_management.invoicing.consultant_name')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="3">
              <VTextField
                v-model="form.consultant_vat_number" :disabled="!props.editable"
                :label="t('project_management.invoicing.consultant_vat_number')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="3">
              <VTextField
                v-model="form.consultant_reference" :disabled="!props.editable"
                :label="t('project_management.invoicing.consultant_reference')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextarea
                v-model="form.consultant_address" rows="2" :disabled="!props.editable"
                :label="t('project_management.invoicing.consultant_address')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="3">
              <VTextField
                v-model="form.vendor_number" :disabled="!props.editable"
                :label="t('project_management.invoicing.vendor_number')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="3">
              <VTextField
                v-model="form.file_number" :disabled="!props.editable"
                :label="t('project_management.invoicing.file_number')"
                variant="outlined" density="compact"
              />
            </VCol>
          </VRow>

          <!-- ── Where to pay ─────────────────────────────────────────────── -->
          <h6 class="text-subtitle-2 mt-4 mb-2">{{ t('project_management.invoicing.banking') }}</h6>
          <VRow dense>
            <VCol cols="12" md="4">
              <VTextField
                v-model="form.banking_name" :disabled="!props.editable"
                :label="t('project_management.invoicing.banking_name')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="form.banking_account_name" :disabled="!props.editable"
                :label="t('project_management.invoicing.banking_account_name')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="form.banking_account_number" :disabled="!props.editable"
                :label="t('project_management.invoicing.banking_account_number')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="form.banking_branch_code" :disabled="!props.editable"
                :label="t('project_management.invoicing.banking_branch_code')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="form.banking_reference" :disabled="!props.editable"
                :label="t('project_management.invoicing.banking_reference')"
                :hint="t('project_management.invoicing.banking_reference_hint')" persistent-hint
                variant="outlined" density="compact"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions v-if="props.editable">
          <VSpacer />
          <VBtn color="primary" :loading="form.processing" @click="save">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </div>
    </VExpandTransition>
  </VCard>
</template>
