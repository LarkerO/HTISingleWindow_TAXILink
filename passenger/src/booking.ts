import { ref } from 'vue';
import type { Point } from './api';
const read = (key: string): Point | null => {
  try {
    return JSON.parse(sessionStorage.getItem(key) || 'null');
  } catch {
    return null;
  }
};
export const pickup = ref<Point | null>(read('pickup')),
  dropoff = ref<Point | null>(read('dropoff'));
export function selectPoint(which: string, point: Point) {
  const key = which === 'dropoff' ? 'dropoff' : 'pickup';
  (key === 'pickup' ? pickup : dropoff).value = point;
  sessionStorage.setItem(key, JSON.stringify(point));
}
