<?php
/**
 * Plugin Name: Native WooCommerce Dimensions Table
 * Description: Adds a lightweight [product_dimensions] shortcode to display native WooCommerce dimensions and Materials, strictly formatted with mobile responsiveness. Also mirrors dimensions, material, on-display status, stock level, and the business's own seller identity into the page's existing Product structured data for AI/AEO crawlers, with zero visible front-end change. Includes a WooCommerce admin page (AEO Preview) that fetches a product's real live page by SKU and shows the actual JSON-LD found on it. Self-updates from a private GitHub repo — see WooCommerce > AEO Settings.
 * Version: 1.14
 * Author: Your Dev Team
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// v1.14: trivial version bump only, to prove the GitHub self-update
// mechanism actually shows an "Update Available" notice on a real site —
// no functional change. Per Room Planner's own precedent (v7.17.0), this
// mechanism was never trustworthy just because the code looked right; it
// needed a real, live, watched test before relying on it.

// ========================================================================
// 0. SELF-UPDATE FROM PRIVATE GITHUB REPO
// ========================================================================
// Same pattern as Universal Room Planner's self-update setup — lets this
// plugin show a normal "Update Available" notice (and support WordPress's
// own auto-update toggle) without being listed on WordPress.org, by
// checking github.com/ra-license/woo-native-dimensions instead. Since that
// repo is private, each site needs its own read-only GitHub access token —
// deliberately a DIFFERENT constant/option name than Room Planner's
// (URP_GITHUB_UPDATE_TOKEN / urp_github_update_token) so a site running
// both plugins configures each independently, either as a wp-config.php
// constant (takes priority, for sites managed by FTP/hosting-panel access):
//   define( 'RMA_GITHUB_UPDATE_TOKEN', 'github_pat_xxxxxxxxxxxxxxxxxxxx' );
// ...or, for sites without easy wp-config.php access, via WooCommerce >
// AEO Settings in wp-admin.
require_once __DIR__ . '/lib/plugin-update-checker/plugin-update-checker.php';

$rmaUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/ra-license/woo-native-dimensions/',
    __FILE__,
    'woo-native-dimensions'
);
$rmaUpdateChecker->setBranch( 'main' );
$rmaGithubToken = defined( 'RMA_GITHUB_UPDATE_TOKEN' ) && RMA_GITHUB_UPDATE_TOKEN
    ? RMA_GITHUB_UPDATE_TOKEN
    : get_option( 'rma_github_update_token', '' );
if ( $rmaGithubToken ) {
    $rmaUpdateChecker->setAuthentication( $rmaGithubToken );
}

// ========================================================================
// 0b. AEO SETTINGS PAGE (GitHub update token, for sites without wp-config access)
// ========================================================================
add_action( 'admin_menu', 'rma_register_settings_page' );
function rma_register_settings_page() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }
    add_submenu_page(
        'woocommerce',
        __( 'AEO Settings', 'rma' ),
        __( 'AEO Settings', 'rma' ),
        'manage_options',
        'rma-aeo-settings',
        'rma_settings_page_html'
    );
}

add_action( 'admin_init', 'rma_register_settings' );
function rma_register_settings() {
    register_setting( 'rma_settings_group', 'rma_github_update_token', 'sanitize_text_field' );
    add_settings_section( 'rma_update_settings', __( 'Auto-Update Settings', 'rma' ), 'rma_update_settings_intro_html', 'rma-settings' );
    add_settings_field( 'rma_github_update_token_field', __( 'GitHub Update Token', 'rma' ), 'rma_github_update_token_html', 'rma-settings', 'rma_update_settings' );
}

function rma_update_settings_intro_html() {
    echo '<p>' . esc_html__( 'Lets this site automatically detect new versions of this plugin instead of needing a manual zip upload. Requires a one-time, read-only GitHub token, scoped to only this plugin\'s repository — see R&A Marketing for the token if you don\'t have it.', 'rma' ) . '</p>';
}

function rma_github_update_token_html() {
    $token               = get_option( 'rma_github_update_token', '' );
    $has_wp_config_token = defined( 'RMA_GITHUB_UPDATE_TOKEN' ) && RMA_GITHUB_UPDATE_TOKEN;
    echo '<input type="password" name="rma_github_update_token" value="' . esc_attr( $token ) . '" style="width: 350px;" autocomplete="off" placeholder="github_pat_..." />';
    if ( $has_wp_config_token ) {
        echo '<p class="description">' . esc_html__( 'A token defined in wp-config.php is already active and takes priority over this field.', 'rma' ) . '</p>';
    } else {
        echo '<p class="description">' . esc_html__( 'This is a repository-scoped, read-only credential — it cannot access anything else in the GitHub account.', 'rma' ) . '</p>';
    }
}

function rma_settings_page_html() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'AEO Settings', 'rma' ); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields( 'rma_settings_group' );
            do_settings_sections( 'rma-settings' );
            submit_button( __( 'Save Settings', 'rma' ) );
            ?>
        </form>
    </div>
    <?php
}

add_shortcode( 'product_dimensions', 'render_native_woo_dimensions_strict_string' );

function render_native_woo_dimensions_strict_string() {
    if ( ! function_exists( 'wc_get_product' ) ) {
        return '';
    }

    $product_id = get_queried_object_id();

    if ( 'product' !== get_post_type( $product_id ) ) {
        $product_id = get_the_ID();
    }

    $product = wc_get_product( $product_id );

    if ( ! $product ) {
        return '';
    }

    // 1. Fetch Dimensions and Material Data
    $length   = $product->get_length();
    $width    = $product->get_width();
    $height   = $product->get_height();
    $material = $product->get_attribute( 'material' ); // Automatically pulls the WooCommerce attribute named "Material"

    $has_dimensions = ( ! empty( $length ) || ! empty( $width ) || ! empty( $height ) );
    $has_material   = ! empty( $material );

    // 2. If all data fields are empty, output nothing
    if ( ! $has_dimensions && ! $has_material ) {
        return '';
    }

    $unit = get_option( 'woocommerce_dimension_unit', 'in' );

    // 3. Build the HTML strictly as a PHP string with Responsive Wrappers
    $html  = '<div class="lightweight-dimensions-wrapper" style="width: 100%;">';
    $html .= '<h3 class="dimensions-title" style="font-size: 1.2rem; margin-bottom: 10px; color: #333; font-family: \'Montserrat\', sans-serif;">Product Specifications</h3>';

    // Add the mobile scrolling wrapper just for the table
    $html .= '<div class="table-scroll-wrapper" style="overflow-x: auto; -webkit-overflow-scrolling: touch; width: 100%; border: 1px solid #ddd;">';

    // Set min-width on the table so it scrolls cleanly on tiny screens instead of squishing
    $html .= '<table class="dimensions-table" style="width: 100%; min-width: 400px; border-collapse: collapse; margin-bottom: 0; font-family: \'Nunito Sans\', sans-serif; text-align: left;">';

    // Only generate the complex headers if dimensions actually exist
    if ( $has_dimensions ) {
        $html .= '<thead style="background-color: #f4f4f4;">';
        $html .= '<tr>';
        $html .= '<th style="padding: 10px 12px; border-bottom: 1px solid #ddd; border-right: 1px solid #ddd; font-weight: bold; color: #333; text-transform: uppercase; font-size: 0.9rem;">Component</th>';
        $html .= '<th style="padding: 10px 12px; border-bottom: 1px solid #ddd; border-right: 1px solid #ddd; font-weight: bold; color: #333; text-transform: uppercase; font-size: 0.9rem;">Width (' . esc_html( $unit ) . ')</th>';
        $html .= '<th style="padding: 10px 12px; border-bottom: 1px solid #ddd; border-right: 1px solid #ddd; font-weight: bold; color: #333; text-transform: uppercase; font-size: 0.9rem;">Depth (' . esc_html( $unit ) . ')</th>';
        $html .= '<th style="padding: 10px 12px; border-bottom: 1px solid #ddd; font-weight: bold; color: #333; text-transform: uppercase; font-size: 0.9rem;">Height (' . esc_html( $unit ) . ')</th>';
        $html .= '</tr>';
        $html .= '</thead>';
    }

    $html .= '<tbody>';

    // Build the Dimensions row if data exists
    if ( $has_dimensions ) {
        $html .= '<tr>';
        $html .= '<td style="padding: 10px 12px; border-bottom: 1px solid #ddd; border-right: 1px solid #ddd; color: #555;">Overall Dimensions</td>';
        $html .= '<td style="padding: 10px 12px; border-bottom: 1px solid #ddd; border-right: 1px solid #ddd; color: #555;">' . esc_html( $width ) . '</td>';
        $html .= '<td style="padding: 10px 12px; border-bottom: 1px solid #ddd; border-right: 1px solid #ddd; color: #555;">' . esc_html( $length ) . '</td>';
        $html .= '<td style="padding: 10px 12px; border-bottom: 1px solid #ddd; color: #555;">' . esc_html( $height ) . '</td>';
        $html .= '</tr>';
    }

    // Build the Material row if data exists
    if ( $has_material ) {
        $html .= '<tr>';
        $html .= '<td style="padding: 10px 12px; border-right: 1px solid #ddd; font-weight: bold; color: #333;">Material</td>';

        // Use a colspan of 3 to neatly span the remaining dimension columns so the table borders line up perfectly
        $colspan = $has_dimensions ? '3' : '1';

        $html .= '<td colspan="' . $colspan . '" style="padding: 10px 12px; color: #555;">' . esc_html( $material ) . '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody>';
    $html .= '</table>';
    $html .= '</div>'; // Close scroll wrapper
    $html .= '</div>'; // Close main wrapper

    return $html;
}

/**
 * AI/AEO structured-data mirror.
 *
 * Extends WooCommerce core's own Product JSON-LD block (fired via
 * woocommerce_structured_data_product) rather than emitting a second,
 * competing Product entity. This filter only runs on sites where core's
 * structured-data output actually fires — if a theme or SEO plugin has
 * disabled WooCommerce's native schema output entirely, this adds nothing,
 * and that's a separate, bigger gap that needs its own diagnosis per site.
 */
