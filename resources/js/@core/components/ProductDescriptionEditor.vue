<script setup lang="ts">
import { ref, watch } from 'vue'
import { Placeholder } from '@tiptap/extension-placeholder'
import { TextAlign } from '@tiptap/extension-text-align'
import { Underline } from '@tiptap/extension-underline'
import { StarterKit } from '@tiptap/starter-kit'
import { EditorContent, useEditor } from '@tiptap/vue-3'

const props = defineProps<{
  modelValue: string
  placeholder?: string
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: string): void
}>()

const editorRef = ref()

const editor = useEditor({
  content: props.modelValue,
  extensions: [
    StarterKit,
    TextAlign.configure({ types: ['heading', 'paragraph'] }),
    Placeholder.configure({ placeholder: props.placeholder ?? 'Write something here...' }),
    Underline,
  ],
  onUpdate() {
    if (!editor.value) return
    emit('update:modelValue', editor.value.getHTML())
  },
})

watch(() => props.modelValue, () => {
  const isSame = editor.value?.getHTML() === props.modelValue
  if (isSame) return
  editor.value?.commands.setContent(props.modelValue)
})
</script>

<template>
  <div class="productDescriptionEditor">

    <!-- ── Toolbar ──────────────────────────────────────────────── -->
    <div
      v-if="editor"
      class="editor-toolbar d-flex gap-1 flex-wrap align-center px-3 py-2"
    >
      <VBtn
        size="small" icon rounded
        :variant="editor.isActive('bold') ? 'tonal' : 'text'"
        :color="editor.isActive('bold') ? 'primary' : 'on-surface'"
        @click="editor.chain().focus().toggleBold().run()"
      >
        <VIcon icon="tabler-bold" />
      </VBtn>

      <VBtn
        size="small" icon rounded
        :variant="editor.isActive('italic') ? 'tonal' : 'text'"
        :color="editor.isActive('italic') ? 'primary' : 'on-surface'"
        @click="editor.chain().focus().toggleItalic().run()"
      >
        <VIcon icon="tabler-italic" />
      </VBtn>

      <VBtn
        size="small" icon rounded
        :variant="editor.isActive('underline') ? 'tonal' : 'text'"
        :color="editor.isActive('underline') ? 'primary' : 'on-surface'"
        @click="editor.commands.toggleUnderline()"
      >
        <VIcon icon="tabler-underline" />
      </VBtn>

      <VBtn
        size="small" icon rounded
        :variant="editor.isActive('strike') ? 'tonal' : 'text'"
        :color="editor.isActive('strike') ? 'primary' : 'on-surface'"
        @click="editor.chain().focus().toggleStrike().run()"
      >
        <VIcon icon="tabler-strikethrough" />
      </VBtn>

      <VDivider vertical class="mx-1" style="height:20px;align-self:center" />

      <VBtn
        size="small" icon rounded
        :variant="editor.isActive({ textAlign: 'left' }) ? 'tonal' : 'text'"
        :color="editor.isActive({ textAlign: 'left' }) ? 'primary' : 'on-surface'"
        @click="editor.chain().focus().setTextAlign('left').run()"
      >
        <VIcon icon="tabler-align-left" />
      </VBtn>

      <VBtn
        size="small" icon rounded
        :variant="editor.isActive({ textAlign: 'center' }) ? 'tonal' : 'text'"
        :color="editor.isActive({ textAlign: 'center' }) ? 'primary' : 'on-surface'"
        @click="editor.chain().focus().setTextAlign('center').run()"
      >
        <VIcon icon="tabler-align-center" />
      </VBtn>

      <VBtn
        size="small" icon rounded
        :variant="editor.isActive({ textAlign: 'right' }) ? 'tonal' : 'text'"
        :color="editor.isActive({ textAlign: 'right' }) ? 'primary' : 'on-surface'"
        @click="editor.chain().focus().setTextAlign('right').run()"
      >
        <VIcon icon="tabler-align-right" />
      </VBtn>

      <VBtn
        size="small" icon rounded
        :variant="editor.isActive({ textAlign: 'justify' }) ? 'tonal' : 'text'"
        :color="editor.isActive({ textAlign: 'justify' }) ? 'primary' : 'on-surface'"
        @click="editor.chain().focus().setTextAlign('justify').run()"
      >
        <VIcon icon="tabler-align-justified" />
      </VBtn>
    </div>

    <VDivider />

    <!-- ── Content ──────────────────────────────────────────────── -->
    <EditorContent
      ref="editorRef"
      :editor="editor"
      class="editor-content px-4 py-3"
    />
  </div>
</template>

<style lang="scss">
.productDescriptionEditor {
  .editor-toolbar {
    border-radius: 8px 8px 0 0;
  }

  .ProseMirror {
    min-block-size: 12vh;
    outline: none;
    font-size: .95rem;
    color: rgb(var(--v-theme-on-surface));

    p { margin-block-end: 0; }

    p.is-editor-empty:first-child::before {
      block-size: 0;
      color: #adb5bd;
      content: attr(data-placeholder);
      float: inline-start;
      pointer-events: none;
    }

    &-focused { outline: none; }
  }
}
</style>
