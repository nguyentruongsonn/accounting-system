import ReportsWorkspace from './ReportsWorkspace';

/** @deprecated Compatibility surface; use ReportsWorkspace directly. */
export default function BalanceSheet() {
    return <ReportsWorkspace requestedReportKey="balance_sheet" />;
}
