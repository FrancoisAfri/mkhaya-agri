<script setup lang="ts">
import { ref, reactive } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()

const documentTypes = [
  { value: 'appointment_letter', title: t('e_sign.create.types.appointment_letter') },
  { value: 'disciplinary',       title: t('e_sign.create.types.disciplinary') },
  { value: 'contract',           title: t('e_sign.create.types.contract') },
  { value: 'offboarding',        title: t('e_sign.create.types.offboarding') },
  { value: 'other',              title: t('e_sign.create.types.other') },
]

const signerRoles = [
  { value: 'employee',             title: t('e_sign.create.roles.employee') },
  { value: 'manager',              title: t('e_sign.create.roles.manager') },
  { value: 'hr',                   title: t('e_sign.create.roles.hr') },
  { value: 'witness',              title: t('e_sign.create.roles.witness') },
  { value: 'authorized_signatory', title: t('e_sign.create.roles.authorized_signatory') },
  { value: 'signer',               title: t('e_sign.create.roles.signer') },
]

type SignerForm = {
  kind: 'internal' | 'external'
  user_id: number | null
  name: string
  email: string
  phone: string
  role: string
  signing_order: number
}

const props = defineProps<{ people: { id: number; name: string; email: string }[] }>()

const form = useForm({
  document:      null as File | null,
  title:         '',
  document_type: 'contract',
  message:       '',
  expires_at:    '',
  signers:       [] as SignerForm[],
})

function addSigner() {
  form.signers.push({
    // Internal by default: most documents are signed by colleagues, and picking one is both
    // quicker and safer than typing an address.
    kind:          'internal',
    user_id:       null,
    name:          '',
    email:         '',
    phone:         '',
    role:          'signer',
    signing_order: form.signers.length + 1,
  })
}

/** Move a signer up or down the order — who signs first is a decision, not an accident of entry. */
function moveSigner(index: number, by: number) {
  const to = index + by
  if (to < 0 || to >= form.signers.length) return
  const [moved] = form.signers.splice(index, 1)
  form.signers.splice(to, 0, moved)
  form.signers.forEach((s, i) => { s.signing_order = i + 1 })
}

function removeSigner(index: number) {
  form.signers.splice(index, 1)
  form.signers.forEach((s, i) => { s.signing_order = i + 1 })
}

function submit() {
  // forceFormData: a File cannot travel as JSON, and Inertia only switches to multipart on its
  // own when it spots one at the top level — a null here on first render is enough for it to
  // decide otherwise and post the envelope without its document.
  form.post(route('esign.envelopes.store'), { forceFormData: true })
}
</script>

