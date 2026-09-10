import { describe, expect, it } from 'vitest';
import customersSource from './Customers.tsx?raw';
import suppliersSource from './Suppliers.tsx?raw';
import employeesSource from './Employees.tsx?raw';

describe('master list response evidence boundary', () => {
    it('rejects malformed 2xx list envelopes instead of treating them as valid empty lists', () => {
        expect(customersSource).toContain("throw new Error('Invalid customer list response.')");
        expect(suppliersSource).toContain("throw new Error('Invalid supplier list response.')");
        expect(employeesSource).toContain("throw new Error('Invalid employee list response.')");
        expect(customersSource).toContain('return parseCustomerPage(data);');
        expect(suppliersSource).toContain('return parseSupplierPage(data);');
        expect(employeesSource).toContain('if (!Array.isArray(rows))');
    });
});
