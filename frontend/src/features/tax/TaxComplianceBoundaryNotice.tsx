import React from 'react';
import { Alert, Button, Card, Tag } from 'antd';
import { FileTextOutlined, SafetyCertificateOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';

type TaxComplianceBoundaryNoticeProps = {
  surface: 'workspace' | 'vat_declaration';
};

/**
 * A fail-closed boundary for statutory-tax surfaces.
 *
 * Posting and reporting data can be useful inputs to a tax process, but they
 * are not sufficient evidence that a statutory declaration has been prepared,
 * validated, signed or submitted. Tax obligations have their own legal basis;
 * they must never inherit a TT99/accounting-report compliance claim.
 */
const TaxComplianceBoundaryNotice: React.FC<TaxComplianceBoundaryNoticeProps> = ({ surface }) => {
  const navigate = useNavigate();
  const isVatDeclaration = surface === 'vat_declaration';

  return (
    <div className="misa-workspace-surface misa-p-16">
      <Card className="misa-w-full" title={<><SafetyCertificateOutlined /> Phạm vi phân hệ Thuế</>}>
        <Alert
          type="warning"
          showIcon
          message={isVatDeclaration
            ? 'Tờ khai 01/GTGT chưa được công bố là khả dụng'
            : 'Phân hệ lập và nộp hồ sơ thuế chưa được công bố là khả dụng'}
          description={
            <>
              <p>
                Phiên bản hiện tại không lập, ký số, nộp hoặc xác nhận hợp lệ các hồ sơ thuế.
                Dữ liệu kế toán không tự thay thế dữ liệu hóa đơn, điều kiện khấu trừ, điều chỉnh,
                phụ lục, chữ ký số hay xác nhận của cơ quan thuế.
              </p>
              <p>
                <strong>REGULATORY DEPENDENCY:</strong> nghĩa vụ GTGT, TNDN, hóa đơn điện tử và
                hồ sơ thuế phải được đánh giá theo pháp luật thuế tương ứng; Thông tư 99/2025/TT-BTC
                chỉ là baseline chế độ kế toán, không chứng nhận tờ khai thuế.
              </p>
              <p className="mb-0">
                Các bề mặt trước đây hiển thị 01/GTGT, phụ lục mua vào/bán ra, 03/TNDN và BC26/AC
                đã bị vô hiệu hoá để tránh tạo kỳ vọng sai về tính sẵn sàng hoặc tuân thủ.
              </p>
            </>
          }
        />

        <div className="misa-flex-col-gap-8 misa-mt-16">
          <div><Tag color="orange">NOT IMPLEMENTED</Tag> Lập tờ khai và phụ lục theo luật thuế</div>
          <div><Tag color="orange">NOT IMPLEMENTED</Tag> Kiểm tra điều kiện, điều chỉnh, ký số và nộp hồ sơ</div>
          <div><Tag color="orange">NOT IMPLEMENTED</Tag> Báo cáo tình hình sử dụng hóa đơn và quyết toán TNDN</div>
        </div>

        <div className="misa-mt-16">
          <Button icon={<FileTextOutlined />} onClick={() => navigate('/reports')}>
            Mở báo cáo kế toán đã công bố phạm vi
          </Button>
        </div>
      </Card>
    </div>
  );
};

export default TaxComplianceBoundaryNotice;
