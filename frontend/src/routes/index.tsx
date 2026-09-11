import { lazy, Suspense } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { useAuthStore } from '../store/useAuthStore';
import RouteLoadingFallback from '../components/common/RouteLoadingFallback';

const MainLayout = lazy(() => import('../layouts/MainLayout'));
const Login = lazy(() => import('../pages/auth/Login'));
const Register = lazy(() => import('../pages/auth/Register'));

// Route boundaries intentionally follow product modules so heavy accounting
// workspaces are fetched only when the user navigates to them.
const ChartOfAccounts = lazy(() => import('../features/master/ChartOfAccounts'));
const Customers = lazy(() => import('../features/master/Customers'));
const Suppliers = lazy(() => import('../features/master/Suppliers'));
const Employees = lazy(() => import('../features/master/Employees'));
const CashWorkspace = lazy(() => import('../features/cash/CashWorkspace'));
const CashReceipts = lazy(() => import('../features/cash/CashReceipts'));
const CashPayments = lazy(() => import('../features/cash/CashPayments'));
const JournalEntries = lazy(() => import('../features/gl/JournalEntries'));
const Dashboard = lazy(() => import('../features/dashboard/Dashboard'));
const CompanySettings = lazy(() => import('../features/settings/CompanySettings'));
const Periods = lazy(() => import('../features/gl/Periods'));
const BankCompatibilityBoundary = lazy(() => import('../features/bank/BankCompatibilityBoundary'));
const PurchaseWorkspace = lazy(() => import('../features/purchase/PurchaseWorkspace'));
const PurchaseInvoices = lazy(() => import('../features/purchase/PurchaseInvoices'));
const APAgingReport = lazy(() => import('../features/purchase/APAgingReport'));
const SalesWorkspace = lazy(() => import('../features/sales/SalesWorkspace'));
const SalesInvoices = lazy(() => import('../features/sales/SalesInvoices'));
const ARAgingReport = lazy(() => import('../features/sales/ARAgingReport'));
const Items = lazy(() => import('../features/inventory/Items'));
const InventoryWorkspace = lazy(() => import('../features/inventory/InventoryWorkspace'));
const InventoryReceipts = lazy(() => import('../features/inventory/InventoryReceipts'));
const InventoryIssues = lazy(() => import('../features/inventory/InventoryIssues'));
const InventoryTransfers = lazy(() => import('../features/inventory/InventoryTransfers'));
const InventoryStockCounts = lazy(() => import('../features/inventory/InventoryStockCounts'));
const StockReport = lazy(() => import('../features/inventory/StockReport'));
const FixedAssets = lazy(() => import('../features/assets/FixedAssets'));
const Tools = lazy(() => import('../features/assets/Tools'));
const FixedAssetWorkspace = lazy(() => import('../features/fixed-asset/FixedAssetWorkspace'));
const GLWorkspace = lazy(() => import('../features/gl/GLWorkspace'));
const ReportsWorkspace = lazy(() => import('../features/reports/ReportsWorkspace'));
const ApArReconciliationInputBoundary = lazy(() => import('../features/reports/ApArReconciliationInputBoundary'));
const InventorySubledgerGlReconciliation = lazy(() => import('../features/reports/InventorySubledgerGlReconciliation'));
const SystemOptions = lazy(() => import('../features/settings/SystemOptions'));
const RoleManagement = lazy(() => import('../features/settings/RoleManagement'));
const ApprovedAccountMappings = lazy(() => import('../features/settings/ApprovedAccountMappings'));
const AccountingAuditTrailExplorer = lazy(() => import('../features/settings/AccountingAuditTrailExplorer'));
const OpeningBalances = lazy(() => import('../features/settings/OpeningBalances'));
const InvoicesManagement = lazy(() => import('../features/invoices-management/InvoicesManagement'));

const ProtectedRoute = ({ children }: { children: React.ReactNode }) => {
    const { isAuthenticated } = useAuthStore();
    if (!isAuthenticated) {
        return <Navigate to="/login" replace />;
    }
    return <>{children}</>;
};

