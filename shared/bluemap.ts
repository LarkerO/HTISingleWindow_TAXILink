/// <reference types="vite/client" />
export interface BlueMapSettings {
  root: string;
  mapId: string;
  allow3d: boolean;
  startPos: [number, number];
  lowres: { tileSize: [number, number]; lodFactor: number; lodCount: number };
}

export const blueMapRoot = (
  import.meta.env.VITE_BLUEMAP_ROOT || '/bluemap/maps/heishan7_0'
).replace(/\/$/, '');

export async function loadBlueMap(signal: AbortSignal): Promise<BlueMapSettings> {
  const configResponse = await fetch('/api/map-settings', { signal });
  const config = configResponse.ok ? await configResponse.json() : {};
  const mapsRoot = blueMapRoot.slice(0, blueMapRoot.lastIndexOf('/'));
  const mapId = config.mapId ?? blueMapRoot.split('/').at(-1);
  const root = mapsRoot + '/' + encodeURIComponent(mapId);
  const response = await fetch(root + '/settings.json', { signal });
  if (!response.ok) throw new Error('BlueMap settings unavailable');
  const settings = await response.json();
  const { tileSize, lodFactor, lodCount } = settings.lowres;
  if (![...tileSize, lodFactor, lodCount].every((value) => Number.isFinite(value) && value > 0))
    throw new Error('Invalid BlueMap tile settings');
  return { ...settings, root, mapId, allow3d: config.allow3d ?? true };
}

function coordinate(value: number) {
  return (value < 0 ? '-' : '') + [...String(Math.abs(value))].join('/');
}

export function blueMapTiles(
  settings: BlueMapSettings,
  x: number,
  z: number,
  w: number,
  h: number,
  scale: number,
) {
  const { tileSize, lodFactor, lodCount } = settings.lowres;
  const lod = Math.min(
    lodCount,
    1 + Math.max(0, Math.floor(Math.log(1 / scale) / Math.log(lodFactor))),
  );
  const width = tileSize[0] * lodFactor ** (lod - 1);
  const height = tileSize[1] * lodFactor ** (lod - 1);
  const tiles = [];
  for (let tx = Math.floor(x / width); tx < Math.ceil((x + w) / width); tx++) {
    for (let tz = Math.floor(z / height); tz < Math.ceil((z + h) / height); tz++) {
      tiles.push({
        x: tx * width,
        z: tz * height,
        width,
        height,
        url: `${settings.root}/tiles/${lod}/x${coordinate(tx)}/z${coordinate(tz)}.png`,
      });
    }
  }
  return tiles;
}

export function blueMap3dUrl(
  settings: BlueMapSettings,
  x = settings.startPos[0],
  z = settings.startPos[1],
  distance = 500,
) {
  const camera = [
    Math.round(x),
    64,
    Math.round(z),
    Math.max(100, Math.round(distance)),
    0,
    0.8,
    0,
    0,
    'perspective',
  ];
  return '/bluemap/#' + [encodeURIComponent(settings.mapId), ...camera].join(':');
}
