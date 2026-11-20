/** Runs inside the same-origin BlueMap WebApp, including native webviews. */
export function blueMapHealthScript(failureUrl = ''): string {
  return `(() => {
    if (window.__taxilinkMapHealth) return;
    window.__taxilinkMapHealth = true;
    const started = Date.now();
    let tuned = false, ready = false, canvas = null;
    let timer;
    const report = status => {
      if (status === 'failed') {
        clearInterval(timer);
        if (${JSON.stringify(failureUrl)}) { location.replace(${JSON.stringify(failureUrl)}); return; }
      }
      parent.postMessage({ type: 'taxilink-bluemap-health', status }, (location.origin === 'null' ? new URL(document.baseURI).origin : location.origin));
    };
    const lost = () => report('failed');
    const check = () => {
      const viewer = window.bluemap?.mapViewer;
      if (document.querySelector('#bm-app-err') || viewer?.renderer?.getContext()?.isContextLost()) {
        report('failed'); return;
      }
      if (viewer && !tuned) {
        tuned = true;
        // Avoid high pixel density and large geometry ranges exhausting a phone's GPU.
        if (viewer.data) {
          viewer.data.superSampling = Math.min(viewer.data.superSampling || 1, 0.75);
          viewer.data.loadedHiresViewDistance = Math.min(viewer.data.loadedHiresViewDistance || 64, 64);
        }
        viewer.superSampling = 0.75;
        viewer.updateLoadedMapArea?.();
        canvas = viewer.renderer?.domElement;
        canvas?.addEventListener('webglcontextlost', lost);
      }
      if (!ready && viewer?.map && canvas?.width > 0 && viewer.renderer?.info?.render?.calls > 0) {
        ready = true;
        report('ready');
      }
      if (!ready && Date.now() - started > 20000) report('failed');
    };
    timer = setInterval(check, 250);
    addEventListener('pagehide', () => {
      clearInterval(timer);
      canvas?.removeEventListener('webglcontextlost', lost);
      const renderer = window.bluemap?.mapViewer?.renderer;
      renderer?.dispose?.();
      renderer?.forceContextLoss?.();
    }, { once: true });
    check();
  })()`;
}
