<?php
/**
 * Review order table
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/checkout/review-order.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 3.8.0
 */

defined('ABSPATH') || exit;

if (! $order) {
    return;
}

$awx_order_items = $order->get_items(apply_filters('woocommerce_purchase_order_item_types', 'line_item'));
?>
<table class="cart-contents">
    <tbody>
        <?php
        foreach ($awx_order_items as $awx_item_id => $awx_item) {
            $awx_product = $awx_item->get_product();
            $awx_product_qty = $awx_item->get_quantity();

            if ($awx_product && $awx_product->is_visible() && $awx_product_qty > 0) {
        ?>
                <tr class="cart-item">
                    <td class="product-image">
                        <?php
                        $awx_post_thumbnail_id = $awx_product->get_image_id();
                        if ($awx_post_thumbnail_id) {
                            $awx_html = wc_get_gallery_image_html($awx_post_thumbnail_id, true);
                            echo wp_kses_post($awx_html);
                        }
                        ?>
                    </td>
                    <td class="product-info">
                        <?php echo wp_kses_post(apply_filters('woocommerce_order_item_quantity_html', ' <strong class="product-quantity">' . sprintf('%s&nbsp;&times;', $awx_product_qty) . '</strong>', $awx_item)); ?>
                        <?php echo wp_kses_post(apply_filters('woocommerce_order_item_name', $awx_item->get_name(), $awx_item, $awx_product->is_visible()) . '&nbsp;'); ?>
                        <?php
                            if ($isShowOrderDetails) {
                                wc_display_item_meta( $awx_item, array(
                                        'before'       => '<div class="wc-item-meta"><div>',
                                        'after'        => '</div></div>',
                                        'separator'    => '</div><div>',
                                        'echo'         => true,
                                        'autop'        => false,
                                        'label_before' => '<strong class="wc-item-meta-label">',
                                        'label_after'  => ': </strong> ',
                                    )
                                );                            
                            }
                        ?>
                        <div class="price">
                            <?php echo wp_kses_post( WC()->cart->get_product_subtotal( $awx_product, $awx_product_qty )); ?>
                        </div>
                    </td>
                </tr>
        <?php
            }
        }
        ?>
    </tbody>
</table>
<table class="totals-table">
    <tfoot>
        <?php
        foreach ($order->get_order_item_totals() as $key => $total) {
            if ('payment_method' === $key  || false !== strpos($key, 'refund_')) {
                continue;
            }
        ?>
            <tr>
                <th scope="row"><?php echo esc_html($total['label']); ?></th>
                <td><?php echo wp_kses_post($total['value']); ?></td>
            </tr>
        <?php
        }
        ?>
    </tfoot>
</table>
