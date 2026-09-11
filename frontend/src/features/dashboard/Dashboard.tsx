import React, { useState } from 'react';
import { Alert, Button, Row, Col, Statistic, Tabs, Tag } from 'antd';
import { 
    DollarCircleOutlined, 
    BankOutlined, 
    FallOutlined, 
    RiseOutlined,
    ArrowRightOutlined,
    LineChartOutlined,
    AppstoreOutlined
} from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import api from '../../api/axios';
import {
    addDecimalMoney,
    compareDecimalMoney,
    decimalRatio,
    formatDecimalMoney,
} from '../../utils/decimalMoney';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';

const Dashboard: React.FC = () => {
    const [activeTab, setActiveTab] = useState('tinh-hinh');
    const navigate = useNavigate();

    type DashboardBalanceData = {
        assets: unknown[];
        liabilities: unknown[];
        equity: unknown[];
    };

    const parseBalanceSheet = (value: unknown): DashboardBalanceData => {
        if (!value || typeof value !== 'object'
            || !Array.isArray((value as DashboardBalanceData).assets)
            || !Array.isArray((value as DashboardBalanceData).liabilities)
            || !Array.isArray((value as DashboardBalanceData).equity)) {
            throw new Error('Invalid dashboard balance-sheet response');
        }
        return value as DashboardBalanceData;
    };

    const parseIncomeStatement = (value: unknown): unknown[] => {
        if (!Array.isArray(value)) {
            throw new Error('Invalid dashboard income-statement response');
        }
        return value;
    };

    // Fetch real data from reports API to show on dashboard
    const balanceQuery = useQuery({
        queryKey: ['balance-sheet-kpi'],
        queryFn: async () => {
            const { data } = await api.get('/reports/balance-sheet');
            return parseBalanceSheet(data);
        },
    });

    const incomeQuery = useQuery({
        queryKey: ['income-statement-kpi'],
        queryFn: async () => {
            const { data } = await api.get('/reports/income-statement');
            return parseIncomeStatement(data);
        },
    });

    const { data: balanceData } = balanceQuery;
    const { data: incomeData } = incomeQuery;
    const reportUnavailable = balanceQuery.isError || incomeQuery.isError;

    type DashboardAmount = string | null;

    const asAmount = (value: unknown): DashboardAmount => {
        if (typeof value === 'string' || typeof value === 'number') return String(value);
        return null;
    };

    const formatCurrency = (value: DashboardAmount) =>
        value === null ? '—' : formatDecimalMoney(value, { currency: true });

    const findAmount = (rows: unknown, code: string, field: string): DashboardAmount => {
        if (!Array.isArray(rows)) return null;
        const row = rows.find((item) => item && typeof item === 'object' && (item as { code?: unknown }).code === code);
        return row && typeof row === 'object' ? asAmount((row as Record<string, unknown>)[field]) : null;
    };

    // Extract KPIs
    const cash = findAmount(balanceData?.assets, '111', 'end_balance');
    const bank = findAmount(balanceData?.assets, '112', 'end_balance');
    const receivables = findAmount(balanceData?.assets, '131', 'end_balance');
    const payables = findAmount(balanceData?.liabilities, '331', 'end_balance');

    const revenue = findAmount(incomeData, '10', 'this_period');
    const profit = findAmount(incomeData, '60', 'this_period');
    const cashTotal = cash !== null && bank !== null
        ? addDecimalMoney(cash, bank)
        : null;
    const liquidAssets = cashTotal !== null && receivables !== null
        ? addDecimalMoney(cashTotal, receivables)
        : null;
    const payablesPositive = payables !== null && compareDecimalMoney(payables, '0.00') > 0;

    const grossCost = findAmount(incomeData, '11', 'this_period');
    const salesExpense = findAmount(incomeData, '25', 'this_period');
    const adminExpense = findAmount(incomeData, '26', 'this_period');
    const operatingExpenses = salesExpense !== null && adminExpense !== null
        ? addDecimalMoney(salesExpense, adminExpense)
        : null;

    const financialTabContent = reportUnavailable ? (
        <Alert
            type="warning"
            showIcon
            message="Bảng điều khiển chưa có dữ liệu báo cáo hợp lệ"
            description="Máy chủ không trả về đủ cấu trúc Bảng cân đối/Kết quả kinh doanh; giao diện không hiển thị KPI tạm hoặc số 0 thay thế."
            action={(
                <Button
                    size="small"
                    onClick={() => {
                        void balanceQuery.refetch();
                        void incomeQuery.refetch();
                    }}
                >
                    Thử lại
                </Button>
            )}
            style={{ borderRadius: 8 }}
        />
    ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
            {/* Top KPI Cards */}
            <Row gutter={[16, 16]}>
                <Col xs={24} sm={12} lg={6}>
                    <div style={{
                        background: '#FFFFFF',
                        border: '1px solid #E5E7EB',
                        borderRadius: 8,
                        padding: '16px 20px',
                        boxShadow: 'none',
                        position: 'relative',
                        overflow: 'hidden',
                    }}>
                        <div style={{ position: 'absolute', top: 0, left: 0, right: 0, height: 3, background: '#0064E0' }} />
                        <Statistic
                            title="Tổng Tiền (111 + 112)"
                            value={cashTotal ?? undefined}
                            prefix={<DollarCircleOutlined style={{ color: '#0064E0', marginRight: 6 }} />}
                            formatter={() => <span style={{ color: '#1C1E21', fontWeight: 700, fontSize: 20 }}>{formatCurrency(cashTotal)}</span>}
                        />
                        <div style={{ marginTop: 6, fontSize: 11, color: '#0064E0', fontWeight: 500 }}>Khả dụng tức thì</div>
                    </div>
                </Col>
                
                <Col xs={24} sm={12} lg={6}>
                    <div style={{
                        background: '#FFFFFF',
                        border: '1px solid #E5E7EB',
                        borderRadius: 8,
                        padding: '16px 20px',
                        boxShadow: 'none',
                        position: 'relative',
                        overflow: 'hidden',
                    }}>
                        <div style={{ position: 'absolute', top: 0, left: 0, right: 0, height: 3, background: '#10B981' }} />
                        <Statistic
                            title={<span style={{ color: '#666A72', fontSize: 13, fontWeight: 500 }}>Phải thu khách hàng (131)</span>}
                            value={receivables ?? undefined}
                            prefix={<RiseOutlined style={{ color: '#10B981', marginRight: 6 }} />}
                            formatter={() => <span style={{ color: '#1C1E21', fontWeight: 700, fontSize: 20 }}>{formatCurrency(receivables)}</span>}
                        />
                        <div style={{ marginTop: 6, fontSize: 11, color: '#10B981', fontWeight: 500 }}>Công nợ đầu ra</div>
                    </div>
                </Col>
                
                <Col xs={24} sm={12} lg={6}>
                    <div style={{
                        background: '#FFFFFF',
                        border: '1px solid #E5E7EB',
                        borderRadius: 8,
                        padding: '16px 20px',
                        boxShadow: 'none',
                        position: 'relative',
                        overflow: 'hidden',
                    }}>
                        <div style={{ position: 'absolute', top: 0, left: 0, right: 0, height: 3, background: '#EF4444' }} />
                        <Statistic
                            title={<span style={{ color: '#666A72', fontSize: 13, fontWeight: 500 }}>Phải trả người bán (331)</span>}
                            value={payables ?? undefined}
                            prefix={<FallOutlined style={{ color: '#EF4444', marginRight: 6 }} />}
                            formatter={() => <span style={{ color: '#1C1E21', fontWeight: 700, fontSize: 20 }}>{formatCurrency(payables)}</span>}
                        />
                        <div style={{ marginTop: 6, fontSize: 11, color: '#EF4444', fontWeight: 500 }}>Công nợ phải thanh toán</div>
                    </div>
                </Col>

                <Col xs={24} sm={12} lg={6}>
                    <div style={{
                        background: '#FFFFFF',
                        border: '1px solid #E5E7EB',
                        borderRadius: 8,
                        padding: '16px 20px',
                        boxShadow: 'none',
                        position: 'relative',
                        overflow: 'hidden',
                    }}>
                        <div style={{ position: 'absolute', top: 0, left: 0, right: 0, height: 3, background: '#0064E0' }} />
                        <Statistic
                            title={<span style={{ color: '#666A72', fontSize: 13, fontWeight: 500 }}>Lợi nhuận sau thuế</span>}
                            value={profit ?? undefined}
                            prefix={<BankOutlined style={{ color: '#0064E0', marginRight: 6 }} />}
                            formatter={() => <span style={{ color: '#1C1E21', fontWeight: 700, fontSize: 20 }}>{formatCurrency(profit)}</span>}
                        />
                        <div style={{ marginTop: 6, fontSize: 11, color: '#0064E0', fontWeight: 500 }}>Kỳ báo cáo hiện tại</div>
                    </div>
                </Col>
            </Row>

            {/* Income & Solvency Details */}
            <Row gutter={[16, 16]}>
                <Col xs={24} lg={12}>
                    <div style={{
                        background: '#FFFFFF',
                        border: '1px solid #E5E7EB',
                        borderRadius: 8,
                        padding: '20px',
                        boxShadow: 'none',
                    }}>
                        <div style={{ fontSize: 15, fontWeight: 700, color: '#1C1E21', marginBottom: 16, display: 'flex', alignItems: 'center', gap: 8 }}>
                            <LineChartOutlined style={{ color: '#0064E0' }} />
                            Kết quả hoạt động kinh doanh
                        </div>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '10px 14px', background: '#FAFBFC', borderRadius: 8, border: '1px solid #E5E7EB' }}>
                                <span style={{ fontWeight: 500, color: '#4B5563', fontSize: 13 }}>Doanh thu thuần</span>
                                <span style={{ fontWeight: 700, color: '#0064E0', fontSize: 14 }}>{formatCurrency(revenue)}</span>
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '10px 14px', background: '#FAFBFC', borderRadius: 8, border: '1px solid #E5E7EB' }}>
                                <span style={{ fontWeight: 500, color: '#4B5563', fontSize: 13 }}>Giá vốn hàng bán</span>
                                <span style={{ fontWeight: 700, color: '#EF4444', fontSize: 14 }}>
                                    {formatCurrency(grossCost)}
                                </span>
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '10px 14px', background: '#FAFBFC', borderRadius: 8, border: '1px solid #E5E7EB' }}>
                                <span style={{ fontWeight: 500, color: '#4B5563', fontSize: 13 }}>Chi phí bán hàng & QLDN</span>
                                <span style={{ fontWeight: 700, color: '#EF4444', fontSize: 14 }}>
                                    {formatCurrency(operatingExpenses)}
                                </span>
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '12px 14px', background: '#EBF5FF', border: '1px solid #BFDBFE', borderRadius: 8 }}>
                                <span style={{ fontWeight: 700, color: '#004BB5', fontSize: 13 }}>LỢI NHUẬN TRƯỚC THUẾ</span>
                                <span style={{ fontWeight: 800, color: '#10B981', fontSize: 16 }}>
                                    {formatCurrency(findAmount(incomeData, '50', 'this_period'))}
                                </span>
                            </div>
                        </div>
                    </div>
                </Col>

                <Col xs={24} lg={12}>
                    <div style={{
                        background: '#FFFFFF',
                        border: '1px solid #E5E7EB',
                        borderRadius: 8,
                        padding: '20px',
                        boxShadow: 'none',
                    }}>
                        <div style={{ fontSize: 15, fontWeight: 700, color: '#1C1E21', marginBottom: 16, display: 'flex', alignItems: 'center', gap: 8 }}>
                            <BankOutlined style={{ color: '#10B981' }} />
                            Chỉ số an toàn thanh toán
                        </div>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                            <div style={{ padding: '14px', border: '1px solid #E5E7EB', borderRadius: 8, background: '#FFFFFF' }}>
                                <div style={{ fontSize: 12, color: '#666A72', marginBottom: 4 }}>Tỷ số thanh toán hiện hành (Current Ratio)</div>
                                <div style={{ fontSize: 22, fontWeight: 800, color: '#1C1E21' }}>
                                    {payablesPositive && liquidAssets !== null ? decimalRatio(liquidAssets, payables) ?? '—' : '—'}
                                </div>
                                <div style={{ fontSize: 11, color: '#10B981', marginTop: 4, fontWeight: 500 }}>
                                    Tài sản lưu động / Nợ ngắn hạn (&gt; 1.0 là an toàn)
                                </div>
                            </div>
                            <div style={{ padding: '14px', border: '1px solid #E5E7EB', borderRadius: 8, background: '#FFFFFF' }}>
                                <div style={{ fontSize: 12, color: '#666A72', marginBottom: 4 }}>Tỷ số thanh toán nhanh (Quick Ratio)</div>
                                <div style={{ fontSize: 22, fontWeight: 800, color: '#1C1E21' }}>
                                    {payablesPositive && cashTotal !== null ? decimalRatio(cashTotal, payables) ?? '—' : '—'}
                                </div>
                                <div style={{ fontSize: 11, color: '#0064E0', marginTop: 4, fontWeight: 500 }}>
                                    Tiền mặt / Nợ ngắn hạn
                                </div>
                            </div>
                        </div>
                    </div>
                </Col>
            </Row>
        </div>
    );

    const workspaceLinks = [
        { title: 'Tiền mặt', description: 'Lập và tra cứu phiếu thu, phiếu chi tiền mặt, kiểm kê quỹ.', route: '/cash', tag: 'Quỹ' },
        { title: 'Mua hàng', description: 'Đơn mua (PO), chứng từ mua hàng qua kho, giảm giá, trả lại.', route: '/purchase', tag: 'Mua' },
        { title: 'Bán hàng', description: 'Báo giá, đơn hàng (SO), chứng từ bán hàng, xuất hóa đơn.', route: '/sales', tag: 'Bán' },
        { title: 'Kho & Giá vốn', description: 'Phiếu nhập kho, xuất kho, chuyển kho, tính giá xuất tự động.', route: '/inventory', tag: 'Kho' },
        { title: 'Sổ cái & Tổng hợp', description: 'Phiếu kế toán, kết chuyển lãi lỗ, khóa sổ kỳ kế toán.', route: '/gl', tag: 'GL' },
        { title: 'Báo cáo nội bộ', description: 'Bảng cân đối, kết quả kinh doanh, cân đối tài khoản và sổ chi tiết.', route: '/reports', tag: 'Báo cáo' },
        { title: 'Danh mục tài khoản', description: 'Hệ thống tài khoản và cấu hình hạch toán được máy chủ phê duyệt.', route: '/settings/account-catalogues', tag: 'COA' },
    ];

    const workspaceTabContent = (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            <div style={{ fontSize: 15, fontWeight: 700, color: '#1C1E21', display: 'flex', alignItems: 'center', gap: 8 }}>
                <AppstoreOutlined style={{ color: '#0064E0' }} />
                Bàn làm việc nội bộ
            </div>
            <p style={{ margin: 0, color: '#666A72', fontSize: 12 }}>
                Phạm vi đang khóa: chỉ mở các phân hệ có luồng nghiệp vụ nội bộ được công bố.
            </p>

            <Row gutter={[16, 16]}>
                {workspaceLinks.map((workflow) => (
                    <Col xs={24} sm={12} lg={8} key={workflow.route}>
                        <div
                            onClick={() => navigate(workflow.route)}
                            style={{
                                background: '#FFFFFF',
                                border: '1px solid #E5E7EB',
                                borderRadius: 8,
                                padding: '18px 20px',
                                height: '100%',
                                display: 'flex',
                                flexDirection: 'column',
                                justifyContent: 'space-between',
                                cursor: 'pointer',
                                transition: 'border-color 150ms ease',
                                boxShadow: 'none',
                            }}
                            onMouseEnter={(e) => {
                                e.currentTarget.style.borderColor = '#0064E0';
                            }}
                            onMouseLeave={(e) => {
                                e.currentTarget.style.borderColor = '#E5E7EB';
                            }}
                        >
                            <div>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                                    <span style={{ fontSize: 15, fontWeight: 700, color: '#1C1E21' }}>{workflow.title}</span>
                                    <Tag color="blue" style={{ borderRadius: 4, margin: 0, fontSize: 10, padding: '0 6px' }}>{workflow.tag}</Tag>
                                </div>
                                <p style={{ fontSize: 12, color: '#666A72', lineHeight: 1.5, margin: 0 }}>
                                    {workflow.description}
                                </p>
                            </div>
                            <div style={{ marginTop: 14, display: 'flex', alignItems: 'center', gap: 6, color: '#0064E0', fontSize: 12, fontWeight: 600 }}>
                                <span>Mở phân hệ</span>
                                <ArrowRightOutlined style={{ fontSize: 11 }} />
                            </div>
                        </div>
                    </Col>
                ))}
            </Row>
        </div>
    );

    return (
        <PageShell
            title={(
                <PageHeader
                    eyebrow="TỔNG QUAN"
                    title="Bảng điều khiển tổng quan"
                    description="Theo dõi sức khỏe tài chính, dòng tiền và truy cập nhanh các phân hệ kế toán."
                />
            )}
         >
            <div style={{
                background: '#FFFFFF',
                borderRadius: 8,
                border: '1px solid #E5E7EB',
                padding: '16px 20px',
                boxShadow: 'none',
            }}>
                <Tabs
                    activeKey={activeTab}
                    onChange={setActiveTab}
                    items={[
                        { key: 'tinh-hinh', label: 'TÌNH HÌNH TÀI CHÍNH', children: financialTabContent },
                        { key: 'ban-lam-viec', label: 'BÀN LÀM VIỆC NGHIỆP VỤ', children: workspaceTabContent },
                    ]}
                />
            </div>
        </PageShell>
    );
};

export default Dashboard;
