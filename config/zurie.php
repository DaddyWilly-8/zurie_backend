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

    /*
     * Both values here are only DEFAULTS: once someone saves Admin >
     * Settings > Tax, SettingsService::getTax() uses that instead.
     *
     * Whether the prices entered on products already include VAT.
     *   true  — a 45,000 item costs the customer 45,000; the sale is split
     *           into net Sales and VAT Output inside that price.
     *   false — VAT is added on top: the same item costs 45,000 + 18%.
     * Either way VAT is computed on the price after any coupon discount,
     * and products marked vat_exempted carry none. Each order stores the
     * mode it was sold under (orders.prices_include_vat) so a later change
     * here never alters how an existing order is reversed.
     */
    'prices_include_vat' => (bool) env('ZURIE_PRICES_INCLUDE_VAT', true),
];
