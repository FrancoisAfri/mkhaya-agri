<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

/**
 * The client field on a project.
 *
 * Two things it has to do that a plain select cannot. It searches whichever system owns clients on
 * this tenant — PM's own list, or CRM when that is installed — without the form knowing which. And
 * it can create one, because a client field whose only answer is "install CRM first" is a dead end
 * on every tenant that has not bought CRM, and the field stays blank forever.
 *
 * Where a newly typed client lands is the provider's business, not this component's: CRM if CRM is
 * there, PM's own refs if it is not. That is what stops a second customer list growing quietly
 * behind Project Management.
 */
const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  modelValue: string | null
  /** The client already on this project, so it is selectable before any search has run. */
  current?: { id: string | null; label: string | null; source?: string | null }
  errorMessage?: string
}>()

const emit = defineEmits<{ (e: 'update:modelValue', value: string | null): void }>()

const items = ref<Array<{ title: string; value: string; source: string }>>([])
const loading = ref(false)
const ownedByCrm = ref(false)
const search = ref('')
/** The platform's town list, so a client's city is picked rather than spelled four ways. */
const cities = ref<string[]>([])

async function fetchClients(query: string) {
  loading.value = true
  try {
    const response = await fetch(
      route('project-management.clients.search') + '?q=' + encodeURIComponent(query),
      { headers: { Accept: 'application/json' }, credentials: 'same-origin' },
    )
    const data = await response.json()

    ownedByCrm.value = data.owned_by_crm
    cities.value = data.cities ?? []

    const found = (data.clients ?? []).map((c: any) => ({ title: c.label, value: c.id, source: c.source }))

    // The current selection is kept in the list even when it is not in the results. Without this,
    // opening the form on a project whose client is the twenty-first alphabetically shows an empty
    // field, and saving would silently clear it.
    if (props.current?.id && !found.some((f: any) => f.value === props.current!.id)) {
      found.unshift({
        title: props.current.label ?? '—',
        value: props.current.id,
        source: props.current.source ?? 'local',
      })
    }

    items.value = found
  } finally {
    loading.value = false
  }
}

let debounce: ReturnType<typeof setTimeout> | undefined

watch(search, value => {
  clearTimeout(debounce)
  debounce = setTimeout(() => fetchClients(value ?? ''), 250)
})

onMounted(() => fetchClients(''))

// ── Adding one ───────────────────────────────────────────────────────────────

const dialog = ref(false)
/*
 * The same fields as the setup screen, because it is the same client.
 *
 * A client added while starting a project got a name, an email and a town, and its first invoice
 * then printed an empty "To:" block and no VAT number — a document that is not a valid tax invoice.
 * Somebody starting a job at eight in the morning has these details in front of them; asking once
 * here is cheaper than retyping them on the first account of every project.
 */
const form = useForm({
  name: '', email: '', phone: '', city: '', notes: '',
  address: '', vat_number: '',
})

function openAdd() {
  form.reset()
  // Whatever they had already typed into the search is almost always the name they want.
  form.name = search.value ?? ''
  dialog.value = true
}

function save() {
  form.post(route('project-management.clients.store'), {
    preserveScroll: true,
    onSuccess: page => {
      dialog.value = false

      const created = (page.props.flash as any)?.pmClient

      if (created?.id) {
        items.value = [{ title: created.label, value: created.id, source: ownedByCrm.value ? 'crm' : 'local' },
          ...items.value]
        emit('update:modelValue', created.id)
      } else {
        fetchClients('')
      }

      form.reset()
    },
  })
}
</script>

<template>
  <div>
    <VAutocomplete
      :model-value="modelValue"
      :items="items"
      :loading="loading"
      :label="t('project_management.projects.col_client')"
      :error-messages="errorMessage"
      :no-data-text="t('project_management.clients.no_matches')"
      variant="outlined" density="compact" clearable
      @update:search="search = $event"
      @update:model-value="emit('update:modelValue', $event)"
    >
      <template #append-item>
        <VDivider />
        <VListItem prepend-icon="tabler-plus" @click="openAdd">
          <VListItemTitle>{{ t('project_management.clients.add_new') }}</VListItemTitle>
        </VListItem>
      </template>
    </VAutocomplete>

    <p class="text-caption text-medium-emphasis mt-n2 mb-3">
      {{ ownedByCrm ? t('project_management.clients.from_crm') : t('project_management.clients.from_pm') }}
    </p>

    <VDialog v-model="dialog" max-width="520">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.clients.add_new') }}</VCardTitle></VCardItem>
        <VDivider />
        <VCardText>
          <VAlert v-if="ownedByCrm" type="info" variant="tonal" density="compact" class="mb-3">
            {{ t('project_management.clients.will_go_to_crm') }}
          </VAlert>
          <VTextField v-model="form.name" autofocus
                      :label="t('project_management.setup_clients.field_name')"
                      :error-messages="form.errors.name" variant="outlined" density="compact" class="mb-3" />
          <VTextField v-model="form.email" type="email"
                      :label="t('project_management.setup_clients.field_email')"
                      :hint="t('project_management.setup_clients.hint_email')" persistent-hint
                      :error-messages="form.errors.email" variant="outlined" density="compact" class="mb-3" />
          <VTextField v-model="form.phone" :label="t('project_management.setup_clients.field_phone')"
                      variant="outlined" density="compact" class="mb-3" />
          <VAutocomplete
            v-model="form.city" :items="cities"
            :label="t('project_management.setup_clients.field_city')"
            :no-data-text="t('project_management.setup_clients.no_city_match')"
            variant="outlined" density="compact" clearable class="mb-3"
          />
          <VTextarea v-model="form.address" rows="2"
                     :label="t('project_management.setup_clients.field_address')"
                     :hint="t('project_management.setup_clients.hint_address')" persistent-hint
                     :error-messages="form.errors.address" variant="outlined" density="compact" class="mb-3" />
          <VTextField v-model="form.vat_number"
                      :label="t('project_management.setup_clients.field_vat_number')"
                      :hint="t('project_management.setup_clients.hint_vat_number')" persistent-hint
                      :error-messages="form.errors.vat_number" variant="outlined" density="compact" />
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
  </div>
</template>
