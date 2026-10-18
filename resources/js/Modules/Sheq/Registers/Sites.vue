<script setup lang="ts">
/**
 * The places SHEQ records hang off.
 *
 * Most of them mirror the company structure, which is right: the tenant built that tree once in
 * Company Settings and rebuilding it here would guarantee the two drift apart.
 *
 * But mirroring was not enough. A depot rented for eight months, a client's premises, a stretch of
 * road under contract — places where work happens and where an incident gets reported, and none of
 * them a line on an HR org chart. There was no way to record one, so people filed against the
 * nearest department and the site on the record was a fiction.
 */
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t } = useI18n()
const route = ziggyRoute

type Site = {
  uuid: string; code: string; name: string; site_type: string; timezone: string
  is_active: boolean; depth: number; unit_name: string | null; unit_type: string | null
  external_reference: string | null
  /** Whether the company structure owns this row, and so what the form may offer for it. */
  from_structure: boolean
  /** null = inherits the company bar; [] = asked, and demands nothing. Not the same thing. */
  required_documents: string[] | null
}

const props = defineProps<{
  rows: Site[]
  siteTypes: string[]
  documentTypes: { code: string; label: string }[]
  companyRequiredDocuments: string[]
  canManage?: boolean
  noCompany?: boolean
}>()

/* ── What this site demands of a contractor ──────────────────────────────────────────────
 *
 * Kept out of the site form on purpose: a site mirrored from the company structure cannot be
 * renamed here, but it can perfectly well set its own bar, and one form would have denied that.
 */
const reqDialog = ref(false)
const reqSite = ref<Site | null>(null)
const reqInherits = ref(true)
const reqForm = useForm<{ required_documents: string[] | null }>({ required_documents: [] })

function openRequirements(site: Site) {
  reqSite.value = site
  reqInherits.value = site.required_documents === null
  reqForm.required_documents = [...(site.required_documents ?? props.companyRequiredDocuments)]
  reqForm.clearErrors()
  reqDialog.value = true
}

function saveRequirements() {
  if (!reqSite.value) return

  // Inheriting is sent as null, not as the company's list copied onto the site — copying would
  // freeze today's company bar onto the site and silently stop it following future changes.
  reqForm
    .transform(data => ({ required_documents: reqInherits.value ? null : data.required_documents }))
    .post(route('sheq.sites.requirements', reqSite.value.uuid), {
      preserveScroll: true,
      onSuccess: () => { reqDialog.value = false },
    })
}

const labelFor = (code: string) =>
  props.documentTypes.find(d => d.code === code)?.label ?? code

const typeColour = (type: string) => ({
  REGION: 'primary', SITE: 'info', AREA: 'secondary',
}[type] ?? 'grey')

// ── Adding and correcting ────────────────────────────────────────────────────

const dialog = ref(false)
const editing = ref<Site | null>(null)

const form = useForm({
  name: '',
  code: '',
  site_type: 'SITE',
  timezone: 'Africa/Johannesburg',
  parent_uuid: null as string | null,
  external_reference: '',
  is_active: true,
})

/** Any site can be a parent, so a rented depot can sit under the region that runs it. */
const parentOptions = computed(() => props.rows
  .filter(site => site.uuid !== editing.value?.uuid)
  .map(site => ({ title: `${site.name} · ${site.code}`, value: site.uuid })))

function openNew() {
  editing.value = null
  form.reset()
  form.clearErrors()
  dialog.value = true
}

function openEdit(site: Site) {
  editing.value = site
  form.clearErrors()
  Object.assign(form, {
    name: site.name,
    code: site.code,
    site_type: site.site_type,
    timezone: site.timezone,
    parent_uuid: null,
    external_reference: site.external_reference ?? '',
    is_active: site.is_active,
  })
  dialog.value = true
}

function save() {
  const done = { preserveScroll: true, onSuccess: () => { dialog.value = false; form.reset() } }

  if (editing.value) {
    form.patch(route('sheq.sites.update', editing.value.uuid), done)
  } else {
    form.post(route('sheq.sites.store'), done)
  }
}

/*
 * Retired, never deleted. Every incident, permit and inspection points at a site — deleting one
 * would orphan the record an inspector asks for, while deactivating takes it out of the dropdowns
 * and leaves the history readable.
 */
