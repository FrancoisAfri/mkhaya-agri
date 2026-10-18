<script setup lang="ts">
import { ref, watch } from 'vue'
import { EditorContent, useEditor } from '@tiptap/vue-3'
import { StarterKit } from '@tiptap/starter-kit'
import { Underline } from '@tiptap/extension-underline'
import { Highlight } from '@tiptap/extension-highlight'
import { Link } from '@tiptap/extension-link'
import { TextAlign } from '@tiptap/extension-text-align'
import { Placeholder } from '@tiptap/extension-placeholder'
import { Superscript } from '@tiptap/extension-superscript'
import { Subscript } from '@tiptap/extension-subscript'

const props = defineProps<{
  modelValue: string
  placeholder?: string
  errorMessages?: string | string[]
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: string): void
}>()

const linkDialogOpen = ref(false)
const linkUrl = ref('')

const editor = useEditor({
  content: props.modelValue,
  extensions: [
    StarterKit.configure({
      codeBlock: { HTMLAttributes: { class: 'kb-code-block' } },
      blockquote: { HTMLAttributes: { class: 'kb-blockquote' } },
    }),
    Underline,
    Highlight.configure({ multicolor: false }),
    Link.configure({ openOnClick: false, HTMLAttributes: { class: 'kb-link' } }),
    TextAlign.configure({ types: ['heading', 'paragraph'] }),
    Placeholder.configure({ placeholder: props.placeholder ?? 'Write article content here…' }),
    Superscript,
    Subscript,
  ],
  onUpdate() {
    emit('update:modelValue', editor.value?.getHTML() ?? '')
  },
})

watch(() => props.modelValue, (val) => {
  if (editor.value && editor.value.getHTML() !== val) {
    editor.value.commands.setContent(val)
  }
})

function setLink() {
  linkUrl.value = editor.value?.getAttributes('link').href ?? ''
  linkDialogOpen.value = true
}

function applyLink() {
  if (!linkUrl.value) {
    editor.value?.chain().focus().unsetLink().run()
  } else {
    editor.value?.chain().focus().setLink({ href: linkUrl.value }).run()
  }
  linkDialogOpen.value = false
  linkUrl.value = ''
}

function isActive(name: string, attrs?: Record<string, any>) {
  return attrs ? editor.value?.isActive(name, attrs) : editor.value?.isActive(name)
}
</script>

