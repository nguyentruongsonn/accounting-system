export type VoucherAction =
    | 'save'
    | 'save-and-new'
    | 'add-line'
    | 'edit'
    | 'delete'
    | 'post'
    | 'unpost';

export interface VoucherActionContext {
    status?: string;
    isPosted?: boolean;
    isLocked?: boolean;
    mode?: 'create' | 'edit' | 'view';
    hasIdentity?: boolean;
}

export interface VoucherActionDecision {
    allowed: boolean;
    reason?: string;
}

const blocked = (reason: string): VoucherActionDecision => ({ allowed: false, reason });

export const getVoucherActionDecision = (
    action: VoucherAction,
    context: VoucherActionContext,
): VoucherActionDecision => {
    const status = String(context.status || '').toLowerCase();
    const isPosted = context.isPosted ?? status === 'posted';
    const isVoided = ['voided', 'void', 'cancelled', 'canceled'].includes(status);

    if (context.isLocked) {
        return blocked('Kỳ kế toán đã khóa. Không thể thay đổi chứng từ trong kỳ này.');
    }

    if (['save', 'save-and-new', 'add-line'].includes(action)) {
        if (context.mode === 'view') {
            return blocked('Chứng từ đang ở chế độ xem. Hãy chọn chứng từ nháp để chỉnh sửa.');
        }
        if (isPosted || isVoided) {
            return blocked('Chứng từ đã ghi sổ hoặc đã hủy nên không thể chỉnh sửa trực tiếp.');
        }
    }

    if (action === 'edit' || action === 'delete') {
        if (!context.hasIdentity) {
            return blocked('Chưa chọn chứng từ để thực hiện thao tác.');
        }
        if (isPosted) {
            return blocked('Chứng từ đã ghi sổ. Phải bỏ ghi sổ hợp lệ trước khi sửa hoặc xóa.');
        }
        if (isVoided) {
            return blocked('Chứng từ đã hủy nên không thể sửa hoặc xóa.');
        }
    }

    if (action === 'post') {
        if (!context.hasIdentity) {
            return blocked('Phải lưu chứng từ trước khi ghi sổ.');
        }
        if (isPosted) {
            return blocked('Chứng từ đã được ghi sổ.');
        }
        if (isVoided) {
            return blocked('Chứng từ đã hủy nên không thể ghi sổ.');
        }
    }

    if (action === 'unpost') {
        if (!context.hasIdentity) {
            return blocked('Chưa chọn chứng từ để bỏ ghi sổ.');
        }
        if (!isPosted) {
            return blocked('Chỉ chứng từ đã ghi sổ mới có thể bỏ ghi sổ.');
        }
    }

    return { allowed: true };
};

export const runVoucherAction = (
    action: VoucherAction,
    context: VoucherActionContext,
    execute: () => void,
    onBlocked: (reason: string) => void,
) => {
    const decision = getVoucherActionDecision(action, context);
    if (!decision.allowed) {
        onBlocked(decision.reason || 'Thao tác không được phép với trạng thái chứng từ hiện tại.');
        return false;
    }

    execute();
    return true;
};
