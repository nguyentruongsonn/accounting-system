import React from 'react';
import { Alert, Card, Tag } from 'antd';
import { SafetyCertificateOutlined } from '@ant-design/icons';

/**
 * Fixed-asset transactions and depreciation runs exist, but no versioned,
 * period-aware asset report definition is published by the reporting backend.
 * Do not render a generic asset list as an S09-DNN/depreciation/disposal
 * report: that would falsely imply a controlled accounting report exists.
 */
type FixedAssetReportsProps = { embedded?: boolean };

export const FixedAssetReports: React.FC<FixedAssetReportsProps> = ({ embedded = false }) => (
  <div className={embedded ? 'misa-workspace-surface misa-p-16 misa-fixed-asset-report-embedded' : 'misa-workspace-surface misa-p-16'}>
    <Card className="misa-w-full" title={<><SafetyCertificateOutlined /> Phạm vi báo cáo tài sản cố định</>}>
      <Alert
        type="warning"
        showIcon
        message="Báo cáo tài sản cố định chuyên biệt chưa được công bố là khả dụng"
        description={
          <>
            <p>
              Phiên bản hiện tại không hiển thị S09-DNN, bảng tổng hợp trích khấu hao,
              hoặc báo cáo tăng giảm TSCĐ. Danh sách tài sản thô và các tổng tính tại
              trình duyệt không phải là báo cáo có định nghĩa, kỳ báo cáo và bằng chứng phát hành.
            </p>
            <p className="mb-0">
              Để mở lại, cần catalogue chỉ tiêu/version được phê duyệt, nguồn dữ liệu theo kỳ và
              depreciation run, exact-decimal calculation, phân quyền, đối chiếu và report-run evidence.
              Trạng thái hiện tại: <Tag color="orange">NOT IMPLEMENTED</Tag>
            </p>
          </>
        }
      />
    </Card>
  </div>
);

export default FixedAssetReports;
