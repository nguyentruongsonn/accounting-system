import React, { useEffect, useState } from 'react';
import { Table, Tag } from 'antd';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import api from '../../api/axios';
import { useAuthStore } from '../../store/useAuthStore';
import { MisaButton } from '../../components/misa/MisaButton';
import UserFormModal, { type UserFormValues } from './UserFormModal';
import DataTableSurface from '../../components/layout/DataTableSurface';
import { toast } from '../../components/feedback/toast';

type CanonicalRole = 'admin' | 'accountant';
type ManagedUser = { id: number; name: string; email: string; roles: string[]; is_active: boolean };
type UserDraft = UserFormValues;
const emptyDraft: UserDraft = { name: '', email: '', role: '', is_active: true, password: '', password_confirmation: '' };
const canonicalRoles = new Set(['admin', 'accountant']);
const matrix = [
  ['Chứng từ nháp, ghi sổ, danh mục và tính giá bình quân', 'Có', 'Có'],
  ['Đối chiếu và tạo bản nháp ánh xạ tài khoản', 'Có', 'Có'],
  ['Duyệt ánh xạ (không tự duyệt bản mình tạo)', 'Có', 'Không'],
  ['Quản lý người dùng, nhật ký kiểm toán', 'Có', 'Không'],
  ['Đảo / bỏ ghi sổ / hủy, đóng kỳ, xóa tài khoản chưa dùng', 'Có điều kiện', 'Không'],
];
const roleCards = [
  { role: 'admin', label: 'Quản trị viên', description: 'Quản lý người dùng, cấu hình và phê duyệt theo chính sách máy chủ.' },
  { role: 'accountant', label: 'Kế toán viên', description: 'Lập chứng từ, danh mục và đối chiếu dữ liệu trong phạm vi được cấp.' },
];

function errorMessage(error: unknown): string {
  const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data;
  return response?.errors ? Object.values(response.errors).flat().join(' ') : response?.message || 'Không thể lưu hoặc tải người dùng. Vui lòng thử lại.';
}

const RoleManagement: React.FC = () => {
  const authUser = useAuthStore(state => state.user);
  const isAdmin = authUser?.roles?.includes('admin') === true;
  const [users, setUsers] = useState<ManagedUser[]>([]);
  const [editing, setEditing] = useState<number | 'new' | null>(null);
  const [draft, setDraft] = useState<UserDraft>(emptyDraft);
  const [error, setError] = useState('');
  const [loadError, setLoadError] = useState('');
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(false);
  const legacyUsers = users.filter(user => user.is_active && (user.roles.length !== 1 || !canonicalRoles.has(user.roles[0])));
  const loadUsers = async () => {
    setLoading(true);
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
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => {
    if (isAdmin) {
      toast.info('Quyền được kiểm tra trên máy chủ. Admin không bỏ qua quy trình kế toán.', { duration: 6 });
      void loadUsers().catch(() => undefined);
    }
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
  const remove = () => {
    if (editing === null || editing === 'new') return;
    if (editing === authUser?.id) { setError('Không thể xóa tài khoản đang đăng nhập.'); return; }
    if (!window.confirm('Người dùng sẽ bị vô hiệu hóa và không thể đăng nhập nữa. Bạn có chắc muốn tiếp tục?')) return;
    void (async () => {
      setBusy(true); setError('');
      try { await api.delete(`/users/${editing}`); setEditing(null); setDraft({ ...emptyDraft }); await loadUsers(); }
      catch (reason) { setError(errorMessage(reason)); } finally { setBusy(false); }
    })();
  };
  return (
    <PageShell
      title={<PageHeader eyebrow="Thiết lập" title="Phân quyền" description="Hai vai trò cố định: admin và accountant. Quyền được kiểm soát trên máy chủ." />}
      toolbar={<PageToolbar
        actions={isAdmin ? <MisaButton variant="primary" onClick={() => edit()}>Thêm người dùng</MisaButton> : undefined}
      />}
    >
      <div className="role-permission-intro">
        <div>
          <p className="role-permission-kicker">Mô hình truy cập</p>
          <h2 className="role-permission-title">Ma trận quyền cố định</h2>
          <p className="role-permission-description">Hai vai trò chuẩn giúp quyền hạn rõ ràng, dễ kiểm tra và không tạo nhầm quyền ngoài chính sách.</p>
        </div>
        <div className="role-permission-cards">
          {roleCards.map(card => <div className="role-permission-card" key={card.role}><span className={`role-permission-card__badge role-permission-card__badge--${card.role}`}>{card.role}</span><strong>{card.label}</strong><span>{card.description}</span></div>)}
        </div>
      </div>
      <DataTableSurface>
        <table className="misa-table misa-w-full role-permission-matrix">
          <thead><tr><th scope="col">Phạm vi</th><th scope="col">admin</th><th scope="col">accountant</th></tr></thead>
          <tbody>{matrix.map(([scope, admin, accountant]) => <tr key={scope}><th scope="row">{scope}</th><td>{admin}</td><td>{accountant}</td></tr>)}</tbody>
        </table>
      </DataTableSurface>
      {isAdmin && legacyUsers.length > 0 && <div className="role-permission-warning" role="status"><strong>{legacyUsers.length} người dùng cần chuẩn hóa vai trò</strong><span>Chọn đúng một vai trò chuẩn trong cửa sổ Sửa. Hệ thống không tự nâng quyền.</span></div>}
      {loadError && <div className="role-permission-error" role="alert"><span>{loadError}</span><MisaButton onClick={() => void loadUsers().catch(() => undefined)}>Thử lại</MisaButton></div>}
      {error && <div className="role-permission-error" role="alert">{error}</div>}
      {isAdmin && <section aria-label="Quản lý người dùng">
        <h2>Người dùng trong công ty</h2>
        <DataTableSurface>
          <div data-testid="user-directory-table" aria-busy={loading ? 'true' : 'false'}>
            <Table<ManagedUser>
              rowKey="id"
              loading={loading}
              dataSource={users}
              pagination={false}
              className="misa-voucher-table"
              columns={[
                { title: 'Họ tên', dataIndex: 'name', key: 'name' },
                { title: 'Email', dataIndex: 'email', key: 'email' },
                { title: 'Vai trò', dataIndex: 'roles', key: 'roles', render: (roles: string[]) => roles.join(', ') },
                { title: 'Trạng thái', dataIndex: 'is_active', key: 'is_active', render: (active: boolean) => <Tag color={active ? 'green' : 'default'}>{active ? 'Hoạt động' : 'Vô hiệu hóa'}</Tag> },
                { title: 'Thao tác', key: 'actions', render: (_value: unknown, user: ManagedUser) => <MisaButton aria-label={`Sửa ${user.name}`} onClick={() => edit(user)}>Sửa</MisaButton> },
              ]}
            />
          </div>
        </DataTableSurface>
      </section>}
      <UserFormModal open={editing !== null} editing={editing !== null && editing !== 'new'} values={draft} busy={busy} error={error} onChange={setDraft} onSubmit={save} onClose={() => { if (!busy) { setEditing(null); setDraft({ ...emptyDraft }); setError(''); } }} onDelete={editing !== null && editing !== 'new' ? remove : undefined} />
    </PageShell>
  );
};

export default RoleManagement;
