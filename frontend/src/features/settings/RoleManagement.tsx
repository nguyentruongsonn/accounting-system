import React, { useEffect, useState } from 'react';
import { Alert } from 'antd';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import api from '../../api/axios';
import { useAuthStore } from '../../store/useAuthStore';
import { MisaButton } from '../../components/misa/MisaButton';

type ManagedUser = { id: number; name: string; email: string; roles: string[]; is_active: boolean };
type CanonicalRole = 'admin' | 'accountant';
type UserDraft = { name: string; email: string; role: CanonicalRole | ''; is_active: boolean; password: string; password_confirmation: string };
const emptyDraft: UserDraft = { name: '', email: '', role: '', is_active: true, password: '', password_confirmation: '' };
const canonicalRoles = new Set(['admin', 'accountant']);
const matrix = [
  ['Chứng từ nháp, ghi sổ, danh mục và tính giá bình quân', 'Có', 'Có'],
  ['Đối chiếu và tạo bản nháp ánh xạ tài khoản', 'Có', 'Có'],
  ['Duyệt ánh xạ (không tự duyệt bản mình tạo)', 'Có', 'Không'],
  ['Quản lý người dùng, nhật ký kiểm toán', 'Có', 'Không'],
  ['Đảo / bỏ ghi sổ / hủy, đóng kỳ, xóa tài khoản chưa dùng', 'Có điều kiện', 'Không'],
];

function errorMessage(error: unknown): string {
  const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data;
  return response?.errors ? Object.values(response.errors).flat().join(' ') : response?.message || 'Không thể lưu hoặc tải người dùng. Vui lòng thử lại.';
}

