import React from 'react';

export interface PageHeaderProps {
  title: string;
  subtitle?: string;
  icon?: React.ReactNode;
  extra?: React.ReactNode;
}

const PageHeader: React.FC<PageHeaderProps> = ({ title, subtitle, icon, extra }) => {
  return (
    <div style={{ 
      display: 'flex', 
      justifyContent: 'space-between', 
      alignItems: 'center', 
      marginBottom: 16,
      background: '#FFFFFF',
      padding: '14px 20px',
      borderRadius: 10,
      border: '1px solid #E2E8F0',
      boxShadow: '0 1px 2px 0 rgba(0, 0, 0, 0.03)'
    }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
        {icon && (
          <div style={{ 
            width: 38,
            height: 38,
            borderRadius: 8,
            background: 'linear-gradient(135deg, #E6F4FF 0%, #BAE0FF 100%)',
            color: '#1677FF',
            display: 'grid',
            placeItems: 'center',
            fontSize: 20,
            boxShadow: '0 2px 6px rgba(22, 119, 255, 0.12)'
          }}>
            {icon}
          </div>
        )}
        <div>
          <h2 style={{ 
            margin: 0, 
            fontSize: 17, 
            fontWeight: 700, 
            color: '#0F172A',
            letterSpacing: '-0.01em'
          }}>
            {title}
          </h2>
          {subtitle && (
            <div style={{ 
              fontSize: 12, 
              color: '#64748B',
              marginTop: 2
            }}>
              {subtitle}
            </div>
          )}
        </div>
      </div>
      {extra && <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>{extra}</div>}
    </div>
  );
};

export default PageHeader;
