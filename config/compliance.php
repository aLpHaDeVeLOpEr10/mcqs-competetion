<?php

/*
|--------------------------------------------------------------------------
| Real-money launch gate (docs/LAUNCH_READINESS.md)
|--------------------------------------------------------------------------
|
| This product combines paid entry with prizes. Whether and where that is
| lawful, and under which conditions, is a question for qualified legal,
| payment-provider and tax review — NOT something this code decides.
|
| Real-money features (paid entries, deposits, prize payouts to wallets,
| withdrawals) stay forced OFF, whatever their own switches say, until:
|   - COMPLIANCE_REAL_MONEY_APPROVED=true,
|   - COMPLIANCE_APPROVAL_REFERENCE names the written decision (e.g. a legal
|     opinion and board approval reference), and
|   - every requirement below is confirmed (each either "in place" or
|     "confirmed not required" by that review, for every permitted jurisdiction).
|
| Several requirements (age verification, KYC, AML, geo-restriction,
| self-exclusion) are NOT implemented in this application today. They can
| only be confirmed once implemented (or provided by a specialist provider),
| or once the review confirms they are not required.
|
*/

$confirmed = fn (string $key) => (bool) env('COMPLIANCE_CONFIRMED_'.$key, false);

return [

    'real_money_approved' => (bool) env('COMPLIANCE_REAL_MONEY_APPROVED', false),

    // Where the written approval is recorded (document / decision reference). Required.
    'approval_reference' => env('COMPLIANCE_APPROVAL_REFERENCE'),

    // ISO 3166-1 alpha-2 codes the approval covers. Enforcing it needs geo-restriction / KYC
    // (requirement "geo_restriction" below).
    'permitted_jurisdictions' => array_values(array_filter(array_map('trim', explode(',', (string) env('COMPLIANCE_PERMITTED_JURISDICTIONS', 'PK'))))),

    // Minimum player age set by the review. Enforcing it needs age verification (below).
    'minimum_age' => (int) env('COMPLIANCE_MINIMUM_AGE', 18),

    'requirements' => [
        'legal_review' => ['label' => 'Qualified legal review of paid entry + prizes in every permitted jurisdiction', 'confirmed' => $confirmed('LEGAL_REVIEW')],
        'payment_provider_approval' => ['label' => 'Payment and payout providers have approved this business model', 'confirmed' => $confirmed('PAYMENT_PROVIDER_APPROVAL')],
        'age_verification' => ['label' => 'Minimum-age verification in place (or confirmed not required)', 'confirmed' => $confirmed('AGE_VERIFICATION')],
        'kyc' => ['label' => 'Identity verification (KYC) in place (or confirmed not required)', 'confirmed' => $confirmed('KYC')],
        'aml' => ['label' => 'Anti-money-laundering controls and reporting in place (or confirmed not required)', 'confirmed' => $confirmed('AML')],
        'geo_restriction' => ['label' => 'Players limited to the permitted jurisdictions', 'confirmed' => $confirmed('GEO_RESTRICTION')],
        'responsible_play' => ['label' => 'Responsible-play controls agreed (deposit/entry limits, messaging)', 'confirmed' => $confirmed('RESPONSIBLE_PLAY')],
        'self_exclusion' => ['label' => 'Self-exclusion available (or confirmed not required)', 'confirmed' => $confirmed('SELF_EXCLUSION')],
        'tax_reporting' => ['label' => 'Tax withholding and reporting obligations determined and configured', 'confirmed' => $confirmed('TAX_REPORTING')],
        'terms_rules_refunds' => ['label' => 'Final terms, contest rules and refund policy reviewed and published', 'confirmed' => $confirmed('TERMS_RULES_REFUNDS')],
        'privacy_policy' => ['label' => 'Final privacy policy reviewed and published', 'confirmed' => $confirmed('PRIVACY_POLICY')],
    ],

];
