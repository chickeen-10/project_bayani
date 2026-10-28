import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createCanvas, DOMMatrix, ImageData, Path2D, loadImage } from '@napi-rs/canvas';
import { renderPdfCover } from '../../resources/js/bayani-pdf.js';

Object.assign(globalThis, { DOMMatrix, ImageData, Path2D });
const pdfjs = await import('pdfjs-dist/legacy/build/pdf.mjs');

test('actual PDF first page renders to a bounded, nonblank image', async () => {
    const pdf = readFileSync(new URL('../../public/sample-files/sample-history.pdf', import.meta.url));
    const result = await renderPdfCover(`data:application/pdf;base64,${pdf.toString('base64')}`, pdfjs, () => createCanvas(1,1));
    assert.match(result, /^data:image\/webp;base64,/);
    const image = await loadImage(result);
    assert.ok(image.width > 100 && image.width <= 1000);
    assert.ok(image.height > 100 && image.height <= 1400);
    const canvas = createCanvas(image.width, image.height);
    const ctx = canvas.getContext('2d'); ctx.drawImage(image,0,0);
    const pixels = ctx.getImageData(0,0,image.width,image.height).data;
    assert.ok(pixels.some((v,i) => i%4!==3 && v<200), 'page includes actual ink rather than a blank white canvas');
});

test('corrupt PDF rejects cleanly for the fallback handler', async () => {
    await assert.rejects(renderPdfCover('data:application/pdf;base64,YmFk', pdfjs, () => createCanvas(1,1)));
});
