<?php

return [
    'resident_document_max_kb' => (int) env('RESIDENT_DOCUMENT_MAX_KB', 2048),
    'payment_proof_max_kb' => (int) env('PAYMENT_PROOF_MAX_KB', 2048),
    'expense_proof_max_kb' => (int) env('EXPENSE_PROOF_MAX_KB', 2048),
    'special_bill_document_max_kb' => (int) env('SPECIAL_BILL_DOCUMENT_MAX_KB', 2048),
];
