import ReportsWorkspace from './ReportsWorkspace';

/** @deprecated Compatibility surface; use ReportsWorkspace directly. */
export default function TrialBalance() {
    return <ReportsWorkspace requestedReportKey="trial_balance" />;
}
