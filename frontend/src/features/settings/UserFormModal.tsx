import React from 'react';
import { Alert, Form, Input, Switch } from 'antd';
import ModalFrame from '../../components/layout/ModalFrame';
import { MisaButton } from '../../components/misa/MisaButton';

export type UserFormValues = {
  name: string;
  email: string;
  role: 'admin' | 'accountant' | '';
  is_active: boolean;
  password: string;
  password_confirmation: string;
};

type UserFormModalProps = {
  open: boolean;
  editing: boolean;
  values: UserFormValues;
  busy: boolean;
  error?: string;
  onChange: (values: UserFormValues) => void;
  onSubmit: (event: React.FormEvent<HTMLFormElement>) => void;
  onClose: () => void;
  onDelete?: () => void;
};

const UserFormModal: React.FC<UserFormModalProps> = ({ open, editing, values, busy, error, onChange, onSubmit, onClose, onDelete }) => (
  open ? <div className="misa-modal-overlay" role="dialog" aria-modal="true" aria-label="Thông tin người dùng">
    <div className="misa-modal-panel" style={{ maxWidth: 600, width: 'calc(100% - 32px)' }}>
      <div className="misa-modal-panel__header"><h2>Thông tin người dùng</h2><button type="button" aria-label="Đóng" onClick={onClose}>×</button></div>
    <ModalFrame>
      <form onSubmit={onSubmit} className="misa-form-stack" aria-label="Thông tin người dùng">
        <h3>{editing ? 'Sửa người dùng' : 'Thêm người dùng'}</h3>
        {error && <Alert type="error" showIcon title={error} />}
        <Form.Item label="Họ tên" required>
          <Input aria-label="Họ tên" required maxLength={255} value={values.name} onChange={event => onChange({ ...values, name: event.target.value })} />
        </Form.Item>
        <Form.Item label="Email" required>
          <Input aria-label="Email" type="email" required value={values.email} onChange={event => onChange({ ...values, email: event.target.value })} />
        </Form.Item>
        <Form.Item label="Vai trò" required>
          <select aria-label="Vai trò" required value={values.role} onChange={event => onChange({ ...values, role: event.target.value as UserFormValues['role'] })}>
            <option value="">Chọn vai trò...</option><option value="accountant">accountant</option><option value="admin">admin</option>
          </select>
        </Form.Item>
        <label className="misa-form-checkbox"><Switch checked={values.is_active} onChange={is_active => onChange({ ...values, is_active })} /> Hoạt động</label>
        <Form.Item label="Mật khẩu" required={!editing}>
          <Input aria-label="Mật khẩu" type="password" autoComplete="new-password" minLength={12} required={!editing} value={values.password} onChange={event => onChange({ ...values, password: event.target.value })} />
        </Form.Item>
        <Form.Item label="Xác nhận mật khẩu" required={!editing && !values.password}>
          <Input aria-label="Xác nhận mật khẩu" type="password" autoComplete="new-password" required={!editing || !!values.password} value={values.password_confirmation} onChange={event => onChange({ ...values, password_confirmation: event.target.value })} />
        </Form.Item>
        <small>Ít nhất 12 ký tự, gồm chữ và số. Khi sửa, để trống nếu không đổi mật khẩu.</small>
        <div className="misa-form-actions">
          {editing && onDelete && <MisaButton variant="danger" danger disabled={busy} onClick={onDelete}>Xóa người dùng</MisaButton>}
          <MisaButton disabled={busy} onClick={onClose}>Hủy</MisaButton>
          <MisaButton variant="primary" htmlType="submit" loading={busy}>Lưu người dùng</MisaButton>
        </div>
      </form>
    </ModalFrame>
    </div>
  </div> : null
);

export default UserFormModal;
