import { describe, expect, it } from 'vitest';
import workspaceSource from './FixedAssetWorkspace.tsx?raw';
import registrationsSource from './FixedAssetRegistrations.tsx?raw';
import depreciationsSource from './FixedAssetDepreciations.tsx?raw';
import revaluationsSource from './FixedAssetRevaluations.tsx?raw';
import disposalsSource from './FixedAssetDisposals.tsx?raw';
import reportsSource from './FixedAssetReports.tsx?raw';
import registrationModalSource from './modals/AssetRevaluationModal.tsx?raw';
import disposalModalSource from './modals/AssetDisposalModal.tsx?raw';
import depreciationModalSource from './modals/RunDepreciationModal.tsx?raw';
import pageShellSource from '../../components/layout/PageShell.tsx?raw';

const tabSources = [registrationsSource, depreciationsSource, revaluationsSource, disposalsSource, reportsSource];

describe('fixed-asset workspace embedded contract', () => {
  it('passes the embedded rendering contract to every mounted tab', () => {
    for (const component of ['FixedAssetRegistrations', 'FixedAssetDepreciations', 'FixedAssetRevaluations', 'FixedAssetDisposals', 'FixedAssetReports']) {
      expect(workspaceSource).toContain(`<${component} embedded />`);
    }
  });

  it('keeps embedded tab toolbars out of the workspace body and exposes a contextual parent toolbar', () => {
    expect(workspaceSource).toContain('actions={<Button');
    expect(workspaceSource).toContain('activeTabKey');
    expect(pageShellSource).toMatch(/if \(embedded\)[\s\S]*return <>{children}<\/>;/);
    expect(pageShellSource).not.toMatch(/if \(embedded\)[\s\S]*return <>\{toolbar\}/);
  });

  it('keeps fixed-asset tabs compatible with standalone rendering while avoiding nested page shells when embedded', () => {
    for (const source of tabSources) {
      expect(source).toMatch(/interface .*Props|type .*Props/);
      expect(source).toContain('embedded?: boolean');
      expect(source).toMatch(/embedded\s*(?:\?|=)/);
    }
  });

  it('uses the shared modal body surface for every fixed-asset form', () => {
    expect(registrationsSource).toContain("from '../../components/layout/ModalFrame'");
    expect(registrationsSource).toContain('<ModalFrame>');
    for (const source of [registrationModalSource, disposalModalSource, depreciationModalSource]) {
      expect(source).toContain("from '../../../components/layout/ModalFrame'");
      expect(source).toContain('<ModalFrame>');
    }
  });

  it('labels registration as a draft save action', () => {
    expect(registrationsSource).toMatch(/>\s*Cất\s*<\/Button>/);
    expect(registrationsSource).not.toContain('Cất & Ghi sổ (F8/F9)');
  });
});
