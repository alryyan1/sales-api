<?php // config/payment_methods.php

return [
    // Every value payments.method / purchase_payments.method may hold going
    // forward, and what the sales/POS/purchase UIs offer for selection.
    'all' => ['cash', 'bankak', 'fawry', 'ocash', 'bank_transfer', 'card'],

    // Legacy values that can still appear on old rows (mainly purchase
    // payments) but are no longer offered as selectable options.
    'legacy' => ['visa', 'mastercard', 'mada', 'refund', 'other'],

    // Methods that settle to a bank/electronic account rather than the cash
    // drawer, for accounting classification (FinanceBridgeService, shift and
    // report breakdowns, dashboard totals).
    'bank' => ['bankak', 'fawry', 'ocash', 'bank_transfer', 'card'],

    'cash' => ['cash'],
];
