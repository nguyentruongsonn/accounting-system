import React from 'react';
import { useNavigate } from 'react-router-dom';
import { Tag } from 'antd';
import { ArrowRightOutlined } from '@ant-design/icons';

export interface WorkflowNode {
  id: string;
  label: string;
  badge?: string;
  route?: string;
  onClick?: () => void;
  description?: string;
  icon?: React.ReactNode;
}

export interface WorkflowRow {
  nodes: (WorkflowNode | null)[];
  connectors?: ('arrow' | 'none')[];
}

interface WorkflowDiagramProps {
  rows: WorkflowRow[];
  verticalConnectors?: { rowFrom: number; colIndex: number }[];
}

const WorkflowDiagram: React.FC<WorkflowDiagramProps> = ({ rows }) => {
  const navigate = useNavigate();

  return (
    <div style={{
      background: '#FFFFFF',
      borderRadius: 12,
      border: '1px solid #E2E8F0',
      padding: '24px',
      marginBottom: 20,
      boxShadow: '0 1px 3px 0 rgba(0, 0, 0, 0.04)',
      overflowX: 'auto',
    }}>
      <div style={{
        display: 'flex',
        flexDirection: 'column',
        gap: 20,
        minWidth: 800,
        alignItems: 'center',
      }}>
        {rows.map((row, rowIdx) => (
          <div 
            key={`wf-row-${rowIdx}`} 
            style={{ 
              display: 'flex', 
              alignItems: 'center', 
              justifyContent: 'center', 
              gap: 16,
              width: '100%' 
            }}
          >
            {row.nodes.map((node, colIdx) => {
              const isLast = colIdx === row.nodes.length - 1;
              const connector = row.connectors?.[colIdx] ?? 'arrow';
              const isClickable = Boolean(node?.route || node?.onClick);

              return (
                <React.Fragment key={`wf-col-${rowIdx}-${colIdx}`}>
                  {node ? (
                    <div
                      onClick={() => {
                        if (node.onClick) {
                          node.onClick();
                        } else if (node.route) {
                          navigate(node.route);
                        }
                      }}
                      style={{
                        width: 170,
                        minHeight: 84,
                        background: isClickable ? '#FFFFFF' : '#F8FAFC',
                        border: '1px solid #CBD5E1',
                        borderRadius: 10,
                        padding: '10px 12px',
                        display: 'flex',
                        flexDirection: 'column',
                        justifyContent: 'center',
                        cursor: isClickable ? 'pointer' : 'default',
                        transition: 'all 0.2s cubic-bezier(0.4, 0, 0.2, 1)',
                        position: 'relative',
                        boxShadow: isClickable ? '0 2px 4px rgba(0, 0, 0, 0.04)' : 'none',
                      }}
                      onMouseEnter={(e) => {
                        if (isClickable) {
                          e.currentTarget.style.borderColor = '#1677FF';
                          e.currentTarget.style.transform = 'translateY(-2px)';
                          e.currentTarget.style.boxShadow = '0 6px 16px rgba(22, 119, 255, 0.16)';
                        }
                      }}
                      onMouseLeave={(e) => {
                        if (isClickable) {
                          e.currentTarget.style.borderColor = '#CBD5E1';
                          e.currentTarget.style.transform = 'translateY(0)';
                          e.currentTarget.style.boxShadow = '0 2px 4px rgba(0, 0, 0, 0.04)';
                        }
                      }}
                    >
                      {node.badge && (
                        <div style={{ position: 'absolute', top: 6, right: 6 }}>
                          <Tag color="blue" style={{ borderRadius: 4, margin: 0, fontSize: 10, lineHeight: '16px', padding: '0 4px' }}>
                            {node.badge}
                          </Tag>
                        </div>
                      )}
                      <div style={{ 
                        fontSize: 13, 
                        fontWeight: 600, 
                        color: isClickable ? '#0F172A' : '#64748B', 
                        lineHeight: 1.3,
                        paddingRight: node.badge ? 24 : 0,
                      }}>
                        {node.label}
                      </div>
                      {node.description && (
                        <div style={{ fontSize: 11, color: '#94A3B8', marginTop: 4 }}>
                          {node.description}
                        </div>
                      )}
                    </div>
                  ) : (
                    <div style={{ width: 170, minHeight: 84 }} />
                  )}

                  {!isLast && connector === 'arrow' && (
                    <div style={{ display: 'flex', alignItems: 'center', color: '#94A3B8' }}>
                      <ArrowRightOutlined style={{ fontSize: 14 }} />
                    </div>
                  )}
                </React.Fragment>
              );
            })}
          </div>
        ))}
      </div>
    </div>
  );
};

export default WorkflowDiagram;
