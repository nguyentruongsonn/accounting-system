import { Modal as AntModal } from 'antd';
import type { ModalFuncProps, ModalProps } from 'antd';
import type { CSSProperties } from 'react';

/** Presentation boundary only: form ownership, close/save callbacks, static
 * confirmation promises and destruction behavior remain owned by Ant Modal. */
function AppModalView({ className, width, style, centered = true, ...props }: ModalProps) {
  const voucher = /(?:voucher|misa-modal$|modal-drawer)/.test(className ?? '')
    || (typeof width === 'string' && /vw|%/.test(width))
    || (typeof width === 'number' && width >= 1100);
  const resolvedWidth = width ?? (voucher ? 1440 : 640);
  const cssWidth = typeof resolvedWidth === 'number' ? `${resolvedWidth}px`
    : typeof resolvedWidth === 'string' ? resolvedWidth : undefined;

  return (
    <AntModal
      {...props}
      centered={centered}
      width={resolvedWidth}
      className={['app-modal', cssWidth && 'app-modal--fixed-width', voucher && 'app-modal--voucher', className].filter(Boolean).join(' ')}
      style={{ ...style, '--app-modal-width': cssWidth } as CSSProperties}
    />
  );
}

const confirmation = (method: 'confirm' | 'info' | 'success' | 'error' | 'warning' | 'warn') =>
  (props: ModalFuncProps) => AntModal[method]({
    ...props,
    centered: props.centered ?? true,
    className: ['app-modal', 'app-modal--confirmation', props.className].filter(Boolean).join(' '),
  });

const AppModal = Object.assign(AppModalView, {
  confirm: confirmation('confirm'),
  info: confirmation('info'),
  success: confirmation('success'),
  error: confirmation('error'),
  warning: confirmation('warning'),
  warn: confirmation('warn'),
  useModal: AntModal.useModal,
  destroyAll: AntModal.destroyAll,
  config: AntModal.config,
});

export default AppModal;
