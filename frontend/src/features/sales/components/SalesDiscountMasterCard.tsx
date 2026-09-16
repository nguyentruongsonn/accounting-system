import React from 'react';
import { Form, Input, Radio, Select, Button, DatePicker } from 'antd';
import type { FormInstance } from 'antd';
import { SearchOutlined, PlusOutlined } from '@ant-design/icons';
import {
    MisaMasterCard,
    MisaTotalCard,
    MultiColumnContactSelect
} from '../../../components/misa';
import type {
    CustomerOption,
    EmployeeOption,
    SalesPaymentMethod
} from '../types';

export interface SalesDiscountMasterCardProps {
    form: FormInstance;
    paymentMethod: SalesPaymentMethod;
    isViewMode?: boolean;
    customers: CustomerOption[];
    employees: EmployeeOption[];
    grandTotal: number;
    onPaymentMethodChange: (method: SalesPaymentMethod) => void;
    onCustomerChange: (val: any, customerItem?: any) => void;
    onOpenAddCustomer: () => void;
    onOpenAddEmployee: () => void;
    onOpenRefModal: () => void;
}

export const SalesDiscountMasterCard: React.FC<SalesDiscountMasterCardProps> = ({
    form,
    paymentMethod,
    isViewMode = false,
    customers,
    employees,
    grandTotal,
    onPaymentMethodChange,
    onCustomerChange,
    onOpenAddCustomer,
    onOpenAddEmployee,
    onOpenRefModal
}) => {
    return (
        <>
            {/* Top Config Bar */}
            <div className="misa-config-bar">
                <div className="misa-config-bar-left">
                    <Radio.Group
                        value={paymentMethod}
                        onChange={e => onPaymentMethodChange(e.target.value)}
                        disabled={isViewMode}
                    >
                        <Radio value="reduce_receivable"><span className="misa-text-semibold">Giảm trừ công nợ</span></Radio>
                        <Radio value="cash"><span className="misa-text-semibold">Trả lại tiền mặt</span></Radio>
                    </Radio.Group>
                </div>

                <div className="misa-config-bar-right">
                    <button
                        type="button"
                        className="misa-btn-link-action"
                        onClick={onOpenRefModal}
                    >
                        <SearchOutlined className="misa-mr-4" />
                        <span>{form.getFieldValue('reference_invoice_id') ? `HĐ gốc: #${form.getFieldValue('reference_invoice_id')}` : 'Chọn từ hóa đơn bán hàng'}</span>
                    </button>
                </div>
            </div>

            {/* Master Card Layout */}
            <MisaMasterCard className="misa-mb-12">
                <div className="misa-master-layout">
                    {/* LEFT: General Info */}
                    <div className="misa-master-left">
                        <div className="misa-form-grid">
                            {/* Khách hàng */}
                            <div className="misa-col-4">
                                <div className="misa-field-label required">Mã khách hàng</div>
                                <Form.Item name="customer_id" noStyle rules={[{ required: true, message: 'Chọn khách hàng' }]}>
                                    <MultiColumnContactSelect
                                        value={form.getFieldValue('customer_id')}
                                        options={customers.map((c: CustomerOption) => ({
                                            id: c.id,
                                            code: c.code || `KH${String(c.id).padStart(4, '0')}`,
                                            name: c.name,
                                            address: c.address,
                                            tax_code: c.tax_code,
                                            phone: c.phone,
                                            contact_person: c.contact_name
                                        }))}
                                        onChange={onCustomerChange}
                                        onQuickAdd={onOpenAddCustomer}
                                        entityType="customer"
                                        placeholder="Chọn khách hàng..."
                                    />
                                </Form.Item>
                            </div>

                            <div className="misa-col-8">
                                <div className="misa-field-label">Tên khách hàng</div>
                                <Form.Item name="customer_name" noStyle>
                                    <Input disabled={isViewMode} className="misa-input" placeholder="Tên khách hàng" />
                                </Form.Item>
                            </div>

                            <div className="misa-col-8">
                                <div className="misa-field-label">Địa chỉ</div>
                                <Form.Item name="customer_address" noStyle>
                                    <Input disabled={isViewMode} className="misa-input" placeholder="Địa chỉ khách hàng" />
                                </Form.Item>
                            </div>

                            <div className="misa-col-4">
                                <div className="misa-field-label">Mã số thuế</div>
                                <Form.Item name="tax_code" noStyle>
                                    <Input disabled={isViewMode} className="misa-input" placeholder="MST khách hàng" />
                                </Form.Item>
                            </div>

                            <div className="misa-col-4">
                                <div className="misa-field-label">Người nhận / liên hệ</div>
                                <Form.Item name="receiver_name" noStyle>
                                    <Input disabled={isViewMode} className="misa-input" placeholder="Họ tên người nhận" />
                                </Form.Item>
                            </div>

                            <div className="misa-col-5">
                                <div className="misa-field-label">Nhân viên bán hàng</div>
                                <div className="misa-input-group misa-w-full" style={{ width: '100%' }}>
                                    <Form.Item name="employee_id" noStyle>
                                        <Select
                                            showSearch
                                            variant="borderless"
                                            className="misa-w-full"
                                            style={{ width: '100%' }}
                                            placeholder="Chọn nhân viên..."
                                            allowClear
                                            disabled={isViewMode}
                                            filterOption={(input, option) =>
                                                (option?.label ?? '').toLowerCase().includes(input.toLowerCase())
                                            }
                                            options={employees.map((emp: EmployeeOption) => ({
                                                value: emp.id,
                                                label: `${emp.code ? emp.code + ' - ' : ''}${emp.name}`
                                            }))}
                                            dropdownRender={(menu) => (
                                                <>
                                                    {menu}
                                                    {!isViewMode && onOpenAddEmployee && (
                                                        <div className="misa-p-4 misa-border-t">
                                                            <Button
                                                                type="link"
                                                                size="small"
                                                                icon={<PlusOutlined />}
                                                                onClick={onOpenAddEmployee}
                                                                className="misa-text-primary"
                                                            >
                                                                Thêm nhanh nhân viên
                                                            </Button>
                                                        </div>
                                                    )}
                                                </>
                                            )}
                                        />
                                    </Form.Item>
                                    <button
                                        type="button"
                                        className={"misa-plus-btn " + (isViewMode ? "misa-btn-disabled" : "")}
                                        disabled={isViewMode}
                                        onClick={isViewMode ? undefined : onOpenAddEmployee}
                                        title="Thêm nhanh nhân viên (F9)"
                                    >
                                        <PlusOutlined className="misa-btn-plus-icon-sm" />
                                    </button>
                                </div>
                            </div>

                            <div className="misa-col-3">
                                <div className="misa-field-label">Tham chiếu</div>
                                <div className="misa-flex-center misa-gap-6">
                                    <button 
                                        type="button" 
                                        className="misa-btn-tool misa-btn-tool-ref" 
                                        onClick={onOpenRefModal}
                                        title="Chọn chứng từ tham chiếu"
                                    >
                                        ...
                                    </button>
                                    {form.getFieldValue('reference_invoice_id') && (
                                        <span className="misa-btn-ref-count">(1 CT)</span>
                                    )}
                                </div>
                            </div>

                            <div className="misa-col-12">
                                <div className="misa-field-label">Lý do giảm giá</div>
                                <Form.Item name="description" noStyle>
                                    <Input disabled={isViewMode} className="misa-input" placeholder="Lý do giảm giá hàng bán..." />
                                </Form.Item>
                            </div>
                        </div>
                    </div>

                    {/* RIGHT: Voucher Info + Total Card */}
                    <div className="misa-master-right">
                        <div className="misa-flex-col misa-gap-6">
                            <div className="misa-meta-row">
                                <span className="misa-field-label required">Ngày hạch toán</span>
                                <Form.Item name="accounting_date" noStyle rules={[{ required: true }]}>
                                    <DatePicker format="DD/MM/YYYY" className="misa-input misa-w-full" disabled={isViewMode} />
                                </Form.Item>
                            </div>

                            <div className="misa-meta-row">
                                <span className="misa-field-label required">Ngày chứng từ</span>
                                <Form.Item name="voucher_date" noStyle rules={[{ required: true }]}>
                                    <DatePicker format="DD/MM/YYYY" className="misa-input misa-w-full" disabled={isViewMode} />
                                </Form.Item>
                            </div>

                            <div className="misa-meta-row">
                                <span className="misa-field-label required">Số chứng từ</span>
                                <Form.Item name="voucher_number" noStyle rules={[{ required: true }]}>
                                    <Input className="misa-input misa-w-full misa-fw-700" disabled={isViewMode} />
                                </Form.Item>
                            </div>

                            <div className="misa-mt-8">
                                <MisaTotalCard
                                    label="Tổng tiền giảm giá"
                                    value={grandTotal}
                                    showWords={true}
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </MisaMasterCard>
        </>
    );
};

export default SalesDiscountMasterCard;
