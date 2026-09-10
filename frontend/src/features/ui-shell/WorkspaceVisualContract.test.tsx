import { describe, expect, it } from 'vitest';
import moduleWorkspace from '../../components/ModuleWorkspace.tsx?raw';
import misaWorkspaceLayout from '../../components/misa/MisaWorkspaceLayout.tsx?raw';

describe('workspace visual contract', () => {
  it('exposes one semantic workspace root and tab region', () => {
    expect(moduleWorkspace).toMatch(/className="[^"]*ui-workspace/);
    expect(moduleWorkspace).toMatch(/className="[^"]*ui-workspace-tabs/);
    expect(misaWorkspaceLayout).toMatch(/className="[^"]*ui-workspace/);
    expect(misaWorkspaceLayout).toMatch(/className="[^"]*ui-workspace-tabs/);
  });

  it('does not render no-op help or module-settings affordances', () => {
    expect(misaWorkspaceLayout).not.toContain('onHelpClick');
    expect(misaWorkspaceLayout).not.toContain('onSettingsClick');
    expect(misaWorkspaceLayout).not.toContain('apple-help-button');
    expect(misaWorkspaceLayout).not.toContain('Cài đặt phân hệ');
  });
});
