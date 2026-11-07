<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api, user, requireLogin, report, money, type Offer, type Order } from '../api';
import { pickup, dropoff } from '../booking';
const router = useRouter(),
  passengers = ref(1),
  offers = ref<Offer[]>([]),
  selected = ref<string[]>([]),
  loading = ref(false),
  submitting = ref(false),
  quoteId = ref(''),
  expires = ref(0),
  unavailable = ref<{ name: string; reason: string }[]>([]);
const model = ref('');
const models = computed(() => [...new Set(offers.value.map((o) => o.vehicleName))]);
const matchingOffers = computed(() =>
  offers.value.filter((o) => !model.value || o.vehicleName === model.value),
);
const companies = computed(() => {
  const map = new Map<string, Offer[]>();
  for (const o of matchingOffers.value) map.set(o.companyId, [...(map.get(o.companyId) || []), o]);
  return [...map.entries()].map(([id, os]) => ({
    id,
    name: os[0].companyName,
    min: Math.min(...os.map((o) => o.fare.amountMinor)),
    max: Math.max(...os.map((o) => o.fare.amountMinor)),
    offer: os[0],
  }));
});
watch(model, () => {
  selected.value = [...new Set(matchingOffers.value.map((o) => o.companyId))];
});
let request = 0;
let orderKey = '';
async function quote() {
  if (!pickup.value || !dropoff.value) {
    router.replace('/');
    return;
  }
  if (!user.value) {
    requireLogin(() => quote());
    return;
  }
  const current = ++request;
  loading.value = true;
  offers.value = [];
  quoteId.value = '';
  orderKey = crypto.randomUUID();
  try {
    const d = await api<{
      id: string;
      expires: number;
      offers: Offer[];
      unavailable: typeof unavailable.value;
    }>('/quotes', {
      pickupId: pickup.value.id,
      dropoffId: dropoff.value.id,
      passengers: passengers.value,
    });
    if (current !== request) return;
    quoteId.value = d.id;
    expires.value = d.expires;
    offers.value = d.offers;
    unavailable.value = d.unavailable;
    selected.value = [...new Set(d.offers.map((o) => o.companyId))];
  } catch (e) {
    report(e);
  } finally {
    if (current === request) loading.value = false;
  }
}
function toggle(id: string) {
  selected.value = selected.value.includes(id)
    ? selected.value.filter((x) => x !== id)
    : [...selected.value, id];
}
async function book() {
  if (!selected.value.length) return;
  if (Date.now() / 1000 >= expires.value) {
    report('报价已过期，请刷新报价');
    return;
  }
  submitting.value = true;
  try {
    const o = await api<Order>(
      '/orders',
      {
        quoteId: quoteId.value,
        companyIds: selected.value,
        offerIds: matchingOffers.value
          .filter((o) => selected.value.includes(o.companyId))
          .map((o) => o.id),
      },
      'POST',
      { 'Idempotency-Key': orderKey },
    );
    router.replace(o.state === 'AWAITING_PAYMENT' ? '/checkout/' + o.id : '/trip/' + o.id);
  } catch (e) {
    report(e);
  } finally {
    submitting.value = false;
  }
}
const paymentLabels: Record<string, string> = {
  before: '先付后乘',
  after: '先乘后付',
  adjust: '预付后补退',
};
const fareLabels: Record<string, string> = {
  baseMinor: '起步费',
  distanceMinor: '里程费',
  timeMinor: '时长费',
  pickupMinor: '接客调度费',
  returnMinor: '回库调度费',
};
onMounted(quote);
</script>
<template>
  <section class="page-head">
    <button class="back" @click="router.push('/')">←</button>
    <div>
      <div class="eyebrow">YOUR RIDE, YOUR CHOICE</div>
      <h1>选择你的出行</h1>
    </div>
  </section>
  <div class="route-summary">
    <span class="point-dot green"></span>{{ pickup?.name }}<span class="route-arrow">→</span
    ><span class="point-dot amber"></span>{{ dropoff?.name }}
  </div>
  <section class="surface passenger-count">
    <div>
      <strong>几位同行？</strong>
      <p class="muted">所有乘客使用相同上下车点</p>
    </div>
    <t-stepper v-model="passengers" :min="1" :max="16" @change="quote" />
  </section>
  <div v-if="models.length" class="model-options">
    <button :class="{ chosen: !model }" @click="model = ''">全部车型</button
    ><button v-for="m in models" :key="m" :class="{ chosen: model === m }" @click="model = m">
      {{ m }}
    </button>
  </div>
  <div class="section-heading">
    <h2>可选运营企业</h2>
    <button class="text-button" :disabled="loading" @click="quote">刷新报价 ↻</button>
  </div>
  <div v-if="loading" class="loading-state">
    <t-loading size="32px" />
    <h2>正在为你寻找路线</h2>
    <p>核实运营范围、轨道连通性与各企业报价</p>
  </div>
  <template v-else
    ><button
      v-for="c in companies"
      :key="c.id"
      class="offer-card"
      :class="{ selected: selected.includes(c.id) }"
      @click="toggle(c.id)"
    >
      <div class="offer-top">
        <span class="company-mark">{{ c.name[0] }}</span
        ><span
          ><strong>{{ c.name }}</strong
          ><small>{{ c.offer.vehicleName }} · {{ c.offer.capacity }} 人座</small></span
        ><span class="check-circle">{{ selected.includes(c.id) ? '✓' : '' }}</span>
      </div>
      <div class="offer-bottom">
        <div>
          <span class="fare-number">{{ money(c.min) }}</span
          ><span v-if="c.max !== c.min">–{{ money(c.max) }}</span
          ><small> 游戏币</small>
          <p>
            {{ c.offer.rules.mode === 'fixed' ? '一口价' : '实时计费 · 预估' }} ·
            {{ paymentLabels[c.offer.rules.payment] }}
          </p>
        </div>
        <span class="pickup-distance"
          >接客 {{ (c.offer.route.pickupDistanceMeters / 1000).toFixed(1) }} km<br /><small
            >行程约 {{ Math.ceil(c.offer.route.estimatedSeconds / 60) }} 分钟</small
          ></span
        >
      </div>
      <details @click.stop>
        <summary>查看费用明细</summary>
        <div v-for="(v, k) in c.offer.fare.components" :key="k" class="fee-row">
          <span>{{ fareLabels[k] }}</span
          >{{ money(v) }} 游戏币
        </div>
      </details>
    </button>
    <p v-for="c in unavailable" :key="c.name" class="helper">{{ c.name }}：{{ c.reason }}</p>
    <div v-if="!companies.length" class="empty">
      <h2>暂时没有可用路线</h2>
      <p>可以调整人数或上下车点后重试</p>
    </div></template
  >
  <div class="booking-footer">
    <p>可多选企业；按轨道接客距离派车，无车时同时候补，成功后自动退出其他候补。</p>
    <t-button
      block
      size="large"
      theme="primary"
      :loading="submitting"
      :disabled="loading || !selected.length || !quoteId"
      @click="book"
      >确认打车 · 已选 {{ selected.length }} 家企业</t-button
    >
  </div>
</template>
