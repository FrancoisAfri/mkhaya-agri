<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ResellerNav from './ResellerNav.vue'

const route = ziggyRoute
const { t } = useI18n()
const page = usePage()

interface Member { id: number; user_id: number; name: string; first_name: string | null; surname: string | null; phone: string | null; email: string; role: 'owner' | 'staff'; active: boolean }
const props = defineProps<{ members: Member[]; me: number }>()

const errors = computed<Record<string, string>>(() => (page.props.errors ?? {}) as Record<string, string>)
const credentials = computed<any>(() => (page.props.flash as any)?.credentials ?? null)
const roles = computed(() => [{ title: t('reseller_portal.role_owner'), value: 'owner' }, { title: t('reseller_portal.role_staff'), value: 'staff' }])

const adding = ref(false)
const add = useForm({ first_name: '', surname: '', email: '', phone: '', role: 'staff', password: '' })
const submitAdd = () => add.post(route('reseller.team.store'), { preserveScroll: true, onSuccess: () => { adding.value = false; add.reset() } })

const editing = ref<Member | null>(null)
const edit = useForm({ first_name: '', surname: '', phone: '', role: 'staff' })
const openEdit = (m: Member) => {
  editing.value = m
  edit.first_name = m.first_name ?? ''
  edit.surname = m.surname ?? ''
  edit.phone = m.phone ?? ''
  edit.role = m.role
  edit.clearErrors()
}
const submitEdit = () => editing.value && edit.put(route('reseller.team.update', editing.value.id), { preserveScroll: true, onSuccess: () => { editing.value = null } })

const removing = ref<Member | null>(null)
const remove = () => removing.value && router.delete(route('reseller.team.remove', removing.value.id), { preserveScroll: true, onFinish: () => { removing.value = null } })
const owners = computed(() => props.members.filter(m => m.role === 'owner').length)
</script>

<template>
  <div class="pa-4">
    <ResellerNav />
    <div class="d-flex flex-wrap align-center justify-space-between ga-3 mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold mb-0">{{ t('reseller_portal.team') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('reseller_portal.team_subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-user-plus" @click="adding = true">{{ t('reseller_portal.add_member') }}</VBtn>
    </div>

    <VAlert v-if="credentials" type="success" variant="tonal" class="mb-4" closable>
      <div class="font-weight-medium">{{ credentials.title }}: {{ credentials.name }}</div>
      <div class="text-body-2">{{ t('reseller_portal.email') }}: <strong>{{ credentials.email }}</strong> · {{ t('reseller_portal.password') }}: <strong>{{ credentials.password }}</strong></div>
      <div class="text-caption">{{ credentials.login_url }} · {{ t('reseller_portal.login_hint') }}</div>
    </VAlert>
    <VAlert v-for="(msg, key) in errors" :key="key" type="error" variant="tonal" class="mb-4" closable>{{ msg }}</VAlert>

    <VCard elevation="0" border rounded="lg">
      <VTable density="comfortable">
        <thead><tr><th>{{ t('reseller_portal.name') }}</th><th>{{ t('reseller_portal.email') }}</th><th>{{ t('reseller_portal.f_phone') }}</th><th>{{ t('reseller_portal.role') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="m in members" :key="m.id">
            <td>
              {{ m.name }} <span v-if="m.user_id === me" class="text-caption text-medium-emphasis">({{ t('reseller_portal.you') }})</span>
              <VChip v-if="!m.active" size="x-small" color="error" variant="tonal" class="ms-1">{{ t('reseller_portal.inactive') }}</VChip>
            </td>
            <td>{{ m.email }}</td>
            <td>{{ m.phone }}</td>
            <td><VChip size="small" :color="m.role === 'owner' ? 'primary' : undefined" variant="tonal">{{ t(`reseller_portal.role_${m.role}`) }}</VChip></td>
            <td class="text-right text-no-wrap">
              <VBtn size="small" variant="text" icon="tabler-pencil" :aria-label="t('reseller_portal.edit_member')" @click="openEdit(m)" />
              <VBtn size="small" variant="text" color="error" icon="tabler-user-minus" :aria-label="t('reseller_portal.remove')"
                    :disabled="m.user_id === me || (m.role === 'owner' && owners < 2)" @click="removing = m" />
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="adding" max-width="520">
      <VCard :title="t('reseller_portal.add_member')">
        <VCardText>
          <VRow dense>
            <VCol cols="12" sm="6"><VTextField v-model="add.first_name" :label="t('reseller_portal.first_name')" :error-messages="add.errors.first_name" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="add.surname" :label="t('reseller_portal.surname')" :error-messages="add.errors.surname" /></VCol>
            <VCol cols="12"><VTextField v-model="add.email" type="email" :label="t('reseller_portal.email')" :error-messages="add.errors.email" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="add.phone" :label="t('reseller_portal.f_phone')" :error-messages="add.errors.phone" /></VCol>
            <VCol cols="12" sm="6"><VSelect v-model="add.role" :items="roles" :label="t('reseller_portal.role')" :error-messages="add.errors.role" /></VCol>
            <VCol cols="12"><VTextField v-model="add.password" type="password" :label="t('reseller_portal.password')" :error-messages="add.errors.password" autocomplete="new-password" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="adding = false">{{ t('reseller_portal.cancel') }}</VBtn><VBtn color="primary" :loading="add.processing" @click="submitAdd">{{ t('reseller_portal.add_member') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="!!editing" max-width="480" @update:model-value="v => { if (!v) editing = null }">
      <VCard v-if="editing" :title="t('reseller_portal.edit_member')" :subtitle="editing.email">
        <VCardText>
          <VRow dense>
            <VCol cols="12" sm="6"><VTextField v-model="edit.first_name" :label="t('reseller_portal.first_name')" :error-messages="edit.errors.first_name" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="edit.surname" :label="t('reseller_portal.surname')" :error-messages="edit.errors.surname" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="edit.phone" :label="t('reseller_portal.f_phone')" /></VCol>
            <VCol cols="12" sm="6"><VSelect v-model="edit.role" :items="roles" :label="t('reseller_portal.role')" :disabled="editing.user_id === me" :error-messages="edit.errors.role" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="editing = null">{{ t('reseller_portal.cancel') }}</VBtn><VBtn color="primary" :loading="edit.processing" @click="submitEdit">{{ t('reseller_portal.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="!!removing" max-width="420" @update:model-value="v => { if (!v) removing = null }">
      <VCard v-if="removing" :title="t('reseller_portal.remove')">
        <VCardText>{{ t('reseller_portal.remove_confirm', { name: removing.name }) }}</VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="removing = null">{{ t('reseller_portal.cancel') }}</VBtn><VBtn color="error" @click="remove">{{ t('reseller_portal.remove') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
