import { describe, expect, it } from 'vitest';
import appSource from '../App.tsx?raw';

describe('shared dropdown positioning', () => {
  it('configures Ant Design popups for viewport overflow adjustment and body portal', () => {
    expect(appSource).toMatch(/popupOverflow\s*=\s*["']viewport["']/);
    expect(appSource).toMatch(/getPopupContainer\s*=\s*\{\s*\(\)\s*=>\s*document\.body\s*\}/);
  });
});
