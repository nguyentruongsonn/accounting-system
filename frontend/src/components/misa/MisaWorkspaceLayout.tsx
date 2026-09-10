import React from 'react';

export interface WorkspaceTab {
    key: string;
    label: string;
}

export interface MisaWorkspaceLayoutProps {
    tabs: WorkspaceTab[];
    activeTabKey: string;
    onTabChange: (key: string) => void;
    extraHeaderRight?: React.ReactNode;
    toolbar?: React.ReactNode;
    children: React.ReactNode;
}

export const MisaWorkspaceLayout: React.FC<MisaWorkspaceLayoutProps> = ({
    tabs,
    activeTabKey,
    onTabChange,
    extraHeaderRight,
    toolbar,
    children
}) => {
    return (
        <div className="ui-workspace flex flex-col flex-1" data-ui="workspace">
            <div className="ui-workspace-frame">
                {/* Native MISA Ultra-Fast Tab Bar */}
                <div className="misa-workspace-tab-nav ui-workspace-tabs" data-ui="workspace-tabs">
                    <div className="misa-workspace-tab-list" role="tablist" aria-label="Workspace tabs">
                        {tabs.map((tab) => (
                            <button
                                key={tab.key}
                                type="button"
                                className={`misa-workspace-tab-item ${activeTabKey === tab.key ? 'active' : ''}`}
                                role="tab"
                                aria-selected={activeTabKey === tab.key}
                                aria-controls="workspace-body"
                                tabIndex={activeTabKey === tab.key ? 0 : -1}
                                onClick={() => onTabChange(tab.key)}
                            >
                                {tab.label}
                            </button>
                        ))}
                    </div>
                    <div className="misa-workspace-tab-extra">
                        {extraHeaderRight}
                    </div>
                </div>

                {/* Tab Panels Container */}
                {toolbar}
                <div id="workspace-body" className="misa-tab-panel-container ui-workspace-body" data-ui="workspace-body">
                    {children}
                </div>
            </div>
        </div>
    );
};

export default MisaWorkspaceLayout;
