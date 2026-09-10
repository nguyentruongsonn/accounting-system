import { describe, expect, it } from 'vitest';

import generalJournal from '../reports/GeneralJournal.tsx?raw';
import generalLedger from '../reports/GeneralLedger.tsx?raw';
import cashForecast from '../cash/CashForecast.tsx?raw';
import cashPaymentRequests from '../cash/CashPaymentRequests.tsx?raw';
import employees from '../master/Employees.tsx?raw';
import fixedAssets from '../assets/FixedAssets.tsx?raw';
import payroll from '../payroll/PayrollList.tsx?raw';

describe('list loading error presentation contract', () => {
  it('does not replace existing rows with an empty list after an API failure', () => {
    for (const source of [generalJournal, generalLedger, cashForecast, cashPaymentRequests, employees, fixedAssets, payroll]) {
      expect(source).not.toMatch(/catch\s*\([^)]*\)\s*\{[^{}]*set(?:Data|Records|Requests|Accounts)\(\[\]\)/s);
    }
  });

  it('offers an explicit retry action and distinguishes load errors from valid empty results', () => {
    for (const source of [generalJournal, generalLedger, cashForecast, cashPaymentRequests, employees, fixedAssets, payroll]) {
      expect(source).toMatch(/Thử lại|ReportDataError/);
      expect(source).toMatch(/loadError|reportError/);
    }
  });
});
