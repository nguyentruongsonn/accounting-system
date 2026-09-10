import React from 'react';

export interface MisaMasterCardProps {
    children: React.ReactNode;
    className?: string;
}

export interface MasterTabItem {
    key: string;
    label: string;
}

export interface MisaMasterTabsProps {
    tabs: MasterTabItem[];
    activeKey: string;
    onChange: (key: string) => void;
}

export const MisaMasterCard: React.FC<MisaMasterCardProps> & {
    Left: React.FC<{ children: React.ReactNode }>;
    Right: React.FC<{ children: React.ReactNode }>;
    Tabs: React.FC<MisaMasterTabsProps>;
    FormGrid: React.FC<{ children: React.ReactNode }>;
    MetaRow: React.FC<{ label: string; children: React.ReactNode; required?: boolean }>;
} = ({ children, className = '' }) => {
    return (
        <div className={`misa-master-card ${className}`}>
            {children}
        </div>
    );
};

const Left: React.FC<{ children: React.ReactNode }> = ({ children }) => (
    <div className="misa-master-left">
        {children}
    </div>
);

const Right: React.FC<{ children: React.ReactNode }> = ({ children }) => (
    <div className="misa-master-right">
        {children}
    </div>
);

const Tabs: React.FC<MisaMasterTabsProps> = ({ tabs, activeKey, onChange }) => (
    <div style={{ display: 'flex', gap: 6, marginBottom: 8, borderBottom: '1px solid #ced1d6' }}>
        {tabs.map(tab => (
            <button
                key={tab.key}
                type="button"
                className={`misa-master-tab-btn ${activeKey === tab.key ? 'active' : ''}`}
                onClick={() => onChange(tab.key)}
            >
                {tab.label}
            </button>
        ))}
    </div>
);

const FormGrid: React.FC<{ children: React.ReactNode }> = ({ children }) => (
    <div className="misa-form-grid">
        {children}
    </div>
);

const MetaRow: React.FC<{ label: string; children: React.ReactNode; required?: boolean }> = ({
    label,
    children,
    required = false
}) => (
    <div className="misa-meta-row">
        <span className={`misa-field-label ${required ? 'required' : ''}`}>
            {label}:
        </span>
        <div>
            {children}
        </div>
    </div>
);

MisaMasterCard.Left = Left;
MisaMasterCard.Right = Right;
MisaMasterCard.Tabs = Tabs;
MisaMasterCard.FormGrid = FormGrid;
MisaMasterCard.MetaRow = MetaRow;

export default MisaMasterCard;
