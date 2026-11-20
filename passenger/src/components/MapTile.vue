<script setup lang="ts">
import { ref, watch } from 'vue';
import { tileTexture } from '../../../shared/bluemap-texture';
const props = defineProps<{ url: string; shading: boolean }>();
const source = ref('');
watch(
  () => [props.url, props.shading],
  async (_, __, cleanup) => {
    let current = true;
    cleanup(() => (current = false));
    source.value = '';
    try {
      const value = await tileTexture(props.url, props.shading);
      if (current) source.value = value;
    } catch {
      /* Missing/unrendered tiles stay blank. */
    }
  },
  { immediate: true },
);
</script>
<template><image v-if="source" :href="source" preserveAspectRatio="none" /></template>
