// Render one bounded page; PDF.js and canvas creation are supplied by the host.
export async function renderPdfCover(source, pdfjs, createCanvas = () => document.createElement('canvas')) {
    const data = Uint8Array.from(atob(source.slice(source.indexOf(',') + 1)), c => c.charCodeAt(0));
    const task = pdfjs.getDocument({ data, isEvalSupported: false, useSystemFonts: true });
    task.onPassword = () => { void task.destroy(); };
    const timeout = setTimeout(() => { void task.destroy(); }, 20000);
    try {
        const pdf = await task.promise;
        const page = await pdf.getPage(1);
        const initial = page.getViewport({ scale: 1 });
        const viewport = page.getViewport({ scale: Math.min(1000 / initial.width, 1400 / initial.height, 2) });
        const canvas = createCanvas();
        canvas.width = Math.ceil(viewport.width);
        canvas.height = Math.ceil(viewport.height);
        await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
        return canvas.toDataURL('image/webp', 0.85);
    } finally { clearTimeout(timeout); await task.destroy(); }
}
