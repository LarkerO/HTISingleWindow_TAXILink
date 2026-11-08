<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, requireLogin, report, money, stateLabel, type Order } from '../api';
import RouteMap from '../components/RouteMap.vue';
const route = useRoute(),
  router = useRouter(),
  order = ref<Order | null>(null),
  busy = ref(false),
  confirmCancel = ref(false),
  connected = ref(false);
let timer: ReturnType<typeof setInterval> | undefined, ws: WebSocket | undefined;
let reconnect: ReturnType<typeof setTimeout> | undefined,
  stopped = false,
  started = false;
function accept(next: Order) {
  if (
    !stopped &&
    next.id === route.params.id &&
    (!order.value || next.revision >= order.value.revision)
  )
    order.value = next;
}
const terminal = computed(
  () => order.value && ['COMPLETED', 'CANCELLED', 'FAILED'].includes(order.value.state),
);
const title = computed(() => {
  const o = order.value;
  if (!o) return '正在加载行程';
  return o.state === 'ACTIVE' ? o.telemetry.stageLabel || '车辆正在准备' : stateLabel[o.state];
});
async function load() {
  try {
    accept(await api<Order>('/orders/' + route.params.id));
  } catch (e) {
    report(e);
  }
}
function start() {
  if (stopped || started) return;
  started = true;
  load();
  timer = setInterval(load, 4000);
  connect();
}
function connect() {
  ws = new WebSocket(`${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.host}/ws`);
  ws.onopen = () => (connected.value = true);
  ws.onmessage = (e) => {
    try {
      const d = JSON.parse(e.data);
      if (d.type === 'orders.snapshot') {
        const o = d.orders.find((v: Order) => v.id === route.params.id);
        if (o) accept(o);
      }
    } catch {
      /* HTTP polling remains available. */
    }
  };
  ws.onclose = () => {
    connected.value = false;
    if (!stopped) reconnect = setTimeout(connect, 5000);
  };
}
watch(
  () => route.params.id,
  () => {
    order.value = null;
    if (started) load();
  },
);
onMounted(() => requireLogin(start));
onUnmounted(() => {
  stopped = true;
  if (reconnect) clearTimeout(reconnect);
  if (timer) clearInterval(timer);
  ws?.close();
});
async function action(which: string) {
  busy.value = true;
  try {
    await api('/orders/' + route.params.id + '/' + which, {});
    confirmCancel.value = false;
    await load();
  } catch (e) {
    report(e);
  } finally {
    busy.value = false;
  }
}
const eta = computed(() => {
  if (!order.value) return '--';
  const t = order.value.telemetry;
  const n = t.stage === 'TO_PICKUP' ? t.etaPickupSeconds : t.etaDropoffSeconds;
  return n == null ? '估算中' : `${Math.max(1, Math.ceil(n / 60))} 分钟`;
});
</script>
<template>
  <section class="trip-head">
    <button class="back" @click="router.push('/orders')">←</button><strong>行程详情</strong
    ><span class="connection"
      ><i class="live-dot" :class="{ offline: !connected }"></i
      >{{ connected ? '实时同步' : '定时同步' }}</span
    >
  </section>
  <RouteMap :geometry="order?.telemetry.geometry" :position="order?.telemetry.position" />
  <section v-if="order" class="trip-panel surface">
    <div class="section-kicker"><span class="live-dot"></span>{{ title }}</div>
    <h1>
      {{
        order.state === 'QUEUED'
          ? '正在为你等候车辆'
          : order.telemetry.stage === 'WAITING_PICKUP'
            ? '车辆已到，请上车'
            : terminal
              ? '感谢你的同行'
              : '每一程，都在掌握'
      }}
    </h1>
    <p v-if="order.state === 'QUEUED'" class="muted">
      已加入所选企业候补，任一中心派车成功即退出其他候补。
    </p>
    <p v-if="order.state === 'NEEDS_ATTENTION'" class="muted">
      车辆或乘客状态需要调度员协助，请等待处理；取消请求不会强制移除车上乘客。
    </p>
    <div class="trip-stats">
      <div>
        <small>{{ terminal ? '本次费用' : '当前金额' }}</small
        ><strong>{{ money(order.amountMinor) }}<em>游戏币</em></strong>
      </div>
      <div>
        <small>{{ order.telemetry.stage === 'TO_PICKUP' ? '预计接客' : '预计抵达' }}</small
        ><strong>{{ terminal ? '已结束' : eta }}</strong>
      </div>
    </div>
    <div class="order-route">
      <p><i class="point-dot green"></i>{{ order.pickup }}</p>
      <p><i class="point-dot amber"></i>{{ order.dropoff }}</p>
    </div>
    <div class="trip-spec">
      <span>{{ order.passengers }} 人同行</span
      ><span>{{
        order.offers.find((o) => o.companyId === order?.companyId)?.companyName ||
        '等待运营企业接单'
      }}</span
      ><span v-if="order.telemetry.geometry"
        >已行驶 {{ (order.telemetry.geometry.progressMeters / 1000).toFixed(2) }} km</span
      >
    </div>
    <div class="trip-actions">
      <t-button
        v-if="order.state === 'ACTIVE' && order.telemetry.stage === 'WAITING_PICKUP'"
        block
        theme="primary"
        size="large"
        :loading="busy"
        @click="action('depart')"
        >我已上车，确认发车</t-button
      ><t-button
        v-if="
          order.state === 'AWAITING_PAYMENT' || (terminal && order.amountMinor !== order.paidMinor)
        "
        block
        theme="primary"
        @click="router.push('/checkout/' + order.id)"
        >前往收银台</t-button
      ><t-button
        v-if="!terminal && order.state !== 'CANCEL_REQUESTED'"
        block
        variant="outline"
        :disabled="busy"
        @click="confirmCancel = true"
        >取消订单</t-button
      >
    </div>
    <p class="booking-note">
      支付仅演示 · {{ order.billingStarted ? '已开始计费' : '实际放行后开始计费' }} ·
      满员或超时也可能自动发车
    </p>
  </section>
  <div v-if="confirmCancel" class="modal-backdrop">
    <section class="login-card" role="dialog" aria-modal="true">
      <h2>确认取消本次行程？</h2>
      <p>已派车可能产生企业配置的取消费用；行驶中或有人乘坐时需调度协助。</p>
      <t-button block theme="danger" :loading="busy" @click="action('cancel')">确认取消</t-button
      ><t-button block variant="outline" @click="confirmCancel = false">继续行程</t-button>
    </section>
  </div>
</template>
