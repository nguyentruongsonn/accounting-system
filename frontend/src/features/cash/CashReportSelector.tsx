import { useRef, useState } from 'react';
import { REPORTS, type CashReportCode } from './cashReportContract';

type CashReportSelectorProps = {
  value: CashReportCode | null;
  onChange: (code: CashReportCode) => void;
};

export function CashReportSelector({ value, onChange }: CashReportSelectorProps) {
  const [focusedCode, setFocusedCode] = useState<CashReportCode>(value ?? REPORTS[0].code);
  const options = useRef(new Map<CashReportCode, HTMLButtonElement>()).current;
  const select = (code: CashReportCode) => { setFocusedCode(code); onChange(code); };
  const moveFocus = (index: number) => {
    const next = REPORTS[(index + REPORTS.length) % REPORTS.length].code;
    setFocusedCode(next);
    options.get(next)?.focus();
  };
  return (
    <aside className="cash-report-selector" aria-label="Danh sách báo cáo tiền mặt">
      <div className="cash-report-selector__heading">Báo cáo tiền mặt</div>
      <div role="listbox" aria-label="Chọn báo cáo tiền mặt" className="cash-report-selector__list">
        {REPORTS.map((report, index) => (
          <button
            key={report.code}
            ref={(element) => { if (element) options.set(report.code, element); else options.delete(report.code); }}
            id={`cash-report-${report.code}`}
            type="button"
            role="option"
            aria-selected={value === report.code}
            className="cash-report-selector__item"
            onClick={() => select(report.code)}
            onFocus={() => setFocusedCode(report.code)}
            onKeyDown={(event) => {
              if (event.key === 'ArrowDown') { event.preventDefault(); moveFocus(index + 1); }
              if (event.key === 'ArrowUp') { event.preventDefault(); moveFocus(index - 1); }
              if (event.key === 'Home') { event.preventDefault(); moveFocus(0); }
              if (event.key === 'End') { event.preventDefault(); moveFocus(REPORTS.length - 1); }
              if ((event.key === 'Enter' || event.key === ' ') && focusedCode === report.code) { event.preventDefault(); select(report.code); }
            }}
          >
            <span className="cash-report-selector__code">{report.code}</span>
            <span className="cash-report-selector__name">{report.name}</span>
          </button>
        ))}
      </div>
    </aside>
  );
}
