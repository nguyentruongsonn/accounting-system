import React from 'react';

type PageToolbarProps = {
  leading?: React.ReactNode;
  filters?: React.ReactNode;
  actions?: React.ReactNode;
  className?: string;
};

const PageToolbar: React.FC<PageToolbarProps> = ({ leading, filters, actions, className }) => {
  return (
    <div className={['ui-page-toolbar', className].filter(Boolean).join(' ')} data-ui="page-toolbar" data-layout="toolbar" data-testid="ui-page-toolbar">
      {leading !== undefined && (
        <div className="ui-page-toolbar__leading" data-ui="page-toolbar-leading" data-region="leading">
          {leading}
        </div>
      )}
      {filters !== undefined && (
        <div className="ui-page-toolbar__filters" data-ui="page-toolbar-filters" data-region="filters">
          {filters}
        </div>
      )}
      {actions !== undefined && (
        <div className="ui-page-toolbar__actions" data-ui="page-toolbar-actions" data-region="actions">
          {actions}
        </div>
      )}
    </div>
  );
};

export default PageToolbar;
