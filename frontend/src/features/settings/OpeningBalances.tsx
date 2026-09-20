import React, { useEffect, useMemo, useState } from 'react';
import { Button, Input, InputNumber, Select, Space, Spin, Table, Tabs, Tag, Typography } from 'antd';
import { CheckOutlined, DeleteOutlined, DownloadOutlined, EditOutlined, PlusOutlined, ReloadOutlined, SaveOutlined, UploadOutlined } from '@ant-design/icons';
import api from '../../api/axios';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import { toast } from '../../components/feedback/toast';
import { runManualDataLoad } from '../../components/feedback/runManualDataLoad';
import OpeningBalanceImportModal from './OpeningBalanceImportModal';
import { exportOpeningBalanceWorkbook } from './openingBalanceImport';
import type { OpeningBalanceImportResult, OpeningBalanceImportRow } from './openingBalanceImport';
import { hasZeroOpeningBalance, multiplyOpeningBalanceValue, subtractOpeningBalanceValue } from './openingBalanceMath';


type Catalogue = {
  id: number; code?: string; name?: string; is_parent?: boolean; is_active?: boolean;
  tool_code?: string; tool_name?: string; asset_code?: string; asset_name?: string;
  original_cost?: string | number; accumulated_allocation?: string | number; remaining_value?: string | number;
  accumulated_depreciation?: string | number; tool_account?: string; asset_account?: string; depreciation_account?: string;
};
type AccountLine = { _key: string; account_code: string; debit_amount: string; credit_amount: string };
type PartyLine = AccountLine & { party_type: 'customer' | 'supplier' | 'employee'; party_id?: number; document_number?: string; due_date?: string };
type InventoryLine = { _key: string; item_id?: number; warehouse_id?: number; account_code: string; quantity: string; unit_cost: string; total_value: string };
type ToolLine = { _key: string; tool_equipment_id?: number; account_code: string; original_cost: string; accumulated_allocation: string; remaining_value: string };
type FixedAssetLine = { _key: string; fixed_asset_id?: number; asset_account: string; depreciation_account: string; original_cost: string; accumulated_depreciation: string };
type PrepaidLine = { _key: string; account_code: string; description: string; original_amount: string; allocated_amount: string; remaining_amount: string };
type WipLine = { _key: string; account_code: string; description: string; amount: string };
type Reconciliation = { balanced: boolean; total_debit: string; total_credit: string; errors: string[] };
type OpeningPackage = {
  id: number; status: 'draft' | 'confirmed'; effective_date: string;
  account_lines?: Omit<AccountLine, '_key'>[]; party_lines?: Omit<PartyLine, '_key'>[];
  inventory_lines?: Omit<InventoryLine, '_key'>[]; tool_lines?: Omit<ToolLine, '_key'>[];
  fixed_asset_lines?: Omit<FixedAssetLine, '_key'>[]; prepaid_lines?: Omit<PrepaidLine, '_key'>[];
  wip_lines?: Omit<WipLine, '_key'>[]; reconciliation?: Reconciliation; period_locked?: boolean;
};

const key = () => `${Date.now()}-${Math.random().toString(36).slice(2)}`;
const mergeWithExistingKeys = <T extends { _key?: string; id?: number }>(
  incoming: Array<Omit<T, '_key'>>,
  existing: T[]
): T[] => {
  return incoming.map((row, index) => {
    if ((row as T)._key) return row as T;
    const rowId = (row as { id?: number }).id;
    if (rowId !== undefined) {
      const match = existing.find(e => e.id === rowId);
      if (match?._key) return { ...row, _key: match._key } as T;
    }
    if (existing[index]?._key) {
      return { ...row, _key: existing[index]._key } as T;
    }
    return { ...row, _key: key() } as T;
  });
};
const rows = (value: unknown, resource: string): Catalogue[] => {
  const candidate = Array.isArray(value)
    ? value
    : value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)
      ? (value as { data: Catalogue[] }).data
      : null;
  if (!candidate) throw new Error(`Phản hồi ${resource} không hợp lệ.`);
  return candidate.filter((row): row is Catalogue => !!row && typeof row === 'object' && Number.isInteger(row.id));
};
const messageOf = (error: unknown) => {
  const data = (error as { response?: { data?: { message?: string; error?: string; errors?: Record<string, string[]> } } })?.response?.data;
  if (data?.errors) return Object.values(data.errors).flat().join(' ');
  if (data?.message || data?.error) return data.message || data.error || 'Không thể tải hoặc lưu số dư đầu kỳ.';
  return error instanceof Error && error.message ? error.message : 'Không thể tải hoặc lưu số dư đầu kỳ.';
};
const accountOptions = (catalogue: Catalogue[]) => catalogue.filter(item => item.is_parent !== true && item.is_active !== false).map(item => ({ value: item.code || '', label: `${item.code || ''} — ${item.name || ''}` }));
const isPositiveAmount = (value: string | number | null | undefined) => Number(value ?? 0) > 0;
const updateBalanceLine = <T extends AccountLine>(lines: T[], rowKey: string, side: 'debit' | 'credit', value: string | number | null): T[] => lines.map(row => {
  if (row._key !== rowKey) return row;
  const amount = String(value ?? '0');
  return side === 'debit'
    ? { ...row, debit_amount: amount, credit_amount: isPositiveAmount(value) ? '0.00' : row.credit_amount }
    : { ...row, debit_amount: isPositiveAmount(value) ? '0.00' : row.debit_amount, credit_amount: amount };
});
const invalidBalanceLine = <T extends AccountLine>(lines: T[]) => lines.find(line => isPositiveAmount(line.debit_amount) && isPositiveAmount(line.credit_amount));
const duplicateAccountLine = (lines: AccountLine[]) => {
  const seen = new Set<string>();
  for (const line of lines) {
    if (!line.account_code) continue;
    if (seen.has(line.account_code)) return line;
    seen.add(line.account_code);
  }
  return undefined;
};

