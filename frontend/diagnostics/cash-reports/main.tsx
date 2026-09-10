import { useState } from 'react';
import { createRoot } from 'react-dom/client';
import { ConfigProvider, Select } from 'antd';
import viVN from 'antd/locale/vi_VN';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AdaptivePopupViewport } from '../../src/components/layout/AdaptivePopupViewport';
import { AdaptiveSelect } from '../../src/components/layout/AdaptiveSelect';
import { AccountSelect } from '../../src/components/misa/AccountSelect';
import { CashReportResult } from '../../src/features/cash/CashReportResult';
import { CashReportSelector } from '../../src/features/cash/CashReportSelector';
import { cashReportTestFixture } from '../../src/features/cash/cashReportTestFixture';
import '../../src/index.css';
import '../../src/features/cash/cash-reports.css';

const report = cashReportTestFixture();
const accounts = Array.from({ length: 100 }, (_, n) => ({ code: `111${String(n).padStart(3, '0')}`, name: n === 99 ? 'LAST ACCOUNT' : `Tài khoản kiểm thử ${n}` }));
const options = Array.from({ length: 100 }, (_, n) => ({ value: n, label: n === 99 ? 'LAST OPTION' : `Option ${n}` }));
const client = new QueryClient();
function Harness() {
  const [position, setPosition] = useState('bottom');
  const [overflow, setOverflow] = useState<'hidden' | 'auto'>('hidden');
  const [selected, setSelected] = useState('');
  const [showReport, setShowReport] = useState(false);
  const [adaptive, setAdaptive] = useState(false);
  const [cssOnly, setCssOnly] = useState(false);
  const StatusSelect = adaptive ? AdaptiveSelect : Select;
  return <ConfigProvider locale={viVN} getPopupContainer={() => document.body} popupOverflow="viewport" theme={{ token: { colorPrimary: '#0064E0', fontFamily: "'Optimistic', Helvetica, Arial, sans-serif", fontSize: 13, borderRadius: 8, controlHeight: 34 }, components: { Select: { controlHeight: 34, borderRadius: 8, fontSize: 13 }, Table: { cellPaddingBlockSM: 6, cellPaddingInlineSM: 10 } } }}>
    <QueryClientProvider client={client}>
      <header style={{ padding: 12 }}><strong>TEST ONLY — deterministic fixtures; no API/authentication integration</strong><br/>
        <label>Position <select value={position} onChange={e => setPosition(e.target.value)}><option value="top">Top</option><option value="middle">Middle</option><option value="bottom">Bottom</option></select></label>{' '}
        <label>Ancestor overflow <select value={overflow} onChange={e => setOverflow(e.target.value as 'hidden' | 'auto')}><option>hidden</option><option>auto</option></select></label>{' '}
        <button onClick={() => setShowReport(!showReport)}>Toggle report</button><output aria-label="Selected value">{selected}</output>
        <label><input type="checkbox" checked={adaptive} onChange={e => setAdaptive(e.target.checked)}/>Owner-sized Select</label>
        <label><input type="checkbox" checked={cssOnly} onChange={e => setCssOnly(e.target.checked)}/>Diagnostic CSS-only shrink</label>
        {cssOnly && <style>{'.diagnostic-css-only .ant-select-dropdown-list-holder { max-height: 120px !important; }'}</style>}
      </header>
      {showReport && <div className="cash-reports-page"><div className="cash-report-workbench"><CashReportSelector value="CA-03" onChange={() => {}}/><CashReportResult report={report} loading={false} error={false} onRetry={() => {}}/></div></div>}
      <div data-testid="trigger-container" style={{ position: 'fixed', left: 20, right: 20, top: position === 'top' ? 100 : position === 'middle' ? '48%' : undefined, bottom: position === 'bottom' ? 20 : undefined, overflow, height: 65, display: 'flex', gap: 20, border: '1px dashed #666', padding: 8 }}>
        <label style={{ width: 220 }}>Report status<StatusSelect aria-label="Report status" classNames={{ popup: { root: cssOnly ? 'diagnostic-css-only' : '' } }} style={{ width: '100%' }} options={options} onChange={v => setSelected(String(v))}/></label>
        <label style={{ width: 240 }}>Non-report account<AccountSelect accounts={accounts} placeholder="Non-report account" onChange={setSelected}/></label>
      </div>
      <AdaptivePopupViewport/>
    </QueryClientProvider>
  </ConfigProvider>;
}
createRoot(document.getElementById('root')!).render(<Harness/>);
