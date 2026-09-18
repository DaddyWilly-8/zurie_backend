<?php

return [
    /*
     * Phase E (VAT/Tax) — the standard VAT rate applied to every
     * non-exempt sale/purchase line, matching Tanzania's standard VAT
     * rate. Deliberately a fixed config value rather than a client-
     * supplied field on Order/Purchase requests: VAT on a customer-facing
     * checkout must be system-computed, never trusted from client input,
     * consistent with this codebase's existing "authoritative pricing
     * only" rule for every other price component (see OrderService::
     * checkout()'s docblock). A future per-shop-configurable rate belongs
     * in the Settings module once that's actually needed; no such
     * infrastructure exists yet.
     */
    'default_vat_percentage' => (float) env('ZURIE_DEFAULT_VAT_PERCENTAGE', 18.0),
];
