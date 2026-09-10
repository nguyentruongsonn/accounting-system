import React, { useState } from 'react';
import { Form, Input, Button } from 'antd';
import { LockOutlined, UserOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import { useAuthStore } from '../../store/useAuthStore';
import api from '../../api/axios';
import { toast } from '../../components/feedback/toast';

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
                    <div className="login-brand-mark" aria-label="Logo hệ thống kế toán">A</div>
                    <div className="misa-font-18-bold text-2xl mb-2">HỆ THỐNG KẾ TOÁN</div>
                </div>

                <Form
                    form={form}
                    name="login"
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
                        <Input.Password
                            autoComplete="current-password"
                            prefix={<LockOutlined className="text-gray-400" />}
                            placeholder="Mật khẩu"
                        />
                    </Form.Item>

                    <Form.Item>
                        <Button type="primary" htmlType="submit" className="w-full misa-btn-primary" loading={loading}>
                            Đăng nhập
                        </Button>
                    </Form.Item>
                </Form>

            </div>
        </div>
    );
};

export default Login;
