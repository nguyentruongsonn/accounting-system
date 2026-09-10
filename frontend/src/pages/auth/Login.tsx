import React, { useState } from 'react';
import { Form, Input, Button, Typography } from 'antd';
import { LockOutlined, UserOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import { useAuthStore } from '../../store/useAuthStore';
import api from '../../api/axios';
import { toast } from '../../components/feedback/toast';

const { Text } = Typography;

const Login: React.FC = () => {
    const [loading, setLoading] = useState(false);
    const [form] = Form.useForm();
    const navigate = useNavigate();
    const { setAuth } = useAuthStore();

    const onFinish = async (values: any) => {
        setLoading(true);
        try {
            const payload = {
                email: String(values.email || '').trim(),
                password: String(values.password || ''),
            };
            const response = await api.post('/auth/login', payload);
            const user = response.data?.user;
            const token = response.data?.token;

            // A 2xx response is not proof that an authenticated session was
            // issued. Do not persist an empty/undefined bearer token or open
            // protected routes when a proxy returns a malformed envelope.
            if (typeof token !== 'string' || token.trim() === '' || !user || user.id == null) {
                throw new Error('Login response did not contain a valid session');
            }
            
            setAuth(user, token);
            toast.success('Đăng nhập thành công!');
            navigate('/');
        } catch (error: any) {
            const serverMessage = error?.response?.data?.message || error?.response?.data?.error;
            if (serverMessage && typeof serverMessage === 'string') {
                toast.error(serverMessage);
            } else {
                toast.error('Đăng nhập thất bại hoặc máy chủ chưa trả về phiên hợp lệ.');
            }
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="min-h-screen flex items-center justify-center bg-gray-50 p-4">
            <div className="w-full max-w-md bg-white p-8 rounded-xl shadow-lg border border-gray-100">
                <div className="text-center mb-8">
                    <div className="misa-font-18-bold text-2xl mb-2">HỆ THỐNG KẾ TOÁN NỘI BỘ</div>
                    <Text type="secondary">Phần mềm quản trị kế toán doanh nghiệp</Text>
                </div>

                <Form
                    form={form}
                    name="login"
                    initialValues={{ email: 'admin@admin.com', password: 'password', remember: true }}
                    onFinish={onFinish}
                    layout="vertical"
                    size="large"
                >
                    <Form.Item
                        name="email"
                        rules={[{ required: true, message: 'Vui lòng nhập Email!' }, { type: 'email', message: 'Email không hợp lệ!' }]}
                    >
                        <Input autoComplete="username" prefix={<UserOutlined className="text-gray-400" />} placeholder="Email đăng nhập" />
                    </Form.Item>

                    <Form.Item
                        name="password"
                        rules={[{ required: true, message: 'Vui lòng nhập Mật khẩu!' }]}
                    >
                        <Input.Password autoComplete="current-password" prefix={<LockOutlined className="text-gray-400" />} placeholder="Mật khẩu" />
                    </Form.Item>

                    <Form.Item>
                        <Button type="primary" htmlType="submit" className="w-full misa-btn-primary" loading={loading}>
                            Đăng nhập
                        </Button>
                    </Form.Item>
                </Form>

                <div className="mt-6 pt-4 border-t border-gray-100">
                    <div className="text-xs font-semibold text-gray-500 mb-2">Tài khoản mẫu:</div>
                    <div className="space-y-2">
                        <div className="flex items-center justify-between text-xs bg-gray-50 p-2 rounded border border-gray-200">
                            <div>
                                <span className="font-medium text-gray-700">Admin: </span>
                                <code className="text-blue-600 font-mono">admin@admin.com</code>
                                <span className="text-gray-400 mx-1">/</span>
                                <code className="text-gray-600 font-mono">password</code>
                            </div>
                            <Button
                                size="small"
                                type="link"
                                className="p-0 h-auto text-xs"
                                onClick={() => form.setFieldsValue({ email: 'admin@admin.com', password: 'password' })}
                            >
                                Điền nhanh
                            </Button>
                        </div>
                        <div className="flex items-center justify-between text-xs bg-gray-50 p-2 rounded border border-gray-200">
                            <div>
                                <span className="font-medium text-gray-700">Kế toán: </span>
                                <code className="text-blue-600 font-mono">accountant@accounting.local</code>
                                <span className="text-gray-400 mx-1">/</span>
                                <code className="text-gray-600 font-mono">password</code>
                            </div>
                            <Button
                                size="small"
                                type="link"
                                className="p-0 h-auto text-xs"
                                onClick={() => form.setFieldsValue({ email: 'accountant@accounting.local', password: 'password' })}
                            >
                                Điền nhanh
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default Login;
