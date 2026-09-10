import React, { useEffect } from 'react';
import { Alert, Card, Form, Input, Button, Divider } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import { SaveOutlined } from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import PageHeader from '../../components/layout/PageHeader';
import PageShell from '../../components/layout/PageShell';
import PageToolbar from '../../components/layout/PageToolbar';

function parseCompanySettings(value: unknown): Record<string, any> {
    if (!value || typeof value !== 'object') {
        throw new Error('Invalid company settings response.');
    }
    const envelope = value as Record<string, unknown>;
    const candidate = envelope.data && typeof envelope.data === 'object'
        ? envelope.data as Record<string, unknown>
        : envelope;
    if (candidate.id === undefined || candidate.id === null) {
        throw new Error('Company settings response has no persisted tenant id.');
    }
    return candidate;
}

const CompanySettings: React.FC = () => {
    const [form] = Form.useForm();
    const queryClient = useQueryClient();

    const {
        data: company,
        isLoading,
        isError: isCompanyError,
        refetch: refetchCompany,
    } = useQuery({
        queryKey: ['company-settings'],
        queryFn: async () => {
            const { data } = await api.get('/master/company');
            return parseCompanySettings(data);
        }
    });

    useEffect(() => {
        if (company) {
            form.setFieldsValue(company);
        }
    }, [company, form]);

    const mutation = useMutation({
        mutationFn: async (values: any) => {
            if (!company?.id) {
                throw new Error('Không thể lưu khi chưa xác minh công ty từ máy chủ.');
            }
            return api.put(`/master/company/${company.id}`, values);
        },
        onSuccess: (response) => {
            if (response?.data?.data?.id === undefined || response?.data?.data?.id === null) {
                message.error('Máy chủ không trả về thông tin công ty đã lưu; không thể báo thành công.');
                return;
            }
            message.success('Cập nhật thông tin công ty thành công!');
            queryClient.invalidateQueries({ queryKey: ['company-settings'] });
        },
        onError: () => {
            message.error('Có lỗi xảy ra, vui lòng thử lại.');
        }
    });

    return (
        <PageShell
            className="apple-settings-page ui-page-shell--narrow"
            title={(
                <PageHeader
                    eyebrow="Thiết lập hệ thống"
                    title="Thông tin công ty"
                    description="Quản lý thông tin pháp lý, liên hệ và người đại diện của doanh nghiệp."
                />
            )}
            toolbar={<PageToolbar leading={<span>Hồ sơ doanh nghiệp</span>} />}
        >
            <Card loading={isLoading} className="shadow-sm">
                {isCompanyError && (
                    <Alert
                        className="mb-4"
                        type="error"
                        showIcon
                        title="Không thể tải thông tin công ty"
                        description="Không hiển thị dữ liệu thay thế; hãy thử tải lại hồ sơ doanh nghiệp."
                        action={<Button size="small" onClick={() => void refetchCompany()}>Thử lại thông tin công ty</Button>}
                    />
                )}
                {!isLoading && !isCompanyError && !company?.id && (
                    <div className="mb-4 rounded border border-amber-200 bg-amber-50 p-3 text-amber-800">
                        Chưa có bằng chứng công ty từ máy chủ; không thể lưu hoặc cập nhật tenant.
                    </div>
                )}
                <Form
                    form={form}
                    layout="vertical"
                    onFinish={(values) => mutation.mutate(values)}
                >
                    <Divider className="my-6">Thông tin cơ bản</Divider>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <Form.Item name="name" label="Tên công ty" rules={[{ required: true }]}>
                            <Input size="large" />
                        </Form.Item>
                        <Form.Item name="code" label="Mã công ty" rules={[{ required: true }]}>
                            <Input size="large" />
                        </Form.Item>
                        <Form.Item name="tax_code" label="Mã số thuế">
                            <Input size="large" />
                        </Form.Item>
                        <Form.Item name="address" label="Địa chỉ">
                            <Input size="large" />
                        </Form.Item>
                    </div>

                    <Divider className="my-6">Thông liên hệ</Divider>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <Form.Item name="phone" label="Số điện thoại">
                            <Input />
                        </Form.Item>
                        <Form.Item name="email" label="Email" rules={[{ type: 'email' }]}>
                            <Input />
                        </Form.Item>
                        <Form.Item name="website" label="Website">
                            <Input />
                        </Form.Item>
                    </div>

                    <Divider className="my-6">Người đại diện (Dùng cho Báo cáo & Chứng từ)</Divider>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <Form.Item name="director_name" label="Giám đốc">
                            <Input />
                        </Form.Item>
                        <Form.Item name="chief_accountant_name" label="Kế toán trưởng">
                            <Input />
                        </Form.Item>
                    </div>

                    <div className="mt-8 flex justify-end">
                        <Button 
                            type="primary" 
                            htmlType="submit" 
                            icon={<SaveOutlined />} 
                            size="large"
                            className="misa-btn-primary"
                            loading={mutation.isPending}
                            disabled={!company?.id || mutation.isPending}
                        >
                            Lưu thông tin
                        </Button>
                    </div>
                </Form>
            </Card>
        </PageShell>
    );
};

export default CompanySettings;
