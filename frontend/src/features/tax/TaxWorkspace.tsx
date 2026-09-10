import React from 'react';
import TaxComplianceBoundaryNotice from './TaxComplianceBoundaryNotice';

/**
 * The previous workspace was a static MISA-like gallery of tax forms. It had
 * no controlled tax data source, validation, filing workflow, or legal-form
 * version. Keep the route, but fail closed until that product scope exists.
 */
const TaxWorkspace: React.FC = () => <TaxComplianceBoundaryNotice surface="workspace" />;

export default TaxWorkspace;
