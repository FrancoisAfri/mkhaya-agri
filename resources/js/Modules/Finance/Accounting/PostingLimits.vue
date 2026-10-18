<script lang="ts" setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface UserLimit { id: number; name: string; email: string | null; max_amount: number | null; can_post: boolean }
const props = defineProps<{ users: UserLimit[] }>()

const dialog = ref(false)
const current = ref<UserLimit | null>(null)
const form = useForm({ user_id: 0, max_amount: null as number | null, can_post: true })

function openEdit(u: UserLimit) { current.value = u; form.user_id = u.id; form.max_amount = u.max_amount; form.can_post = u.can_post; dialog.value = true }
function save() {
  form.post(route('finance.accounting.posting-limits.store'), { preserveScroll: true, onSuccess: () => { dialog.value = false } })
}
const money = (n: number | null) => n === null ? t('acc.unlimited') : n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <div class="text-overline text-medium-emphasis">{{ t('acc.gl_crumb') }}</div>
      <h2 class="text-h5 mb-0">{{ t('acc.posting_limits') }}</h2>
      <div class="text-caption text-medium-emphasis">{{ t('acc.posting_limits_sub') }} <code>finance.journals.post</code></div>
    </div>

    <VCard border>
      <VTable>
        <thead><tr><th>{{ t('acc.user') }}</th><th>{{ t('acc.email_label') }}</th><th>{{ t('acc.can_post') }}</th><th class="text-right">{{ t('acc.max_journal_amount') }}</th><th class="text-right"></th></tr></thead>
        <tbody>
          <tr v-for="u in users" :key="u.id">
            <td class="font-weight-medium">{{ u.name }}</td>
            <td class="text-caption text-medium-emphasis">{{ u.email }}</td>
            <td><VChip size="x-small" :color="u.can_post ? 'success' : 'error'" variant="tonal">{{ u.can_post ? t('acc.yes') : t('acc.blocked') }}</VChip></td>
            <td class="text-right">{{ money(u.max_amount) }}</td>
            <td class="text-right"><VBtn icon="tabler-pencil" size="small" variant="text" @click="openEdit(u)" /></td>
          </tr>
          <tr v-if="!users.length"><td colspan="5" class="text-center text-medium-emphasis py-6">{{ t('acc.no_post_users') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="480">
      <VCard>
        <VCardTitle>{{ t('acc.posting_limit_for') }} — {{ current?.name }}</VCardTitle>
        <VCardText>
          <VSwitch v-model="form.can_post" :label="t('acc.may_post_manual')" color="success" hide-details class="mb-3" />
          <VTextField v-model.number="form.max_amount" type="number" step="0.01" :label="t('acc.max_journal_amount')" variant="outlined" density="comfortable" :disabled="!form.can_post" clearable :hint="t('acc.leave_blank_unlimited')" persistent-hint :error-messages="form.errors.max_amount" />
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" @click="save">{{ t('acc.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
