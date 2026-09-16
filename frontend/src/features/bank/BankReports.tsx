import React from 'react';
import LegacyReportSurfaceNotice from '../reports/LegacyReportSurfaceNotice';

/**
 * Bank-specific report definitions, including bank ledgers and loan reports,
 * are not yet declared by the controlled reporting backend.
 */
export const BankReports: React.FC = () => (
  <LegacyReportSurfaceNotice
    moduleName="tiền gửi ngân hàng và khoản vay"
    description="Sổ tiền gửi, nhật ký thu/chi tiền gửi, bảng kê số dư và báo cáo khế ước vay chưa có definition version, nguồn dữ liệu đối chiếu hoặc approval workflow được kiểm soát."
  />
);

export default BankReports;
