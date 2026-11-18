<script setup lang="ts">
import { ref, watch } from 'vue';
import { api, user } from '../api';
defineOptions({ name: 'NoticeBar' });
const props = defineProps<{ placement: string }>();
const items = ref<{ id: string; title: string }[]>([]);
let epoch = 0;
watch(
  () => [props.placement, user.value?.id],
  async () => {
    const current = ++epoch;
    items.value = [];
    try {
      const result = (
        await api<{ items: typeof items.value }>('/announcements?placement=' + props.placement)
      ).items;
      if (current === epoch) items.value = result;
    } catch {
      /* Announcement outages must not block booking. */
    }
  },
  { immediate: true },
);
</script>
<template>
  <aside v-if="items.length" class="notice-bar" aria-label="平台公告">
    <RouterLink v-for="item in items" :key="item.id" :to="'/announcement/' + item.id"
      >📣 {{ item.title }} →</RouterLink
    >
  </aside>
</template>
