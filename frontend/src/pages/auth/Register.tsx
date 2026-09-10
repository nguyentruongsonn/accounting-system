import { Form, Input, Button, Typography, Divider, Alert } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import { LockOutlined, UserOutlined, MailOutlined } from '@ant-design/icons';
import { Link } from 'react-router-dom';

const { Text } = Typography;

const Register = () => {
    const onFinish = () => {
        message.info('Backend chưa công bố API đăng ký tài khoản; vui lòng liên hệ quản trị viên để được cấp tài khoản.');
    };

    return (
        <div className="min-h-screen flex items-center justify-center bg-gray-50 p-4">
            <div className="w-full max-w-md bg-white p-8 rounded-xl shadow-lg border border-gray-100">
                <div className="text-center mb-6">
                    <div className="misa-font-18-bold text-2xl mb-2">Tạo tài khoản mới</div>
                    <Text type="secondary">Bắt đầu quản lý kế toán chuyên nghiệp</Text>
                </div>

                <Alert
                    className="apple-section-gap"
                    type="warning"
                    showIcon
                    message="Đăng ký tài khoản chưa khả dụng"
                    description="Hệ thống hiện chưa công bố endpoint đăng ký tự phục vụ. Không gửi dữ liệu biểu mẫu đến endpoint không tồn tại."
                />

                <Form
                    name="register"
                    onFinish={onFinish}
                    layout="vertical"
                    size="large"
                    requiredMark={false}
                >
                    <Form.Item
                        name="name"
                        rules={[{ required: true, message: 'Vui lòng nhập họ tên!' }]}
                    >
                        <Input
                            prefix={<UserOutlined className="text-gray-400" />}
                            placeholder="Họ và tên"
                        />
                    </Form.Item>

                    <Form.Item
                        name="email"
                        rules={[
                            { required: true, message: 'Vui lòng nhập Email!' },
                            { type: 'email', message: 'Email không hợp lệ!' }
                        ]}
                    >
                        <Input
                            prefix={<MailOutlined className="text-gray-400" />}
                            placeholder="Email đăng nhập"
                        />
                    </Form.Item>

                    <Form.Item
                        name="password"
                        rules={[
                            { required: true, message: 'Vui lòng nhập mật khẩu!' },
                            { min: 8, message: 'Mật khẩu tối thiểu 8 ký tự!' }
                        ]}
                    >
                        <Input.Password
                            prefix={<LockOutlined className="text-gray-400" />}
                            placeholder="Mật khẩu (tối thiểu 8 ký tự)"
                        />
                    </Form.Item>

                    <Form.Item
                        name="password_confirmation"
                        dependencies={['password']}
                        rules={[
                            { required: true, message: 'Vui lòng xác nhận mật khẩu!' },
                            ({ getFieldValue }) => ({
                                validator(_, value) {
                                    if (!value || getFieldValue('password') === value) {
                                        return Promise.resolve();
                                    }
                                    return Promise.reject(new Error('Mật khẩu xác nhận không khớp!'));
                                },
                            }),
                        ]}
                    >
                        <Input.Password
                            prefix={<LockOutlined className="text-gray-400" />}
                            placeholder="Xác nhận mật khẩu"
                        />
                    </Form.Item>

                    <Form.Item>
                        <Button
                            type="primary"
                            htmlType="submit"
                            disabled
                            block
                            className="misa-btn-primary"
                        >
                            Đăng ký
                        </Button>
                    </Form.Item>
                </Form>

                <Divider plain>
                    <Text type="secondary" className="text-xs">hoặc</Text>
                </Divider>

                <div className="text-center">
                    <Text type="secondary">Đã có tài khoản? </Text>
                    <Link to="/login" className="font-medium hover:underline text-blue-600">Đăng nhập ngay</Link>
                </div>
            </div>
        </div>
    );
};

export default Register;