add_filter( 'woocommerce_structured_data_product', 'add_native_woo_dimensions_to_structured_data', 10, 2 );

function add_native_woo_dimensions_to_structured_data( $markup, $product ) {

    if ( ! $product instanceof WC_Product ) {
        return $markup;
    }

    $unit = get_option( 'woocommerce_dimension_unit', 'in' );

    // Reuse the exact same accessors as the visible shortcode table above,
    // so the AI-facing data and the on-page table can never disagree.
    $length   = $product->get_length();
    $width    = $product->get_width();
    $height   = $product->get_height();
    $material = $product->get_attribute( 'material' );

    // Attribute slug for showroom/on-display status. Confirmed real-world
    // convention (as used on kemperhomefurnishings.com): a WooCommerce
    // attribute taxonomy named "On Display in Showroom" (pa_on-display-in-showroom).
    // Filterable per-site in case another site uses a different slug.
    $on_display_slug = apply_filters( 'rma_on_display_attribute_slug', 'on-display-in-showroom' );
    $on_display      = $product->get_attribute( $on_display_slug );

    $properties = array();

    if ( ! empty( $width ) ) {
        $properties[] = array(
            '@type' => 'PropertyValue',
            'name'  => 'Width',
            'value' => $width . ' ' . $unit,
        );
    }

    if ( ! empty( $length ) ) {
        $properties[] = array(
            '@type' => 'PropertyValue',
            'name'  => 'Depth',
            'value' => $length . ' ' . $unit,
        );
    }

    if ( ! empty( $height ) ) {
        $properties[] = array(
            '@type' => 'PropertyValue',
            'name'  => 'Height',
            'value' => $height . ' ' . $unit,
        );
    }

    if ( ! empty( $on_display ) ) {
        $properties[] = array(
            '@type' => 'PropertyValue',
            'name'  => 'On Display In Showroom',
            'value' => $on_display,
        );
    }

    if ( ! empty( $properties ) ) {
        if ( ! empty( $markup['additionalProperty'] ) && is_array( $markup['additionalProperty'] ) ) {
            $markup['additionalProperty'] = array_merge( $markup['additionalProperty'], $properties );
        } else {
            $markup['additionalProperty'] = $properties;
        }
    }

    if ( ! empty( $material ) ) {
        $markup['material'] = $material;
    }

    // Stock quantity: WooCommerce core's own JSON-LD already emits
    // offers.price / offers.priceCurrency / offers.availability correctly,
    // so price is deliberately left untouched here to avoid two sources of
    // truth disagreeing. What core does NOT emit is the actual numeric
    // count, only the InStock/OutOfStock enum — so we add that as
    // Offer.inventoryLevel (a real schema.org QuantitativeValue), merged
    // into whatever offer(s) core already produced. If core didn't produce
    // an offer at all for this product, we leave it alone rather than
    // guessing at a replacement.
    if ( $product->managing_stock() && ! empty( $markup['offers'] ) ) {
        $stock_quantity = $product->get_stock_quantity();

        if ( null !== $stock_quantity ) {
            $inventory_level = array(
                '@type' => 'QuantitativeValue',
                'value' => (int) $stock_quantity,
            );

            if ( isset( $markup['offers']['@type'] ) ) {
                // Core emitted a single Offer object.
                $markup['offers']['inventoryLevel'] = $inventory_level;
            } else {
                // Core emitted a list of Offer objects (e.g. variable products).
                foreach ( $markup['offers'] as $index => $offer ) {
                    if ( is_array( $offer ) ) {
                        $markup['offers'][ $index ]['inventoryLevel'] = $inventory_level;
                    }
                }
            }
        }
    }

    // Seller identity: ties this specific product's Offer to the business
    // that actually sells it, rather than leaving an anonymous Offer that
    // an AI engine can't distinguish from any other site listing the same
    // manufacturer item. Portable across sites on purpose — see the
    // capture functions below for how the business entity is sourced.
    if ( ! empty( $markup['offers'] ) ) {
        $seller = rma_get_business_seller_entity();

        if ( ! empty( $seller ) ) {
            if ( isset( $markup['offers']['@type'] ) ) {
                if ( empty( $markup['offers']['seller'] ) ) {
                    $markup['offers']['seller'] = $seller;
                }
            } else {
                foreach ( $markup['offers'] as $index => $offer ) {
                    if ( is_array( $offer ) && empty( $offer['seller'] ) ) {
                        $markup['offers'][ $index ]['seller'] = $seller;
                    }
                }
            }
        }
    }

    return $markup;
}

