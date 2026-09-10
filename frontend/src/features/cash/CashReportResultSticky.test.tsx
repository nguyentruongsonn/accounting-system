import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
// @ts-expect-error Node filesystem access binds the rendered sticky table to its real scroll-owner rule.
import { readFileSync } from 'node:fs';
import { CashReportResult } from './CashReportResult';
import { cashReportTestFixture } from './cashReportTestFixture';

const reportStyles = readFileSync('src/features/cash/cash-reports.css', 'utf8');

describe('CashReportResult long-table header', () => {
  it('keeps a sticky header inside the single report table scroll owner for 101 rows', () => {
    const { container } = render(<CashReportResult report={cashReportTestFixture(101)} loading={false} error={false} onRetry={() => undefined} />);
    const scrollOwner = container.querySelector('.cash-report-table-wrap');
    expect(scrollOwner).not.toBeNull();
    expect(scrollOwner?.querySelectorAll('.ant-table-tbody > tr[data-row-key]')).toHaveLength(101);
    expect(scrollOwner?.querySelector('.ant-table-sticky-holder')).not.toBeNull();
    expect(reportStyles).toMatch(/\.cash-report-workbench \.cash-report-table-wrap\s*\{[^}]*max-height:\s*65vh;[^}]*overflow:\s*auto/s);
  });
});
