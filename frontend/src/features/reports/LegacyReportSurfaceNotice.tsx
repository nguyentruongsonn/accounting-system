import React from 'react';
import { Alert, Button, Spin, Tag } from 'antd';
import { useNavigate } from 'react-router-dom';
import { useReportCapabilities } from './reportCapabilities';

type LegacyReportSurfaceNoticeProps = {
  moduleName: string;
  description: string;
  enabled?: boolean;
  /**
   * Some legacy module galleries have no controlled report belonging to that
   * module. In that case, do not imply that a generally available ledger or
   * financial-statement report is a substitute for the missing module report.
   */
  showAvailableReports?: boolean;
};

/**
 * Replaces legacy report galleries that previously rendered sample figures,
 * statutory form codes, signatures and "export succeeded" messages without a
 * controlled reporting implementation behind them.
 */
const LegacyReportSurfaceNotice: React.FC<LegacyReportSurfaceNoticeProps> = ({
  moduleName,
  description,
  enabled = true,
  showAvailableReports = true,
}) => {
  const navigate = useNavigate();
  const { data: manifest, isLoading, isError } = useReportCapabilities(enabled);
  const available = manifest?.capabilities.filter((capability) => capability.available) ?? [];

  if (isLoading) {
    return <Spin tip="Đang xác minh phạm vi báo cáo khả dụng..." />;
  }

  return (
    <div className="misa-flex-col-gap-12 misa-p-16">
      <Alert
        type="warning"
        showIcon
        message={`Chưa có nguồn dữ liệu cho báo cáo ${moduleName}`}
        description={
          <>
            <p>{description}</p>
            <p className="mb-0">
              Màn hình này không dựng số liệu mẫu hoặc thông báo xuất file thành công.
              {isError ? ' Không thể xác minh phạm vi báo cáo từ máy chủ.' : ''}
            </p>
          </>
        }
      />

      {showAvailableReports && available.length > 0 && (
        <div className="misa-border-radius-4 misa-border-e2e misa-p-12">
          <div className="misa-fw-700 misa-mb-8">Báo cáo có dữ liệu</div>
          <div className="misa-flex-col-gap-6">
            {available.map((capability) => (
              <div key={capability.key} className="misa-flex-center misa-gap-8">
                <Tag color="blue" className="misa-m-0">{capability.category}</Tag>
                <span>{capability.label}</span>
              </div>
            ))}
          </div>
        </div>
      )}

      <div>
        <Button type="primary" onClick={() => navigate('/reports')}>
          Mở Báo cáo kế toán
        </Button>
      </div>
    </div>
  );
};

export default LegacyReportSurfaceNotice;