/**
 * Business/seller identity resolution.
 *
 * Rather than guessing at each SEO plugin's internal option storage (field
 * names differ by plugin and by version, and a wrong guess would mean
 * silently feeding AI crawlers a wrong phone number or address — worse than
 * feeding them nothing), this listens on the documented public filter each
 * plugin already uses to build its OWN Organization/LocalBusiness schema,
 * and reuses that exact object. It's the same data the site already
 * publishes elsewhere, never a second, independently-sourced copy.
 *
 * Capture priority: Yoast SEO -> Rank Math -> SEOPress -> WooCommerce store
 * address (name + address only, no phone available there) -> nothing.
 *
 * NOT yet verified against a live site running any of these three plugins
 * (see writeup, "Verification NOT yet done") — confirm in staging that each
 * filter actually fires before woocommerce_structured_data_product does
 * (it should, since these plugins render in wp_head and WooCommerce's own
 * structured data prints later, in wp_footer) and that the captured node's
 * keys/shape match what's assumed here.
 */
function rma_capture_seo_business_entity( $data ) {
    if ( is_array( $data ) && empty( $GLOBALS['rma_captured_business_entity'] ) ) {
        $GLOBALS['rma_captured_business_entity'] = $data;
    }

    return $data;
}
add_filter( 'wpseo_schema_organization', 'rma_capture_seo_business_entity', 20, 1 );
add_filter( 'rank_math/snippet/rich_snippet_local_business_entity', 'rma_capture_seo_business_entity', 20, 1 );
add_filter( 'seopress_pro_get_json_data_local_business', 'rma_capture_seo_business_entity', 20, 1 );

