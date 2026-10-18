<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface IntegrationField {
  key: string; label: string; type: string; placeholder: string
}

interface Integration {
  id: number | null
  integration_type: string
  provider: string
  label: string
  logo: string
  description: string
  fields: IntegrationField[]
  api_endpoint: string
  api_key: string
  api_secret: string
  additional_config: Record<string, string>
  active: boolean
  last_sync_status: string | null
  last_sync_message: string | null
  last_sync_at: string | null
  has_credentials: boolean
}

const props = defineProps<{ integrations: Integration[] }>()

const drawer       = ref(false)
const testing      = ref<number | null>(null)
const snack        = ref<{ show: boolean; text: string; color: string }>({ show: false, text: '', color: 'success' })
const activeTarget = ref<Integration | null>(null)

function toast(text: string, color = 'success') { snack.value = { show: true, text, color } }

const form = useForm({
  integration_type:  '',
  provider:          '',
  label:             '',
  api_endpoint:      '',
  api_key:           '',
  api_secret:        '',
  additional_config: {} as Record<string, string>,
  active:            false,
})

function openEdit(intg: Integration) {
  activeTarget.value         = intg
  form.integration_type      = intg.integration_type
  form.provider              = intg.provider
  form.label                 = intg.label
  form.api_endpoint          = intg.api_endpoint
  form.api_key               = intg.has_credentials ? MASK : ''
  form.api_secret            = intg.has_credentials ? MASK : ''
  form.additional_config     = { ...intg.additional_config }
  form.active                = intg.active
  drawer.value = true
}

function submitForm() {
  if (activeTarget.value?.id) {
    form.patch(`/fleet/integrations/${activeTarget.value.id}`, {
      preserveScroll: true,
      onSuccess: () => { drawer.value = false; toast(t('fleet_integrations_index.toasts.saved')) },
    })
  } else {
    form.post('/fleet/integrations', {
      preserveScroll: true,
      onSuccess: () => { drawer.value = false; toast(t('fleet_integrations_index.toasts.saved')) },
    })
  }
}

function toggleActive(intg: Integration) {
  if (!intg.id) { toast(t('fleet_integrations_index.toasts.save_first'), 'warning'); return }
  router.patch(`/fleet/integrations/${intg.id}/toggle`, {}, {
    preserveScroll: true,
    onSuccess: () => toast(intg.active ? t('fleet_integrations_index.toasts.disabled') : t('fleet_integrations_index.toasts.enabled')),
  })
}

function testConnection(intg: Integration) {
  if (!intg.id) { toast(t('fleet_integrations_index.toasts.save_first'), 'warning'); return }
  testing.value = intg.id
  router.post(`/fleet/integrations/${intg.id}/test`, {}, {
    preserveScroll: true,
    onSuccess: () => { testing.value = null; toast(t('fleet_integrations_index.toasts.test_complete')) },
    onError:   () => { testing.value = null; toast(t('fleet_integrations_index.toasts.test_failed'), 'error') },
    onFinish:  () => { testing.value = null },
  })
}

const typeGroups = computed(() => {
  const labels: Record<string, string> = {
    telematics: t('fleet_integrations_index.groups.telematics'),
    fuel_card:  t('fleet_integrations_index.groups.fuel_card'),
    tollgate:   t('fleet_integrations_index.groups.tollgate'),
    fines:      t('fleet_integrations_index.groups.fines'),
  }
  const icons: Record<string, string> = {
    telematics: 'tabler-satellite',
    fuel_card:  'tabler-credit-card',
    tollgate:   'tabler-barrier-block',
    fines:      'tabler-shield-star',
  }
  const types = ['telematics', 'fuel_card', 'tollgate', 'fines']
  return types.map(type => ({
    type,
    label: labels[type],
    icon:  icons[type],
    items: props.integrations.filter(i => i.integration_type === type),
  }))
})

const currentFields = computed(() =>
  activeTarget.value?.fields ?? []
)

const MASK = '••••••••'

