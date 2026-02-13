<?php
/**
 * Yandex Translation Functions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'trp_yandex_add_settings' ) ) {
    function trp_yandex_add_settings( $mt_settings ) {
        $api_key = isset( $mt_settings['hhgfotr-yandex-key'] ) ? $mt_settings['hhgfotr-yandex-key'] : ( isset( $mt_settings['hhg-yandex-key'] ) ? $mt_settings['hhg-yandex-key'] : '' );
        $folder_id = isset( $mt_settings['hhgfotr-yandex-folder-id'] ) ? $mt_settings['hhgfotr-yandex-folder-id'] : ( isset( $mt_settings['hhg-yandex-folder-id'] ) ? $mt_settings['hhg-yandex-folder-id'] : '' );
        $model = isset( $mt_settings['hhgfotr-yandex-model'] ) ? $mt_settings['hhgfotr-yandex-model'] : ( isset( $mt_settings['hhg-yandex-model'] ) ? $mt_settings['hhg-yandex-model'] : 'yandex' );

        $error_message = '';
        $show_errors = false;
        $translation_engine = isset( $mt_settings['translation-engine'] ) ? $mt_settings['translation-engine'] : '';
        if ( 'hhgfotr_yandex' === $translation_engine && class_exists( 'TRP_HHGFOTR_Yandex_Machine_Translator' ) ) {
            $machine_translator = new TRP_HHGFOTR_Yandex_Machine_Translator( array( 'trp_machine_translation_settings' => $mt_settings ) );
            if ( method_exists( $machine_translator, 'check_api_key_validity' ) ) {
                $api_check = $machine_translator->check_api_key_validity();
                if ( isset( $api_check ) && true === $api_check['error'] ) {
                    $error_message = $api_check['message'];
                    $show_errors = true;
                }
            }
        }

        $text_input_classes = array( 'trp-text-input' );
        if ( $show_errors ) {
            $text_input_classes[] = 'trp-text-input-error';
        }
        
        $is_active = 'hhgfotr_yandex' === $translation_engine;
        ?>

        <div class="trp-engine trp-automatic-translation-engine__container" id="hhgfotr_yandex" style="<?php echo $is_active ? '' : 'display: none;'; ?>">
            <span class="trp-primary-text-bold"><?php esc_html_e( 'Yandex API Key', 'hhg-for-translatepress' ); ?></span>

            <div class="trp-automatic-translation-api-key-container">
                <input type="password" id="hhgfotr-yandex-key" placeholder="<?php esc_html_e( 'Add your API Key here...', 'hhg-for-translatepress' ); ?>" 
                       class="<?php echo esc_attr( implode( ' ', $text_input_classes ) ); ?>" 
                        name="trp_machine_translation_settings[hhgfotr-yandex-key]" 
                       value="<?php echo esc_attr( $api_key ); ?>" style="width: 100%;max-width:480px;" />
                <?php
                if ( $is_active && function_exists( 'trp_output_svg' ) ) {
                    if ( class_exists( 'TRP_HHGFOTR_Yandex_Machine_Translator' ) ) {
                        $machine_translator = new TRP_HHGFOTR_Yandex_Machine_Translator( array( 'trp_machine_translation_settings' => $mt_settings ) );
                        $machine_translator->automatic_translation_svg_output( $show_errors );
                    }
                }
                ?>
            </div>

            <?php if ( $show_errors ) : ?>
                <span class="trp-error-inline trp-settings-error-text">
                    <?php echo wp_kses_post( $error_message ); ?>
                </span>
            <?php endif; ?>

            <div class="trp-yandex-folder-id-container" style="margin-top: 15px;">
                <span class="trp-primary-text-bold"><?php esc_html_e( 'Yandex Folder ID', 'hhg-for-translatepress' ); ?></span>
                <input type="text" id="hhgfotr-yandex-folder-id" 
                       placeholder="<?php esc_html_e( 'Add your Folder ID here...', 'hhg-for-translatepress' ); ?>" 
                       class="<?php echo esc_attr( implode( ' ', $text_input_classes ) ); ?>" 
                       name="trp_machine_translation_settings[hhgfotr-yandex-folder-id]" 
                       value="<?php echo esc_attr( $folder_id ); ?>" style="width: 100%;max-width:480px;margin-top: 5px;" />
            </div>

            <div class="trp-yandex-model-container" style="margin-top: 15px;">
               <p><span class="trp-primary-text-bold"><?php esc_html_e( 'Yandex Model', 'hhg-for-translatepress' ); ?></span></p>
                <select id="hhgfotr-yandex-model" name="trp_machine_translation_settings[hhgfotr-yandex-model]" class="trp-select" style="width: 100%;max-width:480px;">
                    <option value="yandex" <?php selected( $model, 'yandex' ); ?>><?php esc_html_e( 'Yandex Translator', 'hhg-for-translatepress' ); ?></option>
                </select>
            </div>

            <span class="trp-description-text">
                <?php echo wp_kses( __( 'Visit <a href="https://yandex.cloud/en/docs/iam/operations/api-key/create" target="_blank">Yandex Cloud</a> to get your API key and folder ID. The Yandex Translation API provides high-quality translations for many languages.', 'hhg-for-translatepress' ), [ 'a' => [ 'href' => [], 'title' => [], 'target' => [] ] ] ); ?>
                
            </span>
        </div>

        <?php
    }
}