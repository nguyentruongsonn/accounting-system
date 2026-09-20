import { beforeEach, describe, expect, it, vi } from 'vitest';

const { toast } = vi.hoisted(() => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
  },
}));

vi.mock('./toast', () => ({ toast }));

import { runManualDataLoad } from './runManualDataLoad';

describe('runManualDataLoad', () => {
  beforeEach(() => {
    toast.success.mockReset();
    toast.error.mockReset();
  });

  it('reports success only when the query result is valid', async () => {
    await expect(runManualDataLoad(
      async () => ({ isError: false }),
      { success: 'Tải thành công', failure: 'Tải thất bại' },
    )).resolves.toBe(true);

    expect(toast.success).toHaveBeenCalledWith('Tải thành công');
    expect(toast.error).not.toHaveBeenCalled();
  });

  it('reports a rejected request as a failure', async () => {
    await expect(runManualDataLoad(
      async () => { throw new Error('server unavailable'); },
      { success: 'Tải thành công', failure: 'Tải thất bại' },
    )).resolves.toBe(false);

    expect(toast.error).toHaveBeenCalledWith('server unavailable');
    expect(toast.success).not.toHaveBeenCalled();
  });

  it('reports a query result that contains an error', async () => {
    await expect(runManualDataLoad(
      async () => ({ isError: true, error: new Error('query failed') }),
      { success: 'Tải thành công', failure: 'Tải thất bại' },
    )).resolves.toBe(false);

    expect(toast.error).toHaveBeenCalledWith('query failed');
  });
});
