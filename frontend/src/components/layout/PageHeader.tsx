import React from 'react';

type PageHeaderTitle = Exclude<React.ReactNode, boolean | null | undefined>;

type PageHeaderProps = {
  eyebrow?: string;
  title: PageHeaderTitle;
  description?: string;
  extra?: React.ReactNode;
};

const PageHeader: React.FC<PageHeaderProps> = ({ eyebrow, title, description, extra }) => {
  if (
    title === null
    || title === undefined
    || typeof title === 'boolean'
    || (typeof title === 'string' && title.trim().length === 0)
  ) {
    throw new Error('PageHeader requires a title.');
  }

  return (
    <div className="page-title-content" data-ui="page-title-content" data-testid="page-title-content">
      {eyebrow && (
        <p className="page-title-content__eyebrow" data-region="eyebrow">
          {eyebrow}
        </p>
      )}
      <h1 className="page-title-content__title" data-region="title">
        {title}
      </h1>
      {description && (
        <p className="page-title-content__description" data-region="description">
          {description}
        </p>
      )}
      {extra && (
        <div className="page-title-content__extra" data-region="extra">
          {extra}
        </div>
      )}
    </div>
  );
};

export default PageHeader;
