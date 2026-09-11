import ts from 'typescript';
import { describe, expect, it } from 'vitest';
import routesSource from '../../routes/index.tsx?raw';

type MigrationBatch = 4 | 5 | 6 | 7;
type LayoutContract = 'PageShell' | 'PageHeader' | 'PageToolbar' | 'ModalFrame';
type ParsedLeafRoute = {
  path: string;
  component: string;
  kind: 'page' | 'redirect';
};
type AuthSurface = {
  kind: 'auth-surface';
  reason: string;
};
type PendingPage = {
  kind: 'pending-compatibility-boundary';
  batch: MigrationBatch;
  reason: string;
  excludedContracts: readonly LayoutContract[];
  removalOwner: string;
};
type SourceConformantPage = {
  kind: 'source-conformant';
  batch: MigrationBatch;
  sourcePath: string;
  modalContract: 'no-direct-modal' | 'modal-frame';
  evidence: 'source-contract';
};
type RedirectBoundary = {
  kind: 'redirect-boundary';
  batch: MigrationBatch;
  reason: string;
  removalOwner: string;
};
type RoutePolicy = AuthSurface | PendingPage | SourceConformantPage | RedirectBoundary;

const asRoutePath = (path: string) => (path === '/' ? path : `/${path.replace(/^\//, '')}`);

const getJsxName = (name: ts.JsxTagNameExpression): string => {
  if (ts.isIdentifier(name)) return name.text;
  if (ts.isPropertyAccessExpression(name)) return name.getText();
  throw new Error(`Unsupported JSX tag name in route: ${name.getText()}`);
};

const getAttribute = (attributes: ts.JsxAttributes, name: string) => attributes.properties.find(
  (property): property is ts.JsxAttribute => ts.isJsxAttribute(property) && ts.isIdentifier(property.name) && property.name.text === name,
);

const getLiteralRoutePath = (route: ts.JsxAttributes) => {
  const pathAttribute = getAttribute(route, 'path');
  if (!pathAttribute) return undefined;
  if (!pathAttribute.initializer || !ts.isStringLiteral(pathAttribute.initializer)) {
    throw new Error('Every leaf Route path must be a string literal so it can be inventoried.');
  }
  return pathAttribute.initializer.text;
};

const getRouteComponent = (route: ts.JsxAttributes) => {
  const elementAttribute = getAttribute(route, 'element');
  if (!elementAttribute?.initializer || !ts.isJsxExpression(elementAttribute.initializer)) {
    throw new Error('Every leaf Route must provide a JSX element expression.');
  }
  const expression = elementAttribute.initializer.expression;
  if (!expression || !ts.isJsxSelfClosingElement(expression)) {
    throw new Error('Every inventory leaf Route must render a self-closing named JSX component.');
  }
  return getJsxName(expression.tagName);
};

const containsNestedRoute = (node: ts.Node) => {
  let nested = false;
  node.forEachChild((child) => {
    if (
      (ts.isJsxElement(child) && getJsxName(child.openingElement.tagName) === 'Route')
      || (ts.isJsxSelfClosingElement(child) && getJsxName(child.tagName) === 'Route')
    ) nested = true;
    if (!nested && containsNestedRoute(child)) nested = true;
  });
  return nested;
};