function clearIfMasked(field: 'api_key' | 'api_secret') {
  if (form[field] === MASK) form[field] = ''
}

const statusColor = (s: string | null) =>
  ({ success: 'success', error: 'error', pending: 'warning' }[s ?? ''] ?? 'grey')

const statusIcon = (s: string | null) =>
  ({ success: 'tabler-circle-check', error: 'tabler-alert-circle', pending: 'tabler-clock' }[s ?? ''] ?? 'tabler-help-circle')
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow justify="center">
      <VCol cols="12" lg="11">

        <!-- Header -->
        <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
          <div>
            <div class="text-overline text-medium-emphasis">{{ t('fleet_integrations_index.header.subtitle') }}</div>
            <h4 class="text-h4">{{ t('fleet_integrations_index.header.title') }}</h4>
            <p class="text-medium-emphasis mb-0">{{ t('fleet_integrations_index.header.description') }}</p>
          </div>
          <VBtn variant="tonal" prepend-icon="tabler-arrow-left" href="/fleet/setup">
            {{ t('fleet_integrations_index.back_to_setup') }}
          </VBtn>
        </div>

        <!-- Groups -->
        <div v-for="group in typeGroups" :key="group.type" class="mb-8">
          <div class="d-flex align-center ga-2 mb-3">
            <VIcon :icon="group.icon" color="primary" />
            <h6 class="text-h6 font-weight-bold">{{ group.label }}</h6>
          </div>

          <VRow>
            <VCol
              v-for="intg in group.items"
              :key="intg.provider"
              cols="12" md="6" lg="4"
            >
              <VCard
                :class="['h-100', intg.active ? 'border-primary' : '']"
                :style="intg.active ? 'border: 1px solid rgb(var(--v-theme-primary))' : ''"
              >
                <VCardTitle class="pa-4 pb-2 d-flex align-center ga-2">
                  <VIcon :icon="intg.logo" color="primary" size="22" />
                  <span class="text-body-1 font-weight-bold">{{ intg.label }}</span>
                  <VSpacer />
                  <VChip
                    size="x-small"
                    :color="intg.active ? 'success' : 'default'"
                    label
                  >{{ intg.active ? t('fleet_integrations_index.active') : t('fleet_integrations_index.inactive') }}</VChip>
                </VCardTitle>

                <VCardText class="pa-4 pt-1">
                  <p class="text-body-2 text-medium-emphasis mb-3">{{ intg.description }}</p>

                  <div class="d-flex align-center ga-1 mb-2">
                    <VIcon
                      :icon="intg.has_credentials ? 'tabler-lock-check' : 'tabler-lock-open'"
                      :color="intg.has_credentials ? 'success' : 'grey'"
                      size="16"
                    />
                    <span class="text-caption" :class="intg.has_credentials ? 'text-success' : 'text-medium-emphasis'">
                      {{ intg.has_credentials ? t('fleet_integrations_index.credentials_saved') : t('fleet_integrations_index.no_credentials') }}
                    </span>
                  </div>

                  <div v-if="intg.last_sync_at" class="d-flex align-center ga-1 mb-1">
                    <VIcon :icon="statusIcon(intg.last_sync_status)" :color="statusColor(intg.last_sync_status)" size="16" />
                    <span class="text-caption">{{ intg.last_sync_message }}</span>
                  </div>
                  <div v-if="intg.last_sync_at" class="text-caption text-medium-emphasis mb-2">
                    {{ t('fleet_integrations_index.last_tested') }}: {{ intg.last_sync_at }}
                  </div>

                  <div v-if="intg.api_endpoint" class="text-caption text-medium-emphasis font-weight-medium text-truncate mb-2" :title="intg.api_endpoint">
                    <VIcon size="12">tabler-link</VIcon> {{ intg.api_endpoint }}
                  </div>
                </VCardText>

                <VDivider />

                <VCardActions class="pa-3 d-flex ga-2 flex-wrap">
                  <VBtn
                    size="small"
                    variant="tonal"
                    prepend-icon="tabler-pencil"
                    @click="openEdit(intg)"
                  >{{ t('fleet_integrations_index.configure') }}</VBtn>

                  <VBtn
                    size="small"
                    variant="tonal"
                    prepend-icon="tabler-plug-connected"
                    :loading="testing === intg.id"
                    :disabled="!intg.has_credentials"
                    @click="testConnection(intg)"
                  >{{ t('fleet_integrations_index.test') }}</VBtn>

                  <VSpacer />

                  <VSwitch
                    :model-value="intg.active"
                    color="primary"
                    density="compact"
                    hide-details
                    :disabled="!intg.has_credentials"
                    @change="toggleActive(intg)"
                  />
                </VCardActions>
              </VCard>
            </VCol>
          </VRow>
        </div>

      </VCol>
    </VRow>
  </VContainer>

  <!-- Configure Drawer -->
  <VNavigationDrawer v-model="drawer" temporary location="right" width="500">
    <VForm @submit.prevent="submitForm" class="h-100 d-flex flex-column">

      <div class="pa-5 border-b d-flex align-center ga-2">
        <VIcon v-if="activeTarget?.logo" :icon="activeTarget.logo" color="primary" />
        <h5 class="text-h5">{{ activeTarget?.label }}</h5>
      </div>

      <div class="flex-grow-1 overflow-y-auto pa-5">
        <p class="text-body-2 text-medium-emphasis mb-4">{{ activeTarget?.description }}</p>

        <VDivider class="mb-4" />

        <VRow>
          <VCol
            v-for="field in currentFields"
            :key="field.key"
            cols="12"
          >
            <VTextField
              v-if="field.key === 'api_endpoint'"
              v-model="form.api_endpoint"
              :label="field.label"
              :placeholder="field.placeholder"
              :type="field.type"
              :error-messages="form.errors.api_endpoint"
              variant="outlined"
              density="comfortable"
              prepend-inner-icon="tabler-link"
            />
            <VTextField
              v-else-if="field.key === 'api_key'"
              v-model="form.api_key"
              :label="field.label"
              :placeholder="field.placeholder"
              :type="field.type"
              :error-messages="form.errors.api_key"
              variant="outlined"
              density="comfortable"
              prepend-inner-icon="tabler-key"
              @focus="clearIfMasked('api_key')"
            />
            <VTextField
              v-else-if="field.key === 'api_secret'"
              v-model="form.api_secret"
              :label="field.label"
              :placeholder="field.placeholder"
              :type="field.type"
              :error-messages="form.errors.api_secret"
              variant="outlined"
              density="comfortable"
              prepend-inner-icon="tabler-lock"
              @focus="clearIfMasked('api_secret')"
            />
            <VTextField
              v-else
              v-model="form.additional_config[field.key]"
              :label="field.label"
              :placeholder="field.placeholder"
              :type="field.type"
              variant="outlined"
              density="comfortable"
            />
          </VCol>

          <VCol cols="12">
            <VDivider class="mb-3" />
            <VSwitch
              v-model="form.active"
              :label="t('fleet_integrations_index.enable_integration')"
              color="primary"
              density="compact"
              hide-details
            />
          </VCol>

          <VCol cols="12">
            <VAlert type="info" variant="tonal" density="compact" class="text-caption">
              <VIcon start size="14">tabler-shield-lock</VIcon>
              {{ t('fleet_integrations_index.encryption_notice') }}
            </VAlert>
          </VCol>
        </VRow>
      </div>

      <div class="pa-5 border-t d-flex ga-3 justify-end">
        <VBtn variant="tonal" @click="drawer = false">{{ t('fleet_integrations_index.cancel') }}</VBtn>
        <VBtn
          type="submit"
          color="primary"
          :loading="form.processing"
          prepend-icon="tabler-device-floppy"
        >{{ t('fleet_integrations_index.save_credentials') }}</VBtn>
      </div>

    </VForm>
  </VNavigationDrawer>

  <VSnackbar v-model="snack.show" :color="snack.color" location="bottom right" timeout="4000">
    {{ snack.text }}
  </VSnackbar>
</template>