function rma_get_business_seller_entity() {
    static $seller = null;

    if ( null !== $seller ) {
        return $seller;
    }

    $captured = ! empty( $GLOBALS['rma_captured_business_entity'] ) ? $GLOBALS['rma_captured_business_entity'] : array();

    if ( ! empty( $captured ) ) {
        // Prefer a lightweight @id reference — the full entity is already
        // printed elsewhere on this same page by the SEO plugin, so parsers
        // that merge same-page JSON-LD graphs by @id will resolve it fully
        // without this plugin duplicating name/address/phone on every
        // single product page.
        if ( ! empty( $captured['@id'] ) ) {
            $seller = array( '@id' => $captured['@id'] );
        } else {
            $seller = $captured;
        }

        $seller = apply_filters( 'rma_business_seller_entity', $seller, $captured );

        return $seller;
    }

    // Fallback: no Yoast/Rank Math/SEOPress local business data was
    // captured on this page load. Build a minimal Organization from
    // WooCommerce's own store address settings, which exist on every
    // WooCommerce install regardless of SEO plugin. No phone number is
    // available from this source.
    $address_parts = array_filter( array(
        get_option( 'woocommerce_store_address' ),
        get_option( 'woocommerce_store_address_2' ),
        get_option( 'woocommerce_store_city' ),
        get_option( 'woocommerce_store_postcode' ),
    ) );

    if ( empty( $address_parts ) ) {
        $seller = apply_filters( 'rma_business_seller_entity', array(), $captured );

        return $seller;
    }

    $seller = array(
        '@type'   => 'Organization',
        'name'    => get_bloginfo( 'name' ),
        'url'     => home_url( '/' ),
        'address' => array(
            '@type'           => 'PostalAddress',
            'streetAddress'   => implode( ' ', array_filter( array(
                get_option( 'woocommerce_store_address' ),
                get_option( 'woocommerce_store_address_2' ),
            ) ) ),
            'addressLocality' => get_option( 'woocommerce_store_city' ),
            'postalCode'      => get_option( 'woocommerce_store_postcode' ),
        ),
    );

    $seller = apply_filters( 'rma_business_seller_entity', $seller, $captured );

    return $seller;
}

