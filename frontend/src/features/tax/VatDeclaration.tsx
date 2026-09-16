import React from 'react';
import TaxComplianceBoundaryNotice from './TaxComplianceBoundaryNotice';

/**
 * No statutory VAT declaration is rendered from accounting-ledger totals.
 * This avoids presenting a draft aggregation as a valid 01/GTGT filing.
 */
const VatDeclaration: React.FC = () => <TaxComplianceBoundaryNotice surface="vat_declaration" />;

export default VatDeclaration;
