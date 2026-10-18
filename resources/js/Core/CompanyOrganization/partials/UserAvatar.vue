<script setup lang="ts">
import { ref, watch } from 'vue'

const props = defineProps<{
  src?:     string | null
  initial:  string
  size?:    number
  color?:   string
  rounded?: string
}>()

const imgError = ref(false)
const showImg  = ref(!!props.src)

watch(() => props.src, (val) => {
  if (val) {
    imgError.value = false
    showImg.value  = true
  } else {
    showImg.value = false
  }
})

const onError = () => { imgError.value = true; showImg.value = false }
const sz = props.size ?? 32
const bg = props.color ?? '#6366f1'
const br = props.rounded === 'full' ? '50%' : props.rounded === 'lg' ? '8px' : `${props.rounded ?? 8}px`
</script>

<template>
  <div
    :style="{
      width: sz + 'px', height: sz + 'px',
      borderRadius: br,
      background: showImg ? 'transparent' : bg + '30',
      color: bg,
      display: 'flex', alignItems: 'center', justifyContent: 'center',
      overflow: 'hidden', flexShrink: '0',
    }"
  >
    <img
      v-if="src && !imgError"
      :src="src"
      :style="{ width: '100%', height: '100%', objectFit: 'cover' }"
      @error="onError"
    />
    <span
      v-else
      :style="{ fontSize: Math.floor(sz * 0.35) + 'px', fontWeight: '700' }"
    >{{ initial }}</span>
  </div>
</template>
