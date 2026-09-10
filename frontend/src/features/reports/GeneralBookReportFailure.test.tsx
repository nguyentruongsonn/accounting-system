import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { MemoryRouter, useLocation } from 'react-router-dom';
import type { ReactElement } from 'react';
import api from '../../api/axios';
import GeneralLedger from './GeneralLedger';
import GeneralJournal from './GeneralJournal';
import generalLedgerSource from './GeneralLedger.tsx?raw';
import generalJournalSource from './GeneralJournal.tsx?raw';

vi.mock('../../api/axios', () => ({
    default: {
        get: vi.fn(),
    },
}));

const mockedApi = vi.mocked(api);

function renderReport(element: ReactElement) {
    return render(<MemoryRouter>{element}</MemoryRouter>);
}

function RouteProbe() {
    return <output data-testid="route-probe">{useLocation().pathname + useLocation().search}</output>;
}

describe('general book report failure boundary', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockedApi.get.mockImplementation(async (endpoint: string) => {
            if (endpoint === '/master/accounts') {
                return { data: { data: [{ code: '111', name: 'Tài khoản kiểm thử', is_parent: false }] } } as never;
            }
            throw { response: { status: 500, data: { request_id: 'req-book-report-500' } } };
        });
    });

    it('does not render an empty ledger when the report response fails', async () => {
        renderReport(<GeneralLedger />);

        const accountSelect = await screen.findByRole('combobox');
        fireEvent.mouseDown(accountSelect);
        await waitFor(() => expect(screen.getByText('111 - Tài khoản kiểm thử')).toBeInTheDocument());
        fireEvent.click(screen.getByText('111 - Tài khoản kiểm thử'));
        fireEvent.click(screen.getByRole('button', { name: /Xem Sổ cái/ }));

        await waitFor(() => expect(screen.getByText('Không thể tải dữ liệu báo cáo: Sổ cái')).toBeInTheDocument());
        expect(screen.getByText('req-book-report-500')).toBeInTheDocument();
        expect(screen.getByText(/Không hiển thị dữ liệu rỗng/)).toBeInTheDocument();
    }, 15000);

    it('does not render an empty journal when the report response fails', async () => {
        renderReport(<GeneralJournal />);

        fireEvent.click(screen.getByRole('button', { name: /Xem báo cáo/ }));

        await waitFor(() => expect(screen.getByText('Không thể tải dữ liệu báo cáo: Sổ nhật ký chung')).toBeInTheDocument());
        expect(screen.getByText('req-book-report-500')).toBeInTheDocument();
        expect(screen.getByText(/Không hiển thị dữ liệu rỗng/)).toBeInTheDocument();
    });

    it('keeps the last successful journal rows visible when a refresh fails', async () => {
        mockedApi.get
            .mockResolvedValueOnce({ data: { data: [{ voucher_number: 'PT-001', description: 'Thu tiền thử nghiệm' }] } } as never)
            .mockRejectedValueOnce({ response: { status: 500, data: { request_id: 'req-book-report-refresh' } } } as never);

        renderReport(<GeneralJournal />);
        const search = screen.getByRole('button', { name: /Xem báo cáo/ });
        fireEvent.click(search);
        await waitFor(() => expect(screen.getByText('Thu tiền thử nghiệm')).toBeInTheDocument());

        fireEvent.click(search);
        await waitFor(() => expect(screen.getByText('Không thể tải dữ liệu báo cáo: Sổ nhật ký chung')).toBeInTheDocument());
        expect(screen.getByText('Thu tiền thử nghiệm')).toBeInTheDocument();
        expect(screen.getByText('req-book-report-refresh')).toBeInTheDocument();
    }, 15000);

    it('renders the server-calculated opening balance above ledger movements', async () => {
        mockedApi.get.mockImplementation(async (endpoint: string) => {
            if (endpoint === '/master/accounts') {
                return { data: { data: [{ code: '111', name: 'Tài khoản kiểm thử', is_parent: false }] } } as never;
            }
            return {
                data: {
                    data: [{ voucher_number: 'MOVE-001', description: 'Phát sinh trong kỳ', debit: '0.20', credit: '0.00' }],
                    meta: {
                        opening_balance: { as_of_date: '2026-01-31', debit: '0.10', credit: '0.00', balance: '0.10' },
                    },
                },
            } as never;
        });

        renderReport(<GeneralLedger />);
        const accountSelect = await screen.findByRole('combobox');
        fireEvent.mouseDown(accountSelect);
        await waitFor(() => expect(screen.getByText('111 - Tài khoản kiểm thử')).toBeInTheDocument());
        fireEvent.click(screen.getByText('111 - Tài khoản kiểm thử'));
        fireEvent.click(screen.getByRole('button', { name: /Xem Sổ cái/ }));

        await waitFor(() => expect(screen.getByText('Số dư đầu kỳ đến 31/01/2026')).toBeInTheDocument());
        expect(screen.getByText('Phát sinh trong kỳ')).toBeInTheDocument();
    }, 15000);

    it('does not label a pending journal or ledger request as an empty period', () => {
        expect(generalLedgerSource).toContain("loading ? 'Đang tải dữ liệu báo cáo...' :");
        expect(generalJournalSource).toContain("loading ? 'Đang tải dữ liệu báo cáo...' :");
    });

    it('offers print and Excel actions bound to the same report filters', () => {
        expect(generalLedgerSource).toContain("downloadControlledReport('/reports/general-ledger', format, 'general_ledger'");
        expect(generalLedgerSource).toContain('<Button icon={<PrinterOutlined />}');
        expect(generalLedgerSource).toContain('<Button icon={<ExportOutlined />}');
        expect(generalJournalSource).toContain("downloadControlledReport('/reports/general-journal', format, 'general_journal'");
        expect(generalJournalSource).toContain('<Button icon={<PrinterOutlined />}');
        expect(generalJournalSource).toContain('<Button icon={<ExportOutlined />}');
    });

    it('keeps voucher links anchored to the authenticated journal identity', () => {
        expect(generalLedgerSource).toContain('generalReportSourceRoute(record)');
        expect(generalJournalSource).toContain('generalReportSourceRoute(record)');
    });

    it('opens the journal entry from a general-journal report row', async () => {
        mockedApi.get.mockResolvedValue({
            data: {
                data: [{
                    journal_entry_id: 42,
                    voucher_number: 'PT-42',
                    posting_date: '2026-01-05',
                    voucher_date: '2026-01-05',
                    description: 'Thu tiền truy vết',
                    debit_account: '111',
                    credit_account: null,
                    debit_amount: '100.00',
                    credit_amount: '0.00',
                }],
            },
        } as never);

        render(<MemoryRouter><GeneralJournal /><RouteProbe /></MemoryRouter>);
        fireEvent.click(screen.getByRole('button', { name: /Xem báo cáo/ }));
        const link = await screen.findByRole('link', { name: 'PT-42' });
        fireEvent.click(link);

        expect(screen.getByTestId('route-probe')).toHaveTextContent('/gl?tab=tab-journals&source_id=42');
    });

    it('opens the journal entry from a general-ledger report row', async () => {
        mockedApi.get.mockImplementation(async (endpoint: string) => {
            if (endpoint === '/master/accounts') {
                return { data: { data: [{ code: '111', name: 'Tài khoản kiểm thử', is_parent: false }] } } as never;
            }
            return {
                data: {
                    data: [{
                        journal_entry_id: 43,
                        voucher_number: 'PT-43',
                        posting_date: '2026-01-05',
                        voucher_date: '2026-01-05',
                        description: 'Thu tiền truy vết',
                        corresponding_account: '131',
                        debit: '100.00',
                        credit: '0.00',
                    }],
                    meta: {
                        opening_balance: { as_of_date: '2025-12-31', debit: '0.00', credit: '0.00', balance: '0.00' },
                    },
                },
            } as never;
        });

        render(<MemoryRouter><GeneralLedger /><RouteProbe /></MemoryRouter>);
        const accountSelect = await screen.findByRole('combobox');
        fireEvent.mouseDown(accountSelect);
        await waitFor(() => expect(screen.getByText('111 - Tài khoản kiểm thử')).toBeInTheDocument());
        fireEvent.click(screen.getByText('111 - Tài khoản kiểm thử'));
        fireEvent.click(screen.getByRole('button', { name: /Xem Sổ cái/ }));
        const link = await screen.findByRole('link', { name: 'PT-43' });
        fireEvent.click(link);

        expect(screen.getByTestId('route-probe')).toHaveTextContent('/gl?tab=tab-journals&source_id=43');
    }, 15000);
});
