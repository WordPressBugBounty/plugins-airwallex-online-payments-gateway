<tr valign="top">
    <th scope="row" class="titledesc">
        <label for="<?php echo esc_attr( $field_key ); ?>">
            <?php echo wp_kses_post( $data['title'] ); ?>
            <?php echo wp_kses_post( $this->get_tooltip_html( $data ) ); ?>
        </label>
    </th>
    <td class="forminp">
        <?php
        echo wp_kses_post( $this->get_description_html( $data ) );
        ?>
        <fieldset>
            <div class="awx-apm-names">
            </div>
        </fieldset>
    </td>
</tr>

<div class="awx-apm-name-template" style="display:none;">
    <div>
        <label class="awx-apm-name-item">
            <input type="checkbox" name="<?php echo esc_attr( $field_key ); ?>[]"/>
            <span class="awx-display-name"></span>
        </label>
    </div>
</div>