import { describe, expect, it } from 'vitest';
import appSource from '../../App.tsx?raw';
import mainSource from '../../main.tsx?raw';

describe('toast context boundary', () => {
  it('mounts the toast provider inside the themed app and avoids static global config', () => {
    expect(appSource).toContain("import ToastProvider from './components/feedback/ToastProvider';");
    expect(appSource).toContain('import { TOAST_CONFIG } from \'./components/feedback/toast\';');
    expect(appSource).toContain('<ToastProvider config={TOAST_CONFIG}>');
    expect(appSource).not.toContain('message.config(');
    expect(mainSource).not.toContain('<ToastProvider');
  });
});
