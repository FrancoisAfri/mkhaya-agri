<script setup lang="ts">
import { ref, watch } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()

type Customer = {
  uuid: string; name: string; display_number: string | null
  customer_number: string | null; email: string | null
  kind: string; is_active: boolean
}

const props = defineProps<{ customers: Customer[]; filters: Record<string, any> }>()

const search = ref(props.filters?.search ?? '')
let timer: ReturnType<typeof setTimeout>

watch(search, () => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    router.get(route('customers.index'), { search: search.value || undefined },
      { preserveState: true, replace: true })
  }, 400)
})
</script>

<template>
  <VContainer fluid class="pa-6">
    <h1 class="text-h5 font-weight-bold mb-1">{{ t('customer_profile.customers') }}</h1>
    <p class="text-body-2 text-medium-emphasis mb-6">{{ t('customer_profile.customers_subtitle') }}</p>

    <VCard class="mb-4">
      <VCardText>
        <VTextField
          v-model="search"
          :label="t('customer_profile.search_placeholder')"
          prepend-inner-icon="tabler-search"
          density="compact"
          clearable
        />
      </VCardText>
    </VCard>

    <VCard>
      <VTable density="compact">
        <thead>
          <tr>
            <th>{{ t('customer_profile.number') }}</th>
            <th>{{ t('customer_profile.name') }}</th>
            <th>{{ t('customer_profile.email') }}</th>
            <th>{{ t('customer_profile.kind') }}</th>
            <th>{{ t('customer_profile.status') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in props.customers" :key="c.uuid">
            <td>
              <Link :href="route('customers.show', c.uuid)" class="text-primary font-weight-medium">
                {{ c.display_number ?? '—' }}
              </Link>
            </td>
            <td>{{ c.name }}</td>
            <td>{{ c.email ?? '—' }}</td>
            <td>
              <VChip size="x-small" variant="tonal">{{ t(`customer_profile.kind_${c.kind}`) }}</VChip>
            </td>
            <td>
              <VChip size="x-small" :color="c.is_active ? 'success' : 'default'" variant="tonal">
                {{ c.is_active ? t('customer_profile.active') : t('customer_profile.inactive') }}
              </VChip>
            </td>
          </tr>
          <tr v-if="!props.customers.length">
            <td colspan="5" class="text-center text-medium-emphasis py-6">
              {{ t('customer_profile.no_customers') }}
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </VContainer>
</template>
