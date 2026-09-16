import { printElementSafely } from '../../utils/safePrint';
import reportStyles from './cash-reports.css?raw';

// Page dimensions belong only to the isolated report document. Putting this
// unnamed @page rule in the imported app CSS would alter other print workflows.
const reportPageStyles = '@page { size: A4 landscape; margin: 12mm; }';

export function printCashReport(source: HTMLElement, title: string): void {
  printElementSafely(source, title, `${reportStyles}\n${reportPageStyles}`, { allowWholePageFallback: false });
}