<template>
  <div
    class="kb-editor border rounded"
    :class="{ 'border-error': errorMessages && (Array.isArray(errorMessages) ? errorMessages.length : errorMessages) }"
  >
    <!-- ── Toolbar ── -->
    <div v-if="editor" class="kb-editor__toolbar d-flex flex-wrap align-center gap-1 pa-2 border-b">

      <!-- Paragraph / Heading picker -->
      <VSelect
        :model-value="
          editor.isActive('heading', { level: 1 }) ? 'h1'
          : editor.isActive('heading', { level: 2 }) ? 'h2'
          : editor.isActive('heading', { level: 3 }) ? 'h3'
          : 'p'
        "
        :items="[
          { title: 'Paragraph', value: 'p' },
          { title: 'Heading 1', value: 'h1' },
          { title: 'Heading 2', value: 'h2' },
          { title: 'Heading 3', value: 'h3' },
        ]"
        density="compact"
        variant="outlined"
        hide-details
        style="max-width: 130px; min-width: 110px;"
        class="me-1"
        @update:model-value="(v: string) => {
          if (v === 'p') editor.chain().focus().setParagraph().run()
          else editor.chain().focus().setHeading({ level: parseInt(v[1]) as 1|2|3 }).run()
        }"
      />

      <VDivider vertical class="mx-1" style="height:24px" />

      <!-- Inline formatting -->
      <VBtn
        size="x-small" :variant="isActive('bold') ? 'tonal' : 'text'"
        :color="isActive('bold') ? 'primary' : 'default'"
        icon @click="editor.chain().focus().toggleBold().run()"
      ><VIcon size="16">tabler-bold</VIcon></VBtn>

      <VBtn
        size="x-small" :variant="isActive('italic') ? 'tonal' : 'text'"
        :color="isActive('italic') ? 'primary' : 'default'"
        icon @click="editor.chain().focus().toggleItalic().run()"
      ><VIcon size="16">tabler-italic</VIcon></VBtn>

      <VBtn
        size="x-small" :variant="isActive('underline') ? 'tonal' : 'text'"
        :color="isActive('underline') ? 'primary' : 'default'"
        icon @click="editor.chain().focus().toggleUnderline().run()"
      ><VIcon size="16">tabler-underline</VIcon></VBtn>

      <VBtn
        size="x-small" :variant="isActive('strike') ? 'tonal' : 'text'"
        :color="isActive('strike') ? 'primary' : 'default'"
        icon @click="editor.chain().focus().toggleStrike().run()"
      ><VIcon size="16">tabler-strikethrough</VIcon></VBtn>

      <VBtn
        size="x-small" :variant="isActive('highlight') ? 'tonal' : 'text'"
        :color="isActive('highlight') ? 'warning' : 'default'"
        icon @click="editor.chain().focus().toggleHighlight().run()"
      ><VIcon size="16">tabler-highlight</VIcon></VBtn>

      <VBtn
        size="x-small" :variant="isActive('code') ? 'tonal' : 'text'"
        :color="isActive('code') ? 'primary' : 'default'"
        icon @click="editor.chain().focus().toggleCode().run()"
      ><VIcon size="16">tabler-code</VIcon></VBtn>

      <VDivider vertical class="mx-1" style="height:24px" />

      <!-- Lists -->
      <VBtn
        size="x-small" :variant="isActive('bulletList') ? 'tonal' : 'text'"
        :color="isActive('bulletList') ? 'primary' : 'default'"
        icon @click="editor.chain().focus().toggleBulletList().run()"
      ><VIcon size="16">tabler-list</VIcon></VBtn>

      <VBtn
        size="x-small" :variant="isActive('orderedList') ? 'tonal' : 'text'"
        :color="isActive('orderedList') ? 'primary' : 'default'"
        icon @click="editor.chain().focus().toggleOrderedList().run()"
      ><VIcon size="16">tabler-list-numbers</VIcon></VBtn>

      <VBtn
        size="x-small" :variant="isActive('blockquote') ? 'tonal' : 'text'"
        :color="isActive('blockquote') ? 'primary' : 'default'"
        icon @click="editor.chain().focus().toggleBlockquote().run()"
      ><VIcon size="16">tabler-blockquote</VIcon></VBtn>

      <VBtn
        size="x-small" :variant="isActive('codeBlock') ? 'tonal' : 'text'"
        :color="isActive('codeBlock') ? 'primary' : 'default'"
        icon @click="editor.chain().focus().toggleCodeBlock().run()"
      ><VIcon size="16">tabler-braces</VIcon></VBtn>

      <VDivider vertical class="mx-1" style="height:24px" />

      <!-- Link -->
      <VBtn
        size="x-small" :variant="isActive('link') ? 'tonal' : 'text'"
        :color="isActive('link') ? 'primary' : 'default'"
        icon @click="setLink"
      ><VIcon size="16">tabler-link</VIcon></VBtn>

      <!-- HR -->
      <VBtn
        size="x-small" variant="text"
        icon @click="editor.chain().focus().setHorizontalRule().run()"
      ><VIcon size="16">tabler-minus</VIcon></VBtn>

      <VDivider vertical class="mx-1" style="height:24px" />

      <!-- Align -->
      <VBtn
        size="x-small" :variant="isActive('paragraph', { textAlign: 'left' }) ? 'tonal' : 'text'"
        :color="isActive('paragraph', { textAlign: 'left' }) ? 'primary' : 'default'"
        icon @click="editor.chain().focus().setTextAlign('left').run()"
      ><VIcon size="16">tabler-align-left</VIcon></VBtn>

      <VBtn
        size="x-small" :variant="isActive('paragraph', { textAlign: 'center' }) ? 'tonal' : 'text'"
        :color="isActive('paragraph', { textAlign: 'center' }) ? 'primary' : 'default'"
        icon @click="editor.chain().focus().setTextAlign('center').run()"
      ><VIcon size="16">tabler-align-center</VIcon></VBtn>

      <VBtn
        size="x-small" :variant="isActive('paragraph', { textAlign: 'right' }) ? 'tonal' : 'text'"
        :color="isActive('paragraph', { textAlign: 'right' }) ? 'primary' : 'default'"
        icon @click="editor.chain().focus().setTextAlign('right').run()"
      ><VIcon size="16">tabler-align-right</VIcon></VBtn>

      <div class="ms-auto d-flex gap-1">
        <VBtn size="x-small" variant="text" icon :disabled="!editor.can().undo()" @click="editor.chain().focus().undo().run()">
          <VIcon size="16">tabler-arrow-back-up</VIcon>
        </VBtn>
        <VBtn size="x-small" variant="text" icon :disabled="!editor.can().redo()" @click="editor.chain().focus().redo().run()">
          <VIcon size="16">tabler-arrow-forward-up</VIcon>
        </VBtn>
      </div>
    </div>

    <!-- ── Editor content ── -->
    <EditorContent :editor="editor" class="kb-editor__content" />

    <!-- Error message -->
    <div v-if="errorMessages" class="text-caption text-error px-3 pt-1">
      {{ Array.isArray(errorMessages) ? errorMessages[0] : errorMessages }}
    </div>
  </div>

  <!-- Link dialog -->
  <VDialog v-model="linkDialogOpen" max-width="440">
    <VCard>
      <VCardTitle>Insert Link</VCardTitle>
      <VCardText>
        <VTextField
          v-model="linkUrl"
          label="URL"
          placeholder="https://…"
          variant="outlined"
          density="compact"
          autofocus
          @keydown.enter="applyLink"
        />
      </VCardText>
      <VCardActions class="justify-end">
        <VBtn variant="text" @click="linkDialogOpen = false">Cancel</VBtn>
        <VBtn v-if="isActive('link')" color="error" variant="text" @click="linkUrl = ''; applyLink()">Remove</VBtn>
        <VBtn color="primary" @click="applyLink">Apply</VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>

