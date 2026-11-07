<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { api, user, requireLogin, report, money, stateLabel, type Order } from '../api';
const router = useRouter(),
  orders = ref<Order[]>([]),
  total = ref(0),
  page = ref(1),
  loading = ref(false);
async function load(reset = false) {
  if (!user.value) {
    requireLogin(() => load());
    return;
  }
  loading.value = true;
  try {
    if (reset) page.value = 1;
    const d = await api<{ items: Order[]; total: number }>('/orders?page=' + page.value);
    orders.value = page.value === 1 ? d.items : [...orders.value, ...d.items];
    total.value = d.total;
  } catch (e) {
    report(e);
  } finally {
    loading.value = false;
  }
}
onMounted(() => load());
</script>
<template>
  <section class="welcome compact">
    <div class="eyebrow">EVERY JOURNEY MATTERS</div>
    <h1>我的行程</h1>
    <p class="muted">每一次出发，都有迹可循。</p>
  </section>
  <div class="section-heading">
    <h2>
      全部订单 <small>{{ total }}</small>
    </h2>
    <button class="text-button" @click="load(true)">刷新 ↻</button>
  </div>
  <t-loading v-if="loading && !orders.length" text="正在加载行程" /><button
    v-for="o in orders"
    :key="o.id"
    class="order-card surface"
    @click="router.push('/trip/' + o.id)"
  >
    <div class="order-meta">
      <span>{{ new Date(o.createdAt * 1000).toLocaleString('zh-CN') }}</span
      ><span
        class="status-tag"
        :class="{ live: !['COMPLETED', 'CANCELLED', 'FAILED'].includes(o.state) }"
        >{{ stateLabel[o.state] }}</span
      >
    </div>
    <div class="order-route">
      <p><i class="point-dot green"></i>{{ o.pickup }}</p>
      <p><i class="point-dot amber"></i>{{ o.dropoff }}</p>
    </div>
    <div class="order-bottom">
      <span>{{ o.passengers }} 人同行</span
      ><strong>{{ money(o.amountMinor) }} <small>游戏币</small> ↗</strong>
    </div>
  </button>
  <div v-if="!loading && !orders.length" class="empty">
    <span>⌁</span>
    <h2>还没有行程</h2>
    <p>选一个喜欢的地方，出发吧。</p>
    <t-button theme="primary" @click="router.push('/')">去打车</t-button>
  </div>
  <t-button
    v-if="orders.length < total"
    block
    variant="outline"
    :loading="loading"
    @click="
      page++;
      load();
    "
    >加载更多行程</t-button
  >
</template>
