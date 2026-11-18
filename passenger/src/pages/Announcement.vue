<script setup lang="ts">
import { ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { api, user } from '../api';
import { renderMarkdown } from '../../../shared/markdown';
const route = useRoute();
const item = ref<{ title: string; markdown: string; updated_at: number } | null>(null);
const error = ref(''),
  loading = ref(false);
async function load() {
  item.value = null;
  error.value = '';
  loading.value = true;
  try {
    item.value = await api('/announcements/' + route.params.id);
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    loading.value = false;
  }
}
watch(() => [route.params.id, user.value?.id], load, { immediate: true });
</script>
<template>
  <section class="page-head">
    <RouterLink to="/announcements" class="back">←</RouterLink><strong>公告详情</strong>
  </section>
  <p v-if="loading">正在加载…</p>
  <p v-else-if="error" role="alert">{{ error }} <button @click="load">重试</button></p>
  <article v-else-if="item" class="surface announcement-detail">
    <h1>{{ item.title }}</h1>
    <p class="muted">{{ new Date(item.updated_at * 1000).toLocaleString('zh-CN') }}</p>
    <div class="markdown-body" v-html="renderMarkdown(item.markdown)"></div>
  </article>
</template>
