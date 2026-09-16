import { NavLink, useInRouterContext } from 'react-router-dom';
import type { ReactNode } from 'react';

const catalogues = [
  ['/master/customers', 'Khách hàng'],
  ['/master/suppliers', 'Nhà cung cấp'],
  ['/master/employees', 'Nhân viên'],
];

function NavigationFrame({ children }: { children: ReactNode }) {
  return (
    <nav className="misa-workspace-tab-nav ui-workspace-tabs" aria-label="Danh mục">
      <div className="misa-workspace-tab-list">{children}</div>
    </nav>
  );
}

function RoutedNavigation() {
  return <NavigationFrame>
    {catalogues.map(([to, label]) => (
      <NavLink key={to} to={to} className={({ isActive }) => `misa-workspace-tab-item${isActive ? ' active' : ''}`}>
        {label}
      </NavLink>
    ))}
  </NavigationFrame>;
}

export default function MasterDataNavigation() {
  const inRouter = useInRouterContext();
  if (inRouter) return <RoutedNavigation />;

  // Pages are normally mounted below the application Router.  Keeping this
  // fallback empty makes isolated previews/tests safe without introducing
  // navigation links that cannot actually route anywhere.
  return null;
}
