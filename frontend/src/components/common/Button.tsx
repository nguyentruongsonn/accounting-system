import React from 'react';

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'secondary' | 'link';
  children?: React.ReactNode;
  icon?: React.ReactNode;
  fullWidth?: boolean;
}

export const Button: React.FC<ButtonProps> = ({
  variant = 'primary',
  children,
  icon,
  fullWidth = false,
  className = '',
  style,
  disabled,
  ...props
}) => {
  let baseClass = 'ds-btn-primary';
  if (variant === 'secondary') baseClass = 'ds-btn-secondary';
  if (variant === 'link') baseClass = 'ds-link';

  return (
    <button
      className={[baseClass, className].filter(Boolean).join(' ')}
      disabled={disabled}
      style={{
        width: fullWidth ? '100%' : undefined,
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.6 : 1,
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center',
        gap: 6,
        ...style,
      }}
      {...props}
    >
      {icon && <span className="ds-btn-icon">{icon}</span>}
      {children}
    </button>
  );
};

export default Button;
