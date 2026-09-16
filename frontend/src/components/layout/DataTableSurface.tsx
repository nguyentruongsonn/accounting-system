import React from 'react';

type DataTableSurfaceProps = {
  children: React.ReactNode;
  /** Omit with `undefined`; pass `null` to retain an intentionally empty summary region. */
  summary?: React.ReactNode;
  className?: string;
};

const DataTableSurface: React.FC<DataTableSurfaceProps> = ({ children, summary, className }) => (
  <section className={['ui-table-surface', className].filter(Boolean).join(' ')} data-ui="table-surface" data-surface="table" data-testid="ui-table-surface">
    <div className="ui-table-surface__scroll" data-ui="table-scroll" data-region="table-content" data-testid="ui-table-scroll">
      {children}
    </div>
    {summary !== undefined && (
      <div className="ui-table-surface__summary" data-ui="table-summary" data-region="summary" data-testid="ui-table-summary">
        {summary}
      </div>
    )}
  </section>
);

export default DataTableSurface;