const OpeningBalances: React.FC = () => {
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [packageId, setPackageId] = useState<number>();
  const [status, setStatus] = useState<'draft' | 'confirmed'>('draft');
  const [effectiveDate, setEffectiveDate] = useState('');
  const [activeTab, setActiveTab] = useState('accounts');
  const [accounts, setAccounts] = useState<Catalogue[]>([]);
  const [customers, setCustomers] = useState<Catalogue[]>([]);
  const [suppliers, setSuppliers] = useState<Catalogue[]>([]);
  const [employees, setEmployees] = useState<Catalogue[]>([]);
  const [tools, setTools] = useState<Catalogue[]>([]);
  const [fixedAssets, setFixedAssets] = useState<Catalogue[]>([]);
  const [items, setItems] = useState<Catalogue[]>([]);
  const [warehouses, setWarehouses] = useState<Catalogue[]>([]);
  const [accountLines, setAccountLines] = useState<AccountLine[]>([]);
  const [partyLines, setPartyLines] = useState<PartyLine[]>([]);
  const [inventoryLines, setInventoryLines] = useState<InventoryLine[]>([]);
  const [toolLines, setToolLines] = useState<ToolLine[]>([]);
  const [fixedAssetLines, setFixedAssetLines] = useState<FixedAssetLine[]>([]);
  const [prepaidLines, setPrepaidLines] = useState<PrepaidLine[]>([]);
  const [wipLines, setWipLines] = useState<WipLine[]>([]);
  const [periodLocked, setPeriodLocked] = useState(false);
  const [editMode, setEditMode] = useState(false);
  const [importOpen, setImportOpen] = useState(false);
  const disabled = periodLocked || busy || !editMode;

  const applyPackage = (value: OpeningPackage, notifyReconciliation = false) => {
    setPackageId(value.id);
    setStatus(value.status);
    setEffectiveDate(String(value.effective_date ?? '').slice(0, 10));
    setPeriodLocked(Boolean(value.period_locked));
    setEditMode(false);
    setAccountLines(prev => mergeWithExistingKeys((value.account_lines ?? (value as { accountLines?: Omit<AccountLine, '_key'>[] }).accountLines ?? []), prev));
    setPartyLines(prev => mergeWithExistingKeys((value.party_lines ?? (value as { partyLines?: Omit<PartyLine, '_key'>[] }).partyLines ?? []), prev));
    setInventoryLines(prev => mergeWithExistingKeys((value.inventory_lines ?? (value as { inventoryLines?: Omit<InventoryLine, '_key'>[] }).inventoryLines ?? []), prev));
    setToolLines(prev => mergeWithExistingKeys((value.tool_lines ?? (value as { toolLines?: Omit<ToolLine, '_key'>[] }).toolLines ?? []), prev));
    setFixedAssetLines(prev => mergeWithExistingKeys((value.fixed_asset_lines ?? (value as { fixedAssetLines?: Omit<FixedAssetLine, '_key'>[] }).fixedAssetLines ?? []), prev));
    setPrepaidLines(prev => mergeWithExistingKeys((value.prepaid_lines ?? (value as { prepaidLines?: Omit<PrepaidLine, '_key'>[] }).prepaidLines ?? []), prev));
    setWipLines(prev => mergeWithExistingKeys((value.wip_lines ?? (value as { wipLines?: Omit<WipLine, '_key'>[] }).wipLines ?? []), prev));
    if (notifyReconciliation && value.reconciliation && !value.reconciliation.balanced) {
      toast.warning(`Số dư chưa khớp: ${value.reconciliation.errors.join(' ') || 'Kiểm tra lại các dòng chi tiết.'}`);
    }
  };

  const clearPackage = () => {
    setPackageId(undefined); setStatus('draft'); setAccountLines([]); setPartyLines([]); setInventoryLines([]);
    setToolLines([]); setFixedAssetLines([]); setPrepaidLines([]); setWipLines([]);
    setPeriodLocked(false); setEditMode(true);
  };
   const load = async (requestedDate = '', isManual = false) => {
    setLoading(true);
    try {
      const [packages, coa, customerData, supplierData, itemData, warehouseData, catalogueData] = await Promise.all([
        requestedDate ? api.get('/opening-balances', { params: { effective_date: requestedDate } }) : api.get('/opening-balances'), api.get('/master/accounts'), api.get('/master/customers'),
        api.get('/master/suppliers'), api.get('/inventory/items'), api.get('/master/warehouses'), api.get('/opening-balances/catalogues'),
      ]);
      const packageRows = rows(packages.data?.data ?? packages.data, 'số dư đầu kỳ') as unknown as OpeningPackage[];
      setAccounts(rows(coa.data, 'danh mục tài khoản')); setCustomers(rows(customerData.data, 'danh mục khách hàng')); setSuppliers(rows(supplierData.data, 'danh mục nhà cung cấp'));
      setItems(rows(itemData.data, 'danh mục hàng hóa')); setWarehouses(rows(warehouseData.data, 'danh mục kho'));
      const catalogues = catalogueData.data?.data ?? catalogueData.data;
      if (catalogues && typeof catalogues === 'object' && !Array.isArray(catalogues)) {
        setEmployees(rows((catalogues as { employees?: unknown }).employees ?? [], 'danh mục nhân viên'));
        setTools(rows((catalogues as { tools?: unknown }).tools ?? [], 'danh mục CCDC'));
        setFixedAssets(rows((catalogues as { fixed_assets?: unknown }).fixed_assets ?? [], 'danh mục tài sản cố định'));
      }
      if (packageRows[0]) applyPackage(packageRows[0]);
      else clearPackage();
       return true;
     } catch (reason) {
       if (!isManual) toast.error(messageOf(reason));
       return isManual ? { isError: true, error: reason } : false;
     }
     finally { setLoading(false); }
  };
  useEffect(() => { void load(); }, []);

  const payload = () => ({
    effective_date: effectiveDate,
    account_lines: accountLines.map(({ _key, ...line }) => line),
    party_lines: partyLines.map(({ _key, party_id, ...line }) => ({ ...line, party_id })),
    inventory_lines: inventoryLines.map(({ _key, item_id, warehouse_id, ...line }) => ({ ...line, item_id, warehouse_id })),
    tool_lines: toolLines.map(({ _key, tool_equipment_id, ...line }) => ({ ...line, tool_equipment_id })),
    fixed_asset_lines: fixedAssetLines.map(({ _key, fixed_asset_id, ...line }) => ({ ...line, fixed_asset_id })),
    prepaid_lines: prepaidLines.map(({ _key, ...line }) => line),
    wip_lines: wipLines.map(({ _key, ...line }) => line),
  });
  const save = async () => {
    if (busy) return;
    if (accountLines.some(line => !line.account_code)) {
      toast.error('Hãy chọn tài khoản cho tất cả các dòng trước khi lưu bản nháp.');
      return;
    }
    const duplicate = duplicateAccountLine(accountLines);
    if (duplicate) {
      toast.error(`Tài khoản ${duplicate.account_code} đã có trong danh sách. Hãy sửa dòng hiện tại hoặc chọn tài khoản khác.`);
      return;
    }
    const emptyBalanceLine = accountLines.find(line => hasZeroOpeningBalance(line.debit_amount, line.credit_amount)) || partyLines.find(line => hasZeroOpeningBalance(line.debit_amount, line.credit_amount));
    if (emptyBalanceLine) {
      toast.error(`Dòng ${emptyBalanceLine.account_code || 'số dư đầu kỳ'} chưa có số dư thực tế. Hãy nhập Dư Nợ hoặc Dư Có lớn hơn 0, hoặc xóa dòng.`);
      return;
    }
    const invalidLine = invalidBalanceLine(accountLines) || invalidBalanceLine(partyLines);
    if (invalidLine) {
      toast.error(`Tài khoản ${invalidLine.account_code || 'chưa chọn'} phải có đúng một bên Nợ hoặc Có lớn hơn 0. Hãy nhập số tiền ở một bên và để bên còn lại là 0.`);
      return;
    }
    setBusy(true);
    try {
      const response = packageId ? await api.put(`/opening-balances/${packageId}`, payload()) : await api.post('/opening-balances', payload());
      const savedPackage = response.data.data as OpeningPackage;
      applyPackage(savedPackage, false);
      setEditMode(false);
      toast.success('Đã lưu số dư đầu kỳ.');
    } catch (reason) {
      toast.error(messageOf(reason));
    }
    finally { setBusy(false); }
  };
  const confirm = async () => {
    if (!packageId || busy) return;
    if (accountLines.some(line => !line.account_code)) {
      toast.error('Hãy chọn tài khoản cho tất cả các dòng trước khi xác nhận.');
      return;
    }
    const duplicate = duplicateAccountLine(accountLines);
    if (duplicate) {
      toast.error(`Tài khoản ${duplicate.account_code} đã có trong danh sách. Hãy sửa dòng hiện tại hoặc chọn tài khoản khác.`);
      return;
    }
    const emptyBalanceLine = accountLines.find(line => hasZeroOpeningBalance(line.debit_amount, line.credit_amount)) || partyLines.find(line => hasZeroOpeningBalance(line.debit_amount, line.credit_amount));
    if (emptyBalanceLine) {
      toast.error(`Dòng ${emptyBalanceLine.account_code || 'số dư đầu kỳ'} chưa có số dư thực tế. Hãy nhập Dư Nợ hoặc Dư Có lớn hơn 0, hoặc xóa dòng.`);
      return;
    }
    const invalidLine = invalidBalanceLine(accountLines) || invalidBalanceLine(partyLines);
    if (invalidLine) {
      toast.error(`Tài khoản ${invalidLine.account_code || 'chưa chọn'} phải có đúng một bên Nợ hoặc Có lớn hơn 0. Hãy nhập số tiền ở một bên và để bên còn lại là 0.`);
      return;
    }
    if (accountLines.length === 0) {
      toast.error('Hãy thêm ít nhất một dòng tài khoản trước khi xác nhận.');
      return;
    }
    setBusy(true);
    try {
      const confirmed = (await api.post(`/opening-balances/${packageId}/confirm`)).data.data as OpeningPackage;
      applyPackage(confirmed, false);
      if (confirmed.reconciliation && !confirmed.reconciliation.balanced) {
        toast.warning('Đã xác nhận. Số dư đang lệch và sẽ tiếp tục hiển thị cảnh báo cho đến khi được điều chỉnh.');
      } else {
        toast.success('Đã đối chiếu và xác nhận số dư đầu kỳ.');
      }
    }
    catch (reason) { toast.error(messageOf(reason)); }
    finally { setBusy(false); }
  };
  const addAccountLine = () => {
    if (disabled) return;
    if (accountLines.some(line => !line.account_code)) {
      toast.info('Hãy chọn tài khoản cho dòng đang nhập trước khi thêm dòng mới.');
      return;
    }
    setAccountLines(all => [...all, { _key: key(), account_code: '', debit_amount: '0.00', credit_amount: '0.00' }]);
  };

  const options = useMemo(() => accountOptions(accounts), [accounts]);
  const importReferences = useMemo(() => ({
    accountCodes: new Set(options.map(option => option.value)),
    partyCodes: new Set([...customers, ...suppliers, ...employees].map(item => item.code || '').filter(Boolean)),
    itemCodes: new Set(items.map(item => item.code || '').filter(Boolean)),
    warehouseCodes: new Set(warehouses.map(item => item.code || '').filter(Boolean)),
  }), [customers, employees, items, options, suppliers, warehouses]);
  const applyImport = (result: OpeningBalanceImportResult) => {
    const importedAccounts = result.validRows.filter((row): row is Extract<OpeningBalanceImportRow, { kind: 'account' }> => row.kind === 'account');
    const importedParties = result.validRows.filter((row): row is Extract<OpeningBalanceImportRow, { kind: 'party' }> => row.kind === 'party');
    const importedInventory = result.validRows.filter((row): row is Extract<OpeningBalanceImportRow, { kind: 'inventory' }> => row.kind === 'inventory');
    if (importedAccounts.length) setAccountLines(importedAccounts.map(row => ({ ...row, _key: key() })));
    if (importedParties.length) setPartyLines(importedParties.map(row => ({
      _key: key(), party_type: row.party_type, party_id: [...customers, ...suppliers, ...employees].find(item => item.code === row.party_code)?.id,
      account_code: row.account_code, debit_amount: row.debit_amount, credit_amount: row.credit_amount,
    })));
    if (importedInventory.length) setInventoryLines(importedInventory.map(row => ({
      _key: key(), item_id: items.find(item => item.code === row.item_code)?.id, warehouse_id: warehouses.find(item => item.code === row.warehouse_code)?.id,
      account_code: row.account_code, quantity: row.quantity, unit_cost: row.unit_cost, total_value: multiplyOpeningBalanceValue(row.quantity, row.unit_cost),
    })));
    toast.success(`Đã nạp ${result.validRows.length} dòng số dư đầu kỳ.`);
  };
  const exportCurrentRows = () => {
    const partyById = new Map([...customers, ...suppliers, ...employees].map(item => [item.id, item.code || '']));
    const rowsToExport: OpeningBalanceImportRow[] = [
      ...accountLines.map(({ account_code, debit_amount, credit_amount }) => ({ kind: 'account' as const, account_code, debit_amount, credit_amount })),
      ...partyLines.flatMap(({ party_type, party_id, account_code, debit_amount, credit_amount }) => {
        const partyCode = party_id ? partyById.get(party_id) : '';
        return partyCode ? [{ kind: 'party' as const, account_code, party_code: partyCode, party_type, debit_amount, credit_amount }] : [];
      }),
      ...inventoryLines.flatMap(({ item_id, warehouse_id, account_code, quantity, unit_cost, total_value: _totalValue, }) => {
        const itemCode = item_id ? items.find(item => item.id === item_id)?.code : '';
        const warehouseCode = warehouse_id ? warehouses.find(item => item.id === warehouse_id)?.code : '';
        return itemCode && warehouseCode ? [{ kind: 'inventory' as const, account_code, item_code: itemCode, warehouse_code: warehouseCode, debit_amount: '0.00', credit_amount: '0.00', quantity, unit_cost }] : [];
      }),
    ];
    exportOpeningBalanceWorkbook(rowsToExport, `So_du_dau_ky_${effectiveDate || 'chua-chon'}.xlsx`);
  };

  const usedAccountCodes = useMemo(() => new Set(accountLines.map(row => row.account_code).filter(Boolean)), [accountLines]);
  const accountOptionsFor = (record: AccountLine) => options.filter(option => option.value === record.account_code || !usedAccountCodes.has(option.value));
  const resolveAccountOption = (preferred: string | undefined, prefix: string): string => {
    if (preferred && options.some(option => option.value === preferred)) return preferred;
    return options.find(option => option.value.startsWith(prefix))?.value || preferred || '';
  };
  const customerOptions = useMemo(() => customers.map(item => ({ value: item.id, label: `${item.code || ''} — ${item.name || ''}` })), [customers]);
  const supplierOptions = useMemo(() => suppliers.map(item => ({ value: item.id, label: `${item.code || ''} — ${item.name || ''}` })), [suppliers]);
  const employeeOptions = useMemo(() => employees.map(item => ({ value: item.id, label: `${item.code || ''} — ${item.name || ''}` })), [employees]);
  const itemOptions = useMemo(() => items.map(item => ({ value: item.id, label: `${item.code || ''} — ${item.name || ''}` })), [items]);
  const warehouseOptions = useMemo(() => warehouses.map(item => ({ value: item.id, label: `${item.code || ''} — ${item.name || ''}` })), [warehouses]);
  const toolOptions = useMemo(() => tools.map(item => ({ value: item.id, label: `${item.tool_code || item.code || ''} — ${item.tool_name || item.name || ''}` })), [tools]);
  const fixedAssetOptions = useMemo(() => fixedAssets.map(item => ({ value: item.id, label: `${item.asset_code || item.code || ''} — ${item.asset_name || item.name || ''}` })), [fixedAssets]);

  const accountColumns = [
    { title: 'Tài khoản', dataIndex: 'account_code', render: (_: string, record: AccountLine) => <Select aria-label="Tài khoản số dư" disabled={disabled} showSearch optionFilterProp="label" options={accountOptionsFor(record)} value={record.account_code || undefined} onChange={value => setAccountLines(all => all.map(row => row._key === record._key ? { ...row, account_code: value } : row))} style={{ width: '100%' }} /> },
    { title: 'Dư Nợ', dataIndex: 'debit_amount', width: 190, render: (_: string, record: AccountLine) => <InputNumber stringMode min="0" precision={2} disabled={disabled} value={record.debit_amount} onChange={value => setAccountLines(all => updateBalanceLine(all, record._key, 'debit', value))} style={{ width: '100%' }} /> },
    { title: 'Dư Có', dataIndex: 'credit_amount', width: 190, render: (_: string, record: AccountLine) => <InputNumber stringMode min="0" precision={2} disabled={disabled} value={record.credit_amount} onChange={value => setAccountLines(all => updateBalanceLine(all, record._key, 'credit', value))} style={{ width: '100%' }} /> },
    { title: '', width: 54, render: (_: unknown, record: AccountLine) => <Button aria-label="Xóa dòng tài khoản" type="text" danger icon={<DeleteOutlined />} disabled={disabled} onClick={() => setAccountLines(all => all.filter(row => row._key !== record._key))} /> },
  ];
  const partyColumns = [
    { title: 'Đối tượng', render: (_: unknown, record: PartyLine) => <Select disabled={disabled} showSearch optionFilterProp="label" value={record.party_id} options={record.party_type === 'customer' ? customerOptions : record.party_type === 'supplier' ? supplierOptions : employeeOptions} onChange={value => setPartyLines(all => all.map(row => row._key === record._key ? { ...row, party_id: value } : row))} style={{ width: '100%' }} /> },
    { title: 'Tài khoản', width: 220, render: (_: unknown, record: PartyLine) => <Select disabled={disabled} showSearch optionFilterProp="label" options={options} value={record.account_code || undefined} onChange={value => setPartyLines(all => all.map(row => row._key === record._key ? { ...row, account_code: value } : row))} style={{ width: '100%' }} /> },
    { title: 'Dư Nợ', width: 150, render: (_: unknown, record: PartyLine) => <InputNumber stringMode min="0" precision={2} disabled={disabled} value={record.debit_amount} onChange={value => setPartyLines(all => updateBalanceLine(all, record._key, 'debit', value))} style={{ width: '100%' }} /> },
    { title: 'Dư Có', width: 150, render: (_: unknown, record: PartyLine) => <InputNumber stringMode min="0" precision={2} disabled={disabled} value={record.credit_amount} onChange={value => setPartyLines(all => updateBalanceLine(all, record._key, 'credit', value))} style={{ width: '100%' }} /> },
    { title: '', width: 54, render: (_: unknown, record: PartyLine) => <Button aria-label="Xóa dòng công nợ" type="text" danger icon={<DeleteOutlined />} disabled={disabled} onClick={() => setPartyLines(all => all.filter(row => row._key !== record._key))} /> },
  ];
  const inventoryColumns = [
    { title: 'Hàng hóa', render: (_: unknown, record: InventoryLine) => <Select disabled={disabled} showSearch optionFilterProp="label" value={record.item_id} options={itemOptions} onChange={value => setInventoryLines(all => all.map(row => row._key === record._key ? { ...row, item_id: value } : row))} style={{ width: '100%' }} /> },
    { title: 'Kho', width: 220, render: (_: unknown, record: InventoryLine) => <Select disabled={disabled} showSearch optionFilterProp="label" value={record.warehouse_id} options={warehouseOptions} onChange={value => setInventoryLines(all => all.map(row => row._key === record._key ? { ...row, warehouse_id: value } : row))} style={{ width: '100%' }} /> },
    { title: 'Tài khoản kho', width: 210, render: (_: unknown, record: InventoryLine) => <Select disabled={disabled} showSearch optionFilterProp="label" options={options} value={record.account_code || undefined} onChange={value => setInventoryLines(all => all.map(row => row._key === record._key ? { ...row, account_code: value } : row))} style={{ width: '100%' }} /> },
    { title: 'Số lượng', width: 130, render: (_: unknown, record: InventoryLine) => <InputNumber stringMode min="0" precision={4} disabled={disabled} value={record.quantity} onChange={value => setInventoryLines(all => all.map(row => row._key === record._key ? { ...row, quantity: String(value ?? ''), total_value: multiplyOpeningBalanceValue(String(value ?? ''), row.unit_cost) } : row))} style={{ width: '100%' }} /> },
    { title: 'Đơn giá', width: 150, render: (_: unknown, record: InventoryLine) => <InputNumber stringMode min="0" precision={4} disabled={disabled} value={record.unit_cost} onChange={value => setInventoryLines(all => all.map(row => row._key === record._key ? { ...row, unit_cost: String(value ?? ''), total_value: multiplyOpeningBalanceValue(row.quantity, String(value ?? '')) } : row))} style={{ width: '100%' }} /> },
    { title: 'Thành tiền', width: 150, dataIndex: 'total_value', align: 'right' as const },
    { title: '', width: 54, render: (_: unknown, record: InventoryLine) => <Button aria-label="Xóa dòng tồn kho" type="text" danger icon={<DeleteOutlined />} disabled={disabled} onClick={() => setInventoryLines(all => all.filter(row => row._key !== record._key))} /> },
  ];

  const toolColumns = [
    { title: 'CCDC', render: (_: unknown, record: ToolLine) => <Select disabled={disabled} showSearch optionFilterProp="label" value={record.tool_equipment_id} options={toolOptions} onChange={value => {
      const selected = tools.find(item => item.id === value);
      setToolLines(all => all.map(row => row._key === record._key ? { ...row, tool_equipment_id: value, account_code: resolveAccountOption(selected?.tool_account, '242'), original_cost: String(selected?.original_cost ?? row.original_cost), accumulated_allocation: String(selected?.accumulated_allocation ?? row.accumulated_allocation), remaining_value: String(selected?.remaining_value ?? row.remaining_value) } : row));
    }} style={{ width: '100%' }} /> },
    { title: 'TK', width: 130, render: (_: string, record: ToolLine) => <Select disabled={disabled} showSearch optionFilterProp="label" options={options} value={record.account_code || undefined} onChange={value => setToolLines(all => all.map(row => row._key === record._key ? { ...row, account_code: value } : row))} style={{ width: '100%' }} /> },
    { title: 'Nguyên giá', width: 150, render: (_: string, record: ToolLine) => <InputNumber stringMode min="0" precision={2} disabled={disabled} value={record.original_cost} onChange={value => setToolLines(all => all.map(row => row._key === record._key ? { ...row, original_cost: String(value ?? '0'), remaining_value: subtractOpeningBalanceValue(String(value ?? '0'), row.accumulated_allocation) } : row))} style={{ width: '100%' }} /> },
    { title: 'Đã phân bổ', width: 150, render: (_: string, record: ToolLine) => <InputNumber stringMode min="0" precision={2} disabled={disabled} value={record.accumulated_allocation} onChange={value => setToolLines(all => all.map(row => row._key === record._key ? { ...row, accumulated_allocation: String(value ?? '0'), remaining_value: subtractOpeningBalanceValue(row.original_cost, String(value ?? '0')) } : row))} style={{ width: '100%' }} /> },
    { title: 'Còn lại', width: 150, dataIndex: 'remaining_value', align: 'right' as const },
    { title: '', width: 54, render: (_: unknown, record: ToolLine) => <Button aria-label="Xóa dòng CCDC" type="text" danger icon={<DeleteOutlined />} disabled={disabled} onClick={() => setToolLines(all => all.filter(row => row._key !== record._key))} /> },
  ];
  const fixedAssetColumns = [
    { title: 'Tài sản', render: (_: unknown, record: FixedAssetLine) => <Select disabled={disabled} showSearch optionFilterProp="label" value={record.fixed_asset_id} options={fixedAssetOptions} onChange={value => {
      const selected = fixedAssets.find(item => item.id === value);
      setFixedAssetLines(all => all.map(row => row._key === record._key ? { ...row, fixed_asset_id: value, asset_account: resolveAccountOption(selected?.asset_account, '211'), depreciation_account: resolveAccountOption(selected?.depreciation_account, '214'), original_cost: String(selected?.original_cost ?? row.original_cost), accumulated_depreciation: String(selected?.accumulated_depreciation ?? row.accumulated_depreciation) } : row));
    }} style={{ width: '100%' }} /> },
    { title: 'TK nguyên giá', width: 145, render: (_: string, record: FixedAssetLine) => <Select disabled={disabled} showSearch optionFilterProp="label" options={options} value={record.asset_account || undefined} onChange={value => setFixedAssetLines(all => all.map(row => row._key === record._key ? { ...row, asset_account: value } : row))} style={{ width: '100%' }} /> },
    { title: 'TK hao mòn', width: 145, render: (_: string, record: FixedAssetLine) => <Select disabled={disabled} showSearch optionFilterProp="label" options={options} value={record.depreciation_account || undefined} onChange={value => setFixedAssetLines(all => all.map(row => row._key === record._key ? { ...row, depreciation_account: value } : row))} style={{ width: '100%' }} /> },
    { title: 'Nguyên giá', width: 145, render: (_: string, record: FixedAssetLine) => <InputNumber stringMode min="0" precision={2} disabled={disabled} value={record.original_cost} onChange={value => setFixedAssetLines(all => all.map(row => row._key === record._key ? { ...row, original_cost: String(value ?? '0') } : row))} style={{ width: '100%' }} /> },
    { title: 'Hao mòn lũy kế', width: 155, render: (_: string, record: FixedAssetLine) => <InputNumber stringMode min="0" precision={2} disabled={disabled} value={record.accumulated_depreciation} onChange={value => setFixedAssetLines(all => all.map(row => row._key === record._key ? { ...row, accumulated_depreciation: String(value ?? '0') } : row))} style={{ width: '100%' }} /> },
    { title: '', width: 54, render: (_: unknown, record: FixedAssetLine) => <Button aria-label="Xóa dòng tài sản cố định" type="text" danger icon={<DeleteOutlined />} disabled={disabled} onClick={() => setFixedAssetLines(all => all.filter(row => row._key !== record._key))} /> },
  ];
  const prepaidColumns = [
    { title: 'Nội dung', render: (_: string, record: PrepaidLine) => <Input disabled={disabled} value={record.description} onChange={event => setPrepaidLines(all => all.map(row => row._key === record._key ? { ...row, description: event.target.value } : row))} /> },
    { title: 'Tài khoản', width: 170, render: (_: string, record: PrepaidLine) => <Select disabled={disabled} showSearch optionFilterProp="label" options={options} value={record.account_code || undefined} onChange={value => setPrepaidLines(all => all.map(row => row._key === record._key ? { ...row, account_code: value } : row))} style={{ width: '100%' }} /> },
    { title: 'Nguyên giá', width: 145, render: (_: string, record: PrepaidLine) => <InputNumber stringMode min="0" precision={2} disabled={disabled} value={record.original_amount} onChange={value => setPrepaidLines(all => all.map(row => row._key === record._key ? { ...row, original_amount: String(value ?? '0'), remaining_amount: subtractOpeningBalanceValue(String(value ?? '0'), row.allocated_amount) } : row))} style={{ width: '100%' }} /> },
    { title: 'Đã phân bổ', width: 145, render: (_: string, record: PrepaidLine) => <InputNumber stringMode min="0" precision={2} disabled={disabled} value={record.allocated_amount} onChange={value => setPrepaidLines(all => all.map(row => row._key === record._key ? { ...row, allocated_amount: String(value ?? '0'), remaining_amount: subtractOpeningBalanceValue(row.original_amount, String(value ?? '0')) } : row))} style={{ width: '100%' }} /> },
    { title: 'Còn lại', width: 145, dataIndex: 'remaining_amount', align: 'right' as const },
    { title: '', width: 54, render: (_: unknown, record: PrepaidLine) => <Button aria-label="Xóa dòng chi phí trả trước" type="text" danger icon={<DeleteOutlined />} disabled={disabled} onClick={() => setPrepaidLines(all => all.filter(row => row._key !== record._key))} /> },
  ];
  const wipColumns = [
    { title: 'Nội dung', render: (_: string, record: WipLine) => <Input disabled={disabled} value={record.description} onChange={event => setWipLines(all => all.map(row => row._key === record._key ? { ...row, description: event.target.value } : row))} /> },
    { title: 'Tài khoản', width: 170, render: (_: string, record: WipLine) => <Select disabled={disabled} showSearch optionFilterProp="label" options={options} value={record.account_code || undefined} onChange={value => setWipLines(all => all.map(row => row._key === record._key ? { ...row, account_code: value } : row))} style={{ width: '100%' }} /> },
    { title: 'Giá trị', width: 170, render: (_: string, record: WipLine) => <InputNumber stringMode min="0" precision={2} disabled={disabled} value={record.amount} onChange={value => setWipLines(all => all.map(row => row._key === record._key ? { ...row, amount: String(value ?? '0') } : row))} style={{ width: '100%' }} /> },
    { title: '', width: 54, render: (_: unknown, record: WipLine) => <Button aria-label="Xóa dòng chi phí dở dang" type="text" danger icon={<DeleteOutlined />} disabled={disabled} onClick={() => setWipLines(all => all.filter(row => row._key !== record._key))} /> },
  ];

  const section = (body: React.ReactNode) => <section className="opening-balance-section">{body}</section>;
  const partySection = (partyType: PartyLine['party_type'], defaultAccount: string, addLabel: string) => section(
    <><Table rowKey="_key" pagination={false} columns={partyColumns} dataSource={partyLines.filter(row => row.party_type === partyType)} scroll={{ x: 950, y: 380 }} /><Button className="misa-mt-12" icon={<PlusOutlined />} disabled={disabled} onClick={() => setPartyLines(all => [...all, { _key: key(), party_type: partyType, account_code: defaultAccount, debit_amount: '0.00', credit_amount: '0.00' }])}>{addLabel}</Button></>,
  );

  const tabItems = [
    { key: 'accounts', label: 'Số dư tài khoản', children: section(<><Table rowKey="_key" pagination={false} columns={accountColumns} dataSource={accountLines} scroll={{ y: 380 }} /><Button className="misa-mt-12" icon={<PlusOutlined />} disabled={disabled} onClick={addAccountLine}>Thêm dòng tài khoản</Button></>) },
    { key: 'customers', label: 'Công nợ khách hàng', children: partySection('customer', '1311', 'Thêm công nợ khách hàng') },
    { key: 'suppliers', label: 'Công nợ nhà cung cấp', children: partySection('supplier', '3311', 'Thêm công nợ nhà cung cấp') },
    { key: 'employees', label: 'Công nợ nhân viên', children: partySection('employee', '1411', 'Thêm công nợ nhân viên') },
    { key: 'inventory', label: 'Tồn kho', children: section(<><Table rowKey="_key" pagination={false} columns={inventoryColumns} dataSource={inventoryLines} scroll={{ x: 1200, y: 380 }} /><Button className="misa-mt-12" icon={<PlusOutlined />} disabled={disabled} onClick={() => setInventoryLines(all => [...all, { _key: key(), account_code: '1561', quantity: '1.0000', unit_cost: '0.0000', total_value: '0.00' }])}>Thêm tồn kho</Button></>) },
    { key: 'tools', label: 'CCDC', children: section(<><Table rowKey="_key" pagination={false} columns={toolColumns} dataSource={toolLines} scroll={{ x: 950, y: 380 }} /><Button className="misa-mt-12" icon={<PlusOutlined />} disabled={disabled} onClick={() => setToolLines(all => [...all, { _key: key(), account_code: '2422', original_cost: '0.00', accumulated_allocation: '0.00', remaining_value: '0.00' }])}>Thêm CCDC</Button></>) },
    { key: 'fixed-assets', label: 'Tài sản cố định', children: section(<><Table rowKey="_key" pagination={false} columns={fixedAssetColumns} dataSource={fixedAssetLines} scroll={{ x: 1000, y: 380 }} /><Button className="misa-mt-12" icon={<PlusOutlined />} disabled={disabled} onClick={() => setFixedAssetLines(all => [...all, { _key: key(), asset_account: '2111', depreciation_account: '2141', original_cost: '0.00', accumulated_depreciation: '0.00' }])}>Thêm tài sản cố định</Button></>) },
    { key: 'prepaid', label: 'Chi phí trả trước', children: section(<><Table rowKey="_key" pagination={false} columns={prepaidColumns} dataSource={prepaidLines} scroll={{ x: 900, y: 380 }} /><Button className="misa-mt-12" icon={<PlusOutlined />} disabled={disabled} onClick={() => setPrepaidLines(all => [...all, { _key: key(), account_code: '2422', description: '', original_amount: '0.00', allocated_amount: '0.00', remaining_amount: '0.00' }])}>Thêm chi phí trả trước</Button></>) },
    { key: 'wip', label: 'Chi phí dở dang', children: section(<><Table rowKey="_key" pagination={false} columns={wipColumns} dataSource={wipLines} scroll={{ x: 650, y: 380 }} /><Button className="misa-mt-12" icon={<PlusOutlined />} disabled={disabled} onClick={() => setWipLines(all => [...all, { _key: key(), account_code: '1541', description: '', amount: '0.00' }])}>Thêm chi phí dở dang</Button></>) },
  ];

  return <PageShell
    title={<PageHeader eyebrow="Thiết lập ban đầu" title="Số dư đầu kỳ" description="Nhập một lần, đối chiếu chi tiết với tổng hợp rồi xác nhận." />}
    toolbar={<PageToolbar
      filters={<label htmlFor="opening-effective-date"><Typography.Text strong>Ngày bắt đầu dữ liệu</Typography.Text><Input id="opening-effective-date" aria-label="Ngày bắt đầu dữ liệu" type="date" value={effectiveDate} disabled={periodLocked || busy} onChange={event => { const date = event.target.value; setEffectiveDate(date); if (date) void load(date); }} style={{ width: 180, marginLeft: 12 }} /></label>}
      actions={<Space>
          <Button icon={<ReloadOutlined />} loading={loading} disabled={!effectiveDate || busy || loading} onClick={() => void runManualDataLoad(() => load(effectiveDate, true), { success: 'Tải dữ liệu số dư đầu kỳ thành công.', failure: 'Không thể tải dữ liệu số dư đầu kỳ.' })}>Tải dữ liệu</Button>
        <Tag color={status === 'confirmed' ? 'green' : 'blue'}>{status === 'confirmed' ? 'Đã xác nhận' : 'Bản nháp'}</Tag>
        <Button icon={<UploadOutlined />} disabled={disabled} onClick={() => setImportOpen(true)}>Nhập Excel</Button>
        <Button icon={<DownloadOutlined />} disabled={busy} onClick={exportCurrentRows}>Xuất Excel</Button>
        {editMode ? (
          <>
            <Button type="primary" icon={<SaveOutlined />} loading={busy} disabled={periodLocked || busy} onClick={() => void save()}>Lưu thay đổi</Button>
            {packageId ? (
              <Button disabled={busy} onClick={() => { setEditMode(false); if (effectiveDate) void load(effectiveDate); }}>Hủy</Button>
            ) : null}
          </>
        ) : (
          <>
            <Button icon={<EditOutlined />} disabled={!packageId || periodLocked || busy} onClick={() => setEditMode(true)}>Chỉnh sửa</Button>
            {status === 'draft' ? (
              <Button type="primary" icon={<CheckOutlined />} loading={busy} disabled={!packageId || periodLocked} onClick={() => void confirm()}>Đối chiếu & xác nhận</Button>
            ) : null}
          </>
        )}
      </Space>}
    />}
  >
    <DataTableSurface>
      {loading ? <div className="ui-table-loading opening-balance-loading" aria-label="Đang tải số dư đầu kỳ"><Spin description="Đang tải số dư đầu kỳ..." /></div> : <>
      <Tabs activeKey={activeTab} onChange={setActiveTab} className="opening-balance-tabs" type="line" items={tabItems} />
    </>}
      </DataTableSurface>
    <OpeningBalanceImportModal
      open={importOpen}
      references={importReferences}
      onCancel={() => setImportOpen(false)}
      onApply={applyImport}
    />
  </PageShell>;
};

export default OpeningBalances;