const RoleManagement: React.FC = () => {
  const isAdmin = useAuthStore(state => state.user?.roles?.includes('admin') === true);
  const [users, setUsers] = useState<ManagedUser[]>([]);
  const [editing, setEditing] = useState<number | 'new' | null>(null);
  const [draft, setDraft] = useState<UserDraft>(emptyDraft);
  const [error, setError] = useState('');
  const [loadError, setLoadError] = useState('');
  const [busy, setBusy] = useState(false);
  const legacyUsers = users.filter(user => user.is_active && (user.roles.length !== 1 || !canonicalRoles.has(user.roles[0])));
  const loadUsers = async () => {
    try {
      const response = await api.get('/users');
      const directory = response?.data?.data;
      if (!Array.isArray(directory)) {
        throw new Error('Malformed user directory response');
      }
      setUsers(directory);
      setLoadError('');
    } catch (error) {
      setLoadError(errorMessage(error));
      throw error;
    }
  };
  useEffect(() => {
    if (isAdmin) void loadUsers().catch(() => undefined);
    else {
      setUsers([]);
      setLoadError('');
    }
  }, [isAdmin]);
  const edit = (user?: ManagedUser) => {
    setError('');
    setEditing(user?.id ?? 'new');
    const role = user?.roles.length === 1 && canonicalRoles.has(user.roles[0])
      ? user.roles[0] as CanonicalRole
      : '';
    setDraft(user ? { ...emptyDraft, name: user.name, email: user.email, role, is_active: user.is_active } : { ...emptyDraft });
  };
  const save = async (event: React.FormEvent) => {
    event.preventDefault();
    if (busy || !isAdmin || editing === null) return;
    if (draft.role === '') {
      setError('Chọn admin hoặc accountant trước khi lưu.');
      return;
    }
    setBusy(true);
    setError('');
    try {
      const { password, password_confirmation, ...fields } = draft;
      const payload = editing === 'new' || password ? { ...fields, password, password_confirmation } : fields;
      if (editing === 'new') await api.post('/users', payload);
      else await api.put(`/users/${editing}`, payload);
      setEditing(null);
      setDraft({ ...emptyDraft });
      await loadUsers();
    } catch (error) {
      setError(errorMessage(error));
    } finally {
      setBusy(false);
    }
  };
  return (
    <PageShell
      title={<PageHeader eyebrow="Thiết lập" title="Phân quyền" description="Hai vai trò cố định: admin và accountant. Quyền được kiểm soát trên máy chủ." />}
      toolbar={<PageToolbar
        actions={isAdmin ? <MisaButton variant="primary" onClick={() => edit()}>Thêm người dùng</MisaButton> : undefined}
      />}
    >
      <h2>Ma trận quyền cố định</h2>
      <table className="misa-table misa-w-full">
        <thead><tr><th scope="col">Phạm vi</th><th scope="col">admin</th><th scope="col">accountant</th></tr></thead>
        <tbody>{matrix.map(([scope, admin, accountant]) => <tr key={scope}><th scope="row">{scope}</th><td>{admin}</td><td>{accountant}</td></tr>)}</tbody>
      </table>
      <Alert type="info" showIcon title="Admin không bỏ qua kiểm tra kế toán hoặc nguyên tắc người lập / người duyệt." description="Không tạo vai trò tùy ý. Vô hiệu hóa giữ nguyên chứng từ lịch sử. Thay đổi vai trò, mật khẩu hoặc trạng thái sẽ thu hồi phiên đăng nhập." />
      {isAdmin && legacyUsers.length > 0 && <Alert
        type="warning"
        showIcon
        title={`${legacyUsers.length} người dùng đang dùng role legacy chưa được chuẩn hóa`}
        description="Các identity này không qua được kiểm tra ghi sổ. Admin cần mở Sửa và chọn đúng một vai trò admin hoặc accountant sau khi xác minh người sử dụng; hệ thống không tự nâng quyền."
      />}
      {loadError && <Alert
        type="error"
        showIcon
        title={loadError}
        action={<MisaButton onClick={() => void loadUsers().catch(() => undefined)}>Thử lại danh sách người dùng</MisaButton>}
      />}
      {error && <Alert type="error" showIcon title={error} />}
      {isAdmin && <section aria-label="Quản lý người dùng">
        <h2>Người dùng trong công ty</h2>
        <table className="misa-table misa-w-full">
          <thead><tr><th>Họ tên</th><th>Email</th><th>Vai trò</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
        <tbody>{users.map(user => <tr key={user.id}><td>{user.name}</td><td>{user.email}</td><td>{user.roles.join(', ')}</td><td>{user.is_active ? 'Hoạt động' : 'Vô hiệu hóa'}</td><td><MisaButton aria-label={`Sửa ${user.name}`} onClick={() => edit(user)}>Sửa</MisaButton></td></tr>)}</tbody>
        </table>
        {editing !== null && <form onSubmit={save} aria-label="Thông tin người dùng" style={{ display: 'grid', gap: 12, maxWidth: 520, marginTop: 16 }}>
          <h3>{editing === 'new' ? 'Thêm người dùng' : 'Sửa người dùng'}</h3>
          <label htmlFor="user-name">Họ tên</label><input id="user-name" required maxLength={255} value={draft.name} onChange={event => setDraft({ ...draft, name: event.target.value })} />
          <label htmlFor="user-email">Email</label><input id="user-email" type="email" required value={draft.email} onChange={event => setDraft({ ...draft, email: event.target.value })} />
          <label htmlFor="user-role">Vai trò</label><select id="user-role" required value={draft.role} onChange={event => setDraft({ ...draft, role: event.target.value as UserDraft['role'] })}><option value="">Chọn vai trò...</option><option value="accountant">accountant</option><option value="admin">admin</option></select>
          <label htmlFor="user-active"><input id="user-active" type="checkbox" checked={draft.is_active} onChange={event => setDraft({ ...draft, is_active: event.target.checked })} /> Hoạt động</label>
          <label htmlFor="user-password">Mật khẩu</label><input id="user-password" type="password" autoComplete="new-password" minLength={12} required={editing === 'new'} value={draft.password} onChange={event => setDraft({ ...draft, password: event.target.value })} />
          <label htmlFor="user-confirmation">Xác nhận mật khẩu</label><input id="user-confirmation" type="password" autoComplete="new-password" required={editing === 'new' || !!draft.password} value={draft.password_confirmation} onChange={event => setDraft({ ...draft, password_confirmation: event.target.value })} />
          <small>Ít nhất 12 ký tự, gồm chữ và số. Để trống khi sửa nếu không đổi mật khẩu.</small>
          <MisaButton variant="primary" htmlType="submit" loading={busy}>Lưu người dùng</MisaButton>
          <MisaButton disabled={busy} onClick={() => { setEditing(null); setDraft({ ...emptyDraft }); }}>Hủy</MisaButton>
        </form>}
      </section>}
    </PageShell>
  );
};

export default RoleManagement;
