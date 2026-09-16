import React from 'react';

type PageShellProps = {
  title?: React.ReactNode;
  toolbar?: React.ReactNode;
  navigation?: React.ReactNode;
  children: React.ReactNode;
  className?: string;
  /** Render the page contents inside an existing shell (workspace tab mode). */
  embedded?: boolean;
};

const PageShell: React.FC<PageShellProps> = ({ toolbar, navigation, children, className, embedded = false }) => {
  if (embedded) {
    // Embedded pages are mounted inside a workspace that owns the page regions.
    // Their local toolbar is intentionally ignored so it cannot be rendered in
    // the workspace body as a second, visually disconnected toolbar.
    return <>{children}</>;
  }

  return (
    <main className={['ui-page-shell', className].filter(Boolean).join(' ')} data-ui="page-shell" data-surface="page" data-testid="ui-page-shell">
      {navigation}
      {toolbar !== undefined && (
        <div className="ui-page-toolbar-slot" data-region="toolbar">
          {toolbar}
        </div>
      )}
      <section className="ui-page-body" data-ui="page-body" data-region="body">
        {children}
      </section>
    </main>
  );
};

export default PageShell;
