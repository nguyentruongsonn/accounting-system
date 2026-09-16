import React, { useState } from 'react';
import { Tabs } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Periods from './Periods';
import PeriodCloseWorkbench from './PeriodCloseWorkbench';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';

export const PeriodLock: React.FC<{ embedded?: boolean }> = ({ embedded = false }) => {
    const [activeTab, setActiveTab] = useState('periods');
    const [selectedPeriodId, setSelectedPeriodId] = useState<number | undefined>(undefined);

    const handleSelectPeriodToClose = (period: { id: number; name: string }) => {
        setSelectedPeriodId(period.id);
        setActiveTab('workbench');
        message.info(`Đã chọn ${period.name}. Vui lòng kiểm tra readiness và đối chiếu trước khi đóng kỳ.`);
    };

    const content = (
        <div className="period-lock-container bg-white rounded-lg border border-slate-200/90 shadow-xs p-5">
            <Tabs
                activeKey={activeTab}
                onChange={setActiveTab}
                className="misa-tabs misa-mb-16"
                items={[
                    {
                        key: 'periods',
                        label: 'Kỳ kế toán & Khóa sổ',
                        children: <Periods embedded onSelectPeriod={handleSelectPeriodToClose} />,
                    },
                    {
                        key: 'workbench',
                        label: 'Kiểm soát sẵn sàng đóng kỳ',
                        children: <PeriodCloseWorkbench embedded selectedPeriodId={selectedPeriodId} />,
                    },
                ]}
            />
        </div>
    );

    if (embedded) return content;

    return (
        <PageShell title={<PageHeader eyebrow="Sổ cái" title="Khóa sổ kỳ kế toán" description="Theo dõi trạng thái kỳ và thực hiện khóa sổ theo bằng chứng máy chủ." />}>
            {content}
        </PageShell>
    );
};

export default PeriodLock;