export const AppRoutes = () => {
    return (
        <BrowserRouter>
            <Suspense fallback={<RouteLoadingFallback fullScreen />}>
              <Routes>
                <Route path="/login" element={<Login />} />
                <Route path="/register" element={<Register />} />
                
                <Route
                    path="/"
                    element={
                        <ProtectedRoute>
                            <MainLayout />
                        </ProtectedRoute>
                    }
                >
                    <Route index element={<Dashboard />} />

                    {/* Cash Module */}
                    <Route path="cash" element={<CashWorkspace />} />
                    <Route path="cash/receipts" element={<CashReceipts />} />
                    <Route path="cash/payments" element={<CashPayments />} />

                    {/* Bank Module */}
                    <Route path="bank" element={<BankCompatibilityBoundary />} />
                    <Route path="bank/accounts" element={<BankCompatibilityBoundary />} />
                    <Route path="bank/receipts" element={<BankCompatibilityBoundary />} />
                    <Route path="bank/payments" element={<BankCompatibilityBoundary />} />
                    <Route path="bank/reconciliation" element={<BankCompatibilityBoundary />} />

                    {/* Purchase Module */}
                    <Route path="purchase" element={<PurchaseWorkspace />} />
                    <Route path="purchase/invoices" element={<PurchaseInvoices />} />
                    <Route path="purchase/ap-aging" element={<APAgingReport />} />

                    {/* Sales Module */}
                    <Route path="sales" element={<SalesWorkspace />} />
                    <Route path="sales/invoices" element={<SalesInvoices />} />
                    <Route path="sales/ar-aging" element={<ARAgingReport />} />

                    {/* Inventory Module */}
                    <Route path="inventory" element={<InventoryWorkspace />} />
                    <Route path="inventory/items" element={<Items />} />
                    <Route path="inventory/receipts" element={<InventoryReceipts />} />
                    <Route path="inventory/issues" element={<InventoryIssues />} />
                    <Route path="inventory/transfers" element={<InventoryTransfers />} />
                    <Route path="inventory/stock-counts" element={<InventoryStockCounts />} />
                    <Route path="inventory/stock-report" element={<StockReport />} />

                    {/* Fixed Assets Module */}
                    <Route path="fixed-assets" element={<FixedAssetWorkspace />} />

                    {/* General Ledger Module */}
                    <Route path="gl" element={<GLWorkspace />} />

                    {/* Reports Module */}
                    <Route path="reports" element={<ReportsWorkspace />} />
                    {/* Preserve saved links without exposing the statutory/ODR
                        readiness screen in the internal-core release profile. */}
                    <Route path="reports/statutory-readiness" element={<Navigate to="/reports" replace />} />
                    <Route path="reports/ap-ar-input-boundaries" element={<ApArReconciliationInputBoundary />} />
                    <Route path="reports/inventory-reconciliation" element={<InventorySubledgerGlReconciliation />} />
                    
                    {/* Dedicated tax workflows are out of the internal scope. Keep
                        old deep links deterministic by returning to the dashboard. */}
                    <Route path="tax/*" element={<Navigate to="/" replace />} />
                    {/* Assets */}
                    <Route path="assets/fixed" element={<FixedAssets />} />
                    <Route path="assets/tools" element={<Tools />} />
                    {/* Retained deep links redirect explicitly because these modules
                        are outside the internal-small-business scope. */}
                    <Route path="payroll/*" element={<Navigate to="/" replace />} />
                    <Route path="costing/*" element={<Navigate to="/" replace />} />
                    <Route path="budget/*" element={<Navigate to="/" replace />} />

                    {/* GL Module */}
                    <Route path="gl/journal-entries" element={<JournalEntries />} />
                    <Route path="gl/periods" element={<Periods />} />

                    {/* Reports Module */}
                    {/* Compatibility routes keep saved deep links, but all report
                        access is now routed through the capability-gated workspace. */}
                    <Route path="reports/general-journal" element={<ReportsWorkspace requestedReportKey="general_journal" />} />
                    <Route path="reports/general-ledger" element={<ReportsWorkspace requestedReportKey="general_ledger" />} />
                    <Route path="reports/trial-balance" element={<ReportsWorkspace requestedReportKey="trial_balance" />} />
                    <Route path="reports/balance-sheet" element={<ReportsWorkspace requestedReportKey="balance_sheet" />} />
                    <Route path="reports/income-statement" element={<ReportsWorkspace requestedReportKey="income_statement" />} />

                    {/* Master Data */}
                    <Route path="master/accounts" element={<ChartOfAccounts />} />
                    <Route path="master/customers" element={<Customers />} />
                    <Route path="master/suppliers" element={<Suppliers />} />
                    <Route path="master/employees" element={<Employees />} />

                    {/* Settings */}
                    <Route path="settings/company" element={<CompanySettings />} />
                    <Route path="settings/opening-balances" element={<OpeningBalances />} />
                    <Route path="settings/options" element={<SystemOptions />} />
                    <Route path="settings/roles" element={<RoleManagement />} />
                    <Route path="settings/account-mappings" element={<ApprovedAccountMappings />} />
                    {/* Legacy deep links now render the same single MISA-style COA
                        workbench, so users do not land on a second, inconsistent UI. */}
                    <Route path="settings/account-catalogues" element={<ChartOfAccounts />} />
                    <Route path="settings/audit-trail" element={<AccountingAuditTrailExplorer />} />
                    <Route path="invoices-management" element={<InvoicesManagement />} />
                </Route>
              </Routes>
            </Suspense>
        </BrowserRouter>
    );
};
