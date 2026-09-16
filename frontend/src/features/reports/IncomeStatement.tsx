import ReportsWorkspace from './ReportsWorkspace';

/** @deprecated Compatibility surface; use ReportsWorkspace directly. */
export default function IncomeStatement() {
    return <ReportsWorkspace requestedReportKey="income_statement" />;
}
