<script setup lang="ts">
/**
 * Connecting a tenant's own warehouse, so Power BI can read their data.
 *
 * Power BI does not connect to this platform; it connects to a warehouse. So what this screen sets
 * up is a push into the warehouse the customer already points Power BI at — which for the customers
 * asking is Databricks.
 *
 * The token is write-only here. The server never sends it back, so the field is always empty on
 * load and "a token is set" is shown instead. That is deliberate: a page that returns a credential
 * puts it in the browser's memory, in the Inertia payload, and in any error report that captures
 * either.
 */
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

interface Connection {
  id: string
  provider: string
  name: string | null
  host: string | null
  http_path: string | null
  catalog: string | null
  schema: string | null
  is_active: boolean
  has_token: boolean
  last_tested_at: string | null
  last_test_error: string | null
  last_exported_at: string | null
}

const props = defineProps<{
  noCompany?: boolean
  connection: Connection | null
  providers: string[]
  /** Only the modules this tenant runs, each with the datasets that are really there. */
  modules: Array<{
    module: string
    name: string
    datasets: Array<{ key: string; name: string; table: string; columns: string[] }>
  }>
}>()

const { t } = useI18n()

const form = useForm({
  provider: props.connection?.provider ?? 'databricks',
  name: props.connection?.name ?? '',
  host: props.connection?.host ?? '',
  http_path: props.connection?.http_path ?? '',
  catalog: props.connection?.catalog ?? '',
  schema: props.connection?.schema ?? '',
  // Always blank. The server does not send it back, and leaving it empty on save keeps what is
  // already stored — so correcting a catalog name does not mean pasting the token again.
  token: '',
  is_active: props.connection?.is_active ?? true,
})

const testForm = useForm({})

const allKeys = computed(() => props.modules.flatMap(m => m.datasets.map(d => d.key)))

const exportForm = useForm({ datasets: allKeys.value })

/**
 * Everything in one module, on or off together.
 *
 * Somebody who wants "our CRM in Power BI" should say that once rather than tick four boxes, and
 * the indeterminate state is what tells them a module is only partly selected.
 */
function moduleState(module: { datasets: Array<{ key: string }> }) {
  const picked = module.datasets.filter(d => exportForm.datasets.includes(d.key)).length

  return picked === 0 ? 'none' : picked === module.datasets.length ? 'all' : 'some'
}

function toggleModule(module: { datasets: Array<{ key: string }> }) {
  const keys = module.datasets.map(d => d.key)

  exportForm.datasets = moduleState(module) === 'all'
    ? exportForm.datasets.filter(key => !keys.includes(key))
    : [...new Set([...exportForm.datasets, ...keys])]
}

const removeForm = useForm({})

const configured = computed(() => !!props.connection?.has_token)
const showRemove = ref(false)

const save = () => form.post(route('settings.bi-export.save'), {
  preserveScroll: true,
  // Cleared whether or not it was used, so it is not sitting in the form after a save.
  onFinish: () => { form.token = '' },
})

const test = () => testForm.post(route('settings.bi-export.test'), { preserveScroll: true })

const runExport = () => exportForm.post(route('settings.bi-export.run'), { preserveScroll: true })

