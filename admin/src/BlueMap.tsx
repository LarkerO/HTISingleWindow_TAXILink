import { useEffect, useState } from 'react';
import {
  blueMap3dUrl,
  blueMapTiles,
  loadBlueMap,
  type BlueMapSettings,
} from '../../shared/bluemap';

type MapPoint = { id: string; name: string; position?: { x: number; z: number } };
type RoutePoint = [number, number, number, number];

export function BlueMap({
  points = [],
  route = [],
  onSelect,
}: {
  points?: MapPoint[];
  route?: RoutePoint[];
  onSelect?: (id: string) => void;
}) {
  const [view3d, setView3d] = useState(false);
  const [settings, setSettings] = useState<BlueMapSettings>();
  const [failed, setFailed] = useState(false);
  useEffect(() => {
    const request = new AbortController();
    loadBlueMap(request.signal)
      .then(setSettings)
      .catch(() => {
        if (!request.signal.aborted) setFailed(true);
      });
    return () => request.abort();
  }, []);

  const markers = points.flatMap(({ id, name, position }) =>
    position && Number.isFinite(position.x) && Number.isFinite(position.z)
      ? [{ id, name, ...position }]
      : [],
  );
  const locations = [...markers, ...route.map(([, x, , z]) => ({ x, z }))];
  const [startX, startZ] = settings?.startPos ?? [0, 0];
  const xs = locations.length ? locations.map((point) => point.x) : [startX];
  const zs = locations.length ? locations.map((point) => point.z) : [startZ];
  const centerX = (Math.min(...xs) + Math.max(...xs)) / 2;
  const centerZ = (Math.min(...zs) + Math.max(...zs)) / 2;
  const width =
    Math.max(400, Math.max(...xs) - Math.min(...xs), (Math.max(...zs) - Math.min(...zs)) * 2) * 1.2;
  const height = width / 2;
  const x = centerX - width / 2;
  const z = centerZ - height / 2;
  const tiles = settings ? blueMapTiles(settings, x, z, width, height, 500 / width) : [];

  return (
    <figure style={{ margin: '12px 0' }}>
      {settings?.allow3d && (
        <button type="button" onClick={() => setView3d(!view3d)}>
          {view3d ? '切换到 2D' : '切换到 3D'}
        </button>
      )}
      {view3d && settings ? (
        <iframe
          title="BlueMap 3D 地图"
          src={blueMap3dUrl(settings, centerX, centerZ, width)}
          style={{ width: '100%', height: 360, border: 0 }}
        />
      ) : (
        <svg
          aria-label="BlueMap 世界地图"
          viewBox={`${x} ${z} ${width} ${height}`}
          style={{ width: '100%', display: 'block', background: '#e8eee7' }}
        >
          {tiles.map((tile) => (
            <image
              key={tile.url}
              href={tile.url}
              x={tile.x}
              y={tile.z}
              width={tile.width}
              height={tile.height}
              preserveAspectRatio="none"
            />
          ))}
          <polyline
            points={route.map(([, rx, , rz]) => `${rx},${rz}`).join(' ')}
            fill="none"
            stroke="#136c56"
            strokeWidth={3}
            vectorEffect="non-scaling-stroke"
          />
          {markers.map((point) => (
            <circle
              key={point.id}
              cx={point.x}
              cy={point.z}
              r={width / 100}
              fill="#136c56"
              stroke="white"
              strokeWidth={2}
              vectorEffect="non-scaling-stroke"
              role={onSelect ? 'button' : undefined}
              tabIndex={onSelect ? 0 : undefined}
              aria-label={point.name}
              onClick={() => onSelect?.(point.id)}
              onKeyDown={(event) => {
                if (onSelect && ['Enter', ' '].includes(event.key)) {
                  event.preventDefault();
                  onSelect(point.id);
                }
              }}
            >
              <title>{point.name}</title>
            </circle>
          ))}
        </svg>
      )}
      {failed && <figcaption>BlueMap 底图暂不可用</figcaption>}
    </figure>
  );
}
