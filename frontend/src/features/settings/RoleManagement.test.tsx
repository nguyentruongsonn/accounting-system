import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import RoleManagement from './RoleManagement';
import api from '../../api/axios';
import { useAuthStore } from '../../store/useAuthStore';

vi.mock('../../api/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }));

describe('fixed roles and safe user administration', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useAuthStore.setState({ user: { id: 1, name: 'Admin', email: 'admin@example.test', roles: ['admin'] } });
    vi.mocked(api.get).mockResolvedValue({ data: { data: [{ id: 2, name: 'Books', email: 'books@example.test', roles: ['accountant'], is_active: true }] } });
  });

  it('shows the fixed two-role matrix and loads server users for admin', async () => {
    render(<RoleManagement />);
    expect(screen.getByText('Ma trận quyền cố định')).toBeInTheDocument();
    expect(await screen.findByText('books@example.test')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Thêm vai trò/ })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Thêm người dùng' })).toHaveClass('misa-btn-primary');
    expect(screen.getByRole('button', { name: 'Sửa Books' })).toHaveClass('misa-btn-secondary');
  });

  it('flags active users whose legacy roles cannot pass the posting authorizer', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: [
      { id: 2, name: 'Legacy Books', email: 'legacy@example.test', roles: ['chief_accountant'], is_active: true },
    ] } });

    render(<RoleManagement />);

    expect(await screen.findByText('legacy@example.test')).toBeInTheDocument();
    expect(await screen.findByText(/role legacy chưa được chuẩn hóa/)).toBeInTheDocument();
    expect(screen.getByText(/identity này không qua được kiểm tra ghi sổ/)).toBeInTheDocument();
  });

  it('does not infer accountant when editing a legacy identity', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: [
      { id: 2, name: 'Legacy Books', email: 'legacy@example.test', roles: ['chief_accountant'], is_active: true },
    ] } });

    render(<RoleManagement />);
    fireEvent.click(await screen.findByRole('button', { name: 'Sửa Legacy Books' }));

    expect(screen.getByLabelText('Vai trò')).toHaveValue('');
    fireEvent.submit(screen.getByRole('form', { name: 'Thông tin người dùng' }));
    expect(await screen.findByText(/Chọn admin hoặc accountant trước khi lưu/)).toBeInTheDocument();
    expect(api.put).not.toHaveBeenCalled();
  });

  it('never requests the user directory for an accountant', () => {
    useAuthStore.setState({ user: { id: 2, name: 'Books', email: 'books@example.test', roles: ['accountant'] } });
    render(<RoleManagement />);
    expect(screen.getByText('Ma trận quyền cố định')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Thêm người dùng' })).not.toBeInTheDocument();
    expect(api.get).not.toHaveBeenCalled();
  });

  it('submits a real create request and only refreshes after server success', async () => {
    vi.mocked(api.post).mockResolvedValue({ data: { data: { id: 3 } } });
    render(<RoleManagement />);
    fireEvent.click(screen.getByRole('button', { name: 'Thêm người dùng' }));
    fireEvent.change(screen.getByLabelText('Họ tên'), { target: { value: 'New Books' } });
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'new@example.test' } });
    fireEvent.change(screen.getByLabelText('Mật khẩu'), { target: { value: 'Strong-password-123!' } });
    fireEvent.change(screen.getByLabelText('Xác nhận mật khẩu'), { target: { value: 'Strong-password-123!' } });
    fireEvent.change(screen.getByLabelText('Vai trò'), { target: { value: 'accountant' } });
    fireEvent.click(screen.getByRole('button', { name: 'Lưu người dùng' }));
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/users', expect.objectContaining({ name: 'New Books', role: 'accountant', is_active: true })));
  });

  it('keeps server rejection visible without reporting a successful edit', async () => {
    vi.mocked(api.put).mockRejectedValue({ response: { data: { message: 'Last active admin cannot be removed.' } } });
    render(<RoleManagement />);
    fireEvent.click(await screen.findByRole('button', { name: 'Sửa Books' }));
    fireEvent.click(screen.getByRole('button', { name: 'Lưu người dùng' }));
    expect(await screen.findByText('Last active admin cannot be removed.')).toBeInTheDocument();
  });

  it('reports malformed directory data without rendering it as a successful empty list', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: {} });
    render(<RoleManagement />);
    expect(await screen.findByText('Không thể lưu hoặc tải người dùng. Vui lòng thử lại.')).toBeInTheDocument();
  });

  it('offers a retry when the user directory request fails', async () => {
    vi.mocked(api.get)
      .mockRejectedValueOnce({ response: { data: { message: 'Directory unavailable.' } } })
      .mockResolvedValueOnce({ data: { data: [{ id: 2, name: 'Books', email: 'books@example.test', roles: ['accountant'], is_active: true }] } });

    render(<RoleManagement />);
    fireEvent.click(await screen.findByRole('button', { name: 'Thử lại danh sách người dùng' }));
    expect(await screen.findByText('books@example.test')).toBeInTheDocument();
    expect(api.get).toHaveBeenCalledTimes(2);
  });
});
