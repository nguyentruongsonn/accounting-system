import React from 'react';

type ModalFrameProps = {
  children: React.ReactNode;
  /** Omit with `undefined`; pass `null` to retain an intentionally empty footer region. */
  footer?: React.ReactNode;
  className?: string;
  bodyStyle?: React.CSSProperties;
  footerStyle?: React.CSSProperties;
};

const ModalFrame: React.FC<ModalFrameProps> = ({ children, footer, className, bodyStyle, footerStyle }) => (
  <div className={['ui-modal-frame', className].filter(Boolean).join(' ')} data-ui="modal-frame" data-surface="modal" data-testid="ui-modal-frame">
    <div className="ui-modal-frame__body" style={bodyStyle} data-ui="modal-body" data-region="body" data-testid="ui-modal-body">
      {children}
    </div>
    {footer !== undefined && (
      <div className="ui-modal-frame__footer" style={footerStyle} data-ui="modal-footer" data-region="footer" data-testid="ui-modal-footer">
        {footer}
      </div>
    )}
  </div>
);

export default ModalFrame;
