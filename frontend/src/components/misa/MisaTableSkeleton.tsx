import React from 'react';
import { Skeleton } from 'antd';
import './MisaTableSkeleton.css';

interface MisaTableSkeletonProps {
    hasStats?: boolean;
    hasDetailPane?: boolean;
    rowCount?: number;
}

export const MisaTableSkeleton: React.FC<MisaTableSkeletonProps> = ({
    hasStats = true,
    hasDetailPane = true,
    rowCount = 8
}) => {
    return (
        <div className="misa-skeleton-container">
            {/* Top Stat Cards Skeleton */}
            {hasStats && (
                <div className="misa-stat-grid-3 misa-skeleton-stats">
                    {[1, 2, 3].map(i => (
                        <div key={`skel-stat-${i}`} className="misa-stat-card misa-skeleton-card">
                            <div className="misa-flex-center misa-gap-12">
                                <Skeleton.Avatar active shape="square" size={38} />
                                <div style={{ flex: 1 }}>
                                    <Skeleton.Input active size="small" style={{ width: 140, height: 14, marginBottom: 6 }} />
                                    <Skeleton.Input active size="default" style={{ width: 180, height: 22 }} />
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {/* Toolbar Skeleton */}
            <div className="misa-toolbar-header misa-skeleton-toolbar">
                <div className="misa-flex-center misa-gap-8">
                    <Skeleton.Input active size="small" style={{ width: 120, height: 28 }} />
                    <Skeleton.Input active size="small" style={{ width: 130, height: 28 }} />
                    <Skeleton.Button active size="small" style={{ width: 32, height: 28 }} />
                </div>
                <div className="misa-flex-center misa-gap-8">
                    <Skeleton.Input active size="small" style={{ width: 220, height: 28 }} />
                    <Skeleton.Button active size="small" style={{ width: 32, height: 28 }} />
                    <Skeleton.Button active size="small" style={{ width: 32, height: 28 }} />
                    <Skeleton.Button active size="small" style={{ width: 110, height: 28 }} />
                    <Skeleton.Button active size="small" style={{ width: 110, height: 28 }} />
                </div>
            </div>

            {/* Main Table Skeleton */}
            <div className="misa-table-card misa-skeleton-table-card">
                <div className="misa-skeleton-table-header">
                    <div style={{ width: 110 }}><Skeleton.Input active size="small" style={{ width: 90, height: 16 }} /></div>
                    <div style={{ width: 110 }}><Skeleton.Input active size="small" style={{ width: 90, height: 16 }} /></div>
                    <div style={{ width: 110 }}><Skeleton.Input active size="small" style={{ width: 80, height: 16 }} /></div>
                    <div style={{ width: 110 }}><Skeleton.Input active size="small" style={{ width: 90, height: 16 }} /></div>
                    <div style={{ flex: 1 }}><Skeleton.Input active size="small" style={{ width: '60%', height: 16 }} /></div>
                    <div style={{ flex: 1.2 }}><Skeleton.Input active size="small" style={{ width: '70%', height: 16 }} /></div>
                    <div style={{ width: 130 }}><Skeleton.Input active size="small" style={{ width: 100, height: 16 }} /></div>
                    <div style={{ width: 100 }}><Skeleton.Input active size="small" style={{ width: 70, height: 16 }} /></div>
                    <div style={{ width: 110 }}><Skeleton.Input active size="small" style={{ width: 70, height: 16 }} /></div>
                </div>

                <div className="misa-skeleton-table-body">
                    {Array.from({ length: rowCount }).map((_, idx) => (
                        <div key={`skel-row-${idx}`} className="misa-skeleton-table-row">
                            <div style={{ width: 110 }}><Skeleton.Input active size="small" style={{ width: 80, height: 14 }} /></div>
                            <div style={{ width: 110 }}><Skeleton.Input active size="small" style={{ width: 80, height: 14 }} /></div>
                            <div style={{ width: 110 }}><Skeleton.Input active size="small" style={{ width: 70, height: 14 }} /></div>
                            <div style={{ width: 110 }}><Skeleton.Input active size="small" style={{ width: 70, height: 14 }} /></div>
                            <div style={{ flex: 1 }}><Skeleton.Input active size="small" style={{ width: '85%', height: 14 }} /></div>
                            <div style={{ flex: 1.2 }}><Skeleton.Input active size="small" style={{ width: '90%', height: 14 }} /></div>
                            <div style={{ width: 130 }}><Skeleton.Input active size="small" style={{ width: 95, height: 14 }} /></div>
                            <div style={{ width: 100 }}><Skeleton.Input active size="small" style={{ width: 60, height: 14 }} /></div>
                            <div style={{ width: 110 }}><Skeleton.Input active size="small" style={{ width: 60, height: 14 }} /></div>
                        </div>
                    ))}
                </div>
            </div>

            {/* Bottom Detail Pane Skeleton */}
            {hasDetailPane && (
                <div className="misa-skeleton-detail-pane">
                    <div className="misa-skeleton-detail-header">
                        <Skeleton.Input active size="small" style={{ width: 260, height: 16 }} />
                    </div>
                    <div className="misa-skeleton-detail-table">
                        <div key="skel-dt-1" className="misa-skeleton-detail-row">
                            <Skeleton.Input active size="small" style={{ width: '100%', height: 26 }} />
                        </div>
                        <div key="skel-dt-2" className="misa-skeleton-detail-row">
                            <Skeleton.Input active size="small" style={{ width: '100%', height: 26 }} />
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};
export default MisaTableSkeleton;

