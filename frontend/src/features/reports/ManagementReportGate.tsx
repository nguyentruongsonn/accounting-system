import type { ReactNode } from 'react';
import { Spin } from 'antd';
import { useManagementReportCapabilities } from './managementReportCapabilities';

type ManagementReportGateProps = {
  capabilityKey: string;
  children: ReactNode;
};

/**
 * Prevents an operational-report page from requesting data before the server
 * confirms both the published capability and the current actor's permission.
 * This is deliberately not a statutory-report certification surface.
 */
export default function ManagementReportGate({ capabilityKey, children }: ManagementReportGateProps) {
  const { data: manifest, isLoading, isError } = useManagementReportCapabilities();

  if (isLoading) {
    return (
      <div className="flex flex-col items-center justify-center py-16 px-4 w-full text-center">
        <Spin description="Đang xác minh phạm vi và quyền báo cáo vận hành..." />
      </div>
    );
  }

  if (isError || !manifest) {
    return (
      <div role="alert" className="misa-p-12 misa-mb-16" style={{ background: '#FFFBEB', border: '1px solid #FDE68A', borderRadius: 8 }}>
        <div style={{ fontWeight: 600, color: '#92400E', fontSize: 13 }}>Không thể xác minh quyền báo cáo vận hành</div>
        <div style={{ color: '#B45309', fontSize: 12 }}>Giao diện không tải số liệu khi capability manifest của máy chủ không khả dụng.</div>
      </div>
    );
  }

  const capability = manifest.capabilities.find((item) => item.key === capabilityKey);
  if (!capability) {
    return (
      <div role="alert" className="misa-p-12 misa-mb-16" style={{ background: '#FFFBEB', border: '1px solid #FDE68A', borderRadius: 8 }}>
        <div style={{ fontWeight: 600, color: '#92400E', fontSize: 13 }}>Chưa có nguồn dữ liệu báo cáo</div>
        <div style={{ color: '#B45309', fontSize: 12 }}>{`Chưa có nguồn dữ liệu cho capability '${capabilityKey}', nên giao diện không tải số liệu.`}</div>
      </div>
    );
  }

  if (!capability.available || !capability.authorized_for_current_actor) {
    const accessDenied = capability.available && !capability.authorized_for_current_actor;
    return (
      <div role="alert" className="misa-p-12 misa-mb-16" style={{ background: '#FFFBEB', border: '1px solid #FDE68A', borderRadius: 8 }}>
        <div style={{ fontWeight: 600, color: '#92400E', fontSize: 13 }}>{accessDenied ? 'Bạn không có quyền mở báo cáo vận hành này' : 'Chưa có nguồn dữ liệu báo cáo'}</div>
        <div style={{ color: '#B45309', fontSize: 12 }}>
          <p>{capability.reason ?? 'Máy chủ chưa cho phép mở báo cáo này.'}</p>
          <p className="mb-0">
            {accessDenied ? 'Quyền cần có' : 'Quyền áp dụng khi capability được công bố'}: <code>{capability.required_permission ?? 'không áp dụng khi chưa có endpoint'}</code>.
          </p>
        </div>
      </div>
    );
  }

  return <>{children}</>;
}
