import { QueryClient } from '@tanstack/react-query';
import dayjs from 'dayjs';

/**
 * Cập nhật tức thời (0ms) trạng thái ghi sổ / bỏ ghi sổ của một chứng từ trong TanStack Query cache.
 * Hỗ trợ cả cấu trúc cache là mảng thuần (Record[]) hoặc envelope có data (Record[] | { data: Record[] }).
 * 
 * @param queryClient Instance của TanStack QueryClient
 * @param queryKey Khóa truy vấn cần cập nhật (ví dụ: ['purchase-invoices'], ['cash-receipts'])
 * @param voucherId ID của chứng từ
 * @param isPosted Trạng thái ghi sổ mới (true: đã ghi sổ, false: bỏ ghi sổ)
 * @param extraFields Các trường bổ sung cần cập nhật đồng thời (ví dụ: posted_date, status)
 * @returns Trạng thái dữ liệu cũ (snapshot) để phục vụ rollback khi có lỗi
 */
export function optimisticTogglePostStatus<T extends { id?: string | number; [key: string]: any }>(
    queryClient: QueryClient,
    queryKey: readonly unknown[],
    voucherId: string | number,
    isPosted: boolean,
    extraFields?: Record<string, any>
): { previousData: unknown } {
    const previousData = queryClient.getQueryData(queryKey);

    const updateRecord = (record: T): T => {
        if (record.id === voucherId || String(record.id) === String(voucherId)) {
            return {
                ...record,
                is_posted: isPosted,
                status: isPosted ? 'posted' : 'draft',
                posted_date: isPosted ? dayjs().format('YYYY-MM-DD') : record.posted_date,
                ...extraFields,
            };
        }
        return record;
    };

    queryClient.setQueryData(queryKey, (oldData: unknown) => {
        if (!oldData) return oldData;

        if (Array.isArray(oldData)) {
            return oldData.map(updateRecord);
        }

        if (typeof oldData === 'object' && oldData !== null && 'data' in oldData) {
            const inner = (oldData as { data?: unknown }).data;
            if (Array.isArray(inner)) {
                return {
                    ...(oldData as object),
                    data: inner.map(updateRecord),
                };
            }
        }

        return oldData;
    });

    return { previousData };
}

/**
 * Thêm mới hoặc cập nhật một chứng từ vào cache ngay lập tức (0ms).
 */
export function instantUpsertVoucher<T extends { id?: string | number; [key: string]: any }>(
    queryClient: QueryClient,
    queryKey: readonly unknown[],
    voucher: T
): { previousData: unknown } {
    const previousData = queryClient.getQueryData(queryKey);

    queryClient.setQueryData(queryKey, (oldData: unknown) => {
        if (!oldData) return [voucher];

        if (Array.isArray(oldData)) {
            const exists = oldData.some(item => item.id === voucher.id || String(item.id) === String(voucher.id));
            if (exists) {
                return oldData.map(item => (item.id === voucher.id || String(item.id) === String(voucher.id)) ? { ...item, ...voucher } : item);
            }
            return [voucher, ...oldData];
        }

        if (typeof oldData === 'object' && oldData !== null && 'data' in oldData) {
            const inner = (oldData as { data?: unknown }).data;
            if (Array.isArray(inner)) {
                const exists = inner.some(item => item.id === voucher.id || String(item.id) === String(voucher.id));
                const updatedInner = exists
                    ? inner.map(item => (item.id === voucher.id || String(item.id) === String(voucher.id)) ? { ...item, ...voucher } : item)
                    : [voucher, ...inner];
                return {
                    ...(oldData as object),
                    data: updatedInner,
                };
            }
        }

        return oldData;
    });

    return { previousData };
}

/**
 * Xóa tức thời một chứng từ khỏi cache (0ms).
 */
export function instantRemoveVoucher(
    queryClient: QueryClient,
    queryKey: readonly unknown[],
    voucherId: string | number
): { previousData: unknown } {
    const previousData = queryClient.getQueryData(queryKey);

    const filterRecord = (record: { id?: string | number }) => {
        return !(record.id === voucherId || String(record.id) === String(voucherId));
    };

    queryClient.setQueryData(queryKey, (oldData: unknown) => {
        if (!oldData) return oldData;

        if (Array.isArray(oldData)) {
            return oldData.filter(filterRecord);
        }

        if (typeof oldData === 'object' && oldData !== null && 'data' in oldData) {
            const inner = (oldData as { data?: unknown }).data;
            if (Array.isArray(inner)) {
                return {
                    ...(oldData as object),
                    data: inner.filter(filterRecord),
                };
            }
        }

        return oldData;
    });

    return { previousData };
}

/**
 * Rollback dữ liệu cache về snapshot trước đó nếu có lỗi mạng / lỗi server.
 */
export function rollbackVoucherCache(
    queryClient: QueryClient,
    queryKey: readonly unknown[],
    context?: { previousData?: unknown }
): void {
    if (context && context.previousData !== undefined) {
        queryClient.setQueryData(queryKey, context.previousData);
    }
}
