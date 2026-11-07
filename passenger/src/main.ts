import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import { Button, Search, Stepper, Loading } from 'tdesign-mobile-vue';
import 'tdesign-mobile-vue/es/style/index.css';
import App from './App.vue';
import './style.css';
const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', component: () => import('./pages/Home.vue') },
    { path: '/points/:which', component: () => import('./pages/Points.vue') },
    { path: '/vehicles', component: () => import('./pages/Vehicles.vue') },
    { path: '/orders', component: () => import('./pages/Orders.vue') },
    { path: '/profile', component: () => import('./pages/Profile.vue') },
    { path: '/trip/:id', component: () => import('./pages/Trip.vue') },
    { path: '/checkout/:id', component: () => import('./pages/Checkout.vue') },
  ],
});
createApp(App).use(router).use(Button).use(Search).use(Stepper).use(Loading).mount('#app');
