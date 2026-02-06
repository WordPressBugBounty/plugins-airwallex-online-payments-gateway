<tr valign="top">
    <th scope="row" class="titledesc">
        <label for="<?php echo esc_attr( $field_key ); ?>">
            <?php echo wp_kses_post( $data['title'] ); ?>
            <?php echo wp_kses_post( $this->get_tooltip_html( $data ) ); ?>
        </label>
    </th>
    <td class="forminp">
        <fieldset>
            <div style="display: flex; flex-wrap: wrap; max-width:430px;" class="awx-apm-logos">
            </div>
            <?php
            echo wp_kses_post( $this->get_description_html( $data ) );
            ?>
        </fieldset>
    </td>
</tr>

<div class="awx-apm-logo-template" style="display:none;">
    <div style="width:60px; margin-right:10px; text-align:center;" class="awx-apm-logo-item">
        <label>
            <div>
                <img style="max-width:100%; height: 24px;" class="awx-apm-logo"/>
            </div>
            <input class="awx-apm-logo-checkbox" type="checkbox" name="<?php echo esc_attr( $field_key ); ?>[]"/>
        </label>
    </div>
</div>
