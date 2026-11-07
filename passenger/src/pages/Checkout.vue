<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, report, requireLogin, money, type Order } from '../api';
const route = useRoute(),
  router = useRouter(),
  order = ref<Order | null>(null),
  busy = ref(false),
  paid = ref(false);
onMounted(() =>
  requireLogin(async () => {
    try {
      order.value = await api<Order>('/orders/' + route.params.id);
    } catch (e) {
      report(e);
    }
  }),
);
const difference = computed(() =>
  order.value ? order.value.amountMinor - order.value.paidMinor : 0,
);
async function pay() {
  busy.value = true;
  try {
    order.value = await api<Order>('/orders/' + route.params.id + '/pay', {});
    paid.value = true;
  } catch (e) {
    report(e);
  } finally {
    busy.value = false;
  }
}
</script>
<template>
  <section class="page-head">
    <button class="back" @click="router.push('/trip/' + route.params.id)">←</button>
    <div>
      <div class="eyebrow">A SIMPLE CHECKOUT</div>
      <h1>行程收银台</h1>
    </div>
  </section>
  <section v-if="order" class="checkout-card surface">
    <div class="payment-icon">{{ paid ? '✓' : '◈' }}</div>
    <span class="muted">{{
      paid ? '演示支付完成' : difference < 0 ? '应退金额' : '本次待付'
    }}</span>
    <div class="payment-amount">{{ money(Math.abs(difference)) }}<small>游戏币</small></div>
    <div class="demo-notice">演示支付 · 不会扣除任何游戏内货币</div>
    <div class="profile-detail">
      <span>上车点</span><strong>{{ order.pickup }}</strong>
    </div>
    <div class="profile-detail">
      <span>下车点</span><strong>{{ order.dropoff }}</strong>
    </div>
    <div class="profile-detail">
      <span>订单金额</span><strong>{{ money(order.amountMinor) }} 游戏币</strong>
    </div>
    <div class="profile-detail">
      <span>已演示支付</span><strong>{{ money(order.paidMinor) }} 游戏币</strong>
    </div>
    <p class="helper">
      预付按所选方案最高报价演示支付；确定承运企业及结束结算后，多付部分自动生成演示退款，差额可在此补付。
    </p>
    <t-button v-if="!paid" block theme="primary" size="large" :loading="busy" @click="pay">{{
      difference < 0 ? '确认演示退款' : '确认演示支付'
    }}</t-button
    ><t-button v-else block theme="primary" @click="router.replace('/trip/' + order.id)"
      >返回行程</t-button
    >
  </section>
</template>