const toggleForm = useForm({ is_active: false })

function toggle(site: Site) {
  toggleForm.is_active = !site.is_active
  toggleForm.post(route('sheq.sites.toggle', site.uuid), { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.registers.sites.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.registers.sites.subtitle') }}</p>
      </div>

      <VBtn
        v-if="canManage && !noCompany" color="primary" variant="flat" prepend-icon="mdi-plus"
        class="cursor-pointer" @click="openNew"
      >
        {{ t('sheq.sites.add') }}
      </VBtn>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <VAlert type="info" variant="tonal" density="comfortable" class="mb-4">
      {{ t('sheq.registers.sites.mirrored') }}
    </VAlert>

    <VCard elevation="0" border rounded="lg">
      <div v-if="!rows.length" class="text-center py-10 text-medium-emphasis">
        {{ t('sheq.registers.sites.empty') }}
      </div>

      <VTable v-else density="comfortable">
        <thead>
          <tr>
            <th scope="col">{{ t('sheq.registers.col.site') }}</th>
            <th scope="col">{{ t('sheq.registers.col.code') }}</th>
            <th scope="col">{{ t('sheq.registers.col.type') }}</th>
            <th scope="col">{{ t('sheq.registers.col.timezone') }}</th>
            <th scope="col">{{ t('sheq.registers.col.from_unit') }}</th>
            <th scope="col">{{ t('sheq.sites.col_requirements') }}</th>
            <th v-if="canManage" scope="col" class="text-end">{{ t('sheq.sites.col_actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="site in rows" :key="site.uuid" :class="site.is_active ? '' : 'text-disabled'">
            <!-- Indented to the depth of the structure it mirrors, so the tree is readable. -->
            <td :style="{ paddingInlineStart: `${16 + site.depth * 22}px` }">
              <span class="font-weight-medium">{{ site.name }}</span>
              <VChip v-if="!site.is_active" size="x-small" variant="tonal" class="ms-2">
                {{ t('sheq.registers.sites.inactive') }}
              </VChip>
            </td>
            <td class="text-body-2 text-medium-emphasis">{{ site.code }}</td>
            <td>
              <VChip :color="typeColour(site.site_type)" size="x-small" variant="tonal">
                {{ site.site_type }}
              </VChip>
            </td>
            <td class="text-body-2 text-medium-emphasis">{{ site.timezone }}</td>
            <td class="text-body-2 text-medium-emphasis">
              <template v-if="site.from_structure">
                {{ site.unit_name ?? '—' }}
                <span v-if="site.unit_type" class="text-caption">({{ site.unit_type }})</span>
              </template>
              <!-- Says which rows are the tenant's own, and so which a resync will not touch. -->
              <VChip v-else size="x-small" variant="tonal" color="teal">
                {{ t('sheq.sites.added_here') }}
              </VChip>
            </td>
            <td class="text-body-2">
              <!-- Inheriting and demanding nothing look identical unless the screen says which. -->
              <VChip v-if="site.required_documents === null" size="x-small" variant="tonal">
                {{ t('sheq.sites.requirements_inherited', { n: companyRequiredDocuments.length }) }}
              </VChip>
              <VChip v-else-if="site.required_documents.length === 0"
                     size="x-small" variant="tonal" color="warning">
                {{ t('sheq.sites.requirements_none') }}
              </VChip>
              <VChip v-else size="x-small" variant="tonal" color="info"
                     :title="site.required_documents.map(labelFor).join(', ')">
                {{ t('sheq.sites.requirements_own', { n: site.required_documents.length }) }}
              </VChip>
            </td>
            <td v-if="canManage" class="text-end text-no-wrap">
              <VBtn
                icon="mdi-clipboard-check-outline" size="small" variant="text" class="cursor-pointer"
                :title="t('sheq.sites.requirements_edit')" :aria-label="t('sheq.sites.requirements_edit')"
                @click="openRequirements(site)"
              />
              <VBtn
                icon="mdi-pencil" size="small" variant="text" class="cursor-pointer"
                :title="t('sheq.sites.edit')" :aria-label="t('sheq.sites.edit')"
                @click="openEdit(site)"
              />
              <VBtn
                :icon="site.is_active ? 'mdi-eye-off' : 'mdi-eye'"
                size="small" variant="text" class="cursor-pointer"
                :title="site.is_active ? t('sheq.sites.deactivate') : t('sheq.sites.activate')"
                :aria-label="site.is_active ? t('sheq.sites.deactivate') : t('sheq.sites.activate')"
                @click="toggle(site)"
              />
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="reqDialog" max-width="720">
      <VCard v-if="reqSite">
        <VCardTitle class="text-body-1 font-weight-semibold">
          {{ t('sheq.sites.requirements_title') }} · {{ reqSite.name }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-4">
            {{ t('sheq.sites.requirements_hint') }}
          </p>

          <VSwitch
            v-model="reqInherits" color="primary" density="compact" hide-details class="mb-2"
            :label="t('sheq.sites.requirements_inherit_label', { n: companyRequiredDocuments.length })"
          />
          <p class="text-caption text-medium-emphasis mb-4">
            {{ t('sheq.sites.requirements_inherit_hint') }}
          </p>

          <template v-if="!reqInherits">
            <VAlert
              v-if="reqForm.required_documents && reqForm.required_documents.length === 0"
              type="warning" variant="tonal" density="comfortable" class="mb-3"
            >
              {{ t('sheq.sites.requirements_none_warning') }}
            </VAlert>
            <VRow dense>
              <VCol v-for="type in documentTypes" :key="type.code" cols="12" sm="6">
                <VCheckbox
                  v-model="reqForm.required_documents"
                  :value="type.code" :label="type.label"
                  density="compact" hide-details color="primary"
                />
              </VCol>
            </VRow>
          </template>
        </VCardText>
        <VDivider />
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="reqDialog = false">{{ t('sheq.sites.cancel') }}</VBtn>
          <VBtn
            color="primary" variant="flat" :loading="reqForm.processing"
            @click="saveRequirements"
          >{{ t('sheq.registers.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="dialog" max-width="680">
      <VCard>
        <VCardTitle class="text-body-1">
          {{ editing ? t('sheq.sites.edit') : t('sheq.sites.add') }}
        </VCardTitle>
        <VCardSubtitle class="text-wrap pb-2">{{ t('sheq.sites.add_sub') }}</VCardSubtitle>
        <VDivider />

        <VCardText>
          <!--
            Said plainly rather than by disabling the field: the name and the active flag are the
            two things the company structure owns, so editing them here is undone by the next
            resync. Everything else on this form is SHEQ's and stays.
          -->
          <VAlert
            v-if="editing?.from_structure" type="warning" variant="tonal" density="compact" class="mb-3"
          >{{ t('sheq.sites.structure_warning') }}</VAlert>

          <VTextField
            v-model="form.name" autofocus variant="outlined" density="compact" class="mb-3"
            :label="t('sheq.sites.field_name')"
            :error-messages="form.errors.name"
          />

          <div class="d-flex gap-3 mb-3">
            <VTextField
              v-model="form.code" variant="outlined" density="compact"
              :label="t('sheq.sites.field_code')"
              :hint="t('sheq.sites.hint_code')" persistent-hint
              :error-messages="form.errors.code"
            />
            <VSelect
              v-model="form.site_type" :items="siteTypes" variant="outlined" density="compact"
              :label="t('sheq.sites.field_type')"
              :error-messages="form.errors.site_type"
            />
          </div>

          <VTextField
            v-model="form.timezone" variant="outlined" density="compact" class="mb-3"
            :label="t('sheq.sites.field_timezone')"
            :hint="t('sheq.sites.hint_timezone')" persistent-hint
            :error-messages="form.errors.timezone"
          />

          <VAutocomplete
            v-model="form.parent_uuid" :items="parentOptions" clearable
            variant="outlined" density="compact" class="mb-3"
            :label="t('sheq.sites.field_parent')"
            :hint="t('sheq.sites.hint_parent')" persistent-hint
            :error-messages="form.errors.parent_uuid"
          />

          <VTextField
            v-model="form.external_reference" variant="outlined" density="compact"
            :label="t('sheq.sites.field_reference')"
            :hint="t('sheq.sites.hint_reference')" persistent-hint
          />
        </VCardText>

        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('sheq.sites.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.name" @click="save">
            {{ t('sheq.sites.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
