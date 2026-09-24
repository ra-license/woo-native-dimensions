<?php
/**
 * Plugin Name: Native WooCommerce Dimensions Table
 * Description: Adds a lightweight [product_dimensions] shortcode to display native WooCommerce dimensions and Materials, strictly formatted with mobile responsiveness. Also mirrors dimensions, material, on-display status, stock level, showroom location, and the business's own seller identity into the page's existing Product structured data for AI/AEO crawlers, with zero visible front-end change — including a standalone fallback for catalog-only sites with no price/stock management, so that data still reaches AI/search even when WooCommerce's own native schema doesn't fire. Adds CollectionPage/ItemList structured data to product category pages, so AI/search retrieval can see the real product count and listing without a separate crawl per product. Includes a WooCommerce admin page (AEO Preview) that fetches a product's real live page by SKU and shows the actual JSON-LD found on it. Self-updates from a private GitHub repo — see WooCommerce > AEO Settings.
 * Version: 1.22
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
//
// v1.17: adds Offer.availableAtOrFrom — real showroom location data (name,
// address, phone), entered per-site via a new "Showroom Locations" field on
// WooCommerce > AEO Settings, matched against each product's existing
// "On Display in Showroom" attribute value. Not hardcoded to any one
// client's address, since this plugin is shared across sites.
//
// v1.18: adds a "Showroom-location matching (debug)" section to the AEO
// Preview page — v1.17 worked correctly against a fresh copy of this exact
// file, and the reinstalled zip on kemperhomefurnishings.com was confirmed
// byte-identical, yet the live page still wasn't showing availableAtOrFrom
// after every known caching layer (CDN, WP Rocket, a full plugin reinstall)
// was cleared. Rather than keep guessing at the cause from outside, this
// computes the same match live, in wp-admin, independent of the fetched
// page, to see directly whether the mismatch is in the matching logic or
// somewhere in how the front-end actually renders.
//
// v1.19: the v1.18 debug section found the real cause — not a caching or
// code bug at all. The Showroom Locations field's placeholder text used
// Kemper's own real, correct address data as its example (since that's
// what this plugin was built for), which meant an empty, never-actually-saved
// field looked visually identical to a correctly-filled one. Fixes the trap
// itself: the placeholder is now obviously fake, and an empty field shows an
// explicit "nothing saved yet" warning instead of staying silent.
//
// v1.20: adds a standalone Product-schema fallback for catalog-only sites.
// Confirmed real on alysonjon.com (2026-09-24): a 45,000+ product catalog
// with no price or stock managed (no ERP, no dedicated inventory staff)
// produced ZERO Product structured data — WooCommerce's own native schema
// never fired at all for these pages, so this plugin (which only ever
// extended whatever WooCommerce already produced) had nothing to extend.
// Real facts like dimensions, seller identity, and showroom location were
// available the whole time and simply weren't reaching AI/search. This
// version also relaxes the main filter's offer-building so seller and
// showroom location go out even when WooCommerce built no offer at all
// (price/stock fields are just omitted, never invented), and adds a
// wp_footer fallback that builds a complete Product entity independently
// when WooCommerce's own filter never ran — see rma_build_offer_node() and
// rma_output_standalone_product_schema().
//
// v1.21: fixes a real, live-confirmed readability bug in v1.20's fallback
// description field — alysonjon.com's imported catalog content is an
// unspaced HTML table, and stripping tags with no separator ran every
// cell together ("FeaturesLeatherYesProduct DetailsWeight54.00 lbs...").
// rma_html_to_plain_text() inserts a space at block-level tag boundaries
// first so the description reads as real text.
//
// v1.22: the backorder fallback in rma_build_offer_node() now emits
// "LimitedAvailability" instead of schema.org's own "BackOrder" value by
// default (filterable via rma_backorder_availability_value). BackOrder is
// technically correct per schema.org's own spec ("available on backorder"),
// but the word itself can read as "unavailable" to an AI answer engine
// parsing it conversationally — defeating the point of publishing it. Only
// applies within rma_build_offer_node(), i.e. only when there's no other,
// more authoritative availability signal already present (WooCommerce's
// own native output, where it fires, is untouched by this).

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

    register_setting( 'rma_settings_group', 'rma_business_locations', 'sanitize_textarea_field' );
    add_settings_section( 'rma_locations_settings', __( 'Showroom Locations', 'rma' ), 'rma_locations_settings_intro_html', 'rma-settings' );
    add_settings_field( 'rma_business_locations_field', __( 'Locations', 'rma' ), 'rma_business_locations_html', 'rma-settings', 'rma_locations_settings' );
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

function rma_locations_settings_intro_html() {
    echo '<p>' . esc_html__( 'One real showroom location per line, so each product\'s "On Display in Showroom" value can be tied to the specific store that actually has it — this is what powers Offer.availableAtOrFrom in the product\'s structured data. Leave blank if this site has no physical showroom locations.', 'rma' ) . '</p>';
    echo '<p class="description">' . esc_html__( 'Format: Name | Street Address | City | State | ZIP | Phone', 'rma' ) . '<br />' . esc_html__( 'Example: Example Store | 123 Main St | Anytown | ST | 00000 | (555) 555-5555', 'rma' ) . '</p>';
}

function rma_business_locations_html() {
    $locations = get_option( 'rma_business_locations', '' );

    // v1.19: deliberately a fake, obviously-not-real placeholder now — a
    // real site's own real data was used here before, which meant an empty,
    // never-saved field looked identical to a correctly-filled one (grayed
    // placeholder text vs. saved black text is an easy difference to miss).
    // That exact confusion cost real debugging time on kemperhomefurnishings.com
    // (2026-09-15): a whole session tracing cache layers before finding the
    // field itself had simply never been saved.
    echo '<textarea name="rma_business_locations" rows="6" style="width: 500px;" placeholder="Example Store | 123 Main St | Anytown | ST | 00000 | (555) 555-5555">' . esc_textarea( $locations ) . '</textarea>';

    if ( '' === trim( $locations ) ) {
        echo '<p class="description" style="color:#a00;">' . esc_html__( 'Nothing saved yet — the text above is just a placeholder example, not real data. Type your real location(s) and click Save Settings below.', 'rma' ) . '</p>';
    }

    echo '<p class="description">' . esc_html__( 'The "Name" must match (or be contained in) the value used in the "On Display in Showroom" product attribute, so this plugin knows which location an in-stock product actually belongs to.', 'rma' ) . '</p>';
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
 * Category-page structured data: CollectionPage + ItemList.
 *
 * Confirmed live on 2026-09-15 (kemperhomefurnishings.com/living-room/sofas/)
 * that a category archive page carries only a BreadcrumbList — no
 * CollectionPage, no ItemList, no product count — so an AI/search retrieval
 * system has no single machine-readable passage saying "this category has
 * N products" the way a competitor's category page can. This closes that
 * gap, prompted directly by a real third-party AI-agent audit transcript
 * (Phil's own ChatGPT session) that identified category-level retrieval,
 * not product-level schema, as the actual remaining bottleneck.
 *
 * Deliberately does NOT attempt the fuller "availableAtOrFrom" per-showroom
 * inventory idea from that same transcript — that needs two real, complete,
 * separately-addressable location entities (Somerset vs. London) to point
 * at, and the site's current /locations/ page only has one generic
 * FurnitureStore entity (no phone, empty geo coordinates, not
 * location-specific). Building a per-location reference now would mean
 * inventing an @id for an entity that doesn't really exist yet — that's a
 * separate, template-level fix needed first, not something to guess around
 * here. Revisit once real per-location Store entities exist.
 *
 * numberOfItems is pulled from the same query WooCommerce already uses to
 * render the category page (found_posts — the true total across every
 * page of results, per schema.org's own guidance that this need not match
 * how many products are actually listed in itemListElement), so there's
 * nothing to keep in sync manually. itemListElement only lists the
 * products actually shown on the current page/results — never a separate,
 * hidden claim beyond what's visibly on the page.
 *
 * Assumption not yet verified live: that the main WP_Query (checked here
 * via the global $wp_query) is really what drives this site's product
 * grid. True for a standard WooCommerce/theme archive template; would need
 * a different data source if a page builder replaces the loop with its
 * own separate query.
 */