<style lang="scss">
.kb-editor {
  &__toolbar {
    background: rgb(var(--v-theme-surface));
  }

  &__content .ProseMirror {
    padding: 1rem;
    min-block-size: 40vh;
    outline: none;
    line-height: 1.7;

    > * + * { margin-block-start: 0.75em; }

    h1 { font-size: 1.75rem; font-weight: 700; }
    h2 { font-size: 1.4rem;  font-weight: 600; }
    h3 { font-size: 1.15rem; font-weight: 600; }

    ul { list-style: disc;    padding-inline-start: 1.5em; }
    ol { list-style: decimal; padding-inline-start: 1.5em; }

    blockquote {
      border-inline-start: 3px solid rgb(var(--v-theme-primary));
      padding-inline-start: 1em;
      color: rgba(var(--v-theme-on-surface), 0.6);
      font-style: italic;
    }

    code {
      background: rgba(var(--v-theme-on-surface), 0.06);
      border-radius: 4px;
      padding: 0.15em 0.35em;
      font-family: monospace;
      font-size: 0.875em;
    }

    pre.kb-code-block {
      background: rgba(var(--v-theme-on-surface), 0.06);
      border-radius: 6px;
      padding: 1em;
      overflow-x: auto;
      code { background: none; padding: 0; }
    }

    a.kb-link {
      color: rgb(var(--v-theme-primary));
      text-decoration: underline;
      cursor: pointer;
    }

    mark { background: #ffe066; border-radius: 2px; padding: 0 2px; }

    hr { border: none; border-block-start: 1px solid rgba(var(--v-theme-on-surface), 0.12); margin-block: 1.5em; }

    p.is-editor-empty:first-child::before {
      color: rgba(var(--v-theme-on-surface), 0.38);
      content: attr(data-placeholder);
      float: inline-start;
      pointer-events: none;
      block-size: 0;
    }
  }
}
</style>
