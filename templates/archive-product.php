<?php
/**
 * Vlastní šablona archivu kategorie produktů.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

$term = get_queried_object();

// Načteme všechny produkty v kategorii bez stránkování
$products = wc_get_products([
    'status'   => 'publish',
    'limit'    => -1,
    'category' => [ $term->slug ],
    'orderby'  => 'menu_order',
    'order'    => 'ASC',
]);

?>

<div class="sp-archive-outer">

  <?php
  // ── Název a popis kategorie (stejná struktura jako WooCommerce) ──
  $cat_name        = $term->name ?? '';
  $cat_description = term_description( $term->term_id, 'product_cat' );
  $cat_thumbnail   = '';
  $thumbnail_id    = get_term_meta( $term->term_id, 'thumbnail_id', true );
  if ( $thumbnail_id ) {
      $cat_thumbnail = wp_get_attachment_image( (int) $thumbnail_id, 'medium', false, [
          'class' => 'woocommerce-products-header__image',
      ] );
  }
  if ( $cat_name || $cat_description || $cat_thumbnail ) : ?>
  <header class="woocommerce-products-header">
    <?php if ( $cat_thumbnail ) echo $cat_thumbnail; ?>
    <?php if ( $cat_name ) : ?>
      <h1 class="woocommerce-products-header__title page-title">
        <?php echo esc_html( $cat_name ); ?>
      </h1>
    <?php endif; ?>
    <?php if ( $cat_description ) : ?>
      <div class="term-description woocommerce-products-header__description">
        <?php echo wp_kses_post( $cat_description ); ?>
      </div>
    <?php endif; ?>
  </header>
  <?php endif; ?>

  <div class="sp-archive-wrapper">

  <?php if ( ! empty( $products ) ) : ?>

    <?php
    // První produkt – výchozí obrázek pro pravý panel
    $first      = $products[0];
    $first_img  = get_the_post_thumbnail_url( $first->get_id(), 'large' ) ?: wc_placeholder_img_src( 'large' );
    $first_name = $first->get_name();
    ?>

    <!-- LEVÝ SLOUPEC – seznam produktů -->
    <div class="sp-product-list">

      <?php foreach ( $products as $index => $product ) :

        $product_id  = $product->get_id();
        $name        = $product->get_name();
        $short_desc  = $product->get_short_description();
        $permalink   = $product->get_permalink();
        $thumb_url   = get_the_post_thumbnail_url( $product_id, 'large' ) ?: wc_placeholder_img_src( 'large' );
        $is_variable = $product->is_type( 'variable' );
        $price_html  = $product->get_price_html();

        // CFB bundle detection
        $is_cfb_bundle    = ( get_post_meta( $product_id, '_cfb_is_bundle', true ) === '1' );
        $cfb_bundle_items = $is_cfb_bundle ? (array) get_post_meta( $product_id, '_cfb_bundle_items', true ) : [];

        // Varianty – připravíme data pro JS
        $variations_data = [];
        if ( $is_variable )
        {
            $available_variations = $product->get_available_variations();
            foreach ( $available_variations as $variation )
            {
                $var_obj   = wc_get_product( $variation['variation_id'] );
                $var_image = $variation['image']['url'] ?? $thumb_url;
                $var_attrs = [];

                foreach ( $variation['attributes'] as $attr_key => $attr_val )
                {
                    // Normalizujeme klíč: attribute_pa_varianty → attribute_varianty
                    // Selecty generují klíče bez pa_, takže JS porovnání sedí.
                    // pa_ prefix se obnoví v JS těsně před odesláním na WC server.
                    $normalized_key = preg_replace( '/^attribute_pa_/', 'attribute_', $attr_key );
                    $var_attrs[ $normalized_key ] = stripslashes( trim( $attr_val, '"' ) );
                }

                // Spolehlivá detekce skladu přes get_stock_status() – stejný zdroj
                // jaký WooCommerce používá pro třídu "outofstock" na frontendu.
                // get_stock_quantity() > 0 nestačí: WooCommerce synchronizuje
                // stock_status při každé změně qty, takže outofstock = opravdu nedostupné.
                $var_in_stock = $var_obj ? ( $var_obj->get_stock_status() === 'instock' ) : false;

                $variations_data[] = [
                    'id'         => $variation['variation_id'],
                    'price_html' => $var_obj ? $var_obj->get_price_html() : $price_html,
                    'image'      => $var_image,
                    'attributes' => $var_attrs,
                    'in_stock'   => $var_in_stock,
                    'name'       => $var_obj ? implode(', ', array_map(
                        function($k, $v) { return wc_attribute_label(str_replace('attribute_', '', $k)) . ': ' . $v; },
                        array_keys($var_attrs), $var_attrs
                    )) : '',
                ];
            }

            // Navíc projdeme i varianty které get_available_variations() vynechalo
            // (WooCommerce je může filtrovat). Použijeme get_children() jako zálohu.
            $all_child_ids     = $product->get_children();
            $seen_variation_ids = array_column( $variations_data, 'id' );
            foreach ( $all_child_ids as $child_id )
            {
                if ( in_array( $child_id, $seen_variation_ids, true ) ) continue;
                $var_obj = wc_get_product( $child_id );
                if ( ! $var_obj ) continue;
                $var_in_stock = ( $var_obj->get_stock_status() === 'instock' );
                // Přidáme jen in_stock info – ostatní data nepotřebujeme pro tyto skryté varianty
                $variations_data[] = [
                    'id'       => $child_id,
                    'in_stock' => $var_in_stock,
                    'hidden'   => true,
                ];
            }
        }

        // Celkový stock produktu:
        // Variabilní: skladem = alespoň jedna varianta má stock_status = instock
        // Simple: přímo get_stock_status()
        if ( $is_variable ) {
            $in_stock = ! empty( array_filter( $variations_data, fn($v) => $v['in_stock'] ) );
        } else {
            $in_stock = ( $product->get_stock_status() === 'instock' );
        }

        $active_class = ( $index === 0 ) ? ' active' : '';

        // Název produktu – jen název bez hodnot variant
        $display_name = $name;

      ?>

      <div
        class="sp-product-item<?php echo $active_class; ?>"
        data-id="<?php echo esc_attr( $product_id ); ?>"
        data-img="<?php echo esc_url( $thumb_url ); ?>"
        data-name="<?php echo esc_attr( $name ); ?>"
        data-price="<?php echo esc_attr( strip_tags( $price_html ) ); ?>"
        data-price-html="<?php echo esc_attr( $price_html ); ?>"
        data-permalink="<?php echo esc_url( $permalink ); ?>"
        data-type="<?php echo $is_variable ? 'variable' : 'simple'; ?>"
        data-variations="<?php echo esc_attr( wp_json_encode( $variations_data ) ); ?>"
        data-is-cfb-bundle="<?php echo $is_cfb_bundle ? '1' : '0'; ?>"
      >

        <h3><?php echo esc_html( $display_name ); ?></h3>

        <?php if ( $short_desc ) : ?>
          <div class="sp-product-desc"><?php echo wp_kses_post( do_shortcode( $short_desc ) ); ?></div>
        <?php endif; ?>

        <?php if ( $is_cfb_bundle && ! empty( $cfb_bundle_items ) ) : ?>
          <div class="sp-cfb-bundle-summary">
            <strong><?php esc_html_e( 'V balíčku si můžeš navolit tyto produkty:', 'sp-product-archive' ); ?></strong>
            <ul>
              <?php foreach ( $cfb_bundle_items as $bitem ) :
                $blimit = intval( $bitem['limit'] ?? 1 );
                $btitle = trim( $bitem['title'] ?? '' );
                if ( $btitle === '' ) {
                    if ( ( $bitem['type'] ?? '' ) === 'category' && ! empty( $bitem['category_id'] ) ) {
                        $bcat   = get_term( $bitem['category_id'], 'product_cat' );
                        $btitle = ( $bcat && ! is_wp_error( $bcat ) ) ? $bcat->name : __( 'Výběr', 'sp-product-archive' );
                    } else {
                        $btitle = __( 'Výběr produktů', 'sp-product-archive' );
                    }
                }
              ?>
                <li><?php echo esc_html( $blimit . '× ' . $btitle ); ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <!-- Inline akce – desktop -->
        <div class="sp-inline-actions">

          <?php if ( $is_variable ) : ?>
            <div class="sp-variation-selects">
              <?php
              $attributes = $product->get_variation_attributes();
              foreach ( $attributes as $attr_name => $options ) :
                $label = wc_attribute_label( $attr_name );
                // Klíč sjednocen s $normalized_key výše:
                // stripneme 'pa_' prefix pokud existuje, pak sanitize_title
$attr_key_normalized = 'attribute_' . sanitize_title( preg_replace( '/^pa_/', '', $attr_name ) );
              ?>
                <div class="sp-variation-row">
                  <label><?php echo esc_html( $label ); ?></label>
                  <select
                    class="sp-inline-variation-select"
                    data-attribute="<?php echo esc_attr( $attr_key_normalized ); ?>"
                  >
                    <option value="">— Vyberte —</option>
<?php
                  // Pro každou option zjistíme, zda existuje varianta s touto hodnotou a je skladem
                  foreach ( $options as $option ) :
                    $opt_val     = trim( $option, '"' );
                    $opt_label   = $opt_val;
                    $opt_instock = false;
                    $opt_has_matching_variant = false;
                    // Zakázat option pouze pokud VŠECHNY varianty s danou hodnotou nejsou skladem
                    foreach ( $variations_data as $vd ) {
                        if ( isset( $vd['hidden'] ) ) continue;
                        if ( ! isset( $vd['attributes'][ $attr_key_normalized ] ) ) continue;
                        $attr_val_match = $vd['attributes'][ $attr_key_normalized ];
                        // Prázdný string = „any" → odpovídá všem hodnotám atributu
                        if ( $attr_val_match !== '' && $attr_val_match !== $opt_val ) continue;
                        $opt_has_matching_variant = true;
                        if ( $vd['in_stock'] ) {
                            $opt_instock = true;
                            break;
                        }
                    }
                    // Pokud pro tuto hodnotu neexistuje žádná varianta v datech, považujeme za dostupnou
                    if ( ! $opt_has_matching_variant ) $opt_instock = true;
                    $opt_display = $opt_instock ? $opt_label : $opt_label . ' – není skladem';
                  ?>
  <option value="<?php echo esc_attr( $opt_val ); ?>"<?php echo $opt_instock ? '' : ' disabled'; ?>>
    <?php echo esc_html( $opt_display ); ?>
  </option>
<?php endforeach; ?>
                  </select>
                </div>
              <?php endforeach; ?>
            </div>

            <?php
            // Výpis vyprodaných variant s formulářem hlídacího psa – desktop
            // (proměnná $oos_variations se použije i v mobilním panelu níže)
            $oos_variations = array_filter( $variations_data, function($v) {
                return ! $v['in_stock'] && empty( $v['hidden'] ) && ! empty( $v['name'] );
            });
            ?>

          <?php endif; ?>

          <div class="sp-inline-bottom-row">
            <div class="sp-inline-price" id="sp-inline-price-<?php echo esc_attr( $product_id ); ?>">
              <?php if ( ! $is_variable ) : ?>
                <?php echo $price_html; ?>
              <?php endif; ?>
            </div>
            <?php if ( ! $is_cfb_bundle && $in_stock ) : ?>
              <input type="number" class="sp-qty sp-inline-qty" value="1" min="1" />
            <?php endif; ?>
            <?php if ( $is_cfb_bundle ) : ?>
              <?php if ( $in_stock ) : ?>
                <button
                  class="custom-product-btn sp-bundle-select-btn"
                  data-product-id="<?php echo esc_attr( $product_id ); ?>"
                >
                  VÝBĚR PRODUKTŮ
                </button>
              <?php else : ?>
                <span class="sp-out-of-stock">Produkt není skladem</span>
              <?php endif; ?>
            <?php else : ?>
              <?php if ( $in_stock ) : ?>
                <button
                  class="sp-add-to-cart custom-product-btn sp-inline-cart-btn"
                  data-product-id="<?php echo esc_attr( $product_id ); ?>"
                >
                  DO KOŠÍKU
                </button>
              <?php endif; ?>
            <?php endif; ?>
            <a href="<?php echo esc_url( $permalink ); ?>" class="sp-detail-btn">
              ZOBRAZIT DETAIL
            </a>
          </div>

          <?php // ── Hlídací pes – zobrazí se pod košíkovým řádkem ── ?>
          <?php
         $hp_svg = '<svg class="sp-hlidaci-icon" xmlns="http://www.w3.org/2000/svg" viewBox="26 15 411 401" width="38" height="38" fill-rule="evenodd" aria-hidden="true"><path d="M0 0 C38.08987379 34.85317269 61.88574382 84.40217613 65 136 C66.4473474 175.18756063 59.61433871 212.26540064 41 247 C40.65743164 247.65564941 40.31486328 248.31129883 39.96191406 248.98681641 C31.74901637 264.67398678 21.29897077 278.30079154 9 291 C8.49887695 291.5269043 7.99775391 292.05380859 7.48144531 292.59667969 C-26.69675167 328.47846281 -74.99049641 350.89410598 -124.69921875 352.203125 C-179.14422839 352.91843446 -228.60550042 333.5360184 -268.08984375 296.09375 C-273.07033851 291.23200476 -273.07033851 291.23200476 -275 289 C-275 288.34 -275 287.68 -275 287 C-275.66 287 -276.32 287 -277 287 C-280.25714194 283.55673567 -283.09517385 279.74209956 -286 276 C-286.44085938 275.43796875 -286.88171875 274.8759375 -287.3359375 274.296875 C-309.51185953 245.83991147 -324.45798734 209.97212857 -328 174 C-328.10957031 173.01257813 -328.21914063 172.02515625 -328.33203125 171.0078125 C-333.45683112 117.29990989 -318.00941593 64.21027967 -284.375 22 C-282.93506653 20.31741107 -281.47572593 18.65128643 -280 17 C-279.54947266 16.49065918 -279.09894531 15.98131836 -278.63476562 15.45654297 C-262.43570827 -2.79172758 -243.78473966 -17.89097438 -222 -29 C-221.35450195 -29.33692871 -220.70900391 -29.67385742 -220.04394531 -30.02099609 C-201.92127019 -39.40515221 -182.13692921 -45.75652709 -162 -49 C-161.28247559 -49.11569336 -160.56495117 -49.23138672 -159.82568359 -49.35058594 C-101.51423845 -58.24443387 -43.68893013 -38.66535137 0 0 Z M-232 -3 C-233.47919922 -2.01386719 -233.47919922 -2.01386719 -234.98828125 -1.0078125 C-242.62321779 4.31785959 -249.30940761 10.56103631 -256 17 C-256.56041992 17.53318848 -257.12083984 18.06637695 -257.69824219 18.61572266 C-268.53812685 28.9606212 -277.29703445 40.0975327 -285 53 C-285.37769531 53.62583984 -285.75539063 54.25167969 -286.14453125 54.89648438 C-311.30897854 96.95706055 -318.18633681 148.24508314 -306.41796875 195.6953125 C-303.12596122 208.30894746 -298.88415424 220.34937461 -293 232 C-292.45037598 233.10093994 -292.45037598 233.10093994 -291.88964844 234.22412109 C-286.95461755 244.0643098 -281.89349328 253.36543963 -275 262 C-274.42378906 262.76570313 -273.84757812 263.53140625 -273.25390625 264.3203125 C-263.96158553 276.43030467 -253.267887 287.89358528 -241 297 C-240.48147461 297.39348633 -239.96294922 297.78697266 -239.42871094 298.19238281 C-201.87136833 326.62074303 -154.52197404 340.14467062 -107.58984375 333.75 C-86.18541421 330.44343592 -65.96008072 323.40917142 -47 313 C-46.04915527 312.47817139 -46.04915527 312.47817139 -45.07910156 311.94580078 C-31.39501757 304.32220988 -19.37091631 294.74862736 -8 284 C-7.19175781 283.24460937 -6.38351562 282.48921875 -5.55078125 281.7109375 C27.62213198 249.73579037 46.79237196 202.70731195 48.18359375 156.91796875 C49.07745007 108.24794373 33.1551512 63.23646836 1.29443359 26.49414062 C0.12333522 25.14236355 -1.03850021 23.78257524 -2.19921875 22.421875 C-6.46809272 17.49044496 -10.96815389 13.14497157 -16 9 C-16.72574219 8.38640625 -17.45148438 7.7728125 -18.19921875 7.140625 C-79.27464582 -43.33493456 -166.4550378 -46.89057941 -232 -3 Z " fill="currentColor" transform="translate(354,64)"/><path d="M0 0 C13.10907872 7.63635653 21.07681501 19.23679931 25.00390625 33.828125 C25.25011719 34.87484375 25.49632812 35.9215625 25.75 37 C26.10384766 38.44632813 26.10384766 38.44632813 26.46484375 39.921875 C29.25190771 53.4017922 27.98984185 68.80227285 21.10546875 80.88671875 C15.98666267 88.52589558 9.9918183 94.05068992 0.75 96 C-7.12945578 96.93511051 -13.99492133 95.05337794 -20.44921875 90.421875 C-22.48723008 88.69829971 -24.37535753 86.89904129 -26.25 85 C-26.77207031 84.51144531 -27.29414063 84.02289063 -27.83203125 83.51953125 C-31.52216952 79.73202785 -33.7554819 75.30695291 -36 70.5625 C-36.29398682 69.94181641 -36.58797363 69.32113281 -36.89086914 68.68164062 C-43.46675072 54.14047267 -44.61214062 35.58458266 -39.3671875 20.453125 C-35.41221642 11.17295665 -29.9270954 4.28171024 -21.25 -1 C-14.40727555 -3.28090815 -6.5481373 -3.06333582 0 0 Z " fill="currentColor" transform="translate(186.25,83)"/><path d="M0 0 C9.24078575 9.24078575 12.24157761 23.31392209 12.375 35.9375 C12.19923537 52.63114538 7.05911281 68.3668545 -4 81 C-11.3372981 87.74535848 -19.28219164 91.47232736 -29.27734375 91.33984375 C-36.38499659 90.45266183 -42.61156066 86.34304056 -47.40234375 81.14453125 C-57.269101 67.90039988 -58.85190739 51.82506835 -56.71142578 35.84570312 C-54.19307688 22.09450405 -47.69405549 7.64606369 -36.4296875 -0.96484375 C-23.67405751 -9.65217392 -12.20027307 -10.01627357 0 0 Z " fill="currentColor" transform="translate(288,88)"/><path d="M0 0 C13.196681 10.38887653 20.83708038 23.43720053 23.5390625 40 C24.73298875 52.66117136 22.81431112 64.50727633 14.75 74.625 C9.53755992 80.16944264 4.01841813 82.48530314 -3.5625 82.75 C-14.30392952 82.27127334 -21.50572257 77.89159381 -29.0078125 70.359375 C-30.78690589 68.40314244 -32.44383327 66.40293894 -34.0625 64.3125 C-34.51367188 63.73242188 -34.96484375 63.15234375 -35.4296875 62.5546875 C-43.69379825 50.69748513 -46.73340753 34.39996266 -44.6015625 20.25390625 C-42.42140079 10.18668896 -37.69648505 2.14093384 -29.0625 -3.6875 C-18.98556395 -8.61400207 -9.18023205 -5.05849521 0 0 Z " fill="currentColor" transform="translate(130.0625,158.6875)"/><path d="M0 0 C7.22539142 5.21914592 10.50859859 12.9868975 12.61328125 21.47265625 C15.36874279 39.48057154 9.91062756 55.81574038 -0.6875 70.25 C-6.80326357 77.62178411 -15.24767814 83.12367408 -24.859375 84.37109375 C-33.23319246 84.56346523 -39.21585037 82.85886354 -45.77734375 77.56640625 C-53.10198279 70.09266515 -55.49440672 59.83602639 -55.625 49.6875 C-55.40957827 32.82570088 -48.8907734 18.55359508 -37.43359375 6.265625 C-26.85306162 -3.58462963 -12.8957008 -8.04480404 0 0 Z " fill="currentColor" transform="translate(346,157)"/><path d="M0 0 C0.66515625 0.25523437 1.3303125 0.51046875 2.015625 0.7734375 C15.07193571 6.37634249 23.28356247 18.66570807 29.75 30.6875 C37.27237328 44.63766419 47.01838519 56.12538679 59 66.5 C70.65594283 76.60336198 81.71723592 88.27414371 83.22314453 104.38769531 C84.15016976 119.98148026 82.39821193 132.74127302 72 145 C64.67717828 153.15398348 55.58623339 157.43549822 44.75 159 C32.03073071 159.58563542 19.65056139 155.51233565 7.75073242 151.51367188 C-9.27314484 145.79472533 -26.71283507 144.55958791 -43.95263672 150.32763672 C-47.26821105 151.4164862 -50.61847089 152.38746408 -53.96435547 153.37841797 C-57.29735267 154.36869524 -60.62306199 155.38219618 -63.94799805 156.39916992 C-75.17248859 159.68572212 -88.91601324 159.85608065 -99.4609375 154.5390625 C-111.37640712 147.31645476 -118.0238092 138.38223356 -122 125 C-122.28810547 124.09701172 -122.28810547 124.09701172 -122.58203125 123.17578125 C-125.10994416 110.01645891 -122.6771619 96.72699595 -115.8125 85.3125 C-108.13577528 74.0814355 -98.02783209 65.19577272 -87.96484375 56.17578125 C-79.28451205 48.0289924 -73.21015401 37.53670002 -67.4375 27.25 C-59.43514391 13.11901022 -49.56864767 2.50684805 -33.57421875 -2.34375 C-22.74674584 -4.33174503 -10.18494841 -4.53980259 0 0 Z M-48 36 C-49.299375 37.1446875 -49.299375 37.1446875 -50.625 38.3125 C-59.60247338 48.47121988 -62.96691767 58.46932812 -63 72 C-62.19763413 83.75640421 -56.24971695 93.84742541 -47.796875 101.80078125 C-36.86106701 110.39974759 -25.50354381 112.24047476 -12 111 C-8.09704136 110.07138087 -4.62233509 108.70426896 -1 107 C0.39477672 106.41414475 1.790475 105.83047361 3.1875 105.25 C4.115625 104.8375 5.04375 104.425 6 104 C8.54263365 106.20361583 9.87880939 107.61208421 10.89453125 110.86328125 C12.82352749 116.33672289 17.03737598 119.87506511 21 124 C22.06889002 125.17731364 23.1365434 126.35575091 24.203125 127.53515625 C25.32140382 128.73318477 26.44118478 129.92981298 27.5625 131.125 C28.07981689 131.70580322 28.59713379 132.28660645 29.13012695 132.88500977 C32.02379571 135.89270581 33.95540247 137.37494984 38.21875 137.50390625 C42.09514823 137.29909527 42.09514823 137.29909527 44.9375 134.8125 C47.04144916 131.94347842 48 130.53868291 48 127 C45.0099272 121.16194717 40.23410566 116.86201871 35.64892578 112.27832031 C34.12585805 110.75086052 32.61952275 109.20809501 31.11328125 107.6640625 C30.14160715 106.68652276 29.16898236 105.70992689 28.1953125 104.734375 C27.31891113 103.84975586 26.44250977 102.96513672 25.53955078 102.05371094 C22.86130045 99.88783497 21.35257421 99.41844696 18 99 C16.125 97.6875 16.125 97.6875 15 96 C15.64667046 91.7596894 17.87645423 88.18908727 19.84375 84.4375 C23.9940177 75.68828701 23.22063995 62.68323569 20.5 53.5625 C15.67780069 41.36318174 7.85900879 34.08318111 -3.8125 28.6875 C-18.53525112 22.33177684 -36.14393443 25.3934027 -48 36 Z " fill="currentColor" transform="translate(242,190)"/><path d="M0 0 C9.38028705 5.43471176 14.09474962 11.87779894 17.6875 21.9375 C19.58636743 30.79888133 18.25092309 39.13850529 13.99609375 47.15234375 C8.90296004 55.0106591 2.51670199 59.98246609 -6.5625 62.4375 C-17.66227272 63.43601512 -27.2969866 62.78697202 -36.375 55.6875 C-43.80291247 48.67904611 -46.86348106 39.96167674 -47.9375 29.9375 C-46.92148873 20.45472816 -44.19040309 12.48504687 -37.5625 5.4375 C-34.39322094 3.04941609 -31.14251941 1.14117483 -27.5625 -0.5625 C-26.79292969 -0.93375 -26.02335937 -1.305 -25.23046875 -1.6875 C-17.11813938 -4.34805897 -7.64664931 -3.62057259 0 0 Z M-24.5625 7.4375 C-24.913125 8.530625 -25.26375 9.62375 -25.625 10.75 C-26.93933541 13.90185287 -28.09814713 15.06066459 -31.25 16.375 C-32.343125 16.725625 -33.43625 17.07625 -34.5625 17.4375 C-34.5625 18.0975 -34.5625 18.7575 -34.5625 19.4375 C-32.9228125 19.6540625 -32.9228125 19.6540625 -31.25 19.875 C-29.3203125 20.39453125 -29.3203125 20.39453125 -27.5625 21.4375 C-26.03406347 24.32044753 -25.24941621 27.25805924 -24.5625 30.4375 C-22.15284453 29.52237697 -22.15284453 29.52237697 -21.375 26.0625 C-20.45915668 23.73950159 -19.93135486 22.67544432 -17.8125 21.30859375 C-16.09799761 20.59306553 -14.33154567 20.00492974 -12.5625 19.4375 C-12.5625 18.7775 -12.5625 18.1175 -12.5625 17.4375 C-15.2025 16.7775 -17.8425 16.1175 -20.5625 15.4375 C-20.85125 14.303125 -21.14 13.16875 -21.4375 12 C-21.80875 10.824375 -22.18 9.64875 -22.5625 8.4375 C-23.2225 8.1075 -23.8825 7.7775 -24.5625 7.4375 Z M0.4375 19.4375 C0.210625 20.200625 -0.01625 20.96375 -0.25 21.75 C-1.82981682 24.98486301 -3.39532717 25.85391358 -6.5625 27.4375 C-5.77875 28.015 -4.995 28.5925 -4.1875 29.1875 C-1.76202756 31.26647637 -0.70555583 32.52790333 0.4375 35.4375 C2.6611068 33.5120356 2.6611068 33.5120356 3.4375 30.4375 C6 29.25 6 29.25 8.4375 28.4375 C7.62458472 26.26843085 7.62458472 26.26843085 5.5 25.625 C2.75593292 24.04508259 2.36859876 22.3859794 1.4375 19.4375 C1.1075 19.4375 0.7775 19.4375 0.4375 19.4375 Z M-17.5625 36.4375 C-17.8925 38.0875 -18.2225 39.7375 -18.5625 41.4375 C-20.5425 42.0975 -22.5225 42.7575 -24.5625 43.4375 C-22.6370356 45.6611068 -22.6370356 45.6611068 -19.5625 46.4375 C-18.375 49 -18.375 49 -17.5625 51.4375 C-16.9025 51.4375 -16.2425 51.4375 -15.5625 51.4375 C-15.2221875 50.261875 -15.2221875 50.261875 -14.875 49.0625 C-13.5625 46.4375 -13.5625 46.4375 -10.9375 45.125 C-10.15375 44.898125 -9.37 44.67125 -8.5625 44.4375 C-9.38130887 42.27397603 -9.38130887 42.27397603 -11.5 41.5625 C-13.98251234 40.20840236 -14.53121397 39.01571507 -15.5625 36.4375 C-16.2225 36.4375 -16.8825 36.4375 -17.5625 36.4375 Z " fill="currentColor" transform="translate(236.5625,228.5625)"/></svg>';
          ?>
          <?php if ( ! $is_cfb_bundle ) : ?>
            <?php if ( ! $is_variable && ! $in_stock ) : ?>
              <div class="sp-hlidaci-pes-variants">
                <div class="sp-hlidaci-pes-inline" data-product-id="<?php echo esc_attr( $product_id ); ?>" data-variation-id="0">
                  <p class="sp-hlidaci-label"><span class="sp-out-of-stock"><?php esc_html_e( 'Tento produkt není skladem.', 'hlidaci-pes' ); ?></span></p>
                  <p class="sp-hlidaci-label"><?php esc_html_e( 'Mám Tě informovat, až bude produkt naskladněn?', 'hlidaci-pes' ); ?></p>
                  <div class="sp-hlidaci-form">
                    <?php echo $hp_svg; ?>
                    <input type="email" class="sp-hlidaci-email" placeholder="<?php esc_attr_e( 'Tvůj e-mail', 'hlidaci-pes' ); ?>" />
                    <button type="button" class="sp-hlidaci-btn custom-product-btn"
                            title="<?php esc_attr_e( 'Upozorníme Tě mailem, jakmile bude zboží skladem.', 'hlidaci-pes' ); ?>">
                      <?php esc_html_e( 'Hlídací pes', 'hlidaci-pes' ); ?>
                    </button>
                  </div>
                  <p class="sp-hlidaci-response" style="display:none;"></p>
                </div>
              </div>
            <?php elseif ( $is_variable && ! empty( $oos_variations ) ) : ?>
              <div class="sp-hlidaci-pes-variants">
                <?php foreach ( $oos_variations as $oos_v ) : ?>
                  <div class="sp-hlidaci-pes-inline"
                       data-product-id="<?php echo esc_attr( $product_id ); ?>"
                       data-variation-id="<?php echo esc_attr( $oos_v['id'] ); ?>">
                    <?php
                      // Zobraz jen hodnoty atributů (bez klíčů), např. "50g" místo "varianty: 50g"
                      $oos_attr_values = array_values( $oos_v['attributes'] ?? [] );
                      $oos_display_name = ! empty( $oos_attr_values ) ? implode( ', ', $oos_attr_values ) : $oos_v['name'];
                    ?>
                    <p class="sp-hlidaci-label">
                      <span class="sp-out-of-stock"><?php printf( esc_html__( 'Varianta &ldquo;%s&rdquo; není skladem.', 'hlidaci-pes' ), esc_html( $oos_display_name ) ); ?></span>
                    </p>
                    <p class="sp-hlidaci-label"><?php esc_html_e( 'Mám Tě informovat, až bude naskladněna?', 'hlidaci-pes' ); ?></p>
                    <div class="sp-hlidaci-form">
                      <?php echo $hp_svg; ?>
                      <input type="email" class="sp-hlidaci-email" placeholder="<?php esc_attr_e( 'Tvůj e-mail', 'hlidaci-pes' ); ?>" />
                      <button type="button" class="sp-hlidaci-btn custom-product-btn"
                              title="<?php esc_attr_e( 'Upozorníme Tě mailem, jakmile bude zboží skladem.', 'hlidaci-pes' ); ?>">
                        <?php esc_html_e( 'Hlídací pes', 'hlidaci-pes' ); ?>
                      </button>
                    </div>
                    <p class="sp-hlidaci-response" style="display:none;"></p>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>

        </div><!-- /.sp-inline-actions -->

        <!-- Inline blok pro mobil -->
        <div class="sp-mobile-panel">

          <img
            class="sp-mobile-img"
            src="<?php echo esc_url( $thumb_url ); ?>"
            alt="<?php echo esc_attr( $name ); ?>"
          />

          <div class="sp-mobile-price"><?php echo $price_html; ?></div>

          <?php if ( $is_variable ) : ?>
            <div class="sp-variation-selects">
              <?php
              $attributes = $product->get_variation_attributes();
              foreach ( $attributes as $attr_name => $options ) :
                $label = wc_attribute_label( $attr_name );
                $attr_key_normalized = 'attribute_' . sanitize_title( preg_replace( '/^pa_/', '', $attr_name ) );
              ?>
                <div class="sp-variation-row">
                  <label><?php echo esc_html( $label ); ?></label>
                  <select
                    class="sp-variation-select"
                    data-attribute="<?php echo esc_attr( $attr_key_normalized ); ?>"
                  >
                    <option value="">— Vyberte —</option>
<?php
                  foreach ( $options as $option ) :
                    $opt_val     = trim( $option, '"' );
                    $opt_label   = $opt_val;
                    $opt_instock = false;
                    $opt_has_matching_variant = false;
                    // Zakázat option pouze pokud VŠECHNY varianty s danou hodnotou nejsou skladem
                    foreach ( $variations_data as $vd ) {
                        if ( isset( $vd['hidden'] ) ) continue;
                        if ( ! isset( $vd['attributes'][ $attr_key_normalized ] ) ) continue;
                        $attr_val_match = $vd['attributes'][ $attr_key_normalized ];
                        // Prázdný string = „any" → odpovídá všem hodnotám atributu
                        if ( $attr_val_match !== '' && $attr_val_match !== $opt_val ) continue;
                        $opt_has_matching_variant = true;
                        if ( $vd['in_stock'] ) {
                            $opt_instock = true;
                            break;
                        }
                    }
                    // Pokud pro tuto hodnotu neexistuje žádná varianta v datech, považujeme za dostupnou
                    if ( ! $opt_has_matching_variant ) $opt_instock = true;
                    $opt_display = $opt_instock ? $opt_label : $opt_label . ' – není skladem';
                  ?>
  <option value="<?php echo esc_attr( $opt_val ); ?>"<?php echo $opt_instock ? '' : ' disabled'; ?>>
    <?php echo esc_html( $opt_display ); ?>
  </option>
<?php endforeach; ?>
                  </select>
                </div>
              <?php endforeach; ?>
            </div>

          <?php endif; ?>

          <div class="sp-action-row">
            <?php if ( ! $is_cfb_bundle && $in_stock ) : ?>
            <div class="sp-qty-row">
              <button class="sp-qty-minus" type="button" aria-label="Méně">−</button>
              <input type="number" class="sp-qty" value="1" min="1" />
              <button class="sp-qty-plus" type="button" aria-label="Více">+</button>
            </div>
            <?php endif; ?>
            <?php if ( $is_cfb_bundle ) : ?>
              <?php if ( $in_stock ) : ?>
                <button
                  class="custom-product-btn sp-bundle-select-btn"
                  data-product-id="<?php echo esc_attr( $product_id ); ?>"
                >
                  VÝBĚR PRODUKTŮ
                </button>
              <?php else : ?>
                <span class="sp-out-of-stock">Produkt není skladem</span>
              <?php endif; ?>
            <?php else : ?>
              <?php if ( $in_stock ) : ?>
                <button
                  class="sp-add-to-cart sp-inline-cart-btn custom-product-btn"
                  data-product-id="<?php echo esc_attr( $product_id ); ?>"
                >
                  DO KOŠÍKU
                </button>
              <?php endif; ?>
            <?php endif; ?>
            <a href="<?php echo esc_url( $permalink ); ?>" class="sp-detail-btn">
              ZOBRAZIT DETAIL
            </a>
          </div>

          <?php // ── Hlídací pes – pod košíkovým řádkem, mobil ── ?>
          <?php if ( ! $is_cfb_bundle ) : ?>
            <?php if ( ! $is_variable && ! $in_stock ) : ?>
              <div class="sp-hlidaci-pes-variants">
                <div class="sp-hlidaci-pes-inline" data-product-id="<?php echo esc_attr( $product_id ); ?>" data-variation-id="0">
                  <p class="sp-hlidaci-label"><span class="sp-out-of-stock"><?php esc_html_e( 'Tento produkt není skladem.', 'hlidaci-pes' ); ?></span></p>
                  <p class="sp-hlidaci-label"><?php esc_html_e( 'Mám Tě informovat, až bude produkt naskladněn?', 'hlidaci-pes' ); ?></p>
                  <div class="sp-hlidaci-form">
                    <?php echo $hp_svg; ?>
                    <input type="email" class="sp-hlidaci-email" placeholder="<?php esc_attr_e( 'Tvůj e-mail', 'hlidaci-pes' ); ?>" />
                    <button type="button" class="sp-hlidaci-btn custom-product-btn"
                            title="<?php esc_attr_e( 'Upozorníme Tě mailem, jakmile bude zboží skladem.', 'hlidaci-pes' ); ?>">
                      <?php esc_html_e( 'Hlídací pes', 'hlidaci-pes' ); ?>
                    </button>
                  </div>
                  <p class="sp-hlidaci-response" style="display:none;"></p>
                </div>
              </div>
            <?php elseif ( $is_variable && ! empty( $oos_variations ) ) : ?>
              <div class="sp-hlidaci-pes-variants">
                <?php foreach ( $oos_variations as $oos_v ) : ?>
                  <div class="sp-hlidaci-pes-inline"
                       data-product-id="<?php echo esc_attr( $product_id ); ?>"
                       data-variation-id="<?php echo esc_attr( $oos_v['id'] ); ?>">
                    <?php
                      // Zobraz jen hodnoty atributů (bez klíčů), např. "50g" místo "varianty: 50g"
                      $oos_attr_values = array_values( $oos_v['attributes'] ?? [] );
                      $oos_display_name = ! empty( $oos_attr_values ) ? implode( ', ', $oos_attr_values ) : $oos_v['name'];
                    ?>
                    <p class="sp-hlidaci-label">
                      <span class="sp-out-of-stock"><?php printf( esc_html__( 'Varianta &ldquo;%s&rdquo; není skladem.', 'hlidaci-pes' ), esc_html( $oos_display_name ) ); ?></span>
                    </p>
                    <p class="sp-hlidaci-label"><?php esc_html_e( 'Mám Tě informovat, až bude naskladněna?', 'hlidaci-pes' ); ?></p>
                    <div class="sp-hlidaci-form">
                      <?php echo $hp_svg; ?>
                      <input type="email" class="sp-hlidaci-email" placeholder="<?php esc_attr_e( 'Tvůj e-mail', 'hlidaci-pes' ); ?>" />
                      <button type="button" class="sp-hlidaci-btn custom-product-btn"
                              title="<?php esc_attr_e( 'Upozorníme Tě mailem, jakmile bude zboží skladem.', 'hlidaci-pes' ); ?>">
                        <?php esc_html_e( 'Hlídací pes', 'hlidaci-pes' ); ?>
                      </button>
                    </div>
                    <p class="sp-hlidaci-response" style="display:none;"></p>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>

        </div><!-- /.sp-mobile-panel -->

      </div><!-- /.sp-product-item -->

      <?php endforeach; ?>

    </div><!-- /.sp-product-list -->

    <!-- PRAVÝ SLOUPEC – desktop sticky panel -->
    <div class="sp-product-panel">
      <div class="sp-image-frame">
        <img id="sp-panel-img" src="<?php echo esc_url( $first_img ); ?>" alt="<?php echo esc_attr( $first_name ); ?>" />
        <div class="sp-projector-flash" id="sp-projector-flash"></div>
      </div>
    </div><!-- /.sp-product-panel -->

  <?php else : ?>
    <p>V této kategorii zatím nejsou žádné produkty.</p>
  <?php endif; ?>

  </div><!-- /.sp-archive-wrapper -->
</div><!-- /.sp-archive-outer -->

<?php get_footer(); ?>