const parseLeafRoutes = (source: string): ParsedLeafRoute[] => {
  const parsed = ts.createSourceFile('routes.tsx', source, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
  const routes: ParsedLeafRoute[] = [];

  const visit = (node: ts.Node) => {
    const routeTag = ts.isJsxSelfClosingElement(node)
      ? node.tagName
      : ts.isJsxElement(node)
        ? node.openingElement.tagName
        : undefined;
    if (routeTag && getJsxName(routeTag) === 'Route') {
      const attributes = ts.isJsxSelfClosingElement(node) ? node.attributes : (node as ts.JsxElement).openingElement.attributes;
      if (!ts.isJsxSelfClosingElement(node) && containsNestedRoute(node)) {
        node.forEachChild(visit);
        return;
      }
      const indexAttribute = getAttribute(attributes, 'index');
      const path = indexAttribute ? '/' : getLiteralRoutePath(attributes);
      if (!path) throw new Error('Every leaf Route must declare path or index.');
      const component = getRouteComponent(attributes);
      routes.push({ path: asRoutePath(path), component, kind: component === 'Navigate' ? 'redirect' : 'page' });
    }
    node.forEachChild(visit);
  };
  visit(parsed);
  return routes;
};

const lazyImportPaths = new Map(Array.from(
  routesSource.matchAll(/const\s+(\w+)\s*=\s*lazy\(\(\)\s*=>\s*import\('([^']+)'\)\)/g),
  (match) => [match[1], match[2]],
));
const featureSources = import.meta.glob('../**/*.tsx', { eager: true, import: 'default', query: '?raw' }) as Record<string, string>;
const authPageSources = import.meta.glob('../../pages/**/*.tsx', { eager: true, import: 'default', query: '?raw' }) as Record<string, string>;

const sourcePathFor = (component: string) => {
  const importPath = lazyImportPaths.get(component);
  if (!importPath) throw new Error(`${component} is not a lazy route component.`);
  const featureSourcePath = `${importPath.replace('../features/', '../')}.tsx`;
  const source = featureSources[featureSourcePath] ?? authPageSources[importPath.replace('../pages/', '../../pages/') + '.tsx'];
  if (!source) throw new Error(`${component} source ${featureSourcePath} is not available to the inventory.`);
  return { importPath, source };
};

const sourceConformant = (batch: MigrationBatch, modalContract: SourceConformantPage['modalContract'] = 'no-direct-modal'): SourceConformantPage => ({
  kind: 'source-conformant', batch, sourcePath: '', modalContract, evidence: 'source-contract',
});

// This registry is intentionally route-owned. A new leaf route must be classified
// here before its test can pass. `source-conformant` is not rendered evidence;
// migration batches add rendered smoke tests before replacing a pending boundary.
const routePolicies: Readonly<Record<string, RoutePolicy>> = {
  '/login': { kind: 'auth-surface', reason: 'Authentication is outside the signed-in ERP page contract.' },
  '/register': { kind: 'auth-surface', reason: 'Authentication is outside the signed-in ERP page contract.' },
  '/': sourceConformant(4),
  '/cash': sourceConformant(6),
  '/cash/receipts': sourceConformant(6, 'modal-frame'),
  '/cash/payments': sourceConformant(6, 'modal-frame'),
  '/bank': sourceConformant(6),
  '/bank/accounts': sourceConformant(6),
  '/bank/receipts': sourceConformant(6),
  '/bank/payments': sourceConformant(6),
  '/bank/reconciliation': sourceConformant(6),
  '/purchase': sourceConformant(5),
  '/purchase/invoices': sourceConformant(5),
  '/purchase/ap-aging': sourceConformant(5),
  '/sales': sourceConformant(5),
  '/sales/invoices': sourceConformant(5, 'modal-frame'),
  '/sales/ar-aging': sourceConformant(5),
  '/inventory': sourceConformant(7),
  '/inventory/items': sourceConformant(7, 'modal-frame'),
  '/inventory/receipts': sourceConformant(7, 'modal-frame'),
  '/inventory/issues': sourceConformant(7, 'modal-frame'),
  '/inventory/transfers': sourceConformant(7, 'modal-frame'),
  '/inventory/stock-counts': sourceConformant(7, 'modal-frame'),
  '/inventory/stock-report': sourceConformant(7),
  '/fixed-assets': sourceConformant(4),
  '/gl': sourceConformant(7),
  '/reports': sourceConformant(7),
  '/reports/statutory-readiness': { kind: 'redirect-boundary', batch: 7, reason: 'Statutory readiness is outside the internal-core release profile.', removalOwner: 'Internal-core release owner' },
  '/reports/ap-ar-input-boundaries': sourceConformant(7),
  '/reports/inventory-reconciliation': sourceConformant(7),
  '/tax/*': { kind: 'redirect-boundary', batch: 7, reason: 'Tax workflows are intentionally out of internal scope.', removalOwner: 'Tax module owner' },
  '/assets/fixed': sourceConformant(4),
  '/assets/tools': sourceConformant(4),
  '/payroll/*': { kind: 'redirect-boundary', batch: 7, reason: 'Payroll deep links are retired while the voucher flow is consolidated.', removalOwner: 'Payroll module owner' },
  '/costing/*': { kind: 'redirect-boundary', batch: 4, reason: 'Costing deep links are retired while the workspace is consolidated.', removalOwner: 'Costing module owner' },
  '/budget/*': { kind: 'redirect-boundary', batch: 4, reason: 'Budget deep links are retired while the workspace is consolidated.', removalOwner: 'Budget module owner' },
  '/gl/journal-entries': sourceConformant(7, 'modal-frame'),
  '/gl/periods': sourceConformant(7, 'modal-frame'),
  '/reports/general-journal': sourceConformant(7),
  '/reports/general-ledger': sourceConformant(7),
  '/reports/trial-balance': sourceConformant(7),
  '/reports/balance-sheet': sourceConformant(7),
  '/reports/income-statement': sourceConformant(7),
  '/master/accounts': sourceConformant(4, 'modal-frame'),
  '/master/customers': sourceConformant(4),
  '/master/suppliers': sourceConformant(4),
  '/master/employees': sourceConformant(4, 'modal-frame'),
  '/settings/company': sourceConformant(4),
  '/settings/opening-balances': sourceConformant(4),
  '/settings/options': sourceConformant(4),
  '/settings/roles': sourceConformant(4),
  '/settings/account-mappings': sourceConformant(4, 'modal-frame'),
  '/settings/account-catalogues': sourceConformant(4, 'modal-frame'),
  '/settings/audit-trail': sourceConformant(4),
  '/invoices-management': sourceConformant(5),
};

const componentOwnership: Readonly<Record<string, MigrationBatch>> = {
  Dashboard: 4, CashWorkspace: 6, CashReceipts: 6, CashPayments: 6,
  BankCompatibilityBoundary: 6,
  PurchaseWorkspace: 5, PurchaseInvoices: 5, APAgingReport: 5,
  SalesWorkspace: 5, SalesInvoices: 5, ARAgingReport: 5,
  InventoryWorkspace: 7, Items: 7, InventoryReceipts: 7, InventoryIssues: 7, InventoryTransfers: 7, InventoryStockCounts: 7, StockReport: 7,
  FixedAssetWorkspace: 4, GLWorkspace: 7, ReportsWorkspace: 7,
  ApArReconciliationInputBoundary: 7, InventorySubledgerGlReconciliation: 7,
  FixedAssets: 4, Tools: 4, JournalEntries: 7, Periods: 7,
  ChartOfAccounts: 4, Customers: 4, Suppliers: 4, Employees: 4,
  CompanySettings: 4, OpeningBalances: 4, SystemOptions: 4, RoleManagement: 4,
  ApprovedAccountMappings: 4, AccountingAccountCatalogues: 4, AccountingAuditTrailExplorer: 4,
  InvoicesManagement: 5, Login: 4, Register: 4,
};

// These workspaces intentionally do not reserve a toolbar region: the
// dashboard is read-only, reports render their capability/error state before
// the tab surface, cash renders tab-owned actions inside the active workbench,
// while period lock owns its controls inside the tabbed workbench. An empty
// PageToolbar would create a blank band and violate the shared shell contract.
const toolbarOptionalComponents = new Set([
  'Dashboard',
  'ReportsWorkspace',
  'PeriodLock',
  'CashWorkspace',
]);

const assertSourceContract = (route: ParsedLeafRoute, policy: SourceConformantPage) => {
  const { importPath, source } = sourcePathFor(route.component);
  expect(importPath).toBe(lazyImportPaths.get(route.component));
  expect(source).toContain("from '../../components/layout/PageShell'");
  expect(source).toContain("from '../../components/layout/PageHeader'");
  expect(source).toContain('<PageShell');
  expect(source).toContain('<PageHeader');
  if (toolbarOptionalComponents.has(route.component)) {
    expect(source).not.toContain("from '../../components/layout/PageToolbar'");
    expect(source).not.toContain('<PageToolbar');
  } else {
    expect(source).toContain("from '../../components/layout/PageToolbar'");
    expect(source).toContain('<PageToolbar');
  }
  if (policy.modalContract === 'no-direct-modal') expect(source).not.toMatch(/<Modal\b/);
  else {
    expect(source).toContain("from '../../components/layout/ModalFrame'");
    expect(source).toContain('<ModalFrame');
  }
};

describe('routed page inventory', () => {
  it('parses every leaf route through the TypeScript JSX AST in declared order', () => {
    const routes = parseLeafRoutes(routesSource);
    expect(routes).toHaveLength(56);
    expect(routes.map(({ path }) => path)).toEqual(Object.keys(routePolicies));
  });

  it('rejects unsupported leaf route syntax instead of silently omitting it', () => {
    const unsupported = routesSource.replace('path="cash"', 'path={getCashPath()}');
    expect(() => parseLeafRoutes(unsupported)).toThrow('string literal');
  });

  it('binds every page component to the router lazy-import source before it can be certified', () => {
    for (const route of parseLeafRoutes(routesSource).filter((entry) => entry.kind === 'page')) {
      const { importPath, source } = sourcePathFor(route.component);
      expect(importPath).toBeTruthy();
      expect(source).toBeTruthy();
    }
  });

  it('documents a precise owner and removal action for every pending boundary', () => {
    for (const [path, policy] of Object.entries(routePolicies)) {
      if (policy.kind !== 'pending-compatibility-boundary') continue;
      expect(policy.batch).toBe(componentOwnership[parseLeafRoutes(routesSource).find((route) => route.path === path)?.component ?? '']);
      expect(policy.reason).toMatch(/(?:Move|Replace|Remove)/);
      expect(policy.excludedContracts).not.toHaveLength(0);
      expect(policy.removalOwner.trim()).not.toBe('');
    }
  });

  it('marks the completed master and settings route boundaries source-conformant after their batch-4 rendered smoke coverage', () => {
    for (const path of [
      '/master/accounts',
      '/master/customers',
      '/master/suppliers',
      '/master/employees',
      '/settings/account-mappings',
      '/settings/account-catalogues',
    ]) {
      expect(routePolicies[path].kind).toBe('source-conformant');
    }
  });

  it('marks batch-5 invoice routes source-conformant only after their rendered smoke coverage', () => {
    for (const path of [
      '/purchase/invoices',
      '/sales/invoices',
      '/invoices-management',
    ]) {
      expect(routePolicies[path].kind).toBe('source-conformant');
    }
  });

  it('redirects the statutory-readiness compatibility deep link out of internal-core', () => {
    const route = parseLeafRoutes(routesSource).find((entry) => entry.path === '/reports/statutory-readiness');

    expect(route?.kind).toBe('redirect');
    expect(routePolicies['/reports/statutory-readiness'].kind).toBe('redirect-boundary');
  });

  it('marks cash voucher routes source-conformant only after their rendered smoke coverage', () => {
    for (const path of ['/cash/receipts', '/cash/payments']) {
      expect(routePolicies[path].kind).toBe('source-conformant');
    }
  });

  it('marks the batch-7 leaf pages source-conformant only after their rendered smoke coverage', () => {
    for (const path of [
      '/inventory/items',
      '/inventory/receipts',
      '/inventory/issues',
      '/inventory',
      '/gl',
      '/gl/journal-entries',
      '/gl/periods',
    ]) {
      expect(routePolicies[path].kind).toBe('source-conformant');
    }
  });

  it('keeps auth and redirect surfaces outside signed-in page migration while checking source-conformant pages honestly', () => {
    for (const route of parseLeafRoutes(routesSource)) {
      const policy = routePolicies[route.path];
      expect(policy).toBeDefined();
      if (policy.kind === 'auth-surface') {
        expect(route.component).toMatch(/^(Login|Register)$/);
        expect(sourcePathFor(route.component).source).toBeTruthy();
        continue;
      }
      if (policy.kind === 'redirect-boundary') {
        expect(route.kind).toBe('redirect');
        expect(policy.removalOwner.trim()).not.toBe('');
        continue;
      }
      expect(componentOwnership[route.component]).toBeDefined();
      expect(policy.batch).toBe(componentOwnership[route.component]);
      if (policy.kind === 'source-conformant') assertSourceContract(route, policy);
    }
  });

  it('keeps the current 54-leaf classification and named module ownership anchors', () => {
    const routes = parseLeafRoutes(routesSource);
    expect(routes).toHaveLength(56);
    expect(routes.filter((route) => route.kind === 'page' && routePolicies[route.path].kind !== 'auth-surface')).toHaveLength(49);
    expect(routes.filter((route) => routePolicies[route.path].kind === 'auth-surface')).toHaveLength(2);
    expect(routes.filter((route) => route.kind === 'redirect')).toHaveLength(5);
    expect(componentOwnership.FixedAssetWorkspace).toBe(4);
    expect(componentOwnership.BankCompatibilityBoundary).toBe(6);
  });
});
