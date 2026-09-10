import React, { useEffect, useMemo, useState } from 'react';
import { Alert, Button, Input, InputNumber, Select, Space, Spin, Table, Tabs, Tag, Typography } from 'antd';
import { CheckOutlined, DeleteOutlined, PlusOutlined, SaveOutlined } from '@ant-design/icons';
import api from '../../api/axios';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import { multiplyOpeningBalanceValue } from './openingBalanceMath';

type Catalogue = { id: number; code?: string; name?: string; is_parent?: boolean; is_active?: boolean };
type AccountLine = { _key: string; account_code: string; debit_amount: string; credit_amount: string };
type PartyLine = AccountLine & { party_type: 'customer' | 'supplier'; party_id?: number; document_number?: string; due_date?: string };
type InventoryLine = { _key: string; item_id?: number; warehouse_id?: number; account_code: string; quantity: string; unit_cost: string; total_value: string };
type Reconciliation = { balanced: boolean; total_debit: string; total_credit: string; errors: string[] };
type OpeningPackage = {
  id: number; status: 'draft' | 'confirmed'; effective_date: string;
  account_lines?: Omit<AccountLine, '_key'>[]; party_lines?: Omit<PartyLine, '_key'>[];
  inventory_lines?: Omit<InventoryLine, '_key'>[]; reconciliation?: Reconciliation;
};

const key = () => `${Date.now()}-${Math.random().toString(36).slice(2)}`;
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

