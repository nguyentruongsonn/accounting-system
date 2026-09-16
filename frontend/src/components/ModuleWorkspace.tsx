import React from 'react';
import { Tabs } from 'antd';
import type { TabsProps } from 'antd';

interface ModuleWorkspaceProps {
  title?: string;
  items: TabsProps['items'];
  defaultActiveKey?: string;
  /**
   * Use a controlled tab when a module has durable deep links.  Keeping this
   * optional preserves the behaviour of older workspaces that intentionally
   * manage their own initial tab only.
   */
  activeKey?: string;
  onChange?: (key: string) => void;
  nativeTabs?: boolean;
}

const workspacePanelId = (key: string) => `workspace-panel-${key.replace(/[^a-zA-Z0-9_-]/g, '-')}`;

const ModuleWorkspace: React.FC<ModuleWorkspaceProps> = ({ 
  title,
  items, 
  defaultActiveKey = '1',
  activeKey,
  onChange,
  nativeTabs = false,
}) => {
  const workspaceItems = items?.map((item) => {
    if (item === null) return item;

    return {
      ...item,
      children: <div id={workspacePanelId(item.key)} className="ui-workspace-body" data-ui="workspace-body">{item.children}</div>,
    };
  });

  return (
    <div className="ui-workspace flex flex-col flex-1" data-ui="workspace">
      {title && (
        <div className="apple-page-title" data-testid="module-workspace-title">
          {title}
        </div>
      )}
      <div className="ui-workspace-frame">
        <Tabs
          defaultActiveKey={defaultActiveKey}
          activeKey={activeKey}
          items={workspaceItems}
          onChange={onChange}
          className="ui-workspace-tabs-root h-full"
          renderTabBar={(tabBarProps, DefaultTabBar) => nativeTabs ? (
            <div className="misa-workspace-tab-nav ui-workspace-tabs" data-ui="workspace-tabs">
              <div className="misa-workspace-tab-list" role="tablist" aria-label={title ?? 'Workspace tabs'}>
                {items?.map((item) => item && (
                  <button key={item.key} type="button" disabled={item.disabled}
                    className={`misa-workspace-tab-item ${tabBarProps.activeKey === item.key ? 'active' : ''}`}
                    role="tab"
                    aria-selected={tabBarProps.activeKey === item.key}
                    aria-controls={workspacePanelId(item.key)}
                    tabIndex={tabBarProps.activeKey === item.key ? 0 : -1}
                    onClick={(event) => tabBarProps.onTabClick?.(item.key, event)}>
                    {item.label}
                  </button>
                ))}
              </div>
            </div>
          ) : (
            <div className="ui-workspace-tabs" data-ui={'workspace-tabs'}>
              <DefaultTabBar {...tabBarProps} />
            </div>
          )}
        />
      </div>
    </div>
  );
};

export default ModuleWorkspace;
