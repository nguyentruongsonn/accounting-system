import { useState, useEffect } from 'react';
import { Alert, Empty, Table, Button, Space, Input, Tag, Form } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons';
import api from '../../api/axios';
import ExportExcelButton from '../../components/ExportExcelButton';
import PageShell from '../../components/layout/PageShell';
import MasterDataNavigation from './MasterDataNavigation';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import ModalFrame from '../../components/layout/ModalFrame';

const { Search } = Input;

export default function Employees() {
    const [data, setData] = useState<any[]>([]);
    const [loading, setLoading] = useState(false);
    const [loadError, setLoadError] = useState(false);
    const [isModalVisible, setIsModalVisible] = useState(false);
    const [form] = Form.useForm();
    const [editingId, setEditingId] = useState<number | null>(null);

    const fetchData = async () => {
        setLoading(true);
        setLoadError(false);
        try {
            const response = await api.get('/master/employees');
            const rows = response?.data?.data;
            if (!Array.isArray(rows)) {
                throw new Error('Invalid employee list response.');
            }
            setData(rows);
            setLoadError(false);
        } catch {
            setLoadError(true);
            message.error('Lỗi khi tải danh sách nhân viên');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchData();
    }, []);

    const showModal = (record?: any) => {
        if (record) {
            setEditingId(record.id);
            form.setFieldsValue(record);
        } else {
            setEditingId(null);
            form.resetFields();
        }
        setIsModalVisible(true);
    };

    const handleCancel = () => {
        setIsModalVisible(false);
        form.resetFields();
    };

    const handleOk = async () => {
        try {
            const values = await form.validateFields();
            let response;
            if (editingId) {
                response = await api.put(`/master/employees/${editingId}`, values);
                if (response?.data?.data?.id === undefined || response?.data?.data?.id === null) {
                    throw new Error('Máy chủ không trả về nhân viên đã cập nhật.');
                }
                message.success('Cập nhật nhân viên thành công');
            } else {
                response = await api.post('/master/employees', values);
                if (response?.data?.data?.id === undefined || response?.data?.data?.id === null) {
                    throw new Error('Máy chủ không trả về nhân viên đã lưu.');
                }
                message.success('Thêm nhân viên thành công');
            }
            setIsModalVisible(false);
            fetchData();
        } catch {
            message.error('Lỗi khi lưu thông tin');
        }
    };

    const handleDelete = async (id: number) => {
        try {
            const response = await api.delete(`/master/employees/${id}`);
            if (typeof response?.data?.message !== 'string' || response.data.message.trim() === '') {
                throw new Error('Máy chủ không xác nhận đã xóa nhân viên.');
            }
            message.success('Xóa nhân viên thành công');
            fetchData();
        } catch {
            message.error('Lỗi khi xóa nhân viên');
        }
    };

    const columns = [
        {
            title: 'Mã NV',
            dataIndex: 'code',
            key: 'code',
            render: (text: string) => <a>{text}</a>,
        },
        {
            title: 'Tên nhân viên',
            dataIndex: 'name',
            key: 'name',
            render: (text: string) => <strong>{text}</strong>,
        },
        {
            title: 'Phòng ban',
            dataIndex: 'department',
            key: 'department',
        },
        {
            title: 'Chức vụ',
            dataIndex: 'position',
            key: 'position',
        },
        {
            title: 'Lương cơ bản',
            dataIndex: 'base_salary',
            key: 'base_salary',
            align: 'right' as const,
            render: (val: number) => val ? val.toLocaleString('vi-VN') : '',
        },
        {
            title: 'Trạng thái',
            key: 'status',
            dataIndex: 'status',
            render: (status: string) => (
                <Tag color={status === 'active' ? 'green' : 'red'}>
                    {status === 'active' ? 'Đang làm việc' : 'Đã nghỉ'}
                </Tag>
            ),
        },
        {
            title: 'Thao tác',
            key: 'action',
            render: (_: any, record: any) => (
                <Space size="middle">
                    <Button type="text" className="misa-btn-tool" icon={<EditOutlined />} onClick={() => showModal(record)} />
                    <Button type="text" danger className="misa-btn-tool" icon={<DeleteOutlined />} onClick={() => handleDelete(record.id)} />
                </Space>
            ),
        },
    ];

    return (
        <PageShell
            navigation={<MasterDataNavigation />}
            title={<PageHeader eyebrow="DANH MỤC" title="Nhân viên" description="Quản lý danh sách nhân viên do máy chủ cung cấp." />}
            toolbar={<PageToolbar
                filters={
                    <Search placeholder="Tìm kiếm theo mã, tên..." className="w-[300px]" />
                }
                actions={
                    <Space>
                    <ExportExcelButton data={data} columns={columns} filename="Danh_Sach_Nhan_Vien" />
                    <Button type="primary" className="misa-btn-primary" icon={<PlusOutlined />} onClick={() => showModal()}>
                        Thêm nhân viên
                    </Button>
                    </Space>
                }
            />}
        >
            {loadError && <Alert
                className="mb-4"
                type="error"
                showIcon
                message="Không thể tải danh sách nhân viên"
                description="Các dòng đang hiển thị được giữ nguyên; máy chủ chưa trả về dữ liệu mới hợp lệ."
                action={<Button size="small" onClick={() => void fetchData()}>Thử lại</Button>}
            />}
            <DataTableSurface>
                <Table
                    columns={columns}
                    dataSource={data}
                    rowKey="id"
                    loading={loading}
                    locale={{
                        emptyText: (
                            <Empty
                                image={Empty.PRESENTED_IMAGE_SIMPLE}
                                description={loadError ? 'Không có dữ liệu mới do lỗi tải.' : 'Chưa có nhân viên.'}
                            />
                        ),
                    }}
                    pagination={{ pageSize: 10 }}
                    className="misa-voucher-table"
                />
            </DataTableSurface>

            <Modal 
                title={editingId ? "Sửa thông tin nhân viên" : "Thêm nhân viên mới"} 
                open={isModalVisible} 
                onOk={handleOk} 
                onCancel={handleCancel}
                okText="Lưu"
                cancelText="Hủy"
                className="misa-modal"
                okButtonProps={{ className: 'misa-btn-primary' }}
            >
                <ModalFrame>
                <Form form={form} layout="vertical">
                    <Form.Item name="code" label="Mã NV" rules={[{ required: true, message: 'Vui lòng nhập mã NV' }]}>
                        <Input />
                    </Form.Item>
                    <Form.Item name="name" label="Tên nhân viên" rules={[{ required: true, message: 'Vui lòng nhập tên NV' }]}>
                        <Input />
                    </Form.Item>
                    <Form.Item name="department" label="Phòng ban">
                        <Input />
                    </Form.Item>
                    <Form.Item name="position" label="Chức vụ">
                        <Input />
                    </Form.Item>
                    <Form.Item name="base_salary" label="Lương cơ bản">
                        <Input type="number" />
                    </Form.Item>
                    <Form.Item name="status" label="Trạng thái" initialValue="active">
                        <Input disabled />
                    </Form.Item>
                </Form>
                </ModalFrame>
            </Modal>
        </PageShell>
    );
}
