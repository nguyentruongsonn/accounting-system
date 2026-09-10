import { useEffect } from 'react';

export interface VoucherShortcutsConfig {
    onSave?: () => void;           // Ctrl + S or F8 (Cất)
    onSaveAndNew?: () => void;     // Ctrl + Shift + S (Cất và Thêm)
    onPrint?: () => void;          // Ctrl + P (Cất và In)
    onAddLine?: () => void;        // Insert or F7 (Thêm dòng)
    onDeleteLine?: () => void;     // Ctrl + Delete or Shift + Delete (Xóa dòng)
    onPost?: () => void;           // Ctrl + G or F9 (Ghi sổ)
    onUnpost?: () => void;         // Ctrl + B (Bỏ ghi sổ)
    onEdit?: () => void;           // Ctrl + E (Sửa)
    onDelete?: () => void;         // Ctrl + D (Xóa)
    onClose?: () => void;          // Esc (Đóng modal/drawer)
    enabled?: boolean;
}

export const useVoucherShortcuts = ({
    onSave,
    onSaveAndNew,
    onPrint,
    onAddLine,
    onDeleteLine,
    onPost,
    onUnpost,
    onEdit,
    onDelete,
    onClose,
    enabled = true
}: VoucherShortcutsConfig) => {
    useEffect(() => {
        if (!enabled) return;

        const handleKeyDown = (e: KeyboardEvent) => {
            // Esc -> Close modal / drawer
            if (e.key === 'Escape' && onClose) {
                e.preventDefault();
                onClose();
                return;
            }

            // Insert or F7 -> Add Line
            if ((e.key === 'Insert' || e.key === 'F7') && onAddLine) {
                e.preventDefault();
                onAddLine();
                return;
            }

            // Ctrl + Delete or Shift + Delete -> Delete Line
            if (((e.ctrlKey && e.key === 'Delete') || (e.shiftKey && e.key === 'Delete')) && onDeleteLine) {
                e.preventDefault();
                onDeleteLine();
                return;
            }

            // Ctrl + G or F9 -> Post to GL
            if (((e.ctrlKey && (e.key === 'G' || e.key === 'g')) || e.key === 'F9') && onPost) {
                e.preventDefault();
                onPost();
                return;
            }

            // Ctrl + B -> Unpost
            if (e.ctrlKey && (e.key === 'B' || e.key === 'b') && onUnpost) {
                e.preventDefault();
                onUnpost();
                return;
            }

            // Ctrl + E -> Edit
            if (e.ctrlKey && (e.key === 'E' || e.key === 'e') && onEdit) {
                e.preventDefault();
                onEdit();
                return;
            }

            // Ctrl + D -> Delete
            if (e.ctrlKey && (e.key === 'D' || e.key === 'd') && onDelete) {
                e.preventDefault();
                onDelete();
                return;
            }

            // Ctrl + Shift + S -> Save and New
            if (e.ctrlKey && e.shiftKey && (e.key === 'S' || e.key === 's') && onSaveAndNew) {
                e.preventDefault();
                onSaveAndNew();
                return;
            }

            // Ctrl + S or F8 -> Save (Cất chứng từ chuẩn MISA AMIS)
            if (((e.ctrlKey && !e.shiftKey && (e.key === 'S' || e.key === 's')) || e.key === 'F8') && onSave) {
                e.preventDefault();
                onSave();
                return;
            }

            // Ctrl + P -> Print (Cất và In)
            if (e.ctrlKey && (e.key === 'P' || e.key === 'p') && onPrint) {
                e.preventDefault();
                onPrint();
                return;
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [onSave, onSaveAndNew, onPrint, onAddLine, onDeleteLine, onPost, onUnpost, onEdit, onDelete, onClose, enabled]);
};

export const useVoucherHotkeys = useVoucherShortcuts;

export default useVoucherShortcuts;