const remove = () => removeForm.delete(route('settings.bi-export.destroy'), {
  preserveScroll: true,
  onFinish: () => { showRemove.value = false },
})
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('analytics.bi.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('analytics.bi.subtitle') }}</p>
    </div>

    <VAlert v-if="noCompany" type="info" variant="tonal" class="mb-4">
      {{ t('analytics.bi.no_company') }}
    </VAlert>

    <template v-else>
      <!--
        Said once, plainly. Somebody is about to paste a credential that can read and write their
        warehouse, and they are entitled to know where it goes.
      -->
      <VAlert type="info" variant="tonal" density="compact" class="mb-4" icon="tabler-lock">
        {{ t('analytics.bi.token_notice') }}
      </VAlert>

      <VRow>
        <VCol cols="12" md="7">
          <VCard border>
            <VCardItem>
              <VCardTitle class="text-body-1">{{ t('analytics.bi.connection') }}</VCardTitle>
              <VCardSubtitle>{{ t('analytics.bi.connection_sub') }}</VCardSubtitle>
            </VCardItem>
            <VDivider />

            <VCardText>
              <VSelect
                v-model="form.provider" :items="providers"
                :label="t('analytics.bi.provider')"
                variant="outlined" density="compact" class="mb-3"
                :error-messages="form.errors.provider"
              />

              <VTextField
                v-model="form.host" variant="outlined" density="compact" class="mb-3"
                :label="t('analytics.bi.host')"
                placeholder="adb-1234567890.7.azuredatabricks.net"
                :hint="t('analytics.bi.host_hint')" persistent-hint
                :error-messages="form.errors.host"
              />

              <VTextField
                v-model="form.http_path" variant="outlined" density="compact" class="mb-3"
                :label="t('analytics.bi.http_path')"
                placeholder="/sql/1.0/warehouses/abc123"
                :hint="t('analytics.bi.http_path_hint')" persistent-hint
                :error-messages="form.errors.http_path"
              />

              <div class="d-flex gap-3 mb-3">
                <VTextField
                  v-model="form.catalog" variant="outlined" density="compact"
                  :label="t('analytics.bi.catalog')" placeholder="main"
                  :error-messages="form.errors.catalog"
                />
                <VTextField
                  v-model="form.schema" variant="outlined" density="compact"
                  :label="t('analytics.bi.schema')" placeholder="mkhaya"
                  :error-messages="form.errors.schema"
                />
              </div>

              <VTextField
                v-model="form.token" type="password" variant="outlined" density="compact"
                autocomplete="new-password"
                :label="t('analytics.bi.token')"
                :placeholder="configured ? t('analytics.bi.token_set') : ''"
                :hint="configured ? t('analytics.bi.token_replace_hint') : t('analytics.bi.token_hint')"
                persistent-hint
                :error-messages="form.errors.token"
              />

              <VSwitch
                v-model="form.is_active" color="primary" density="compact" hide-details class="mt-2"
                :label="t('analytics.bi.active')"
              />
            </VCardText>

            <VDivider />
            <VCardActions class="pa-4 flex-wrap gap-2">
              <VBtn
                v-if="configured" variant="text" color="error" size="small"
                @click="showRemove = true"
              >{{ t('analytics.bi.remove') }}</VBtn>
              <VSpacer />
              <VBtn
                v-if="configured" variant="tonal" :loading="testForm.processing" @click="test"
              >{{ t('analytics.bi.test') }}</VBtn>
              <VBtn color="primary" :loading="form.processing" @click="save">
                {{ t('analytics.bi.save') }}
              </VBtn>
            </VCardActions>
          </VCard>
        </VCol>

        <VCol cols="12" md="5">
          <VCard border class="mb-4">
            <VCardItem>
              <VCardTitle class="text-body-1">{{ t('analytics.bi.status') }}</VCardTitle>
            </VCardItem>
            <VDivider />
            <VCardText>
              <div class="d-flex align-center gap-2 mb-2">
                <VIcon :icon="configured ? 'tabler-circle-check' : 'tabler-circle-dashed'"
                       :color="configured ? 'success' : 'grey'" size="18" />
                <span class="text-body-2">
                  {{ configured ? t('analytics.bi.token_set') : t('analytics.bi.token_missing') }}
                </span>
              </div>

              <div v-if="connection?.last_tested_at" class="text-caption text-medium-emphasis">
                {{ t('analytics.bi.last_tested', { when: connection.last_tested_at }) }}
              </div>
              <div v-if="connection?.last_exported_at" class="text-caption text-medium-emphasis">
                {{ t('analytics.bi.last_exported', { when: connection.last_exported_at }) }}
              </div>

              <!-- Shown here rather than left in a log a customer cannot read. -->
              <VAlert
                v-if="connection?.last_test_error" type="warning" variant="tonal"
                density="compact" class="mt-3"
              >
                <div class="text-caption" style="white-space: pre-wrap">{{ connection.last_test_error }}</div>
              </VAlert>
            </VCardText>
          </VCard>

          <VCard border>
            <VCardItem>
              <VCardTitle class="text-body-1">{{ t('analytics.bi.datasets') }}</VCardTitle>
              <VCardSubtitle class="text-wrap">{{ t('analytics.bi.datasets_sub') }}</VCardSubtitle>
            </VCardItem>
            <VDivider />
            <VCardText>
              <!-- Grouped by the module that owns it, which is how somebody looks for it. -->
              <div v-for="module in modules" :key="module.module" class="mb-3">
                <VCheckbox
                  :model-value="moduleState(module) === 'all'"
                  :indeterminate="moduleState(module) === 'some'"
                  density="compact" hide-details color="primary"
                  @update:model-value="toggleModule(module)"
                >
                  <template #label>
                    <span class="text-body-2 font-weight-medium">{{ module.name }}</span>
                  </template>
                </VCheckbox>

                <div class="ps-6">
                  <VCheckbox
                    v-for="set in module.datasets" :key="set.key"
                    v-model="exportForm.datasets" :value="set.key"
                    density="compact" hide-details color="primary"
                  >
                    <template #label>
                      <div>
                        <div class="text-body-2">{{ t(`analytics.bi.dataset_${set.name}`, set.name) }}</div>
                        <!-- The table it becomes, so a report can be pointed at it before it exists. -->
                        <div class="text-caption text-medium-emphasis">{{ set.table }}</div>
                      </div>
                    </template>
                  </VCheckbox>
                </div>
              </div>

              <!-- A tenant running none of the modules that can be exported yet. -->
              <div v-if="!modules.length" class="text-body-2 text-medium-emphasis py-4">
                {{ t('analytics.bi.no_modules') }}
              </div>

              <!-- Replaced, not appended: a second run must not double every figure downstream. -->
              <p class="text-caption text-medium-emphasis mt-2 mb-0">
                {{ t('analytics.bi.replace_notice') }}
              </p>
            </VCardText>
            <VDivider />
            <VCardActions class="pa-4">
              <VSpacer />
              <VBtn
                color="primary" variant="flat" prepend-icon="tabler-upload"
                :disabled="!configured || !exportForm.datasets.length"
                :loading="exportForm.processing"
                @click="runExport"
              >{{ t('analytics.bi.export_now') }}</VBtn>
            </VCardActions>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <VDialog v-model="showRemove" max-width="480">
      <VCard>
        <VCardTitle class="text-body-1">{{ t('analytics.bi.remove') }}</VCardTitle>
        <VCardText class="text-body-2">{{ t('analytics.bi.remove_confirm') }}</VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="showRemove = false">{{ t('analytics.bi.cancel') }}</VBtn>
          <VBtn color="error" :loading="removeForm.processing" @click="remove">
            {{ t('analytics.bi.remove') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
