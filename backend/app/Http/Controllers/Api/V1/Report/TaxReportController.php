<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TaxReportController extends Controller
{
    public function vatDeclaration(Request $request)
    {
        // A ledger aggregation is not a statutory declaration. The legacy
        // endpoint also had no controlled tax-form version, invoice evidence,
        // validation, signature, or submission workflow. Return an explicit
        // API contract rather than failing at runtime or implying 01/GTGT is
        // available under the accounting-report permission.
        return response()->json([
            'error' => 'VAT declaration filing is not implemented.',
            'code' => 'TAX_DECLARATION_NOT_IMPLEMENTED',
            'regulatory_dependency' => 'Tax obligations must be evaluated under applicable tax law; TT99/2025/TT-BTC is an accounting-regime baseline, not a tax-filing certification.',
            'not_a_statutory_declaration' => true,
        ], 501);
    }
}
