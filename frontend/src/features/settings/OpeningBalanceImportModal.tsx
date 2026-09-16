import React, { useState } from 'react';
import { Button, Modal, Space, Table, Typography } from 'antd';
import * as XLSX from 'xlsx';
import { parseOpeningBalanceRows } from './openingBalanceImport';
import type { OpeningBalanceImportReferences, OpeningBalanceImportResult } from './openingBalanceImport';
import ModalFrame from '../../components/layout/ModalFrame';

type Props = {
  open: boolean;
  references: OpeningBalanceImportReferences;
  onCancel: () => void;
  onApply: (result: OpeningBalanceImportResult) => void;
};

export const OpeningBalanceImportModal: React.FC<Props> = ({ open, references, onCancel, onApply }) => {
  const [result, setResult] = useState<OpeningBalanceImportResult>();
  const [fileName, setFileName] = useState('');

  const reset = () => {
    setResult(undefined);
    setFileName('');
  };
  const handleCancel = () => {
    reset();
    onCancel();
  };
  const handleFile = async (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    try {
      const workbook = XLSX.read(await file.arrayBuffer(), { type: 'array' });
      const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
      const rows = XLSX.utils.sheet_to_json<unknown[]>(firstSheet, { header: 1, defval: '' });
      setFileName(file.name);
      setResult(parseOpeningBalanceRows(rows, references));
    } catch {
      setFileName(file.name);
      setResult({ validRows: [], errors: [{ row: 1, message: 'Không thể đọc tệp Excel. Hãy dùng đúng mẫu số dư đầu kỳ.' }], totals: { debit: '0.00', credit: '0.00', inventory_value: '0.00' } });
    }
  };

  return <Modal
    open={open}
    title="Nhập số dư đầu kỳ từ Excel"
    okText="Thêm dữ liệu hợp lệ"
    cancelText="Hủy"
    okButtonProps={{ disabled: !result || result.validRows.length === 0 || result.errors.length > 0 }}
    onCancel={handleCancel}
    onOk={() => { if (result && result.errors.length === 0) { onApply(result); handleCancel(); } }}
    width={780}
  >
    <ModalFrame>
      <Space direction="vertical" size="middle" style={{ width: '100%' }}>
        <Typography.Text type="secondary">Tệp gồm các cột: Loại dòng, Tài khoản, Mã đối tượng, Loại đối tượng, Mã hàng, Mã kho, Dư Nợ, Dư Có, Số lượng, Đơn giá.</Typography.Text>
        <Button type="dashed" onClick={() => document.getElementById('opening-balance-import-file')?.click()}>Chọn tệp Excel/CSV</Button>
        <input id="opening-balance-import-file" type="file" accept=".xlsx,.xls,.csv" hidden onChange={event => void handleFile(event)} />
        {fileName ? <Typography.Text>{fileName}</Typography.Text> : null}
        {result ? <>
          <Typography.Text type={result.errors.length ? 'danger' : 'success'}>
            {result.errors.length ? `Có ${result.errors.length} dòng lỗi; hãy sửa tệp trước khi nhập.` : `Đã kiểm tra ${result.validRows.length} dòng hợp lệ.`}
          </Typography.Text>
          <Typography.Text>Tổng Nợ {result.totals.debit} · Tổng Có {result.totals.credit} · Giá trị tồn kho {result.totals.inventory_value}</Typography.Text>
          {result.errors.length ? <Table
            size="small"
            pagination={false}
            rowKey="row"
            dataSource={result.errors}
            columns={[{ title: 'Dòng', dataIndex: 'row', width: 90 }, { title: 'Lỗi', dataIndex: 'message' }]}
          /> : null}
        </> : null}
      </Space>
    </ModalFrame>
  </Modal>;
};

export default OpeningBalanceImportModal;
