import { render } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useQuery } from '@tanstack/react-query';
import api from '../../api/axios';
import ReferenceVoucherModal from './ReferenceVoucherModal';

vi.mock('@tanstack/react-query', () => ({
    useQuery: vi.fn(),
}));

vi.mock('../../api/axios', () => ({
    default: {
        get: vi.fn(),
    },
}));

const mockedUseQuery = vi.mocked(useQuery);
const mockedApi = vi.mocked(api);

type QueryCapture = {
    queryKey: string[];
    queryFn: () => Promise<unknown>;
};

describe('ReferenceVoucherModal lookup contracts', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockedApi.get.mockRejectedValue(new Error('lookup unavailable'));
    });

    it('uses only the published master contact endpoints when a lookup fails', async () => {
        const captures: QueryCapture[] = [];
        mockedUseQuery.mockImplementation((options) => {
            captures.push(options as unknown as QueryCapture);
            return { data: [], isLoading: false, refetch: vi.fn() } as never;
        });

        render(<ReferenceVoucherModal open onCancel={vi.fn()} onSelect={vi.fn()} />);

        const customerQuery = captures.find((query) => query.queryKey[0] === 'customers-ref-lookup');
        const supplierQuery = captures.find((query) => query.queryKey[0] === 'suppliers-ref-lookup');
        expect(customerQuery).toBeDefined();
        expect(supplierQuery).toBeDefined();

        await expect(customerQuery!.queryFn()).rejects.toThrow('lookup unavailable');
        expect(mockedApi.get).toHaveBeenCalledWith('/master/customers');
        expect(mockedApi.get).not.toHaveBeenCalledWith('/customers');

        await expect(supplierQuery!.queryFn()).rejects.toThrow('lookup unavailable');
        expect(mockedApi.get).toHaveBeenCalledWith('/master/suppliers');
        expect(mockedApi.get).not.toHaveBeenCalledWith('/suppliers');
    });

    it('does not turn employee lookup failures into a valid empty option list', async () => {
        const captures: QueryCapture[] = [];
        mockedUseQuery.mockImplementation((options) => {
            captures.push(options as unknown as QueryCapture);
            return { data: [], isLoading: false, refetch: vi.fn() } as never;
        });

        render(<ReferenceVoucherModal open onCancel={vi.fn()} onSelect={vi.fn()} />);

        const employeeQuery = captures.find((query) => query.queryKey[0] === 'employees-ref-lookup');
        expect(employeeQuery).toBeDefined();
        await expect(employeeQuery!.queryFn()).rejects.toThrow('lookup unavailable');
    });
});
