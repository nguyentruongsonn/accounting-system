import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { Button, Form, Input, Modal as AntModal } from 'antd';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AppModal from './AppModal';

afterEach(() => vi.restoreAllMocks());

describe('AppModal behavioral compatibility', () => {
  it('keeps validation and native submit in the original form', async () => {
    const onFinish = vi.fn();
    render(<AppModal open title="Form" footer={null}>
      <Form onFinish={onFinish}>
        <Form.Item name="name" rules={[{ required: true, message: 'Nhập tên' }]}>
          <Input aria-label="Tên"/>
        </Form.Item>
        <Button htmlType="submit">Cất</Button>
      </Form>
    </AppModal>);
    fireEvent.click(screen.getByRole('button', { name: 'Cất' }));
    await screen.findByText('Nhập tên');
    expect(onFinish).not.toHaveBeenCalled();
    fireEvent.change(screen.getByLabelText('Tên'), { target: { value: 'Chứng từ thử' } });
    fireEvent.click(screen.getByRole('button', { name: 'Cất' }));
    await waitFor(() => expect(onFinish).toHaveBeenCalledWith({ name: 'Chứng từ thử' }));
  });

  it('retains cancellation, disabled and loading behavior on native footer buttons', () => {
    const onOk = vi.fn();
    const onCancel = vi.fn();
    const { rerender } = render(<AppModal open title="Confirm" onOk={onOk} onCancel={onCancel}
      okText="Cất" cancelText="Hủy" okButtonProps={{ disabled: true }} confirmLoading>
      Content
    </AppModal>);
    expect(screen.getByRole('button', { name: /Cất/ })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: /Cất/ }));
    expect(onOk).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Hủy' }));
    expect(onCancel).not.toHaveBeenCalled();
    rerender(<AppModal open title="Confirm" onOk={onOk} onCancel={onCancel}
      okText="Cất" cancelText="Hủy" confirmLoading={false}>Content</AppModal>);
    fireEvent.click(screen.getByRole('button', { name: 'Hủy' }));
    expect(onCancel).toHaveBeenCalledTimes(1);
  });

  it('preserves async confirmation callbacks and update/destroy handles', () => {
    const result = { destroy: vi.fn(), update: vi.fn() };
    const promise = Promise.resolve('saved');
    const onOk = vi.fn(() => promise);
    const native = vi.spyOn(AntModal, 'confirm').mockReturnValue(result);
    const handle = AppModal.confirm({ title: 'Xác nhận', onOk, maskClosable: false });
    expect(handle).toBe(result);
    const options = native.mock.calls[0][0];
    expect(options.onOk).toBe(onOk);
    expect(options.onOk?.()).toBe(promise);
    expect(options.maskClosable).toBe(false);
  });
});