<template>
  <div class="pa-6" style="max-width: 900px;">
    <div class="d-flex align-center gap-3 mb-6">
      <VBtn variant="text" icon size="small" @click="router.visit(route('esign.envelopes.index'))">
        <VIcon icon="tabler-arrow-left" />
      </VBtn>
      <div>
        <h4 class="text-h4 font-weight-bold mb-1">{{ t('e_sign.create.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('e_sign.create.subtitle') }}</p>
      </div>
    </div>

    <VForm @submit.prevent="submit">
      <VRow>
        <VCol cols="12">
          <VCard elevation="0" border class="mb-4">
            <VCardText class="pa-5">
              <h6 class="text-h6 font-weight-semibold mb-4">{{ t('e_sign.create.details_section') }}</h6>

              <VRow>
                <VCol cols="12" md="8">
                  <VTextField
                    v-model="form.title"
                    :label="t('e_sign.create.title_label')"
                    variant="outlined"
                    density="comfortable"
                    :error-messages="form.errors.title"
                    :placeholder="t('e_sign.create.title_placeholder')"
                  />
                </VCol>
                <VCol cols="12" md="4">
                  <VSelect
                    v-model="form.document_type"
                    :label="t('e_sign.create.type_label')"
                    variant="outlined"
                    density="comfortable"
                    :items="documentTypes"
                    item-title="title"
                    item-value="value"
                    :error-messages="form.errors.document_type"
                  />
                </VCol>
                <VCol cols="12">
                  <VTextarea
                    v-model="form.message"
                    :label="t('e_sign.create.message_label')"
                    variant="outlined"
                    density="comfortable"
                    rows="3"
                    :placeholder="t('e_sign.create.message_placeholder')"
                    :error-messages="form.errors.message"
                  />
                </VCol>
                <VCol cols="12">
                  <!--
                    The document is what the envelope is for, so it sits with the details rather
                    than at the end: an envelope sent without one reaches the signer as a page with
                    nothing on it to read.
                  -->
                  <VFileInput
                    v-model="form.document"
                    :label="t('e_sign.create.document_label')"
                    :hint="t('e_sign.create.document_hint')"
                    persistent-hint
                    accept="application/pdf"
                    prepend-icon=""
                    prepend-inner-icon="tabler-file-type-pdf"
                    variant="outlined"
                    density="comfortable"
                    show-size
                    :error-messages="form.errors.document"
                  />
                </VCol>
                <VCol cols="12" md="4">
                  <VTextField
                    v-model="form.expires_at"
                    :label="t('e_sign.create.expires_label')"
                    variant="outlined"
                    density="comfortable"
                    type="date"
                    :error-messages="form.errors.expires_at"
                  />
                </VCol>
              </VRow>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12">
          <VCard elevation="0" border class="mb-4">
            <VCardText class="pa-5">
              <div class="d-flex align-center justify-space-between mb-4">
                <h6 class="text-h6 font-weight-semibold">{{ t('e_sign.create.signers_section') }}</h6>
                <VBtn size="small" variant="tonal" prepend-icon="tabler-plus" @click="addSigner">
                  {{ t('e_sign.create.add_signer') }}
                </VBtn>
              </div>

              <p v-if="!form.signers.length" class="text-body-2 text-medium-emphasis">
                {{ t('e_sign.create.no_signers') }}
              </p>

              <div
                v-for="(signer, idx) in form.signers"
                :key="idx"
                class="mb-5 pa-4 rounded"
                style="border: 1px solid rgba(0,0,0,0.12);"
              >
                <div class="d-flex align-center justify-space-between mb-3">
                  <p class="text-body-2 font-weight-medium mb-0">{{ t('e_sign.create.signer_number', { n: idx + 1 }) }}</p>
                  <div class="d-flex align-center ga-1">
                    <!-- Who signs first is a decision, so it is made by moving people, not by
                         editing a number and hoping the others agree. -->
                    <VBtn icon size="x-small" variant="text" :disabled="idx === 0" :title="t('e_sign.create.move_up')" @click="moveSigner(idx, -1)">
                      <VIcon icon="tabler-arrow-up" />
                    </VBtn>
                    <VBtn icon size="x-small" variant="text" :disabled="idx === form.signers.length - 1" :title="t('e_sign.create.move_down')" @click="moveSigner(idx, 1)">
                      <VIcon icon="tabler-arrow-down" />
                    </VBtn>
                    <VBtn icon size="x-small" variant="text" color="error" @click="removeSigner(idx)">
                      <VIcon icon="tabler-trash" />
                    </VBtn>
                  </div>
                </div>

                <VBtnToggle v-model="signer.kind" mandatory density="comfortable" variant="outlined" divided class="mb-3">
                  <VBtn value="internal" prepend-icon="tabler-users">{{ t('e_sign.create.internal') }}</VBtn>
                  <VBtn value="external" prepend-icon="tabler-mail">{{ t('e_sign.create.external') }}</VBtn>
                </VBtnToggle>

                <VRow>
                  <!--
                    A colleague is picked. Their name and address are already on file, and a
                    hand-typed address is the one thing that quietly sends a contract to nobody.
                  -->
                  <VCol v-if="signer.kind === 'internal'" cols="12">
                    <VAutocomplete
                      v-model="signer.user_id"
                      :items="people"
                      item-title="name"
                      item-value="id"
                      :label="t('e_sign.create.pick_person')"
                      variant="outlined"
                      density="comfortable"
                      :no-data-text="t('e_sign.create.no_people')"
                      :error-messages="(form.errors as any)[`signers.${idx}.user_id`]"
                    >
                      <template #item="{ props: itemProps, item }">
                        <VListItem v-bind="itemProps" :subtitle="item.raw.email" />
                      </template>
                    </VAutocomplete>
                  </VCol>

                  <template v-else>
                    <VCol cols="12" md="6">
                      <VTextField v-model="signer.name" :label="t('e_sign.create.name_label')" variant="outlined" density="comfortable"
                        :error-messages="(form.errors as any)[`signers.${idx}.name`]" />
                    </VCol>
                    <VCol cols="12" md="6">
                      <VTextField v-model="signer.email" :label="t('e_sign.create.email_label')" variant="outlined" density="comfortable" type="email"
                        :error-messages="(form.errors as any)[`signers.${idx}.email`]" />
                    </VCol>
                  </template>
                  <VCol cols="12" md="4">
                    <VTextField v-model="signer.phone" :label="t('e_sign.create.phone_label')" variant="outlined" density="comfortable" />
                  </VCol>
                  <VCol cols="12" md="4">
                    <VSelect v-model="signer.role" :label="t('e_sign.create.role_label')" variant="outlined" density="comfortable"
                      :items="signerRoles" item-title="title" item-value="value" />
                  </VCol>
                  <VCol cols="12" md="4">
                    <VTextField v-model.number="signer.signing_order" :label="t('e_sign.create.order_label')" variant="outlined" density="comfortable" type="number" min="1" readonly
                      :hint="t('e_sign.create.order_hint')" persistent-hint />
                  </VCol>
                </VRow>
              </div>

              <p v-if="form.errors.signers" class="text-caption text-error mt-1">{{ form.errors.signers }}</p>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12">
          <div class="d-flex gap-3">
            <VBtn type="submit" color="primary" prepend-icon="tabler-file-plus" :loading="form.processing" :disabled="!form.signers.length">
              {{ t('e_sign.create.create_button') }}
            </VBtn>
            <VBtn variant="tonal" @click="router.visit(route('esign.envelopes.index'))">
              {{ t('e_sign.create.cancel') }}
            </VBtn>
          </div>
        </VCol>
      </VRow>
    </VForm>
  </div>
</template>