const OpeningBalances: React.FC = () => {
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [packageId, setPackageId] = useState<number>();
  const [status, setStatus] = useState<'draft' | 'confirmed'>('draft');
  const [effectiveDate, setEffectiveDate] = useState('');
  const [accounts, setAccounts] = useState<Catalogue[]>([]);
  const [customers, setCustomers] = useState<Catalogue[]>([]);
  const [suppliers, setSuppliers] = useState<Catalogue[]>([]);
  const [items, setItems] = useState<Catalogue[]>([]);
  const [warehouses, setWarehouses] = useState<Catalogue[]>([]);
  const [accountLines, setAccountLines] = useState<AccountLine[]>([]);
  const [partyLines, setPartyLines] = useState<PartyLine[]>([]);
  const [inventoryLines, setInventoryLines] = useState<InventoryLine[]>([]);
  const [reconciliation, setReconciliation] = useState<Reconciliation>();
  const disabled = status === 'confirmed' || busy;

  const applyPackage = (value: OpeningPackage) => {
    setPackageId(value.id); setStatus(value.status); setEffectiveDate(value.effective_date.slice(0, 10));
    setAccountLines((value.account_lines || []).map(row => ({ ...row, _key: key() })));
    setPartyLines((value.party_lines || []).map(row => ({ ...row, _key: key() })));
    setInventoryLines((value.inventory_lines || []).map(row => ({ ...row, _key: key() })));
    setReconciliation(value.reconciliation);
  };

  const load = async () => {
    setLoading(true); setError('');
    try {
      const [packages, coa, customerData, supplierData, itemData, warehouseData] = await Promise.all([
        api.get('/opening-balances'), api.get('/master/accounts'), api.get('/master/customers'),
        api.get('/master/suppliers'), api.get('/inventory/items'), api.get('/master/warehouses'),
      ]);
      const packageRows = rows(packages.data?.data ?? packages.data, 'số dư đầu kỳ') as unknown as OpeningPackage[];
      setAccounts(rows(coa.data, 'danh mục tài khoản')); setCustomers(rows(customerData.data, 'danh mục khách hàng')); setSuppliers(rows(supplierData.data, 'danh mục nhà cung cấp'));
      setItems(rows(itemData.data, 'danh mục hàng hóa')); setWarehouses(rows(warehouseData.data, 'danh mục kho'));
      if (packageRows[0]) applyPackage(packageRows[0]);
    } catch (reason) { setError(messageOf(reason)); }
    finally { setLoading(false); }
  };
  useEffect(() => { void load(); }, []);

  const payload = () => ({
    effective_date: effectiveDate,
    account_lines: accountLines.map(({ _key, ...line }) => line),
    party_lines: partyLines.map(({ _key, party_id, ...line }) => ({ ...line, party_id })),
    inventory_lines: inventoryLines.map(({ _key, item_id, warehouse_id, ...line }) => ({ ...line, item_id, warehouse_id })),
  });
  const save = async () => {
    if (busy) return;
    setBusy(true); setError('');
    try {
      const response = packageId ? await api.put(`/opening-balances/${packageId}`, payload()) : await api.post('/opening-balances', payload());
      applyPackage(response.data.data as OpeningPackage);
    } catch (reason) { setError(messageOf(reason)); }
    finally { setBusy(false); }
  };
  const confirm = async () => {
    if (!packageId || busy) return;
    setBusy(true); setError('');
    try { applyPackage((await api.post(`/opening-balances/${packageId}/confirm`)).data.data as OpeningPackage); }
    catch (reason) { setError(messageOf(reason)); }
    finally { setBusy(false); }
  };

  const options = useMemo(() => accountOptions(accounts), [accounts]);
  const accountColumns = [
    { title: 'Tài khoản', dataIndex: 'account_code', render: (_: string, record: AccountLine) => <Select aria-label="Tài khoản số dư" disabled={disabled} showSearch optionFilterProp="label" options={options} value={record.account_code || undefined} onChange={value => setAccountLines(all => all.map(row => row._key === record._key ? { ...row, account_code: value } : row))} style={{ width: '100%' }} /> },
    { title: 'Dư Nợ', dataIndex: 'debit_amount', width: 190, render: (_: string, record: AccountLine) => <InputNumber stringMode min="0" precision={2} disabled={disabled} value={record.debit_amount} onChange={value => setAccountLines(all => all.map(row => row._key === record._key ? { ...row, debit_amount: String(value ?? '0') } : row))} style={{ width: '100%' }} /> },
    { title: 'Dư Có', dataIndex: 'credit_amount', width: 190, render: (_: string, record: AccountLine) => <InputNumber stringMode min="0" precision={2} disabled={disabled} value={record.credit_amount} onChange={value => setAccountLines(all => all.map(row => row._key === record._key ? { ...row, credit_amount: String(value ?? '0') } : row))} style={{ width: '100%' }} /> },
    { title: '', width: 54, render: (_: unknown, record: AccountLine) => <Button aria-label="Xóa dòng tài khoản" type="text" danger icon={<DeleteOutlined />} disabled={disabled} onClick={() => setAccountLines(all => all.filter(row => row._key !== record._key))} /> },
  ];
  const partyColumns = [
    { title: 'Loại', width: 150, render: (_: unknown, record: PartyLine) => <Select disabled={disabled} value={record.party_type} options={[{ value: 'customer', label: 'Khách hàng' }, { value: 'supplier', label: 'Nhà cung cấp' }]} onChange={value => setPartyLines(all => all.map(row => row._key === record._key ? { ...row, party_type: value, party_id: undefined } : row))} style={{ width: '100%' }} /> },
    { title: 'Đối tượng', render: (_: unknown, record: PartyLine) => <Select disabled={disabled} showSearch optionFilterProp="label" value={record.party_id} options={(record.party_type === 'customer' ? customers : suppliers).map(item => ({ value: item.id, label: `${item.code || ''} — ${item.name || ''}` }))} onChange={value => setPartyLines(all => all.map(row => row._key === record._key ? { ...row, party_id: value } : row))} style={{ width: '100%' }} /> },
    { title: 'Tài khoản', width: 220, render: (_: unknown, record: PartyLine) => <Select disabled={disabled} showSearch optionFilterProp="label" options={options} value={record.account_code || undefined} onChange={value => setPartyLines(all => all.map(row => row._key === record._key ? { ...row, account_code: value } : row))} style={{ width: '100%' }} /> },
    { title: 'Dư Nợ', width: 150, render: (_: unknown, record: PartyLine) => <InputNumber stringMode min="0" precision={2} disabled={disabled} value={record.debit_amount} onChange={value => setPartyLines(all => all.map(row => row._key === record._key ? { ...row, debit_amount: String(value ?? '0') } : row))} style={{ width: '100%' }} /> },
    { title: 'Dư Có', width: 150, render: (_: unknown, record: PartyLine) => <InputNumber stringMode min="0" precision={2} disabled={disabled} value={record.credit_amount} onChange={value => setPartyLines(all => all.map(row => row._key === record._key ? { ...row, credit_amount: String(value ?? '0') } : row))} style={{ width: '100%' }} /> },
    { title: '', width: 54, render: (_: unknown, record: PartyLine) => <Button aria-label="Xóa dòng công nợ" type="text" danger icon={<DeleteOutlined />} disabled={disabled} onClick={() => setPartyLines(all => all.filter(row => row._key !== record._key))} /> },
  ];
  const inventoryColumns = [
    { title: 'Hàng hóa', render: (_: unknown, record: InventoryLine) => <Select disabled={disabled} showSearch optionFilterProp="label" value={record.item_id} options={items.map(item => ({ value: item.id, label: `${item.code || ''} — ${item.name || ''}` }))} onChange={value => setInventoryLines(all => all.map(row => row._key === record._key ? { ...row, item_id: value } : row))} style={{ width: '100%' }} /> },
    { title: 'Kho', width: 220, render: (_: unknown, record: InventoryLine) => <Select disabled={disabled} showSearch optionFilterProp="label" value={record.warehouse_id} options={warehouses.map(item => ({ value: item.id, label: `${item.code || ''} — ${item.name || ''}` }))} onChange={value => setInventoryLines(all => all.map(row => row._key === record._key ? { ...row, warehouse_id: value } : row))} style={{ width: '100%' }} /> },
    { title: 'Tài khoản kho', width: 210, render: (_: unknown, record: InventoryLine) => <Select disabled={disabled} showSearch optionFilterProp="label" options={options} value={record.account_code || undefined} onChange={value => setInventoryLines(all => all.map(row => row._key === record._key ? { ...row, account_code: value } : row))} style={{ width: '100%' }} /> },
    { title: 'Số lượng', width: 130, render: (_: unknown, record: InventoryLine) => <InputNumber stringMode min="0" precision={4} disabled={disabled} value={record.quantity} onChange={value => setInventoryLines(all => all.map(row => row._key === record._key ? { ...row, quantity: String(value ?? ''), total_value: multiplyOpeningBalanceValue(String(value ?? ''), row.unit_cost) } : row))} style={{ width: '100%' }} /> },
    { title: 'Đơn giá', width: 150, render: (_: unknown, record: InventoryLine) => <InputNumber stringMode min="0" precision={4} disabled={disabled} value={record.unit_cost} onChange={value => setInventoryLines(all => all.map(row => row._key === record._key ? { ...row, unit_cost: String(value ?? ''), total_value: multiplyOpeningBalanceValue(row.quantity, String(value ?? '')) } : row))} style={{ width: '100%' }} /> },
    { title: 'Thành tiền', width: 150, dataIndex: 'total_value', align: 'right' as const },
    { title: '', width: 54, render: (_: unknown, record: InventoryLine) => <Button aria-label="Xóa dòng tồn kho" type="text" danger icon={<DeleteOutlined />} disabled={disabled} onClick={() => setInventoryLines(all => all.filter(row => row._key !== record._key))} /> },
  ];

  const tabItems = [
    { key: 'accounts', label: 'Tài khoản', children: <><Table rowKey="_key" pagination={false} columns={accountColumns} dataSource={accountLines} scroll={{ y: 380 }} /><Button className="misa-mt-12" icon={<PlusOutlined />} disabled={disabled} onClick={() => setAccountLines(all => [...all, { _key: key(), account_code: '', debit_amount: '0.00', credit_amount: '0.00' }])}>Thêm dòng tài khoản</Button></> },
    { key: 'parties', label: 'Công nợ', children: <><Table rowKey="_key" pagination={false} columns={partyColumns} dataSource={partyLines} scroll={{ x: 1050, y: 380 }} /><Button className="misa-mt-12" icon={<PlusOutlined />} disabled={disabled} onClick={() => setPartyLines(all => [...all, { _key: key(), party_type: 'customer', account_code: '131', debit_amount: '0.00', credit_amount: '0.00' }])}>Thêm dòng công nợ</Button></> },
    { key: 'inventory', label: 'Tồn kho', children: <><Table rowKey="_key" pagination={false} columns={inventoryColumns} dataSource={inventoryLines} scroll={{ x: 1200, y: 380 }} /><Button className="misa-mt-12" icon={<PlusOutlined />} disabled={disabled} onClick={() => setInventoryLines(all => [...all, { _key: key(), account_code: '1561', quantity: '1.0000', unit_cost: '0.0000', total_value: '0.00' }])}>Thêm dòng tồn kho</Button></> },
  ];

  return <PageShell
    title={<PageHeader eyebrow="Thiết lập ban đầu" title="Số dư đầu kỳ" description="Nhập một lần, đối chiếu chi tiết với tổng hợp rồi xác nhận." />}
    toolbar={<PageToolbar
      filters={<label htmlFor="opening-effective-date"><Typography.Text strong>Ngày bắt đầu dữ liệu</Typography.Text><Input id="opening-effective-date" aria-label="Ngày bắt đầu dữ liệu" type="date" value={effectiveDate} disabled={disabled} onChange={event => setEffectiveDate(event.target.value)} style={{ width: 180, marginLeft: 12 }} /></label>}
      actions={<Space><Tag color={status === 'confirmed' ? 'green' : 'blue'}>{status === 'confirmed' ? 'Đã xác nhận' : 'Bản nháp'}</Tag><Button icon={<SaveOutlined />} loading={busy} disabled={status === 'confirmed'} onClick={() => void save()}>Lưu bản nháp</Button><Button type="primary" icon={<CheckOutlined />} loading={busy} disabled={!packageId || status === 'confirmed'} onClick={() => void confirm()}>Đối chiếu & xác nhận</Button></Space>}
    />}
  >
      {loading ? <Spin description="Đang tải số dư đầu kỳ..." /> : <>
      {error && <Alert type="error" showIcon title="Không thể tải hoặc hoàn tất thao tác" description={error} action={<Button size="small" onClick={() => void load()}>Thử lại số dư đầu kỳ</Button>} className="misa-mb-12" />}
      {reconciliation && <Alert type={reconciliation.balanced ? 'success' : 'warning'} showIcon title={reconciliation.balanced ? 'Số dư đã đối chiếu' : 'Số dư chưa khớp'} description={`Tổng Nợ ${reconciliation.total_debit} · Tổng Có ${reconciliation.total_credit}${reconciliation.errors.length ? ` · ${reconciliation.errors.join(' ')}` : ''}`} className="misa-mb-12" />}
      <Tabs items={tabItems} />
    </>}
  </PageShell>;
};

export default OpeningBalances;
