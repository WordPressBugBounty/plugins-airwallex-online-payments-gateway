<tr valign="top">
    <th scope="row" class="titledesc">
        <label for="<?php echo esc_attr($fieldKey); ?>">
            <?php echo wp_kses_post($data['title']); ?>
        </label>
    </th>

    <td class="forminp">
        <fieldset>
            <div class="awx-pos-device-container">

                <input
                        type="text"
                        class="awx-pos-device-input"
                        id="<?php echo esc_attr($fieldKey); ?>"
                        name="<?php echo esc_attr($fieldKey); ?>"
                        value="<?php echo esc_attr($value); ?>"
                        readonly
                />

                <div class="awx-pos-device-info" style="margin:12px 0 8px; display:none;">
                    <div class="awx-pos-info-title" style="font-weight:bold; margin-bottom:6px;">
                        <?php echo __('Bound Device', 'airwallex-online-payments-gateway'); ?>
                    </div>

                    <div class="awx-pos-info-id">
                        <strong><?php echo __('Device ID:', 'airwallex-online-payments-gateway'); ?></strong>
                        <span class="value"></span>
                    </div>

                    <div class="awx-pos-info-nickname">
                        <strong><?php echo __('Nick Name:', 'airwallex-online-payments-gateway'); ?></strong>
                        <span class="value"></span>
                    </div>

                    <div class="awx-pos-info-serial">
                        <strong><?php echo __('Serial Number:', 'airwallex-online-payments-gateway'); ?></strong>
                        <span class="value"></span>
                    </div>
                </div>

                <div class="awx-pos-device-list-wrapper">
                    <div class="awx-pos-device-list" style="margin-top: 10px;"></div>

                    <div class="awx-pos-device-no-data"
                            style="display:none; text-align:center; padding:20px; color:#666;">
                        <?php echo __('-- No devices found --', 'airwallex-online-payments-gateway'); ?>
                    </div>
                </div>

                <div class="awx-pos-pagination" style="margin-top:12px; text-align:center; display:none;">
                    <button type="button" class="awx-pos-prev-btn" style="padding:6px 14px; margin-right:10px;">
                        <?php echo __('Prev', 'airwallex-online-payments-gateway'); ?>
                    </button>

                    <button type="button" class="awx-pos-next-btn" style="padding:6px 14px; margin-left:10px;">
                        <?php echo __('Next', 'airwallex-online-payments-gateway'); ?>
                    </button>
                </div>

                <div class="awx-pos-item-template" style="display:none;">
                    <div class="awx-pos-item"
                            style="border:1px solid #ccc; border-radius:5px; padding:10px; margin-bottom:8px; cursor:pointer; font-size: 13px;">

                        <div class="awx-pos-template-nickname">
                            <strong><?php echo __('Nick Name:', 'airwallex-online-payments-gateway'); ?></strong>
                            <span class="value"></span>
                        </div>

                        <div class="awx-pos-template-serial">
                            <strong><?php echo __('Serial Number:', 'airwallex-online-payments-gateway'); ?></strong>
                            <span class="value"></span>
                        </div>
                    </div>
                </div>

            </div>
        </fieldset>
    </td>
</tr>