/**
 * AEO Preview admin page.
 *
 * Look up a product by SKU and see the *real* structured data being sent
 * to search engines/AI crawlers for it — fetched directly from the
 * product's actual live page (a real HTTP request), not recomputed. An
 * earlier version (v1.9) called WooCommerce's schema generator function
 * directly from wp-admin instead; that produced a plausible-looking but
 * wrong result (missing price/availability/stock/seller) because calling
 * it outside a real front-end page load doesn't populate `offers` the way
 * an actual visit does — confirmed live on 2026-09-14. Fetching the real
 * page sidesteps that entirely: whatever this shows is exactly what was
 * actually sent, by construction.
 */
add_action( 'admin_menu', 'rma_add_aeo_preview_page' );

function rma_add_aeo_preview_page() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }
    add_submenu_page(
        'woocommerce',
        __( 'AEO Preview', 'rma' ),
        __( 'AEO Preview', 'rma' ),
        'manage_woocommerce',
        'rma-aeo-preview',
        'rma_render_aeo_preview_page'
    );
}

/**
 * Fetches the product's REAL live page — a genuine HTTP request, the same
 * as a crawler would make — and reads the actual JSON-LD out of it.
 *
 * v1.9 originally called WC()->structured_data->generate_product_data()
 * directly from wp-admin to build this preview. That turned out to be a
 * real bug, confirmed live on 2026-09-14: calling the generator outside
 * a real front-end page load does not populate `offers` the way an actual
 * visit does (this plugin's own additionalProperty/material still showed,
 * since those don't depend on `offers`, which is why only PART of the
 * preview was wrong rather than all of it — easy to miss without a live
 * side-by-side check). Fetching the real page instead of re-invoking the
 * generator sidesteps that whole class of problem: whatever comes back is
 * exactly what was actually sent, by construction, not a simulation of it.
 *
 * Returns a WP_Error if the page couldn't be fetched at all (distinct from
 * an empty array, which means the fetch worked but no Product schema was
 * found in the response — those need different messages to the user).
 */
