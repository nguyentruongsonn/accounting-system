import React from 'react';

/**
 * Legacy compatibility surface.
 *
 * PurchaseWorkspace uses PurchaseDashboard, which reads the server dashboard
 * endpoint. This older tab had no data source and rendered hard-coded
 * suppliers, amounts, and KPIs; keeping that display would make accounting
 * evidence look persisted when it is not.
 */
export const PurchaseDashboardTab: React.FC = () => (
    <div className="misa-page-layout-gray" />
);

export default PurchaseDashboardTab;
