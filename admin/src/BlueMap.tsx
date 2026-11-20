import { blueMapEmbed } from '../../shared/bluemap-embed';
import { Button, Space, Switch, Alert } from 'antd';
import { tileTexture } from '../../shared/bluemap-texture';
import { blueMapHealthScript } from '../../shared/bluemap-health';
import { useEffect, useState, useRef } from 'react';
import {
  blueMap3dUrl,
  blueMapTiles,
  loadBlueMap,
  type BlueMapSettings,
} from '../../shared/bluemap';

function Tile({
  url,
  shading,
  ...attrs
}: {
  url: string;
  shading: boolean;
  x: number;
  y: number;
  width: number;
  height: number;
}) {
  const [src, setSrc] = useState('');
  useEffect(() => {
    let current = true;
    setSrc('');
    tileTexture(url, shading)
      .then((v) => {
        if (current) setSrc(v);
      })
      .catch(() => {});
    return () => {
      current = false;
    };
  }, [url, shading]);
  return src ? <image href={src} {...attrs} preserveAspectRatio="none" /> : null;
}
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
  const [pan, setPan] = useState({ x: 0, z: 0 });
  const [zoom, setZoom] = useState(1);
  const [shading, setShading] = useState(true);
  const [notice, setNotice] = useState('');
  const drag = useRef<{ id: number; x: number; y: number } | null>(null);
  const frame = useRef<HTMLIFrameElement>(null);
  const [embed, setEmbed] = useState('');
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

  useEffect(() => {
    if (!view3d || !embed) return;
    const fail = () => {
      setView3d(false);
      setNotice('3D 加载失败，已恢复 2D；请检查 WebGL2 与 BlueMap 资源代理。');
    };
    const timer = setInterval(() => {
      try {
        (frame.current?.contentWindow as any)?.eval(blueMapHealthScript());
      } catch {}
    }, 500);
    const deadline = setTimeout(fail, 22000);
    const receive = (e: MessageEvent) => {
      if (
        e.origin !== location.origin ||
        e.source !== frame.current?.contentWindow ||
        e.data?.type !== 'taxilink-bluemap-health'
      )
        return;
      if (e.data.status === 'failed') fail();
      if (e.data.status === 'ready') clearTimeout(deadline);
    };
    window.addEventListener('message', receive);
    return () => {
      clearInterval(timer);
      clearTimeout(deadline);
      window.removeEventListener('message', receive);
    };
  }, [view3d, embed]);
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
    (Math.max(400, Math.max(...xs) - Math.min(...xs), (Math.max(...zs) - Math.min(...zs)) * 2) *
      1.2) /
    zoom;
  const height = width / 2;
  const x = centerX + pan.x - width / 2;
  const z = centerZ + pan.z - height / 2;
  const tiles = settings ? blueMapTiles(settings, x, z, width, height, 500 / width) : [];

  useEffect(() => {
    if (!view3d || !settings) return;
    const controller = new AbortController();
    setEmbed('');
    blueMapEmbed(settings, centerX + pan.x, centerZ + pan.z, width, controller.signal)
      .then((v) => {
        if (!controller.signal.aborted) setEmbed(v);
      })
      .catch(() => {
        if (!controller.signal.aborted) {
          setView3d(false);
          setNotice('3D WebApp 加载失败，已恢复 2D');
        }
      });
    return () => controller.abort();
  }, [view3d, settings]);
  return (
    <figure style={{ margin: '12px 0' }}>
      <Space wrap style={{ marginBottom: 8 }}>
        {settings?.allow3d && (
          <Button
            onClick={() => {
              setNotice('');
              setView3d(!view3d);
            }}
          >
            {view3d ? '切换到 2D' : '切换到 3D'}
          </Button>
        )}
        {!view3d && (
          <>
            <Button onClick={() => setZoom((v) => Math.min(8, v * 1.4))}>放大</Button>
            <Button onClick={() => setZoom((v) => Math.max(0.25, v / 1.4))}>缩小</Button>
            <Button
              onClick={() => {
                setPan({ x: 0, z: 0 });
                setZoom(1);
              }}
            >
              复位
            </Button>
            <Switch
              checked={shading}
              onChange={setShading}
              checkedChildren="高度/光照"
              unCheckedChildren="纯色底图"
            />
          </>
        )}
      </Space>
      {notice && <Alert type="warning" message={notice} />}
      {view3d && settings ? (
        <iframe
          ref={frame}
          onError={() => {
            setView3d(false);
            setNotice('3D 资源加载失败');
          }}
          title="BlueMap 3D 地图"
          srcDoc={embed || undefined}
          style={{ width: '100%', height: 360, border: 0 }}
        />
      ) : (
        <svg
          onPointerDown={(e) => {
            e.currentTarget.setPointerCapture(e.pointerId);
            drag.current = { id: e.pointerId, x: e.clientX, y: e.clientY };
          }}
          onPointerMove={(e) => {
            const d = drag.current;
            if (!d || d.id !== e.pointerId) return;
            const box = e.currentTarget.getBoundingClientRect();
            setPan((p) => ({
              x: p.x - ((e.clientX - d.x) * width) / box.width,
              z: p.z - ((e.clientY - d.y) * height) / box.height,
            }));
            drag.current = { id: e.pointerId, x: e.clientX, y: e.clientY };
          }}
          onPointerUp={() => {
            drag.current = null;
          }}
          onPointerCancel={() => {
            drag.current = null;
          }}
          onWheel={(e) =>
            setZoom((v) => Math.max(0.25, Math.min(8, v * (e.deltaY < 0 ? 1.12 : 0.88))))
          }
          aria-label="BlueMap 世界地图"
          viewBox={`${x} ${z} ${width} ${height}`}
          style={{
            width: '100%',
            display: 'block',
            background: '#e8eee7',
            touchAction: 'none',
            cursor: 'grab',
          }}
        >
          {tiles.map((tile) => (
            <Tile
              key={tile.url}
              url={tile.url}
              shading={shading}
              x={tile.x}
              y={tile.z}
              width={tile.width}
              height={tile.height}
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
