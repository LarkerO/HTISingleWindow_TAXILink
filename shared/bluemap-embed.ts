import { blueMap3dUrl, type BlueMapSettings } from './bluemap';
/** Isolate the embedded viewer from unrelated archived maps that may never respond. */
export async function blueMapEmbed(
  settings: BlueMapSettings,
  x: number,
  z: number,
  distance: number,
  signal: AbortSignal,
): Promise<string> {
  const response = await fetch('/bluemap/', {
    signal: AbortSignal.any([signal, AbortSignal.timeout(8000)]),
  });
  if (!response.ok) throw new Error('BlueMap WebApp 加载失败');
  const html = await response.text();
  if (!/<head[\s>]/i.test(html)) throw new Error('BlueMap WebApp 格式无效');
  const options = JSON.stringify({
    map: settings.mapId,
    hash: blueMap3dUrl(settings, x, z, distance).split('#')[1],
  }).replace(/</g, '\\u003c');
  const boot = `<base href="${location.origin}/bluemap/"><script>(()=>{const config=${options};const original=window.fetch.bind(window);window.fetch=async(input,init)=>{const response=await original(input,init);const url=new URL(typeof input==='string'?input:input.url,document.baseURI);if(url.pathname==='/bluemap/settings.json'&&response.ok){const data=await response.json();data.maps=[config.map];data.startLocation=config.hash;return new Response(JSON.stringify(data),{status:200,headers:{'Content-Type':'application/json'}});}return response;};})();<\/script>`;
  return html.replace(/<head([^>]*)>/i, `<head$1>${boot}`);
}
