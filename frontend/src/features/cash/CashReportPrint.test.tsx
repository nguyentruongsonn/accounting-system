import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { CashReportPrint } from './CashReportPrint';
import { cashReportTestFixture } from './cashReportTestFixture';

describe('full cash report print subtree', () => {
  it('contains 101 full result rows including the last, metadata, zero and negative summaries, and no controls', () => {
    const { container } = render(<CashReportPrint report={cashReportTestFixture()} />);
    const table = screen.getByRole('table');
    expect(table.querySelectorAll('tbody tr')).toHaveLength(101);
    expect(within(table).getByText('PT-LAST')).toBeInTheDocument();
    expect(table.querySelector('thead')).not.toBeNull();
    expect(screen.getByRole('heading')).toHaveTextContent('Sổ kế toán chi tiết quỹ tiền mặt');
    expect(container).toHaveTextContent('CA-03');
    expect(container).toHaveTextContent('01/08/2026 – 31/08/2026');
    expect(container).toHaveTextContent('1111');
    expect(container).toHaveTextContent('=literal search');
    expect(container).toHaveTextContent('Số dư đầu kỳ: -200 ₫');
    expect(container).toHaveTextContent('Tổng thu: 0 ₫');
    expect(container).toHaveTextContent('Số dư cuối kỳ: -300 ₫');
    expect(container.querySelector('button, input, nav, [role="dialog"], .ant-pagination')).toBeNull();
  });
  it('prints an empty report without losing opening or closing balance', () => {
    const { container } = render(<CashReportPrint report={cashReportTestFixture(0)} />);
    expect(container).toHaveTextContent('Không có dòng phát sinh');
    expect(container).toHaveTextContent('Số dư đầu kỳ: -200 ₫');
    expect(container).toHaveTextContent('Số dư cuối kỳ: -300 ₫');
  });
});
