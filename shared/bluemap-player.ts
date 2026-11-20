import type { BlueMapSettings } from './bluemap';
export type PlayerPosition = { x: number; y?: number; z: number };
export async function ownBlueMapPosition(
  settings: BlueMapSettings,
  username: string,
  signal: AbortSignal,
): Promise<PlayerPosition | null> {
  const response = await fetch(settings.root + '/live/players.json', { signal, cache: 'no-store' });
  if (!response.ok) throw new Error('BlueMap 在线位置不可用');
  const data = await response.json();
  if (!Array.isArray(data.players)) throw new Error('BlueMap 在线位置格式无效');
  const matches = data.players.filter((p: any) => p.name === username && p.foreign === false);
  if (matches.length !== 1) return null;
  const p = matches[0].position;
  return p && Number.isFinite(p.x) && Number.isFinite(p.z) ? { x: p.x, y: p.y, z: p.z } : null;
}