add_action( 'wp_head', 'rma_output_category_structured_data', 20 );

function rma_output_category_structured_data() {
    if ( ! function_exists( 'is_product_category' ) || ! is_product_category() ) {
        return;
    }

    global $wp_query;

    $term = get_queried_object();
    if ( ! ( $term instanceof WP_Term ) ) {
        return;
    }

    $category_url = get_term_link( $term );
    if ( is_wp_error( $category_url ) ) {
        return;
    }

    $total_items = isset( $wp_query->found_posts ) ? (int) $wp_query->found_posts : 0;

    $list_items = array();
    if ( ! empty( $wp_query->posts ) ) {
        $position = 1;
        foreach ( $wp_query->posts as $queried_post ) {
            $product_id = is_object( $queried_post ) ? $queried_post->ID : (int) $queried_post;
            $permalink  = get_permalink( $product_id );
            if ( ! $permalink ) {
                continue;
            }
            $list_items[] = array(
                '@type'    => 'ListItem',
                'position' => $position,
                'url'      => $permalink,
            );
            ++$position;
        }
    }

    $markup = array(
        '@context' => 'https://schema.org',
        '@graph'   => array(
            array(
                '@type'      => 'CollectionPage',
                '@id'        => $category_url . '#webpage',
                'url'        => $category_url,
                'name'       => $term->name,
                'isPartOf'   => array( '@id' => home_url( '/' ) . '#website' ),
                'mainEntity' => array( '@id' => $category_url . '#product-list' ),
            ),
            array(
                '@type'           => 'ItemList',
                '@id'             => $category_url . '#product-list',
                'name'            => $term->name,
                'numberOfItems'   => $total_items,
                'itemListElement' => $list_items,
            ),
        ),
    );

    $markup = apply_filters( 'rma_category_structured_data', $markup, $term );

    echo '<script type="application/ld+json">' . wp_json_encode( $markup, JSON_UNESCAPED_SLASHES ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
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
/**
 * Shared builders used by both the normal WooCommerce-extension path below
 * and the standalone fallback path (rma_output_standalone_product_schema)
 * for sites where WooCommerce's own native schema never fires at all.
 * Keeping these in one place means both paths produce identical shapes —
 * "the same info pushed out as though it did have stock and price" is only
 * true if there is exactly one place that decides what that info is.
 */
function rma_build_dimension_properties( $product, $unit, $on_display ) {
    $properties = array();

    $width  = $product->get_width();
    $length = $product->get_length();
    $height = $product->get_height();

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

    return $properties;
}

/**
 * Builds a complete Offer node from scratch: real availability (derived
 * the same way WooCommerce core itself does — backorder, then in-stock,
 * then out-of-stock), seller identity, and showroom location always
 * included where determinable. price / priceCurrency / inventoryLevel are
 * included ONLY when the product actually has them — never fabricated for
 * a catalog-only site that doesn't manage price or stock. This is what
 * lets a client with no ERP and no dedicated inventory staff still publish
 * everything else (seller, showroom, dimensions live alongside this via
 * additionalProperty) instead of getting nothing at all.
 */
function rma_build_offer_node( $product, $on_display ) {
    $offer = array(
        '@type' => 'Offer',
        'url'   => get_permalink( $product->get_id() ),
    );

    if ( $product->is_on_backorder() ) {
        // "BackOrder" is schema.org's technically-correct value here (its
        // own spec defines it as "available on backorder"), but AI answer
        // engines can read the word itself as a signal the item ISN'T
        // available, which defeats the purpose of publishing this data at
        // all. Filterable per site — this fallback default only applies via
        // rma_build_offer_node(), i.e. only when there's no other, more
        // authoritative availability signal already present to defer to
        // (WooCommerce's own native output, where it fires, is never
        // touched by this — see add_native_woo_dimensions_to_structured_data()).
        $availability = apply_filters( 'rma_backorder_availability_value', 'LimitedAvailability', $product );
    } elseif ( $product->is_in_stock() ) {
        $availability = 'InStock';
    } else {
        $availability = 'OutOfStock';
    }
    $offer['availability'] = 'https://schema.org/' . $availability;

    $price = $product->get_price();
    if ( '' !== $price && null !== $price ) {
        $offer['price']         = $price;
        $offer['priceCurrency'] = get_woocommerce_currency();
    }

    if ( $product->managing_stock() ) {
        $stock_quantity = $product->get_stock_quantity();
        if ( null !== $stock_quantity ) {
            $offer['inventoryLevel'] = array(
                '@type' => 'QuantitativeValue',
                'value' => (int) $stock_quantity,
            );
        }
    }

    $seller = rma_get_business_seller_entity();
    if ( ! empty( $seller ) ) {
        $offer['seller'] = $seller;
    }

    if ( ! empty( $on_display ) ) {
        $available_at = rma_get_locations_for_display_value( $on_display );
        if ( ! empty( $available_at ) ) {
            $offer['availableAtOrFrom'] = ( 1 === count( $available_at ) ) ? $available_at[0] : $available_at;
        }
    }

    return $offer;
}

add_filter( 'woocommerce_structured_data_product', 'add_native_woo_dimensions_to_structured_data', 10, 2 );

function add_native_woo_dimensions_to_structured_data( $markup, $product ) {

    if ( ! $product instanceof WC_Product ) {
        return $markup;
    }

    // Proves WooCommerce's own generator actually ran and applied this
    // filter for this page load. rma_output_standalone_product_schema()
    // checks this so it only ever runs on sites where WooCommerce's native
    // schema doesn't fire at all — never alongside a working native output,
    // which would mean two competing Product entities on the same page.
    $GLOBALS['rma_native_product_filter_fired'] = true;

    $unit = get_option( 'woocommerce_dimension_unit', 'in' );

    $material = $product->get_attribute( 'material' );

    // Attribute slug for showroom/on-display status. Confirmed real-world
    // convention (as used on kemperhomefurnishings.com): a WooCommerce
    // attribute taxonomy named "On Display in Showroom" (pa_on-display-in-showroom).
    // Filterable per-site in case another site uses a different slug.
    $on_display_slug = apply_filters( 'rma_on_display_attribute_slug', 'on-display-in-showroom' );
    $on_display      = $product->get_attribute( $on_display_slug );

    $properties = rma_build_dimension_properties( $product, $unit, $on_display );

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

    // Some clients run a catalog-only site with no price or stock
    // management at all (no ERP integration, no dedicated workforce to
    // keep it current) — for those, WooCommerce core produces no `offers`
    // at all. That used to mean this plugin added nothing either, even
    // though seller identity, showroom location, and dimensions were all
    // real, available facts the whole time. If WooCommerce built an offer,
    // keep enriching it in place (unchanged behavior); if it didn't, build
    // one from scratch — real fields only, price/stock simply omitted
    // rather than invented.
    if ( empty( $markup['offers'] ) ) {
        $markup['offers'] = rma_build_offer_node( $product, $on_display );

        return $markup;
    }

    // Stock quantity: WooCommerce core's own JSON-LD already emits
    // offers.price / offers.priceCurrency / offers.availability correctly,
    // so price is deliberately left untouched here to avoid two sources of
    // truth disagreeing. What core does NOT emit is the actual numeric
    // count, only the InStock/OutOfStock enum — so we add that as
    // Offer.inventoryLevel (a real schema.org QuantitativeValue), merged
    // into whatever offer(s) core already produced.
    if ( $product->managing_stock() ) {
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

    // Showroom location: ties this specific product's Offer to the actual
    // physical store it's on display at (Offer.availableAtOrFrom), using
    // the same "On Display in Showroom" attribute value already read above
    // for the visible facts table. Only real, site-configured locations are
    // ever used (see rma_get_business_locations()) — a product whose
    // on-display value doesn't match any configured location gets nothing
    // added here, never a guessed or invented location.
    if ( ! empty( $on_display ) ) {
        $available_at = rma_get_locations_for_display_value( $on_display );

        if ( ! empty( $available_at ) ) {
            // A single matched location is embedded as one object; multiple
            // matches (a product on display in more than one showroom) as a
            // list — both are valid shapes for availableAtOrFrom.
            $available_at_value = ( 1 === count( $available_at ) ) ? $available_at[0] : $available_at;

            if ( isset( $markup['offers']['@type'] ) ) {
                if ( empty( $markup['offers']['availableAtOrFrom'] ) ) {
                    $markup['offers']['availableAtOrFrom'] = $available_at_value;
                }
            } else {
                foreach ( $markup['offers'] as $index => $offer ) {
                    if ( is_array( $offer ) && empty( $offer['availableAtOrFrom'] ) ) {
                        $markup['offers'][ $index ]['availableAtOrFrom'] = $available_at_value;
                    }
                }
            }
        }
    }

    return $markup;
}

/**
 * Converts an HTML product description into readable plain text for a
 * schema.org `description` field. A bare wp_strip_all_tags() runs every
 * cell of a table-formatted description together with no separator at all
 * (confirmed real on alysonjon.com's imported catalog content, e.g. a
 * "Features / Leather / Yes / Product Details / Weight / 54.00 lbs" table
 * collapsing into "FeaturesLeatherYesProduct DetailsWeight54.00 lbs") —
 * this inserts a space at each block-level tag boundary first, so the
 * words a table only visually separated stay separated as real text too.
 */
function rma_html_to_plain_text( $html ) {
    $html = preg_replace( '/<\/(td|th|tr|p|div|li|h[1-6])>/i', '$0 ', $html );
    $html = preg_replace( '/<br\s*\/?>/i', ' ', $html );
    $text = wp_strip_all_tags( $html );
    $text = preg_replace( '/\s+/', ' ', $text );

    return trim( $text );
}

/**
 * Standalone Product schema — only for sites where WooCommerce's own
 * native structured-data output never fires at all for a product page.
 *
 * Confirmed real (alysonjon.com, 2026-09-24): a large catalog-only site
 * (45,000+ products, no price or stock managed — no ERP, no dedicated
 * inventory staff) produced ZERO Product structured data on every product
 * page checked, not even the base name/url/image WooCommerce normally
 * emits unconditionally — meaning woocommerce_structured_data_product
 * never fired at all for these pages (something upstream in that site's
 * rendering pipeline, not a price/stock gate in WooCommerce's own
 * generator — that generator doesn't check price/stock before running).
 * Since this plugin previously only EXTENDED whatever WooCommerce already
 * produced, it had nothing to extend and published nothing either — even
 * though dimensions, material, seller, and showroom location were all
 * real, available facts the whole time.
 *
 * Hooked to wp_footer (priority 20, after WooCommerce's own native output,
 * which prints at its default priority) rather than wp_head, specifically
 * so the flag check below runs only after woocommerce_single_product_summary
 * would already have fired during this same page load if it was ever going
 * to. That flag — set inside add_native_woo_dimensions_to_structured_data()
 * — is the real, first-party signal for "did WooCommerce's own generator
 * actually run," not a guess, and it's what guarantees these two code paths
 * never both emit a Product entity for the same page.
 */
add_action( 'wp_footer', 'rma_output_standalone_product_schema', 20 );

function rma_output_standalone_product_schema() {
    if ( ! function_exists( 'is_product' ) || ! is_product() ) {
        return;
    }

    if ( ! empty( $GLOBALS['rma_native_product_filter_fired'] ) ) {
        return;
    }

    $product = wc_get_product( get_the_ID() );
    if ( ! $product instanceof WC_Product ) {
        return;
    }

    $unit            = get_option( 'woocommerce_dimension_unit', 'in' );
    $on_display_slug = apply_filters( 'rma_on_display_attribute_slug', 'on-display-in-showroom' );
    $on_display      = $product->get_attribute( $on_display_slug );
    $material        = $product->get_attribute( 'material' );
    $permalink       = get_permalink( $product->get_id() );

    $markup = array(
        '@context' => 'https://schema.org/',
        '@type'    => 'Product',
        '@id'      => $permalink . '#product',
        'name'     => $product->get_name(),
        'url'      => $permalink,
    );

    $description = $product->get_description();
    if ( empty( $description ) ) {
        $description = $product->get_short_description();
    }
    if ( ! empty( $description ) ) {
        $markup['description'] = rma_html_to_plain_text( $description );
    }

    $image_id = $product->get_image_id();
    if ( $image_id ) {
        $image_url = wp_get_attachment_image_url( $image_id, 'full' );
        if ( $image_url ) {
            $markup['image'] = $image_url;
        }
    }

    $sku = $product->get_sku();
    if ( ! empty( $sku ) ) {
        $markup['sku'] = $sku;
    }

    // Brand attribute slug is filterable — sites vary between a plain
    // "Brand" product attribute (assumed here as the real-world default)
    // and a dedicated brand taxonomy from a separate brands plugin.
    $brand_slug = apply_filters( 'rma_brand_attribute_slug', 'brand' );
    $brand      = $product->get_attribute( $brand_slug );
    if ( ! empty( $brand ) ) {
        $markup['brand'] = array(
            '@type' => 'Brand',
            'name'  => $brand,
        );
    }

    $properties = rma_build_dimension_properties( $product, $unit, $on_display );
    if ( ! empty( $properties ) ) {
        $markup['additionalProperty'] = $properties;
    }

    if ( ! empty( $material ) ) {
        $markup['material'] = $material;
    }

    $markup['offers'] = rma_build_offer_node( $product, $on_display );

    $markup = apply_filters( 'rma_standalone_product_structured_data', $markup, $product );

    echo '<script type="application/ld+json">' . wp_json_encode( $markup, JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
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
 * Showroom location resolution.
 *
 * Deliberately NOT hardcoded to any one client's real address — this plugin
 * runs on multiple sites, and a real address baked into the shared codebase
 * as a "default" would be wrong (and confusing) everywhere except the one
 * site it came from. Instead, each site's admin enters their own real
 * locations once in WooCommerce > AEO Settings (a simple pipe-delimited
 * textarea, same idea as the GitHub token field above), and this function
 * just parses whatever is actually configured there. A site with nothing
 * configured gets an empty array, never an invented placeholder — same
 * "real data or nothing" rule this whole plugin follows everywhere else.
 *
 * Confirmed real for kemperhomefurnishings.com (2026-09-15, from the site's
 * own visible About Us page): Somerset — 1755 US Hwy. 27 South, Somerset,
 * KY 42501, (606) 677-0800; London — 1334 South Laurel Road, London, KY
 * 40744, (606) 864-4061. That data is entered via the settings field on
 * that site, not written into this file.
 */
function rma_get_business_locations() {
    static $locations = null;

    if ( null !== $locations ) {
        return $locations;
    }

    $locations = array();
    $raw       = get_option( 'rma_business_locations', '' );

    if ( empty( trim( $raw ) ) ) {
        return $locations = apply_filters( 'rma_business_locations', $locations );
    }

    $lines = preg_split( '/\r\n|\r|\n/', $raw );

    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( '' === $line ) {
            continue;
        }

        $fields = array_map( 'trim', explode( '|', $line ) );
        $name   = isset( $fields[0] ) ? $fields[0] : '';

        if ( '' === $name ) {
            continue;
        }

        $street  = isset( $fields[1] ) ? $fields[1] : '';
        $city    = isset( $fields[2] ) ? $fields[2] : '';
        $state   = isset( $fields[3] ) ? $fields[3] : '';
        $zip     = isset( $fields[4] ) ? $fields[4] : '';
        $phone   = isset( $fields[5] ) ? $fields[5] : '';

        $entity = array(
            '@type'   => 'FurnitureStore',
            'name'    => $name,
            'address' => array(
                '@type'           => 'PostalAddress',
                'streetAddress'   => $street,
                'addressLocality' => $city,
                'addressRegion'   => $state,
                'postalCode'      => $zip,
            ),
        );

        if ( '' !== $phone ) {
            $entity['telephone'] = $phone;
        }

        $locations[] = $entity;
    }

    $locations = apply_filters( 'rma_business_locations', $locations );

    return $locations;
}

/**
 * Matches a product's raw "On Display in Showroom" attribute value (which
 * may name more than one location, comma-separated, for products on
 * display in multiple stores) against the real locations configured above,
 * and returns the matching entity/entities. A configured location's name
 * only has to appear as a substring of the attribute term (or vice versa)
 * so "Somerset" on the product matches a configured "Somerset" location
 * without requiring an exact string match. No match, anywhere, means an
 * empty array — never a guess at which store a product is actually in.
 */
function rma_get_locations_for_display_value( $on_display_raw ) {
    $locations = rma_get_business_locations();

    if ( empty( $locations ) ) {
        return array();
    }

    $terms   = array_map( 'trim', explode( ',', $on_display_raw ) );
    $matches = array();

    foreach ( $terms as $term ) {
        if ( '' === $term ) {
            continue;
        }

        foreach ( $locations as $location ) {
            if ( empty( $location['name'] ) ) {
                continue;
            }

            if ( false !== stripos( $term, $location['name'] ) || false !== stripos( $location['name'], $term ) ) {
                $matches[ $location['name'] ] = $location;
            }
        }
    }

    return array_values( $matches );
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

/**
 * When the AEO Preview finds no Product schema at all, this points at the
 * specific likely cause instead of a generic hint — checked live twice now
 * (ABC Furniture Retailer and Kemper), both times traced to an SEO plugin's
 * own "disable WooCommerce's native schema" setting. Read-only detection
 * only: this never changes another plugin's settings itself — see the
 * writeup for why that's a deliberate boundary, not an oversight.
 *
 * Detects by plugin file path (the standard, documented slug for each),
 * not by internal option names, since those vary by version and aren't
 * worth coupling to. Not yet verified against a live Yoast or Rank Math
 * install this session — only the SEOPress path has been confirmed live,
 * twice.
 */
function rma_seo_plugin_disable_hint() {
    if ( ! function_exists( 'is_plugin_active' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    if ( is_plugin_active( 'wp-seopress/seopress.php' ) || is_plugin_active( 'wp-seopress-pro/seopress-pro.php' ) ) {
        return __( 'SEOPress is active on this site. Confirmed live cause on two sites so far: SEO → PRO → WooCommerce → "Remove default JSON-LD structured data (WooCommerce 3+)" — check whether that box is checked with no replacement schema built under SEO → Schemas.', 'rma' );
    }
    if ( is_plugin_active( 'wordpress-seo/wp-seo.php' ) || is_plugin_active( 'wordpress-seo-premium/wp-seo-premium.php' ) ) {
        return __( 'Yoast SEO is active on this site. Not yet confirmed live, but check its WooCommerce-related SEO settings for anything disabling native Product schema.', 'rma' );
    }
    if ( is_plugin_active( 'seo-by-rank-math/rank-math.php' ) ) {
        return __( 'Rank Math is active on this site. Not yet confirmed live, but check its schema/WooCommerce settings for anything disabling native Product schema.', 'rma' );
    }

    return '';
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
                        <?php esc_html_e( 'The page fetched successfully, but no Product entity showed up in its JSON-LD. This means WooCommerce\'s own schema output isn\'t reaching this page — a theme or another plugin may be disabling it. This plugin only extends that data; it can\'t create it from nothing. Nothing from this plugin (or WooCommerce core) is reaching search engines or AI crawlers for this product until that\'s fixed.', 'rma' ); ?>
                        <?php $rma_seo_hint = rma_seo_plugin_disable_hint(); ?>
                        <?php if ( $rma_seo_hint ) : ?>
                            <br /><br /><strong><?php esc_html_e( 'Likely cause on this site:', 'rma' ); ?></strong> <?php echo esc_html( $rma_seo_hint ); ?>
                        <?php endif; ?>
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

                <?php
                // v1.18 diagnostic: computed live, right here in wp-admin, via
                // the exact same functions the front-end filter uses — NOT
                // from the fetched page above. Added specifically to isolate
                // whether a showroom-location mismatch (or a stale front-end
                // render) is the cause when availableAtOrFrom doesn't show up
                // in the fetched JSON-LD above, instead of guessing further.
                $rma_on_display_slug  = apply_filters( 'rma_on_display_attribute_slug', 'on-display-in-showroom' );
                $rma_on_display_value = $product->get_attribute( $rma_on_display_slug );
                $rma_locations_raw    = get_option( 'rma_business_locations', '' );
                $rma_locations        = rma_get_business_locations();
                $rma_matched          = rma_get_locations_for_display_value( $rma_on_display_value );
                ?>
                <h3><?php esc_html_e( 'Showroom-location matching (debug)', 'rma' ); ?></h3>
                <p><?php esc_html_e( 'Computed right now, directly in wp-admin, using the same functions the live front-end filter uses — independent of whatever the fetched page above shows.', 'rma' ); ?></p>
                <table class="widefat striped" style="max-width:900px;">
                    <tbody>
                        <tr>
                            <td style="width:260px;"><strong><?php esc_html_e( 'On Display attribute (raw)', 'rma' ); ?></strong></td>
                            <td><?php echo '' !== $rma_on_display_value ? esc_html( $rma_on_display_value ) : esc_html__( '(empty)', 'rma' ); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e( 'Saved "Showroom Locations" setting (raw)', 'rma' ); ?></strong></td>
                            <td><pre style="white-space:pre-wrap;margin:0;"><?php echo '' !== trim( $rma_locations_raw ) ? esc_html( $rma_locations_raw ) : esc_html__( '(empty — nothing saved)', 'rma' ); ?></pre></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e( 'Parsed locations', 'rma' ); ?></strong></td>
                            <td>
                                <?php if ( empty( $rma_locations ) ) : ?>
                                    <?php esc_html_e( '(none parsed)', 'rma' ); ?>
                                <?php else : ?>
                                    <?php echo esc_html( implode( ', ', wp_list_pluck( $rma_locations, 'name' ) ) ); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e( 'Matched location(s) for this product', 'rma' ); ?></strong></td>
                            <td>
                                <?php if ( empty( $rma_matched ) ) : ?>
                                    <strong style="color:#a00;"><?php esc_html_e( 'No match', 'rma' ); ?></strong>
                                <?php else : ?>
                                    <span style="color:#0a0;"><?php echo esc_html( implode( ', ', wp_list_pluck( $rma_matched, 'name' ) ) ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
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
