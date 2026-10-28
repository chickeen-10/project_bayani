import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const css = await readFile(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
const sharedPageCss = await readFile(new URL('../../resources/css/phase14.css', import.meta.url), 'utf8');

function tokens(selector) {
  const escapedSelector = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const block = css.match(new RegExp(`${escapedSelector}\\s*\\{([^}]+)\\}`))?.[1];
  assert.ok(block, `Expected ${selector} theme tokens`);
  return Object.fromEntries([...block.matchAll(/--([\w-]+):\s*(#[\da-f]{3,8})/gi)].map(([, key, value]) => [key, value]));
}

function luminance(hex) {
  const normalized = hex.length === 4 ? [...hex.slice(1)].map(x => x + x).join('') : hex.slice(1);
  const [r, g, b] = normalized.match(/.{2}/g).map(value => parseInt(value, 16) / 255)
    .map(value => value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4);
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrast(foreground, background) {
  const a = luminance(foreground), b = luminance(background);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

test('outline-primary keeps readable foregrounds across hover, focus, and active states', () => {
  assert.match(css, /\.btn\.btn-outline-primary:not\(:disabled\):not\(\.disabled\):hover,[\s\S]*?\.btn\.btn-outline-primary:not\(:disabled\):not\(\.disabled\):focus-visible,[\s\S]*?\.btn\.btn-outline-primary:not\(:disabled\):not\(\.disabled\):active\s*\{[^}]*color:\s*var\(--bayani-primary-contrast\)[^}]*background-color:\s*var\(--bayani-primary\)/);

  const themes = [tokens(':root'), tokens('html[data-theme="dark"]')];
  for (const theme of themes) {
    assert.ok(contrast(theme['bayani-primary-contrast'], theme['bayani-primary']) >= 4.5,
      'Outline button hover/focus/active colors must meet WCAG AA for normal text');
  }

  assert.match(sharedPageCss, /\.hero-editorial \.hero-actions \.btn \+ \.btn:not\(:hover\):not\(:focus-visible\):not\(:active\)/);
  assert.match(sharedPageCss, /html\[data-theme="dark"\] \.hero-editorial \.hero-actions \.btn \+ \.btn:not\(:hover\):not\(:focus-visible\):not\(:active\)/);
});
