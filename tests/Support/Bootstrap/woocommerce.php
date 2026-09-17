<?php

/*
 * Minimum API surface for the supported-version bootstrap test. The real
 * WooCommerce integrations are exercised by the rest of the Integration
 * suite; this isolated process verifies the exact version boundary and load
 * order without pretending that an older installed WooCommerce has new APIs.
 */

namespace Automattic\WooCommerce\Utilities {
    class FeaturesUtil
    {
        public static function declare_compatibility(string $feature, string $plugin, bool $compatible): void
        {
            $GLOBALS['bootstrap_compatibility'][$feature] = $compatible;
        }
    }
}

namespace Automattic\WooCommerce\Blocks\Integrations {
    interface IntegrationInterface {}
}

namespace {
    class WC_Shipping_Flat_Rate {}

    function wc_get_container(): object
    {
        return new class {
            public function get(string $class): object
            {
                return new class {
                    public function custom_orders_table_usage_is_enabled(): bool
                    {
                        return true;
                    }
                };
            }
        };
    }
}
