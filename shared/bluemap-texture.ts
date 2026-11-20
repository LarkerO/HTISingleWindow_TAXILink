// BlueMap metadata layout: G/B encode signed height, R encodes block light.
// See BlueMap's MIT-licensed LowresFragmentShader; this is a 2D approximation.
const cache = new Map<string, Promise<string>>();
export function tileTexture(url: string, shading: boolean): Promise<string> {
  const key = `${shading}:${url}`;
  const found = cache.get(key);
  if (found) return found;
  const pending = new Promise<string>((resolve, reject) => {
    const image = new Image();
    image.onload = () => {
      try {
        const w = image.naturalWidth,
          full = image.naturalHeight;
        const h = full === w * 2 ? full / 2 : full;
        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext('2d')!;
        ctx.drawImage(image, 0, 0);
        if (shading && h !== full) {
          const color = ctx.getImageData(0, 0, w, h);
          ctx.drawImage(image, 0, -h);
          const meta = ctx.getImageData(0, 0, w, h).data;
          const height = (i: number) => {
            const n = meta[i + 1] * 256 + meta[i + 2];
            return n >= 32768 ? -(65535 - n) : n;
          };
          for (let y = 0; y < h; y++)
            for (let x = 0; x < w; x++) {
              const i = (y * w + x) * 4;
              const dx = (y * w + Math.min(w - 1, x + 1)) * 4;
              const dz = (Math.min(h - 1, y + 1) * w + x) * 4;
              const shade = Math.max(
                -0.2,
                Math.min(0.04, (2 * height(i) - height(dx) - height(dz)) * 0.06),
              );
              // Daylight preview with a small block-light contribution.
              const light = 0.85 + (0.15 * Math.min(15, meta[i])) / 15;
              for (let c = 0; c < 3; c++)
                color.data[i + c] = (color.data[i + c] + shade * 255) * light;
            }
          ctx.putImageData(color, 0, 0);
        }
        resolve(canvas.toDataURL());
      } catch (error) {
        reject(error);
      }
    };
    image.onerror = () => reject(new Error('地图瓦片加载失败'));
    image.src = url;
  });
  cache.set(key, pending);
  pending.catch(() => cache.delete(key));
  if (cache.size > 192) cache.delete(cache.keys().next().value!);
  return pending;
}
