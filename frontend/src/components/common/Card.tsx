import React from 'react';

export interface CardProps extends React.HTMLAttributes<HTMLDivElement> {
  children?: React.ReactNode;
}

export const Card: React.FC<CardProps> = ({
  children,
  className = '',
  style,
  ...props
}) => {
  return (
    <div
      className={['ds-card', className].filter(Boolean).join(' ')}
      style={style}
      {...props}
    >
      {children}
    </div>
  );
};

export default Card;