function rma_generate_live_structured_data_for_product( $product ) {
    if ( ! $product instanceof WC_Product ) {
        return array();
    }

    $url = get_permalink( $product->get_id() );
    if ( ! $url ) {
        return array();
    }

    // A cache-busting query arg makes it less likely a CDN/full-page cache
    // hands back a stale response for this specific fetch — but it can't
    // force a site's cache to actually purge, so a result here could still
    // reflect a cached page on a site whose CDN ignores query strings.
    // Worth remembering when comparing this against what you see elsewhere.
    $fetch_url = add_query_arg( 'rma_aeo_preview', substr( md5( microtime() ), 0, 8 ), $url );

    $response = wp_remote_get(
        $fetch_url,
        array(
            'timeout'    => 15,
            'user-agent' => 'RMA-AEO-Preview/1.10 (+' . home_url( '/' ) . ')',
        )
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( 200 !== (int) $code ) {
        return new WP_Error( 'rma_fetch_failed', sprintf(
            /* translators: %d: HTTP status code */
            __( 'The live page returned HTTP %d instead of 200.', 'rma' ),
            $code
        ) );
    }

    return rma_find_product_node_in_html( wp_remote_retrieve_body( $response ) );
}

/**
 * Pulls every <script type="application/ld+json"> block out of a page's
 * HTML and returns the first node whose @type is "Product" — checking
 * both a bare top-level object (plain WooCommerce core shape) and a
 * node nested inside "@graph" (the shape SEOPress and similar plugins
 * build, confirmed live on 2026-09-14). Returns an empty array, not an
 * error, if the fetch succeeded but no Product node was found — that's a
 * real "nothing here" result, not a failure to check.
 */
function rma_find_product_node_in_html( $html ) {
    if ( ! preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches ) ) {
        return array();
    }

    foreach ( $matches[1] as $json ) {
        $data = json_decode( trim( $json ), true );
        if ( ! is_array( $data ) ) {
            continue;
        }

        if ( isset( $data['@type'] ) && 'Product' === $data['@type'] ) {
            return $data;
        }

        if ( ! empty( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
            foreach ( $data['@graph'] as $node ) {
                if ( is_array( $node ) && isset( $node['@type'] ) && 'Product' === $node['@type'] ) {
                    return $node;
                }
            }
        }
    }

    return array();
}

/**
 * An Offer can legitimately come back as a single object OR a JSON array
 * of one-or-more Offer objects (confirmed live: SEOPress's enhanced price
 * output wraps it in an array even for a simple product) — this returns
 * whichever single Offer should represent the "key facts" table, or an
 * empty array if there isn't one.
 */
function rma_first_offer( $markup ) {
    if ( empty( $markup['offers'] ) || ! is_array( $markup['offers'] ) ) {
        return array();
    }
    if ( isset( $markup['offers']['@type'] ) ) {
        return $markup['offers'];
    }
    $first = reset( $markup['offers'] );
    return is_array( $first ) ? $first : array();
}

/**
 * Looks up one named entry in additionalProperty (e.g. "Width", "On
 * Display In Showroom") and returns its value, or null if that property
 * isn't there at all — lets the key-facts table show "Not present" for
 * these the same consistent way it already does for Price/Material/etc,
 * instead of silently dropping the row when a product doesn't have it.
 */
function rma_additional_property_value( $markup, $name ) {
    if ( empty( $markup['additionalProperty'] ) || ! is_array( $markup['additionalProperty'] ) ) {
        return null;
    }
    foreach ( $markup['additionalProperty'] as $prop ) {
        if ( isset( $prop['name'] ) && $name === $prop['name'] ) {
            return isset( $prop['value'] ) ? $prop['value'] : null;
        }
    }
    return null;
}

function rma_render_aeo_preview_page() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }

    $sku = isset( $_GET['rma_sku'] ) ? sanitize_text_field( wp_unslash( $_GET['rma_sku'] ) ) : '';

    // Resolve everything up front, so the markup below can always close its
    // own tags cleanly instead of bailing out mid-template.
    $product     = false;
    $not_found   = false;
    $fetch_error = null;
    $markup      = array();
    $rows        = array();

    if ( '' !== $sku ) {
        $product_id = wc_get_product_id_by_sku( $sku );
        $product    = $product_id ? wc_get_product( $product_id ) : false;
        $not_found  = ! $product;

        if ( $product ) {
            $result = rma_generate_live_structured_data_for_product( $product );

            if ( is_wp_error( $result ) ) {
                $fetch_error = $result;
            } else {
                $markup = $result;
            }

            if ( ! empty( $markup ) ) {
                $offer = rma_first_offer( $markup );
                $rows  = array(
                    __( 'SKU', 'rma' )           => isset( $markup['sku'] ) ? $markup['sku'] : __( 'Not present', 'rma' ),
                    __( 'Price', 'rma' )        => isset( $offer['price'] ) ? $offer['price'] . ' ' . ( isset( $offer['priceCurrency'] ) ? $offer['priceCurrency'] : '' ) : __( 'Not present', 'rma' ),
                    __( 'Availability', 'rma' ) => isset( $offer['availability'] ) ? $offer['availability'] : __( 'Not present', 'rma' ),
                    __( 'Stock count', 'rma' )  => isset( $offer['inventoryLevel']['value'] ) ? $offer['inventoryLevel']['value'] : __( 'Not present', 'rma' ),
                    __( 'Seller', 'rma' )       => isset( $offer['seller'] ) ? wp_json_encode( $offer['seller'] ) : __( 'Not present', 'rma' ),
                    __( 'Material', 'rma' )     => isset( $markup['material'] ) ? $markup['material'] : __( 'Not present', 'rma' ),
                );

                // Every additionalProperty this plugin knows how to add gets
                // its own row every time, "Not present" included — same
                // consistent treatment as Price/Material/etc above, rather
                // than silently disappearing whenever a product doesn't have
                // that particular one set.
                $known_properties = array( 'Width', 'Depth', 'Height', 'On Display In Showroom' );
                foreach ( $known_properties as $name ) {
                    $value        = rma_additional_property_value( $markup, $name );
                    $rows[ $name ] = ( null !== $value ) ? $value : __( 'Not present', 'rma' );
                }

                // Anything else in additionalProperty that isn't one of the
                // known properties above (a future addition, or another
                // plugin's own entry) still surfaces rather than being
                // dropped — it just doesn't get a "Not present" placeholder
                // if it's absent, since this plugin doesn't know to expect it.
                if ( ! empty( $markup['additionalProperty'] ) && is_array( $markup['additionalProperty'] ) ) {
                    foreach ( $markup['additionalProperty'] as $prop ) {
                        if ( ! empty( $prop['name'] ) && ! in_array( $prop['name'], $known_properties, true ) ) {
                            $rows[ $prop['name'] ] = $prop['value'];
                        }
                    }
                }
            }
        }
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'AEO Preview', 'rma' ); ?></h1>
        <p><?php esc_html_e( 'Look up a product by SKU to see exactly what structured data is really being sent to search engines and AI crawlers for it. This fetches the product\'s actual live page and reads the real JSON-LD out of it — the same thing a crawler would see — rather than recomputing it, so it can never show something different from reality. If this site sits behind a CDN or page cache, purge it first for the freshest result.', 'rma' ); ?></p>

        <form method="get">
            <input type="hidden" name="page" value="rma-aeo-preview" />
            <input type="text" name="rma_sku" value="<?php echo esc_attr( $sku ); ?>" placeholder="<?php esc_attr_e( 'Enter a SKU…', 'rma' ); ?>" style="min-width:280px;" />
            <?php submit_button( __( 'Preview', 'rma' ), 'primary', '', false ); ?>
        </form>

        <?php if ( '' !== $sku ) : ?>
            <hr />

            <?php if ( $not_found ) : ?>
                <p>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %s: the SKU that was searched for */
                            __( 'No product found with SKU "%s".', 'rma' ),
                            $sku
                        )
                    );
                    ?>
                </p>

            <?php elseif ( $fetch_error ) : ?>
                <h2><?php echo esc_html( $product->get_name() ); ?></h2>
                <div class="notice notice-error inline">
                    <p>
                        <strong><?php esc_html_e( 'Could not fetch the live page to check it.', 'rma' ); ?></strong><br />
                        <?php echo esc_html( $fetch_error->get_error_message() ); ?><br />
                        <?php esc_html_e( 'This is a fetch problem, not necessarily a schema problem — the product may be password-protected, blocked from outside requests, or the URL may not resolve from this server. It does not mean structured data is missing, only that this tool couldn\'t check.', 'rma' ); ?>
                    </p>
                </div>

            <?php elseif ( empty( $markup ) ) : ?>
                <h2><?php echo esc_html( $product->get_name() ); ?></h2>
                <div class="notice notice-error inline">
                    <p>
                        <strong><?php esc_html_e( 'No Product structured data was found on the live page.', 'rma' ); ?></strong><br />
                        <?php esc_html_e( 'The page fetched successfully, but no Product entity showed up in its JSON-LD. This means WooCommerce\'s own schema output isn\'t reaching this page — a theme or another plugin (an SEO plugin\'s "remove default structured data" option is a common cause) may be disabling it. This plugin only extends that data; it can\'t create it from nothing. Nothing from this plugin (or WooCommerce core) is reaching search engines or AI crawlers for this product until that\'s fixed.', 'rma' ); ?>
                    </p>
                </div>

            <?php else : ?>
                <h2>
                    <?php echo esc_html( $product->get_name() ); ?>
                    &nbsp;<a href="<?php echo esc_url( get_edit_post_link( $product->get_id() ) ); ?>" style="font-size:.7em;"><?php esc_html_e( 'Edit product', 'rma' ); ?></a>
                    &nbsp;<a href="<?php echo esc_url( get_permalink( $product->get_id() ) ); ?>" style="font-size:.7em;" target="_blank"><?php esc_html_e( 'View live page', 'rma' ); ?></a>
                </h2>

                <h3><?php esc_html_e( 'Key facts at a glance', 'rma' ); ?></h3>
                <table class="widefat striped" style="max-width:720px;">
                    <tbody>
                        <?php foreach ( $rows as $label => $value ) : ?>
                            <tr>
                                <td style="width:200px;"><strong><?php echo esc_html( $label ); ?></strong></td>
                                <td><?php echo esc_html( is_scalar( $value ) ? $value : wp_json_encode( $value ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <h3><?php esc_html_e( 'Full structured data (raw JSON-LD)', 'rma' ); ?></h3>
                <p><?php esc_html_e( 'Copy this into Google\'s Rich Results Test or the schema.org validator to double-check it independently.', 'rma' ); ?></p>
                <textarea readonly rows="20" style="width:100%;max-width:900px;font-family:monospace;"><?php echo esc_textarea( wp_json_encode( $markup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></textarea>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}
