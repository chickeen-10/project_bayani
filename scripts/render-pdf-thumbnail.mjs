import { readFile, writeFile } from 'node:fs/promises';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';
import { createCanvas } from '@napi-rs/canvas';

const [sourcePath, outputPath] = process.argv.slice(2);
if (!sourcePath || !outputPath) process.exit(2);

const bytes = new Uint8Array(await readFile(sourcePath));
if (bytes.byteLength > 20 * 1024 * 1024) process.exit(3);

const task = getDocument({
    data: bytes,
    isEvalSupported: false,
    enableXfa: false,
    useSystemFonts: true,
    stopAtErrors: true,
    maxImageSize: 4_000_000,
    canvasMaxAreaInBytes: 8_000_000,
    verbosity: 0,
});
task.onPassword = () => {
    void task.destroy();
};

try {
    const document = await task.promise;
    const page = await document.getPage(1);
    const base = page.getViewport({ scale: 1 });
    const scale = Math.min(1000 / base.width, 1400 / base.height, 2);
    const viewport = page.getViewport({ scale });
    const canvas = createCanvas(Math.ceil(viewport.width), Math.ceil(viewport.height));
    const context = canvas.getContext('2d');
    await page.render({ canvas, canvasContext: context, viewport, background: '#ffffff' }).promise;
    const png = canvas.toBuffer('image/png');
    if (png.byteLength > 4 * 1024 * 1024) process.exit(4);
    await writeFile(outputPath, png, { flag: 'wx' });
    page.cleanup();
    await task.destroy();
} catch (error) {
    await task.destroy().catch(() => {});
    process.stderr.write('PDF preview generation failed.\n');
    process.exitCode = 1;
}
