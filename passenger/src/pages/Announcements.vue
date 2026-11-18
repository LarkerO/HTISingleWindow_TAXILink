<script setup lang="ts">
import { ref, watch } from 'vue';
import { api, user } from '../api';
const items = ref<{ id: string; title: string; updated_at: number; login_required: number }[]>([]);
const page = ref(1),
  total = ref(0),
  loading = ref(false),
  error = ref('');
async function load() {
  loading.value = true;
  error.value = '';
  try {
    const data = await api<{ items: typeof items.value; total: number }>(
      '/announcements?page=' + page.value,
    );
    items.value = data.items;
    total.value = data.total;
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    loading.value = false;
  }
}
watch(
  () => user.value?.id,
  () => {
    page.value = 1;
    load();
  },
  { immediate: true },
);
</script>
<template>
  <section class="page-head">
    <RouterLink to="/profile" class="back">←</RouterLink>
    <h1>公告中心</h1>
  </section>
  <p v-if="loading">正在加载公告…</p>
  <div v-else-if="error" role="alert">{{ error }} <button @click="load">重试</button></div>
  <p v-else-if="!items.length" class="empty">暂无公告</p>
  <RouterLink
    v-for="item in items"
    :key="item.id"
    :to="'/announcement/' + item.id"
    class="announcement-item surface"
  >
    <strong>{{ item.title }}</strong
    ><small
      >{{ new Date(item.updated_at * 1000).toLocaleDateString('zh-CN')
      }}{{ Number(item.login_required) ? ' · 登录可见' : '' }}</small
    >
  </RouterLink>
  <div class="section-heading">
    <button
      :disabled="page <= 1 || loading"
      @click="
        page--;
        load();
      "
    >
      上一页</button
    ><span>{{ page }}</span
    ><button
      :disabled="page * 30 >= total || loading"
      @click="
        page++;
        load();
      "
    >
      下一页
    </button>
  </div>
</template>
