import React, { useState, useMemo } from 'react';
import { Table, Select, Button, Space, Tag, Modal, Input } from 'antd';
import {
  FileTextOutlined,
  PrinterOutlined,
  ExportOutlined,
  ReloadOutlined,
  SearchOutlined
} from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import api from '../../api/axios';
import DataTableSurface from '../../components/layout/DataTableSurface';
import { toast as message } from '../../components/feedback/toast';

export type FixedAssetReportsProps = { embedded?: boolean };

interface FixedAssetRecord {
  id: number;
  asset_code: string;
  asset_name: string;
  category_code?: string | null;
  department_code?: string | null;
  purchase_date?: string | null;
  voucher_number?: string | null;
  original_cost?: number | string | null;
  depreciable_cost?: number | string | null;
  accumulated_depreciation?: number | string | null;
  net_value?: number | string | null;
  useful_life_months?: number | null;
  monthly_depreciation?: number | string | null;
  status?: string;
  is_posted?: boolean;
}

export const FixedAssetReports: React.FC<FixedAssetReportsProps> = ({ embedded = false }) => {
  const [reportType, setReportType] = useState<'S09-DNN' | 'DEPRECIATION' | 'MOVEMENT'>('S09-DNN');
  const [period, setPeriod] = useState<string>('year');
  const [departmentFilter, setDepartmentFilter] = useState<string>('all');
  const [searchText, setSearchText] = useState<string>('');
  const [isPrintModalOpen, setIsPrintModalOpen] = useState(false);

  const { data: assets = [], isLoading, refetch } = useQuery<FixedAssetRecord[]>({
    queryKey: ['fixed-assets'],
    queryFn: async () => {
      const { data } = await api.get('/fixed-assets');
      return Array.isArray(data) ? data : (data?.data || []);
    },
  });

  // Filter assets based on department and search text
  const filteredAssets = useMemo(() => {
    return assets.filter((asset) => {
      if (departmentFilter !== 'all' && asset.department_code !== departmentFilter) {
        return false;
      }
      if (searchText.trim()) {
        const query = searchText.toLowerCase();
        const matchCode = asset.asset_code?.toLowerCase().includes(query);
        const matchName = asset.asset_name?.toLowerCase().includes(query);
        const matchVoucher = asset.voucher_number?.toLowerCase().includes(query);
        if (!matchCode && !matchName && !matchVoucher) return false;
      }
      return true;
    });
  }, [assets, departmentFilter, searchText]);

  // Aggregate financial metrics
  const totalOriginalCost = useMemo(() => {
    return filteredAssets.reduce((sum, item) => sum + (Number(item.original_cost) || 0), 0);
  }, [filteredAssets]);

  const totalDepreciation = useMemo(() => {
    return filteredAssets.reduce((sum, item) => sum + (Number(item.accumulated_depreciation) || 0), 0);
  }, [filteredAssets]);

  const totalNetValue = useMemo(() => {
    return filteredAssets.reduce((sum, item) => sum + (Number(item.net_value) || 0), 0);
  }, [filteredAssets]);

  const activeCount = useMemo(() => {
    return filteredAssets.filter(item => item.status === 'active' || item.is_posted).length;
  }, [filteredAssets]);

  const handleExportCsv = () => {
    if (filteredAssets.length === 0) {
      message.warning('Không có dữ liệu để xuất báo cáo.');
      return;
    }

    const headers = [
      'STT',
      'Mã TSCĐ',
      'Tên TSCĐ',
      'Bộ phận',
      'Ngày SD',
      'Số chứng từ',
      'Nguyên giá (VND)',
      'Hao mòn lũy kế (VND)',
      'Giá trị còn lại (VND)',
      'Trạng thái'
    ];

    const rows = filteredAssets.map((asset, idx) => [
      idx + 1,
      `"${asset.asset_code || ''}"`,
      `"${(asset.asset_name || '').replace(/"/g, '""')}"`,
      `"${asset.department_code || ''}"`,
      `"${asset.purchase_date ? dayjs(asset.purchase_date).format('DD/MM/YYYY') : ''}"`,
      `"${asset.voucher_number || ''}"`,
      Number(asset.original_cost || 0),
      Number(asset.accumulated_depreciation || 0),
      Number(asset.net_value || 0),
      `"${asset.status === 'disposed' ? 'Đã ghi giảm' : 'Đang sử dụng'}"`
    ]);

    const csvContent = '\uFEFF' + [headers.join(','), ...rows.map(r => r.join(','))].join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.setAttribute('href', url);
    link.setAttribute('download', `BaoCao_TSCD_${reportType}_${dayjs().format('YYYYMMDD')}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    message.success('Đã xuất file báo cáo TSCĐ thành công!');
  };

  const columnsS09 = [
    {
      title: 'STT',
      key: 'stt',
      width: 50,
      align: 'center' as const,
      render: (_: any, __: any, index: number) => index + 1,
    },
    {
      title: 'Số hiệu TSCĐ',
      dataIndex: 'asset_code',
      key: 'asset_code',
      width: 110,
      render: (text: string) => <span className="font-semibold text-blue-700">{text}</span>,
    },
    {
      title: 'Tên, quy cách TSCĐ',
      dataIndex: 'asset_name',
      key: 'asset_name',
      ellipsis: true,
      render: (text: string) => <span className="font-medium text-slate-800">{text}</span>,
    },
    {
      title: 'Bộ phận sử dụng',
      dataIndex: 'department_code',
      key: 'department_code',
      width: 130,
      render: (code: string) => <Tag color="blue">{code || 'Chưa phân bổ'}</Tag>,
    },
    {
      title: 'Ngày đưa vào SD',
      dataIndex: 'purchase_date',
      key: 'purchase_date',
      width: 120,
      align: 'center' as const,
      render: (val: string) => val ? dayjs(val).format('DD/MM/YYYY') : '—',
    },
    {
      title: 'Số hiệu CT ghi tăng',
      dataIndex: 'voucher_number',
      key: 'voucher_number',
      width: 140,
      render: (text: string) => text || '—',
    },
    {
      title: 'Nguyên giá (VND)',
      dataIndex: 'original_cost',
      key: 'original_cost',
      width: 140,
      align: 'right' as const,
      render: (val: number) => <span className="font-semibold text-slate-800">{Number(val || 0).toLocaleString('vi-VN')} đ</span>,
    },
    {
      title: 'Hao mòn lũy kế (VND)',
      dataIndex: 'accumulated_depreciation',
      key: 'accumulated_depreciation',
      width: 140,
      align: 'right' as const,
      render: (val: number) => <span className="text-orange-600">{Number(val || 0).toLocaleString('vi-VN')} đ</span>,
    },
    {
      title: 'Giá trị còn lại (VND)',
      dataIndex: 'net_value',
      key: 'net_value',
      width: 140,
      align: 'right' as const,
      render: (val: number) => <strong className="text-emerald-700">{Number(val || 0).toLocaleString('vi-VN')} đ</strong>,
    },
    {
      title: 'Trạng thái',
      key: 'status',
      width: 120,
      align: 'center' as const,
      render: (_: any, r: FixedAssetRecord) => (
        <Tag color={r.status === 'disposed' ? 'default' : 'success'}>
          {r.status === 'disposed' ? 'Đã giảm' : 'Đang sử dụng'}
        </Tag>
      ),
    },
  ];

  return (
    <div className={embedded ? 'misa-workspace-surface misa-fixed-asset-report-embedded' : 'misa-workspace-surface'}>
      {/* KPI Cards Header */}
      <div className="p-4 bg-white border-b border-slate-200">
        <div className="grid grid-cols-4 gap-3 mb-4">
          <div className="misa-stat-card">
            <div className="misa-stat-label">Tổng nguyên giá</div>
            <div className="misa-stat-value">
              {totalOriginalCost.toLocaleString('vi-VN')} ₫
            </div>
            <div className="misa-stat-time">{filteredAssets.length} tài sản theo tiêu chí</div>
          </div>
          <div className="misa-stat-card">
            <div className="misa-stat-label">Hao mòn lũy kế</div>
            <div className="misa-stat-value">
              {totalDepreciation.toLocaleString('vi-VN')} ₫
            </div>
            <div className="misa-stat-time">Tỷ lệ hao mòn: {totalOriginalCost > 0 ? ((totalDepreciation / totalOriginalCost) * 100).toFixed(1) : 0}%</div>
          </div>
          <div className="misa-stat-card">
            <div className="misa-stat-label">Giá trị còn lại</div>
            <div className="misa-stat-value">
              {totalNetValue.toLocaleString('vi-VN')} ₫
            </div>
            <div className="misa-stat-time">Bảo toàn vốn tài sản</div>
          </div>
          <div className="misa-stat-card">
            <div className="misa-stat-label">Đang hoạt động</div>
            <div className="misa-stat-value">
              {activeCount} / {filteredAssets.length}
            </div>
            <div className="misa-stat-time">Sẵn sàng trích khấu hao</div>
          </div>
        </div>

        {/* Toolbar: Report Type, Filters, Actions */}
        <div className="flex items-center justify-between gap-3 bg-slate-50 p-2.5 rounded-md border border-slate-200">
          <div className="flex items-center gap-2 flex-wrap flex-1">
            <div className="flex items-center gap-1.5 mr-2 font-medium text-slate-700 text-xs">
              <FileTextOutlined className="text-blue-600" />
              <span>Mẫu biểu:</span>
            </div>
            <Select
              value={reportType}
              onChange={setReportType}
              size="small"
              className="w-64"
              options={[
                { value: 'S09-DNN', label: 'S09-DNN: Sổ tài sản cố định' },
                { value: 'DEPRECIATION', label: 'Bảng trích khấu hao TSCĐ theo kỳ' },
                { value: 'MOVEMENT', label: 'Báo cáo tổng hợp tăng giảm TSCĐ' },
              ]}
            />
            <Select
              value={period}
              onChange={setPeriod}
              size="small"
              className="w-32"
              options={[
                { value: 'year', label: 'Năm 2026' },
                { value: 'quarter', label: 'Quý này' },
                { value: 'month', label: 'Tháng này' },
                { value: 'all', label: 'Tất cả các kỳ' },
              ]}
            />
            <Select
              value={departmentFilter}
              onChange={setDepartmentFilter}
              size="small"
              className="w-40"
              options={[
                { value: 'all', label: 'Tất cả bộ phận' },
                { value: 'QLDN', label: 'Quản lý DN (642)' },
                { value: 'BAN_HANG', label: 'Bán hàng (641)' },
                { value: 'SAN_XUAT', label: 'Phân xưởng (154)' },
                { value: 'VAN_PHONG', label: 'Khối Văn phòng' },
              ]}
            />
            <Input
              placeholder="Tìm theo mã, tên TSCĐ, số chứng từ..."
              prefix={<SearchOutlined className="text-slate-400" />}
              value={searchText}
              onChange={(e) => setSearchText(e.target.value)}
              size="small"
              allowClear
              className="w-64"
            />
          </div>

          <Space>
            <Button
              size="small"
              icon={<ReloadOutlined />}
              onClick={() => {
                refetch();
                message.success('Đã nạp lại dữ liệu báo cáo!');
              }}
            >
              Nạp lại
            </Button>
            <Button
              size="small"
              icon={<ExportOutlined />}
              onClick={handleExportCsv}
            >
              Xuất Excel
            </Button>
            <Button
              size="small"
              type="primary"
              icon={<PrinterOutlined />}
              onClick={() => setIsPrintModalOpen(true)}
              className="misa-btn-primary"
            >
              In sổ (S09-DNN)
            </Button>
          </Space>
        </div>
      </div>

      {/* Main Report Table Surface */}
      <DataTableSurface className="p-4">
        <div className="bg-white rounded-md border border-slate-200 overflow-hidden">
          <div className="p-3 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
            <div>
              <span className="font-bold text-slate-800 text-sm">
                {reportType === 'S09-DNN' && 'SỔ TÀI SẢN CỐ ĐỊNH (MẪU S09-DNN)'}
                {reportType === 'DEPRECIATION' && 'BẢNG TÍNH VÀ PHÂN BỔ KHẤU HAO TÀI SẢN CỐ ĐỊNH'}
                {reportType === 'MOVEMENT' && 'BÁO CÁO TỔNG HỢP BIẾN ĐỘNG TĂNG GIẢM TÀI SẢN CỐ ĐỊNH'}
              </span>
            </div>
            <div className="text-xs text-slate-600 font-medium">
              Số dòng: <strong>{filteredAssets.length}</strong> bản ghi
            </div>
          </div>

          <Table
            columns={columnsS09}
            dataSource={filteredAssets}
            rowKey="id"
            loading={isLoading}
            size="middle"
            className="misa-voucher-table"
            pagination={{ pageSize: 15, showSizeChanger: true, showTotal: (t) => `Tổng ${t} tài sản` }}
            locale={{ emptyText: 'Chưa có dữ liệu tài sản phù hợp với tiêu chí lọc.' }}
            summary={() => (
              <Table.Summary fixed>
                <Table.Summary.Row className="bg-slate-50 font-bold">
                  <Table.Summary.Cell index={0} colSpan={6} align="center">
                    TỔNG CỘNG
                  </Table.Summary.Cell>
                  <Table.Summary.Cell index={1} align="right" className="text-blue-900">
                    {totalOriginalCost.toLocaleString('vi-VN')} đ
                  </Table.Summary.Cell>
                  <Table.Summary.Cell index={2} align="right" className="text-orange-900">
                    {totalDepreciation.toLocaleString('vi-VN')} đ
                  </Table.Summary.Cell>
                  <Table.Summary.Cell index={3} align="right" className="text-emerald-900">
                    {totalNetValue.toLocaleString('vi-VN')} đ
                  </Table.Summary.Cell>
                  <Table.Summary.Cell index={4} align="center">
                    {activeCount} đang dùng
                  </Table.Summary.Cell>
                </Table.Summary.Row>
              </Table.Summary>
            )}
          />
        </div>
      </DataTableSurface>

      {/* Print View Modal */}
      <Modal
        title="Xem trước mẫu in Sổ tài sản cố định (Mẫu S09-DNN)"
        open={isPrintModalOpen}
        onCancel={() => setIsPrintModalOpen(false)}
        width={1000}
        footer={[
          <Button key="close" onClick={() => setIsPrintModalOpen(false)}>Đóng</Button>,
          <Button key="print" type="primary" icon={<PrinterOutlined />} onClick={() => window.print()} className="misa-btn-primary">
            In ra giấy / PDF
          </Button>,
        ]}
      >
        <div className="p-6 bg-white text-slate-900">
          <div className="flex justify-between items-start mb-6">
            <div>
              <div className="font-bold text-xs uppercase">ĐƠN VỊ: DOANH NGHIỆP CỦA BẠN</div>
              <div className="text-xs text-slate-500">Mã số thuế: 0101234567</div>
              <div className="text-xs text-slate-500">Địa chỉ: Hà Nội, Việt Nam</div>
            </div>
            <div className="text-right">
              <div className="font-bold text-xs">Mẫu số S09-DNN</div>
              <div className="text-xs text-slate-500 italic">(Ban hành theo TT số 133/2016/TT-BTC & TT 200/2014/TT-BTC)</div>
            </div>
          </div>

          <div className="text-center my-6">
            <h2 className="text-xl font-bold uppercase tracking-wider mb-1">SỔ TÀI SẢN CỐ ĐỊNH</h2>
            <div className="text-sm italic text-slate-600">Năm 2026</div>
          </div>

          <table className="w-full border-collapse border border-slate-300 text-xs mb-8">
            <thead>
              <tr className="bg-slate-100 text-center font-bold">
                <th className="border border-slate-300 p-2">STT</th>
                <th className="border border-slate-300 p-2">Mã TSCĐ</th>
                <th className="border border-slate-300 p-2">Tên tài sản</th>
                <th className="border border-slate-300 p-2">Bộ phận SD</th>
                <th className="border border-slate-300 p-2">Ngày SD</th>
                <th className="border border-slate-300 p-2">Nguyên giá (VND)</th>
                <th className="border border-slate-300 p-2">Hao mòn LK (VND)</th>
                <th className="border border-slate-300 p-2">Giá trị còn lại (VND)</th>
              </tr>
            </thead>
            <tbody>
              {filteredAssets.map((asset, idx) => (
                <tr key={asset.id}>
                  <td className="border border-slate-300 p-2 text-center">{idx + 1}</td>
                  <td className="border border-slate-300 p-2 font-mono font-semibold">{asset.asset_code}</td>
                  <td className="border border-slate-300 p-2 font-medium">{asset.asset_name}</td>
                  <td className="border border-slate-300 p-2 text-center">{asset.department_code || '—'}</td>
                  <td className="border border-slate-300 p-2 text-center">{asset.purchase_date ? dayjs(asset.purchase_date).format('DD/MM/YYYY') : '—'}</td>
                  <td className="border border-slate-300 p-2 text-right">{Number(asset.original_cost || 0).toLocaleString('vi-VN')}</td>
                  <td className="border border-slate-300 p-2 text-right">{Number(asset.accumulated_depreciation || 0).toLocaleString('vi-VN')}</td>
                  <td className="border border-slate-300 p-2 text-right font-bold">{Number(asset.net_value || 0).toLocaleString('vi-VN')}</td>
                </tr>
              ))}
              <tr className="bg-slate-50 font-bold">
                <td colSpan={5} className="border border-slate-300 p-2 text-center">TỔNG CỘNG</td>
                <td className="border border-slate-300 p-2 text-right">{totalOriginalCost.toLocaleString('vi-VN')}</td>
                <td className="border border-slate-300 p-2 text-right">{totalDepreciation.toLocaleString('vi-VN')}</td>
                <td className="border border-slate-300 p-2 text-right">{totalNetValue.toLocaleString('vi-VN')}</td>
              </tr>
            </tbody>
          </table>

          <div className="grid grid-cols-3 text-center text-xs mt-12">
            <div>
              <div className="font-bold">Người lập biểu</div>
              <div className="text-slate-400 italic mt-1">(Ký, họ tên)</div>
              <div className="mt-16 font-semibold">Nguyễn Văn A</div>
            </div>
            <div>
              <div className="font-bold">Kế toán trưởng</div>
              <div className="text-slate-400 italic mt-1">(Ký, họ tên)</div>
              <div className="mt-16 font-semibold">Trần Thị B</div>
            </div>
            <div>
              <div className="font-bold">Giám đốc</div>
              <div className="text-slate-400 italic mt-1">(Ký, đóng dấu, họ tên)</div>
              <div className="mt-16 font-semibold">Lê Văn C</div>
            </div>
          </div>
        </div>
      </Modal>
    </div>
  );
};

export default FixedAssetReports;
