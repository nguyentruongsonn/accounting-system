import React from 'react';
import { Alert, Button, Spin } from 'antd';
import { 
    ReloadOutlined,
    ShoppingOutlined,
    FileTextOutlined,
    CreditCardOutlined
} from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import api from '../../api/axios';
import { formatDecimalMoney } from '../../utils/decimalMoney';

export const PurchaseDashboard: React.FC = () => {
    const { data: stats, isLoading, isError, refetch } = useQuery({
        queryKey: ['purchase-dashboard'],
        queryFn: async () => {
            const { data } = await api.get('/purchase/dashboard');
            return data;
        }
    });

    const availability = (key: string) => stats?.metric_availability?.[key];
    const formatVND = (v: string | number | null | undefined, metric?: string) => {
        if (metric && availability(metric)?.status === 'unavailable') return 'Chưa có nguồn dữ liệu';
        return v == null ? '—' : formatDecimalMoney(v);
    };
    const asOf = stats?.as_of ? new Date(stats.as_of).toLocaleString('vi-VN') : '—';
    const unavailable = (key: string) => availability(key)?.status === 'unavailable';
    const colors = ['#2563eb', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6'];

    if (isLoading) {
        return (
            <div className="misa-center-spinner">
                <Spin size="large" />
            </div>
        );
    }

    if (isError || !stats) {
        return (
            <div className="misa-page-layout-gray">
                <Alert
                    type="error"
                    showIcon
                    title="Không thể tải dữ liệu dashboard"
                    description="Máy chủ không trả về dữ liệu hợp lệ; không hiển thị số liệu thay thế."
                    action={<Button size="small" onClick={() => void refetch()}>Thử lại dashboard</Button>}
                />
            </div>
        );
    }

    return (
        <div className="misa-page-layout-gray">
            {/* Top Toolbar / Unit Indicator */}
            <div className="misa-flex-between">
                <span className="misa-field-label">
                    Đơn vị tính: <strong>Đồng ({stats.currency || 'VND'})</strong>
                </span>
                <button 
                    type="button" 
                    onClick={() => { void refetch(); }}
                    className="misa-unit-badge"
                >
                    <ReloadOutlined className="misa-icon-blue" /> Tải lại toàn bộ
                </button>
            </div>

            {/* Top 3 Stat KPI Cards Row */}
            <div className="misa-dashboard-stat-grid">
                {/* Card 1: Đơn mua hàng */}
                <div className="misa-dashboard-card">
                    <div>
                        <div className="misa-dashboard-card-header">
                            <div className="misa-dashboard-card-title">
                                <ShoppingOutlined className="misa-icon-blue" />
                                <span>Đơn mua hàng (toàn bộ dữ liệu)</span>
                            </div>
                        </div>

                        <div className="misa-flex-col misa-gap-8">
                            <div className="misa-dashboard-card-row">
                                <span className="apple-muted-text">Giá trị đơn hàng</span>
                                <span className="misa-table-summary-total">{formatVND(stats?.orders?.total_amount_decimal ?? stats?.orders?.total_amount)}</span>
                            </div>
                            <div className="misa-dashboard-card-row">
                                <span className="apple-muted-text">Đã thực hiện</span>
                                <span className="misa-table-link">{formatVND(stats?.orders?.executed_amount_decimal ?? stats?.orders?.executed_amount)}</span>
                            </div>
                            <div className="misa-dashboard-card-row">
                                <span className="apple-muted-text">Đã thanh toán</span>
                                <span className="misa-text-semibold misa-text-primary" title={availability('orders.paid_amount')?.reason}>{formatVND(stats.orders.paid_amount, 'orders.paid_amount')}</span>
                            </div>
                            <div className="misa-dashboard-card-row-last">
                                <span className="apple-muted-text">Còn phải trả</span>
                                <span className="misa-text-bold misa-text-danger" title={availability('orders.remaining_amount')?.reason}>{formatVND(stats.orders.remaining_amount, 'orders.remaining_amount')}</span>
                            </div>
                        </div>
                    </div>

                    <div className="misa-dashboard-card-footer">
                        <span>Số liệu tính đến: {asOf}</span>
                        <button type="button" onClick={() => { void refetch(); }} className="misa-btn-plain-primary">
                            <ReloadOutlined /> Tải lại
                        </button>
                    </div>
                </div>

                {/* Card 2: Hợp đồng mua */}
                <div className="misa-dashboard-card">
                    <div>
                        <div className="misa-dashboard-card-header">
                            <div className="misa-dashboard-card-title">
                                <FileTextOutlined className="misa-icon-violet" />
                                <span>Hợp đồng mua (toàn bộ dữ liệu)</span>
                            </div>
                        </div>

                        <div className="misa-flex-col misa-gap-8">
                            <div className="misa-dashboard-card-row">
                                <span className="apple-muted-text">Giá trị hợp đồng</span>
                                <span className="misa-table-summary-total">{formatVND(stats?.contracts?.total_amount_decimal ?? stats?.contracts?.total_amount)}</span>
                            </div>
                            <div className="misa-dashboard-card-row">
                                <span className="apple-muted-text">Đã thực hiện</span>
                                <span className="misa-table-link" title={availability('contracts.executed_amount')?.reason}>{formatVND(stats.contracts.executed_amount, 'contracts.executed_amount')}</span>
                            </div>
                            <div className="misa-dashboard-card-row">
                                <span className="apple-muted-text">Đã thanh toán</span>
                                <span className="misa-text-semibold misa-text-primary" title={availability('contracts.paid_amount')?.reason}>{formatVND(stats.contracts.paid_amount, 'contracts.paid_amount')}</span>
                            </div>
                            <div className="misa-dashboard-card-row-last">
                                <span className="apple-muted-text">Còn phải trả</span>
                                <span className="misa-text-bold misa-text-danger" title={availability('contracts.remaining_amount')?.reason}>{formatVND(stats.contracts.remaining_amount, 'contracts.remaining_amount')}</span>
                            </div>
                        </div>
                    </div>

                    <div className="misa-dashboard-card-footer">
                        <span>Số liệu tính đến: {asOf}</span>
                        <button type="button" onClick={() => { void refetch(); }} className="misa-btn-plain-primary">
                            <ReloadOutlined /> Tải lại
                        </button>
                    </div>
                </div>

                {/* Card 3: Mua hàng */}
                <div className="misa-dashboard-card">
                    <div>
                        <div className="misa-dashboard-card-header">
                            <div className="misa-dashboard-card-title">
                                <CreditCardOutlined className="misa-icon-emerald" />
                                <span>Mua hàng đã ghi sổ</span>
                            </div>
                        </div>

                        <div className="misa-flex-col misa-gap-8">
                            <div className="misa-dashboard-card-row">
                                <span className="apple-muted-text">Tổng tiền mua hàng</span>
                                <span className="misa-table-summary-total">{formatVND(stats?.invoices?.total_amount_decimal ?? stats?.invoices?.total_amount)}</span>
                            </div>
                            <div className="misa-dashboard-card-row">
                                <span className="apple-muted-text">Đã thanh toán</span>
                                <span className="misa-text-semibold misa-text-primary" title={availability('invoices.paid_amount')?.reason}>{formatVND(stats.invoices.paid_amount_decimal ?? stats.invoices.paid_amount, 'invoices.paid_amount')}</span>
                            </div>
                            <div className="misa-dashboard-card-row-last">
                                <span className="apple-muted-text">Còn phải trả</span>
                                <span className="misa-text-bold misa-text-danger" title={availability('invoices.remaining_amount')?.reason}>{formatVND(stats.invoices.remaining_amount_decimal ?? stats.invoices.remaining_amount, 'invoices.remaining_amount')}</span>
                            </div>
                        </div>
                    </div>

                    <div className="misa-dashboard-card-footer">
                        <span>Số liệu tính đến: {asOf}</span>
                        <button type="button" onClick={() => { void refetch(); }} className="misa-btn-plain-primary">
                            <ReloadOutlined /> Tải lại
                        </button>
                    </div>
                </div>
            </div>

            {/* Bottom 2 Donut Charts Row */}
            <div className="misa-dashboard-donut-grid">
                {/* Chart 4: Nhà cung cấp có công nợ lớn */}
                <div className="misa-dashboard-card">
                    <div>
                        <div className="misa-dashboard-card-header">
                            <div>
                                <div className="misa-dashboard-card-title">Nhà cung cấp có công nợ lớn</div>
                                <div className="misa-cell-sub-title">Đvt: đồng</div>
                            </div>
                        </div>

                        <div className="misa-donut-layout">
                            <div className="misa-donut-legend-list">
                                {unavailable('top_debt_suppliers') ? (
                                    <span className="apple-muted-text" title={availability('top_debt_suppliers')?.reason}>
                                        Chưa có nguồn dữ liệu công nợ
                                    </span>
                                ) : stats.top_debt_suppliers.map((supp: any, idx: number) => (
                                    <div key={idx} className="misa-donut-legend-item">
                                        <div className="misa-donut-legend-label">
                                            <div className={`misa-donut-legend-dot ${supp.colorClass}`} />
                                            <span className="misa-donut-legend-name" title={supp.name}>
                                                {supp.name}
                                            </span>
                                        </div>
                                        <span className="misa-donut-legend-value">
                                            {formatVND(supp.amount_decimal ?? supp.amount)} ({supp.percent}%)
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    <div className="misa-dashboard-card-footer">
                        <span>Số liệu tính đến: {asOf}</span>
                        <button type="button" onClick={() => { void refetch(); }} className="misa-btn-plain-primary">
                            <ReloadOutlined /> Tải lại
                        </button>
                    </div>
                </div>

                {/* Chart 5: Nhà cung cấp có giá trị mua lớn */}
                <div className="misa-dashboard-card">
                    <div>
                        <div className="misa-dashboard-card-header">
                            <div>
                                <div className="misa-dashboard-card-title">Nhà cung cấp có giá trị mua lớn</div>
                                <div className="misa-cell-sub-title">Đvt: đồng</div>
                            </div>
                        </div>

                        <div className="misa-donut-layout">
                            <div className="misa-donut-legend-list">
                                {stats.top_purchase_suppliers.length === 0 ? (
                                    <span className="apple-muted-text">Chưa có hóa đơn mua đã ghi sổ</span>
                                ) : stats.top_purchase_suppliers.map((supp: any, idx: number) => (
                                    <div key={idx} className="misa-donut-legend-item">
                                        <div className="misa-donut-legend-label">
                                            <div className="misa-donut-legend-dot" style={{ backgroundColor: colors[idx % colors.length] }} />
                                            <span className="misa-donut-legend-name" title={supp.name}>
                                                {supp.name}
                                            </span>
                                        </div>
                                        <span className="misa-donut-legend-value">
                                            {formatVND(supp.amount_decimal ?? supp.amount)} ({supp.percent}%)
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    <div className="misa-dashboard-card-footer">
                        <span>Số liệu tính đến: {asOf}</span>
                        <button type="button" onClick={() => { void refetch(); }} className="misa-btn-plain-primary">
                            <ReloadOutlined /> Tải lại
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default PurchaseDashboard;
