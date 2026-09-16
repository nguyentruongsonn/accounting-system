import React from 'react';

export interface VoucherStatusRecord {
    status?: unknown;
    is_posted?: unknown;
    is_active?: unknown;
}

const VOIDED_STATUSES = new Set(['voided', 'void', 'cancelled', 'canceled']);

export interface VoucherStatusInfo {
    label: string;
    tone: 'misa-apple-pill-green' | 'misa-apple-pill-orange' | 'misa-apple-pill-red' | 'misa-apple-pill-blue' | 'misa-apple-pill-gray';
}

export function resolveVoucherStatusInfo(
    statusSource?: VoucherStatusRecord | boolean | string | null,
    defaultUnpostedLabel = 'Chưa ghi sổ'
): VoucherStatusInfo {
    if (typeof statusSource === 'boolean') {
        return statusSource
            ? { label: 'Đã ghi sổ', tone: 'misa-apple-pill-green' }
            : { label: defaultUnpostedLabel, tone: 'misa-apple-pill-orange' };
    }

    if (typeof statusSource === 'string') {
        const normalized = statusSource.trim().toLowerCase();
        if (VOIDED_STATUSES.has(normalized)) {
            return { label: 'Đã hủy', tone: 'misa-apple-pill-red' };
        }
        if (normalized === 'posted' || normalized === 'đã ghi sổ') {
            return { label: 'Đã ghi sổ', tone: 'misa-apple-pill-green' };
        }
        if (normalized === 'approved' || normalized === 'đã duyệt') {
            return { label: 'Đã duyệt', tone: 'misa-apple-pill-blue' };
        }
        if (normalized === 'confirmed' || normalized === 'đã xác nhận') {
            return { label: 'Đã xác nhận', tone: 'misa-apple-pill-blue' };
        }
        if (normalized === 'completed' || normalized === 'hoàn thành') {
            return { label: 'Hoàn thành', tone: 'misa-apple-pill-green' };
        }
        if (normalized === 'delivered' || normalized === 'đã giao đủ') {
            return { label: 'Đã giao đủ', tone: 'misa-apple-pill-green' };
        }
        if (normalized === 'invoiced' || normalized === 'đã xuất đủ') {
            return { label: 'Đã xuất đủ', tone: 'misa-apple-pill-green' };
        }
        if (normalized === 'processing' || normalized === 'đang xử lý') {
            return { label: 'Đang xử lý', tone: 'misa-apple-pill-orange' };
        }
        if (normalized === 'delivering' || normalized === 'đang giao') {
            return { label: 'Đang giao', tone: 'misa-apple-pill-blue' };
        }
        if (normalized === 'partial') {
            return { label: 'Một phần', tone: 'misa-apple-pill-orange' };
        }
        if (normalized === 'sent' || normalized === 'đã gửi') {
            return { label: 'Đã gửi', tone: 'misa-apple-pill-blue' };
        }
        if (normalized === 'converted' || normalized === 'đã lập đh') {
            return { label: 'Đã lập ĐH', tone: 'misa-apple-pill-blue' };
        }
        if (normalized === 'accepted' || normalized === 'đã chấp nhận') {
            return { label: 'Đã chấp nhận', tone: 'misa-apple-pill-green' };
        }
        if (normalized === 'rejected' || normalized === 'đã từ chối') {
            return { label: 'Đã từ chối', tone: 'misa-apple-pill-red' };
        }
        if (normalized === 'active' || normalized === 'đang sử dụng') {
            return { label: 'Đang sử dụng', tone: 'misa-apple-pill-green' };
        }
        if (normalized === 'disposed' || normalized === 'đã ghi giảm') {
            return { label: 'Đã ghi giảm', tone: 'misa-apple-pill-gray' };
        }
        if (normalized === 'draft' || normalized === 'unposted' || normalized === 'bản nháp' || normalized === 'chưa ghi sổ') {
            return { label: defaultUnpostedLabel, tone: 'misa-apple-pill-orange' };
        }
        if (normalized === 'pending' || normalized === 'chờ duyệt') {
            return { label: 'Chờ duyệt', tone: 'misa-apple-pill-gray' };
        }
        return { label: statusSource, tone: 'misa-apple-pill-gray' };
    }

    if (!statusSource || typeof statusSource !== 'object') {
        return { label: '—', tone: 'misa-apple-pill-gray' };
    }

    const rawStatus = typeof statusSource.status === 'string' ? statusSource.status.trim().toLowerCase() : '';
    if (VOIDED_STATUSES.has(rawStatus)) {
        return { label: 'Đã hủy', tone: 'misa-apple-pill-red' };
    }
    if (rawStatus === 'disposed' || statusSource.is_active === false) {
        return { label: 'Đã ghi giảm', tone: 'misa-apple-pill-gray' };
    }
    if (statusSource.is_posted === true || rawStatus === 'posted') {
        return { label: 'Đã ghi sổ', tone: 'misa-apple-pill-green' };
    }
    if (rawStatus === 'approved') {
        return { label: 'Đã duyệt', tone: 'misa-apple-pill-blue' };
    }
    if (rawStatus === 'confirmed') {
        return { label: 'Đã xác nhận', tone: 'misa-apple-pill-blue' };
    }
    if (rawStatus === 'completed') {
        return { label: 'Hoàn thành', tone: 'misa-apple-pill-green' };
    }
    if (rawStatus === 'active') {
        return { label: 'Đang sử dụng', tone: 'misa-apple-pill-green' };
    }
    if (rawStatus === 'processing') {
        return { label: 'Đang xử lý', tone: 'misa-apple-pill-orange' };
    }
    if (rawStatus === 'pending') {
        return { label: 'Chờ duyệt', tone: 'misa-apple-pill-gray' };
    }
    if (statusSource.is_posted === false || rawStatus === 'draft' || rawStatus === 'unposted') {
        return { label: defaultUnpostedLabel, tone: 'misa-apple-pill-orange' };
    }

    return { label: rawStatus || '—', tone: 'misa-apple-pill-gray' };
}

export interface VoucherStatusBadgeProps {
    status?: VoucherStatusRecord | boolean | string | null;
    customLabel?: string;
    customTone?: 'misa-apple-pill-green' | 'misa-apple-pill-orange' | 'misa-apple-pill-red' | 'misa-apple-pill-blue' | 'misa-apple-pill-gray';
    defaultUnpostedLabel?: string;
    className?: string;
}

export const VoucherStatusBadge: React.FC<VoucherStatusBadgeProps> = ({
    status,
    customLabel,
    customTone,
    defaultUnpostedLabel = 'Chưa ghi sổ',
    className = '',
}) => {
    const info = resolveVoucherStatusInfo(status, defaultUnpostedLabel);
    const label = customLabel || info.label;
    const tone = customTone || info.tone;

    return (
        <span className={`misa-apple-pill ${tone} ${className}`.trim()}>
            <span className="misa-apple-pill-dot" />
            {label}
        </span>
    );
};

export default VoucherStatusBadge;
