<?php

/**
 * Plugin Name: HHG for TranslatePress
 * Plugin URI: https://huhonggang.com/hhg-for-translatepress/
 * Description: Google Gemini AI, OpenAI GPT, ZhiPu AI, Yandex Translation, The engine is integrated into the plugin TranslatePress as a translation source.
 * Version: 1.1.55
 * Author: huhonggang
 * Author URI: https://huhonggang.com/
 * Text Domain: hhg-for-translatepress
 * Requires Plugins: translatepress-multilingual
 * License: GPLv2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Domain Path: /languages/
 */
 
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'HHGFOTR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HHGFOTR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'HHG_TRANSLATEPRESS_PLUGIN_DIR' ) ) {
    define( 'HHG_TRANSLATEPRESS_PLUGIN_DIR', HHGFOTR_PLUGIN_DIR );
}
if ( ! defined( 'HHG_TRANSLATEPRESS_PLUGIN_URL' ) ) {
    define( 'HHG_TRANSLATEPRESS_PLUGIN_URL', HHGFOTR_PLUGIN_URL );
}

class HHGFOTR_TranslatePress {
    private static $instance = null;

    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ( ! $this->is_translatepress_active() ) {
            add_action( 'admin_notices', array( $this, 'missing_translatepress_notice' ) );
            return;
        }
        add_action( 'plugins_loaded', array( $this, 'init' ), 1 );
    }

    private function is_translatepress_active() {
        if ( class_exists( 'TRP_Translate_Press' ) ) {
            return true;
        }
        
        $active_plugins = get_option( 'active_plugins', array() );
        if ( in_array( 'translatepress-multilingual/index.php', $active_plugins ) ) {
            return true;
        }

        if ( is_multisite() ) {
            $network_active_plugins = get_site_option( 'active_sitewide_plugins', array() );
            if ( isset( $network_active_plugins['translatepress-multilingual/index.php'] ) ) {
                return true;
            }
        }
        
        return false;
    }

    public function init() {
        $this->load_engines();
        $this->register_hooks();
        $this->ensure_engine_switch();
        $this->setup_debug();
        if ( false ) { add_action( 'plugins_loaded', array( $this, 'force_active_engine_instance' ), 99 ); }
    }

    private function register_hooks() {
        add_filter( 'trp_machine_translation_engines', array( $this, 'add_hhg_engines_to_list' ), 20 );
        add_filter( 'trp_automatic_translation_engines_classes', array( $this, 'register_engine_classes' ), 20 );
        add_filter( 'trp_automatic_translation_engines_classes', array( $this, 'override_mtapi_to_zhipu' ), 100 );
        add_filter( 'trp_machine_translator_is_available', array( $this, 'force_mt_available' ), 999 );
        add_filter( 'trp_machine_translation_sanitize_settings', array( $this, 'sanitize_settings' ), 20, 2 );
        add_action( 'trp_machine_translation_extra_settings_middle', array( $this, 'add_settings_fields' ), 20, 1 );
        add_filter( 'trp_get_default_trp_machine_translation_settings', array( $this, 'add_default_settings' ), 20, 1 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_filter( 'trp_machine_translation_sanitize_settings', array( $this, 'extend_machine_translation_keys' ), 10, 2 );
        add_action( 'wp_ajax_hhgfotr_zhipu_test_api', array( $this, 'handle_zhipu_test_api' ) );
        add_action( 'wp_ajax_hhg_zhipu_test_api', array( $this, 'handle_zhipu_test_api' ) );
        add_action( 'wp_ajax_hhgfotr_yandex_test_api', array( $this, 'handle_yandex_test_api' ) );
        add_action( 'wp_ajax_hhg_yandex_test_api', array( $this, 'handle_yandex_test_api' ) );
    }

    private function load_engines() {
        $logger_path = HHGFOTR_PLUGIN_DIR . 'includes/core/class-logger.php';
        $cache_path  = HHGFOTR_PLUGIN_DIR . 'includes/core/class-cache.php';
        $api_path    = HHGFOTR_PLUGIN_DIR . 'includes/core/class-api-client.php';

        if ( file_exists( $logger_path ) ) {
            require_once $logger_path;
        } else {
            error_log('[HHG-TP] Logger file missing at ' . $logger_path);
        }

        if ( file_exists( $cache_path ) ) {
            require_once $cache_path;
        } else {
            error_log('[HHG-TP] Cache file missing at ' . $cache_path);
        }

        if ( file_exists( $api_path ) ) {
            require_once $api_path;
        } else {
            error_log('[HHG-TP] API client file missing at ' . $api_path);
        }
        
        // load engine helper functions (safe)
        if ( file_exists( HHGFOTR_PLUGIN_DIR . 'includes/gemini/functions.php' ) ) {
            require_once HHGFOTR_PLUGIN_DIR . 'includes/gemini/functions.php';
        }
        if ( file_exists( HHGFOTR_PLUGIN_DIR . 'includes/hunyuan/functions.php' ) ) {
            require_once HHGFOTR_PLUGIN_DIR . 'includes/hunyuan/functions.php';
        }
        if ( file_exists( HHGFOTR_PLUGIN_DIR . 'includes/openai/functions.php' ) ) {
            require_once HHGFOTR_PLUGIN_DIR . 'includes/openai/functions.php';
        }
        if ( file_exists( HHGFOTR_PLUGIN_DIR . 'includes/zhipu/functions.php' ) ) {
            require_once HHGFOTR_PLUGIN_DIR . 'includes/zhipu/functions.php';
        }
        if ( file_exists( HHGFOTR_PLUGIN_DIR . 'includes/yandex/functions.php' ) ) {
            require_once HHGFOTR_PLUGIN_DIR . 'includes/yandex/functions.php';
        }
    }

    public function register_engine_classes( $classes ) {
        // ensure translator classes are loaded when TP requests class names
        if ( class_exists( 'TRP_Machine_Translator' ) ) {
            $files = array(
                'includes/gemini/class-gemini-translator.php',
                'includes/hunyuan/class-hunyuan-translator.php',
                'includes/openai/class-openai-translator.php',
                'includes/zhipu/class-zhipu-translator.php',
            );
            foreach ( $files as $rel ) {
                $path = HHGFOTR_PLUGIN_DIR . $rel;
                if ( file_exists( $path ) ) {
                    require_once $path;
                }
            }
            
            // Load Yandex translator if it exists
            $yandex_path = HHGFOTR_PLUGIN_DIR . 'includes/yandex/class-yandex-translator.php';
            if ( file_exists( $yandex_path ) ) {
                require_once $yandex_path;
            }
        }
        $classes['hhgfotr_gemini'] = 'TRP_HHGFOTR_Gemini_Machine_Translator';
        $classes['hhgfotr_hunyuan'] = 'TRP_HHGFOTR_Hunyuan_Machine_Translator';
        $classes['hhgfotr_openai'] = 'TRP_HHGFOTR_OpenAI_Machine_Translator';
        $classes['hhgfotr_zhipu'] = 'TRP_HHGFOTR_Zhipu_Machine_Translator';
        $classes['hhgfotr_yandex'] = 'TRP_HHGFOTR_Yandex_Machine_Translator';
        $classes['hhg_gemini'] = 'TRP_HHGFOTR_Gemini_Machine_Translator';
        $classes['hhg_hunyuan'] = 'TRP_HHGFOTR_Hunyuan_Machine_Translator';
        $classes['hhg_openai'] = 'TRP_HHGFOTR_OpenAI_Machine_Translator';
        $classes['hhg_zhipu'] = 'TRP_HHGFOTR_Zhipu_Machine_Translator';
        $classes['hhg_yandex'] = 'TRP_HHGFOTR_Yandex_Machine_Translator';
        return $classes;
    }

    public function override_mtapi_to_zhipu( $classes ) {
        $classes['mtapi'] = 'TRP_HHGFOTR_Zhipu_Machine_Translator';
        return $classes;
    }

    public function force_mt_available( $is_available ) {
        $mt = get_option( 'trp_machine_translation_settings', array() );
        $engine = isset( $mt['translation-engine'] ) ? $mt['translation-engine'] : '';
        $enabled = isset( $mt['machine-translation'] ) ? $mt['machine-translation'] : 'no';
        if ( $enabled === 'yes' ) {
            if ( in_array( $engine, array( 'hhgfotr_zhipu', 'hhg_zhipu', 'hhgfotr_gemini', 'hhg_gemini', 'hhgfotr_openai', 'hhg_openai', 'hhgfotr_hunyuan', 'hhg_hunyuan', 'hhgfotr_yandex', 'hhg_yandex', 'mtapi' ), true ) ) {
                return true;
            }
        }
        return $is_available;
    }

    private function ensure_engine_switch() {
        $mt = get_option( 'trp_machine_translation_settings', array() );
        $enabled = isset( $mt['machine-translation'] ) ? $mt['machine-translation'] : 'no';
        $changed = false;
        if ( $enabled !== 'yes' ) {
            $mt['machine-translation'] = 'yes';
            $changed = true;
        }
        if ( $changed ) {
            update_option( 'trp_machine_translation_settings', $mt );
            if ( class_exists( 'HHG_Logger' ) ) {
                HHG_Logger::log( 'Ensured machine translation enabled', $mt );
            }
        }
    }

    private function setup_debug() {
        if ( defined( 'HHGFOTR_DEBUG' ) && HHGFOTR_DEBUG ) {
            add_action( 'http_api_debug', function( $response, $context, $class, $args, $url ) {
                if ( class_exists( 'HHG_Logger' ) ) {
                    HHG_Logger::log( 'HTTP API call', array( 'url' => $url, 'method' => isset($args['method']) ? $args['method'] : 'GET' ) );
                }
            }, 10, 5 );

            add_action( 'wp_loaded', function() {
                $mt = get_option( 'trp_machine_translation_settings', array() );
                $trp = class_exists( 'TRP_Translate_Press' ) ? TRP_Translate_Press::get_trp_instance() : null;
                $machine_translator = $trp ? $trp->get_component( 'machine_translator' ) : null;
                $class = $machine_translator ? get_class( $machine_translator ) : 'none';
                if ( class_exists( 'HHG_Logger' ) ) {
                    HHG_Logger::log( 'TP settings snapshot', array( 'mt_settings' => $mt, 'active_engine_class' => $class ) );
                }
            });
        }
    }

    public function force_active_engine_instance() {
        if ( ! class_exists( 'TRP_Translate_Press' ) ) {
            return;
        }
        $trp = TRP_Translate_Press::get_trp_instance();
        if ( ! $trp ) return;
        $settings_component = $trp->get_component( 'settings' );
        if ( ! $settings_component ) return;
        $settings = isset($settings_component->get_settings) ? $settings_component->get_settings() : get_option( 'trp_settings', array() );

        $mt_settings = get_option( 'trp_machine_translation_settings', array() );
        $engine = isset( $mt_settings['translation-engine'] ) ? $mt_settings['translation-engine'] : '';
        $enabled = isset( $mt_settings['machine-translation'] ) ? $mt_settings['machine-translation'] : 'no';

        if ( $enabled === 'yes' && $engine === 'hhgfotr_zhipu' ) {
            $translator_settings = array_merge( $settings, array( 'trp_machine_translation_settings' => $mt_settings ) );
            $instance = new TRP_HHGFOTR_Zhipu_Machine_Translator( $translator_settings );
            $trp->machine_translator = $instance;
            if ( class_exists( 'HHG_Logger' ) ) {
                HHG_Logger::log( 'Forced active engine instance to Zhipu' );
            }
        }
    }

    public function add_settings_fields( $mt_settings ) {
        if ( function_exists('trp_gt_add_settings') ) {
            trp_gt_add_settings($mt_settings);
        }
        if ( function_exists('trp_deepl_add_settings') ) {
            trp_deepl_add_settings($mt_settings);
        }
        if ( function_exists('trp_yandex_add_settings') ) {
            trp_yandex_add_settings($mt_settings);
        }
        
        $trp = TRP_Translate_Press::get_trp_instance();
        $machine_translator = $trp->get_component( 'machine_translator' );
        $translation_engine = isset( $mt_settings['translation-engine'] ) ? $mt_settings['translation-engine'] : '';
        
        // Only include the individual settings functions if needed
        if (in_array($translation_engine, ['hhgfotr_gemini', 'hhg_gemini'])) {
            $this->add_gemini_settings($mt_settings, $machine_translator, $translation_engine);
        } elseif (in_array($translation_engine, ['hhgfotr_hunyuan', 'hhg_hunyuan'])) {
            $this->add_hunyuan_settings($mt_settings, $machine_translator, $translation_engine);
        } elseif (in_array($translation_engine, ['hhgfotr_openai', 'hhg_openai'])) {
            $this->add_openai_settings($mt_settings, $machine_translator, $translation_engine);
        } elseif (in_array($translation_engine, ['hhgfotr_zhipu', 'hhg_zhipu'])) {
            $this->add_zhipu_settings($mt_settings, $machine_translator, $translation_engine);
        } elseif (in_array($translation_engine, ['hhgfotr_yandex', 'hhg_yandex'])) {
            $this->add_yandex_settings($mt_settings, $machine_translator, $translation_engine);
        }
    }

    private function add_gemini_settings( $mt_settings, $machine_translator, $translation_engine ) {
        $api_key = isset( $mt_settings['hhgfotr-gemini-key'] ) ? $mt_settings['hhgfotr-gemini-key'] : ( isset( $mt_settings['hhg-gemini-key'] ) ? $mt_settings['hhg-gemini-key'] : '' );
        $model = isset( $mt_settings['hhgfotr-gemini-model'] ) ? $mt_settings['hhgfotr-gemini-model'] : ( isset( $mt_settings['hhg-gemini-model'] ) ? $mt_settings['hhg-gemini-model'] : 'gemini-2.0-flash' );

        $error_message = '';
        $show_errors = false;
        if ( in_array( $translation_engine, array( 'hhgfotr_gemini', 'hhg_gemini' ), true ) && method_exists( $machine_translator, 'check_api_key_validity' ) ) {
            $api_check = $machine_translator->check_api_key_validity();
            if ( isset( $api_check ) && true === $api_check['error'] ) {
                $error_message = $api_check['message'];
                $show_errors = true;
            }
        }

        $text_input_classes = array( 'trp-text-input' );
        if ( $show_errors ) {
            $text_input_classes[] = 'trp-text-input-error';
        }
        
        $is_active = in_array( $translation_engine, array( 'hhgfotr_gemini', 'hhg_gemini' ), true );
        ?>

<div class="trp-engine trp-automatic-translation-engine__container" id="hhgfotr_gemini"
    style="<?php echo $is_active ? '' : 'display: none;'; ?>">
    <span class="trp-primary-text-bold"><?php esc_html_e( 'Google Gemini API Key', 'hhg-for-translatepress' ); ?></span>

    <div class="trp-automatic-translation-api-key-container">
        <input type="text" id="hhgfotr-gemini-key"
            placeholder="<?php esc_html_e( 'Add your API Key here...', 'hhg-for-translatepress' ); ?>"
            class="<?php echo esc_attr( implode( ' ', $text_input_classes ) ); ?>"
            name="trp_machine_translation_settings[hhgfotr-gemini-key]" value="<?php echo esc_attr( $api_key ); ?>"
            style="width: 100%;max-width:480px;" />
        <?php
                if ( $is_active && function_exists( 'trp_output_svg' ) ) {
                    $machine_translator->automatic_translation_svg_output( $show_errors );
                }
                ?>
    </div>

    <?php if ( $show_errors ) : ?>
    <span class="trp-error-inline trp-settings-error-text">
        <?php echo wp_kses_post( $error_message ); ?>
    </span>
    <?php endif; ?>

    <div class="trp-gemini-model-container" style="margin-top: 15px;">
        <p><span class="trp-primary-text-bold"><?php esc_html_e( 'Gemini Model', 'hhg-for-translatepress' ); ?></span>
        </p>
        <select id="hhgfotr-gemini-model" name="trp_machine_translation_settings[hhgfotr-gemini-model]"
            class="trp-select" style="width: 100%;max-width:480px;">
            <option value="gemini-2.5-flash" <?php selected( $model, 'gemini-2.5-flash' ); ?>>
                <?php esc_html_e( 'Gemini 2.5 Flash (Fast)', 'hhg-for-translatepress' ); ?></option>
            <option value="gemini-2.5-flash-lite" <?php selected( $model, 'gemini-2.5-flash-lite' ); ?>>
                <?php esc_html_e( 'Gemini 2.5 Flash-Lite (Cheaper & Fast)', 'hhg-for-translatepress' ); ?></option>
            <option value="gemini-3-pro-preview" <?php selected( $model, 'gemini-3-pro-preview' ); ?>>
                <?php esc_html_e( 'Gemini 3 Pro (Preview)', 'hhg-for-translatepress' ); ?></option>
        </select>
    </div>

    <span class="trp-description-text">
        <?php echo wp_kses( __( 'Visit <a href="https://aistudio.google.com/apikey" target="_blank">Google AI Studio</a> to get your API key. Gemini 2.5 models offer the fastest translation speeds with optimized rate limits.', 'hhg-for-translatepress' ), [ 'a' => [ 'href' => [], 'title' => [], 'target' => [] ] ] ); ?>

    </span>
</div>

<?php
    }

    private function add_hunyuan_settings( $mt_settings, $machine_translator, $translation_engine ) {
        $secret_id = isset( $mt_settings['hhgfotr-hunyuan-secret-id'] ) ? $mt_settings['hhgfotr-hunyuan-secret-id'] : ( isset( $mt_settings['hhg-hunyuan-secret-id'] ) ? $mt_settings['hhg-hunyuan-secret-id'] : '' );
        $secret_key = isset( $mt_settings['hhgfotr-hunyuan-secret-key'] ) ? $mt_settings['hhgfotr-hunyuan-secret-key'] : ( isset( $mt_settings['hhg-hunyuan-secret-key'] ) ? $mt_settings['hhg-hunyuan-secret-key'] : '' );
        $model = isset( $mt_settings['hhgfotr-hunyuan-model'] ) ? $mt_settings['hhgfotr-hunyuan-model'] : ( isset( $mt_settings['hhg-hunyuan-model'] ) ? $mt_settings['hhg-hunyuan-model'] : 'hunyuan-lite' );
        $error_message = '';
        $show_errors = false;
        if ( in_array( $translation_engine, array( 'hhgfotr_hunyuan', 'hhg_hunyuan' ), true ) && method_exists( $machine_translator, 'check_api_key_validity' ) ) {
            $api_check = $machine_translator->check_api_key_validity();
            if ( isset( $api_check ) && true === $api_check['error'] ) {
                $error_message = $api_check['message'];
                $show_errors = true;
            }
        }
        $text_input_classes = array( 'trp-text-input' );
        if ( $show_errors ) {
            $text_input_classes[] = 'trp-text-input-error';
        }
        $is_active = in_array( $translation_engine, array( 'hhgfotr_hunyuan', 'hhg_hunyuan' ), true );
        ?>
<div class="trp-engine trp-automatic-translation-engine__container" id="hhgfotr_hunyuan"
    style="<?php echo $is_active ? '' : 'display: none;'; ?>">
    <span
        class="trp-primary-text-bold"><?php esc_html_e( 'Tencent Hunyuan SecretId', 'hhg-for-translatepress' ); ?></span>
    <div class="trp-automatic-translation-api-key-container">
        <input type="text" id="hhgfotr-hunyuan-secret-id"
            name="trp_machine_translation_settings[hhgfotr-hunyuan-secret-id]"
            value="<?php echo esc_attr( $secret_id ); ?>"
            placeholder="<?php esc_attr_e( 'Enter your SecretId...', 'hhg-for-translatepress' ); ?>"
            class="<?php echo esc_attr( implode( ' ', $text_input_classes ) ); ?>"
            style="width: 100%;max-width:480px;" />
        <?php if ( $show_errors ) : ?>
        <span class="trp-error-inline trp-settings-error-text"><?php echo esc_html( $error_message ); ?></span>
        <?php endif; ?>
    </div>
    <span class="trp-primary-text-bold"
        style="margin-top:15px;display:block;"><?php esc_html_e( 'Tencent Hunyuan SecretKey', 'hhg-for-translatepress' ); ?></span>
    <div class="trp-automatic-translation-api-key-container">
        <input type="password" id="hhgfotr-hunyuan-secret-key"
            name="trp_machine_translation_settings[hhgfotr-hunyuan-secret-key]"
            value="<?php echo esc_attr( $secret_key ); ?>"
            placeholder="<?php esc_attr_e( 'Enter your SecretKey...', 'hhg-for-translatepress' ); ?>"
            class="<?php echo esc_attr( implode( ' ', $text_input_classes ) ); ?>"
            style="width: 100%;max-width:480px;" />
    </div>
    <div class="trp-hunyuan-model-container" style="margin-top: 15px;">
        <p><span
                class="trp-primary-text-bold"><?php esc_html_e( 'Tencent Hunyuan Model', 'hhg-for-translatepress' ); ?></span>
        </p>
        <select id="hhgfotr-hunyuan-model" name="trp_machine_translation_settings[hhgfotr-hunyuan-model]"
            class="trp-select" style="max-width:480px;">
            <option value="hunyuan-translation" <?php selected( $model, 'hunyuan-translation' ); ?>>
                <?php esc_html_e( 'Hunyuan Translation', 'hhg-for-translatepress' ); ?></option>
            <option value="hunyuan-translation-lite" <?php selected( $model, 'hunyuan-translation-lite' ); ?>>
                <?php esc_html_e( 'Hunyuan Translation Lite', 'hhg-for-translatepress' ); ?></option>
        </select>
    </div>
    <span class="trp-description-text" style="display:block;margin-top:10px;">
        <?php echo wp_kses( __( 'Visit the <a href="https://console.cloud.tencent.com/hunyuan" target="_blank">Tencent Hunyuan Console</a> for your API credentials. The Hunyuan model provides high-quality Chinese translation services.', 'hhg-for-translatepress' ), [ 'a' => [ 'href' => [], 'title' => [], 'target' => [] ] ] ); ?>
    </span>
</div>
<?php
    }

    private function add_openai_settings( $mt_settings, $machine_translator, $translation_engine ) {
        $api_key = isset( $mt_settings['hhgfotr-openai-key'] ) ? $mt_settings['hhgfotr-openai-key'] : ( isset( $mt_settings['hhg-openai-key'] ) ? $mt_settings['hhg-openai-key'] : '' );
        $model = isset( $mt_settings['hhgfotr-openai-model'] ) ? $mt_settings['hhgfotr-openai-model'] : ( isset( $mt_settings['hhg-openai-model'] ) ? $mt_settings['hhg-openai-model'] : 'gpt-4o-mini' );
        $custom_model = isset( $mt_settings['hhgfotr-openai-custom-model'] ) ? $mt_settings['hhgfotr-openai-custom-model'] : ( isset( $mt_settings['hhg-openai-custom-model'] ) ? $mt_settings['hhg-openai-custom-model'] : '' );
        $endpoint = isset( $mt_settings['hhgfotr-openai-endpoint'] ) ? $mt_settings['hhgfotr-openai-endpoint'] : ( isset( $mt_settings['hhg-openai-endpoint'] ) ? $mt_settings['hhg-openai-endpoint'] : 'https://api.openai.com/v1/chat/completions' );
        $error_message = '';
        $show_errors = false;
        if ( in_array( $translation_engine, array( 'hhgfotr_openai', 'hhg_openai' ), true ) && method_exists( $machine_translator, 'check_api_key_validity' ) ) {
            $api_check = $machine_translator->check_api_key_validity();
            if ( isset( $api_check ) && true === $api_check['error'] ) {
                $error_message = $api_check['message'];
                $show_errors = true;
            }
        }
        $text_input_classes = array( 'trp-text-input' );
        if ( $show_errors ) {
            $text_input_classes[] = 'trp-text-input-error';
        }
        $is_active = in_array( $translation_engine, array( 'hhgfotr_openai', 'hhg_openai' ), true );
        ?>
<div class="trp-engine trp-automatic-translation-engine__container" id="hhgfotr_openai"
    style="<?php echo $is_active ? '' : 'display: none;'; ?>">
    <span class="trp-primary-text-bold"><?php esc_html_e( 'OpenAI API Key', 'hhg-for-translatepress' ); ?></span>
    <div class="trp-automatic-translation-api-key-container">
        <input type="text" id="hhgfotr-openai-key"
            placeholder="<?php esc_html_e( 'Add your API Key here...', 'hhg-for-translatepress' ); ?>"
            class="<?php echo esc_attr( implode( ' ', $text_input_classes ) ); ?>"
            name="trp_machine_translation_settings[hhgfotr-openai-key]" value="<?php echo esc_attr( $api_key ); ?>"
            style="width: 100%;max-width:480px;" />
        <?php
                if ( $is_active && function_exists( 'trp_output_svg' ) ) {
                    $machine_translator->automatic_translation_svg_output( $show_errors );
                }
                ?>
    </div>
    <?php if ( $show_errors ) : ?>
    <span class="trp-error-inline trp-settings-error-text">
        <?php echo wp_kses_post( $error_message ); ?>
    </span>
    <?php endif; ?>
    <div class="trp-openai-endpoint-container" style="margin-top: 15px;">
        <p><span
                class="trp-primary-text-bold"><?php esc_html_e( 'OpenAI API Endpoint', 'hhg-for-translatepress' ); ?></span>
        </p>
        <input type="text" id="hhgfotr-openai-endpoint" name="trp_machine_translation_settings[hhgfotr-openai-endpoint]"
            value="<?php echo esc_attr( $endpoint ); ?>" placeholder="https://api.openai.com/v1/chat/completions"
            class="trp-text-input" style="width: 100%;max-width:480px;" />
    </div>
    <div class="trp-openai-model-container" style="margin-top: 15px;">
        <p><span class="trp-primary-text-bold"><?php esc_html_e( 'OpenAI Model', 'hhg-for-translatepress' ); ?></span>
        </p>
        <select id="hhgfotr-openai-model" name="trp_machine_translation_settings[hhgfotr-openai-model]"
            class="trp-select" style="max-width:480px;">
            <option value="gpt-4o-mini" <?php selected( $model, 'gpt-4o-mini' ); ?>>
                <?php esc_html_e( 'GPT-4o Mini (Hot)', 'hhg-for-translatepress' ); ?></option>
            <option value="gpt-4o" <?php selected( $model, 'gpt-4o' ); ?>>
                <?php esc_html_e( 'GPT-4o', 'hhg-for-translatepress' ); ?></option>
            <option value="gpt-4-turbo" <?php selected( $model, 'gpt-4-turbo' ); ?>>
                <?php esc_html_e( 'GPT-4 Turbo', 'hhg-for-translatepress' ); ?></option>
            <option value="gpt-3.5-turbo" <?php selected( $model, 'gpt-3.5-turbo' ); ?>>
                <?php esc_html_e( 'GPT-3.5 Turbo', 'hhg-for-translatepress' ); ?></option>
            <option value="custom" <?php selected( $model, 'custom' ); ?>>
                <?php esc_html_e( 'Custom Models', 'hhg-for-translatepress' ); ?></option>
        </select>
        <div id="hhgfotr-openai-custom-model-container"
            style="margin-top:10px;<?php echo $model === 'custom' ? '' : 'display:none;'; ?>">
            <input type="text" id="hhgfotr-openai-custom-model"
                name="trp_machine_translation_settings[hhgfotr-openai-custom-model]"
                value="<?php echo esc_attr( $custom_model ); ?>" placeholder="ex: gpt-o1-preview" class="trp-text-input"
                style="width: 100%;max-width:480px;" />
            <span class="trp-description-text">
                <?php esc_html_e( 'Enter your custom OpenAI model name', 'hhg-for-translatepress' ); ?>
            </span>
        </div>
    </div>
    <span class="trp-description-text">
        <?php echo wp_kses( __( 'Visit <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Platform</a> to get your API key. GPT-4o models offer the fastest translation speeds with optimized performance.', 'hhg-for-translatepress' ), [ 'a' => [ 'href' => [], 'title' => [], 'target' => [] ] ] ); ?>
    </span>
</div>
<?php
    }

    private function add_zhipu_settings( $mt_settings, $machine_translator, $translation_engine ) {
        $api_key = isset( $mt_settings['hhgfotr-zhipu-key'] ) ? $mt_settings['hhgfotr-zhipu-key'] : ( isset( $mt_settings['hhg-zhipu-key'] ) ? $mt_settings['hhg-zhipu-key'] : '' );
        $model = isset( $mt_settings['hhgfotr-zhipu-model'] ) ? $mt_settings['hhgfotr-zhipu-model'] : ( isset( $mt_settings['hhg-zhipu-model'] ) ? $mt_settings['hhg-zhipu-model'] : 'general' );

        $error_message = '';
        $show_errors = false;
        if ( in_array( $translation_engine, array( 'hhgfotr_zhipu', 'hhg_zhipu' ), true ) && method_exists( $machine_translator, 'check_api_key_validity' ) ) {
            $api_check = $machine_translator->check_api_key_validity();
            if ( isset( $api_check ) && true === $api_check['error'] ) {
                $error_message = $api_check['message'];
                $show_errors = true;
            }
        }

        $text_input_classes = array( 'trp-text-input' );
        if ( $show_errors ) {
            $text_input_classes[] = 'trp-text-input-error';
        }
        
        $is_active = in_array( $translation_engine, array( 'hhgfotr_zhipu', 'hhg_zhipu' ), true );
        ?>

<div class="trp-engine trp-automatic-translation-engine__container" id="hhgfotr_zhipu"
    style="<?php echo $is_active ? '' : 'display: none;'; ?>">
    <span class="trp-primary-text-bold"><?php esc_html_e( 'ZhiPu AI API Key', 'hhg-for-translatepress' ); ?></span>

    <div class="trp-automatic-translation-api-key-container">
        <input type="text" id="hhgfotr-zhipu-key"
            placeholder="<?php esc_html_e( 'Add your API Key here...', 'hhg-for-translatepress' ); ?>"
            class="<?php echo esc_attr( implode( ' ', $text_input_classes ) ); ?>"
            name="trp_machine_translation_settings[hhgfotr-zhipu-key]" value="<?php echo esc_attr( $api_key ); ?>"
            style="width: 100%;max-width:480px;" />
        <?php
                if ( $is_active && function_exists( 'trp_output_svg' ) ) {
                    $machine_translator->automatic_translation_svg_output( $show_errors );
                }
                ?>
    </div>

    <?php if ( $show_errors ) : ?>
    <span class="trp-error-inline trp-settings-error-text">
        <?php echo wp_kses_post( $error_message ); ?>
    </span>
    <?php endif; ?>

    <div class="trp-zhipu-model-container" style="margin-top: 15px;">
        <p><span
                class="trp-primary-text-bold"><?php esc_html_e( 'Translation Strategy', 'hhg-for-translatepress' ); ?></span>
        </p>
        <select id="hhgfotr-zhipu-model" name="trp_machine_translation_settings[hhgfotr-zhipu-model]" class="trp-select"
            style="width: 100%;max-width:480px;">
            <option value="general" <?php selected( $model, 'general' ); ?>>
                <?php esc_html_e( 'General (Default)', 'hhg-for-translatepress' ); ?></option>
            <option value="paraphrase" <?php selected( $model, 'paraphrase' ); ?>>
                <?php esc_html_e( 'Paraphrase (More Natural)', 'hhg-for-translatepress' ); ?></option>
            <option value="two_step" <?php selected( $model, 'two_step' ); ?>>
                <?php esc_html_e( 'Two Step (Review & Refine)', 'hhg-for-translatepress' ); ?></option>
            <option value="three_step" <?php selected( $model, 'three_step' ); ?>>
                <?php esc_html_e( 'Three Step (Deep Analysis)', 'hhg-for-translatepress' ); ?></option>
            <option value="reflection" <?php selected( $model, 'reflection' ); ?>>
                <?php esc_html_e( 'Reflection (Self-Correction)', 'hhg-for-translatepress' ); ?></option>
            <option value="cot" <?php selected( $model, 'cot' ); ?>>
                <?php esc_html_e( 'Chain of Thought (Reasoning)', 'hhg-for-translatepress' ); ?></option>
        </select>
    </div>



    <span class="trp-description-text">
        <?php echo wp_kses( __( 'Visit the <a href="https://www.bigmodel.cn/invite?icode=BOAFyzK705RHkwZsGiYl40jPr3uHog9F4g5tjuOUqno%3D" target="_blank">ZhiPu AI</a> to get your API key. Select a strategy that best fits your content type.', 'hhg-for-translatepress' ), [ 'a' => [ 'href' => [], 'title' => [], 'target' => [] ] ] ); ?>
    </span>
</div>

<?php
    }

    private function add_yandex_settings( $mt_settings, $machine_translator, $translation_engine ) {
        $api_key = isset( $mt_settings['hhgfotr-yandex-key'] ) ? $mt_settings['hhgfotr-yandex-key'] : ( isset( $mt_settings['hhg-yandex-key'] ) ? $mt_settings['hhg-yandex-key'] : '' );
        $folder_id = isset( $mt_settings['hhgfotr-yandex-folder-id'] ) ? $mt_settings['hhgfotr-yandex-folder-id'] : ( isset( $mt_settings['hhg-yandex-folder-id'] ) ? $mt_settings['hhg-yandex-folder-id'] : '' );
        $model = isset( $mt_settings['hhgfotr-yandex-model'] ) ? $mt_settings['hhgfotr-yandex-model'] : ( isset( $mt_settings['hhg-yandex-model'] ) ? $mt_settings['hhg-yandex-model'] : 'yandex' );

        $error_message = '';
        $show_errors = false;
        if ( in_array( $translation_engine, array( 'hhgfotr_yandex', 'hhg_yandex' ), true ) && method_exists( $machine_translator, 'check_api_key_validity' ) ) {
            $api_check = $machine_translator->check_api_key_validity();
            if ( isset( $api_check ) && true === $api_check['error'] ) {
                $error_message = $api_check['message'];
                $show_errors = true;
            }
        }

        $text_input_classes = array( 'trp-text-input' );
        if ( $show_errors ) {
            $text_input_classes[] = 'trp-text-input-error';
        }

        $is_active = in_array( $translation_engine, array( 'hhgfotr_yandex', 'hhg_yandex' ), true );
        $yandex_test_nonce = wp_create_nonce( 'hhgfotr_yandex_test_nonce' );
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
                    $machine_translator->automatic_translation_svg_output( $show_errors );
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


            <div class="trp-yandex-test-container" style="margin-top: 15px;">
                <button type="button" class="button button-secondary" id="hhgfotr-yandex-test-api">
                    <?php esc_html_e( 'Test Yandex API Connection', 'hhg-for-translatepress' ); ?>
                </button>
                <p id="hhgfotr-yandex-test-result" class="trp-description-text" style="margin-top:8px;"></p>
            </div>

            <script type="text/javascript">
                (function($){
                    $(document).on('click', '#hhgfotr-yandex-test-api', function(){
                        var $button = $(this);
                        var $result = $('#hhgfotr-yandex-test-result');

                        $button.prop('disabled', true);
                        $result.text('<?php echo esc_js( __( 'Testing Yandex API connection...', 'hhg-for-translatepress' ) ); ?>').css('color', '');

                        $.post(ajaxurl, {
                            action: 'hhgfotr_yandex_test_api',
                            nonce: '<?php echo esc_js( $yandex_test_nonce ); ?>',
                            api_key: $('#hhgfotr-yandex-key').val(),
                            folder_id: $('#hhgfotr-yandex-folder-id').val(),
                            model: $('#hhgfotr-yandex-model').val()
                        }).done(function(response){
                            if (response && response.success) {
                                $result.text(response.data).css('color', '#1a7f37');
                            } else {
                                var message = (response && response.data) ? response.data : '<?php echo esc_js( __( 'Yandex API test failed.', 'hhg-for-translatepress' ) ); ?>';
                                $result.text(message).css('color', '#b32d2e');
                            }
                        }).fail(function(){
                            $result.text('<?php echo esc_js( __( 'Request failed. Please try again.', 'hhg-for-translatepress' ) ); ?>').css('color', '#b32d2e');
                        }).always(function(){
                            $button.prop('disabled', false);
                        });
                    });
                })(jQuery);
            </script>

            <span class="trp-description-text">
                <?php echo wp_kses( __( 'Visit <a href="https://yandex.cloud/en/docs/iam/operations/api-key/create" target="_blank">Yandex Cloud</a> to get your API key and folder ID. The Yandex Translation API provides high-quality translations for many languages.', 'hhg-for-translatepress' ), [ 'a' => [ 'href' => [], 'title' => [], 'target' => [] ] ] ); ?>

            </span>
        </div>

        <?php
    }

    private function get_setting_value( $key, $settings ) {
        return isset( $settings['trp_machine_translation_settings'][$key] ) ? $settings['trp_machine_translation_settings'][$key] : '';
    }

    public function sanitize_settings( $settings, $mt_settings ) {
        if ( isset( $mt_settings['hhgfotr-gemini-key'] ) ) {
            $settings['hhgfotr-gemini-key'] = sanitize_text_field( $mt_settings['hhgfotr-gemini-key'] );
        } elseif ( isset( $mt_settings['hhg-gemini-key'] ) ) {
            $settings['hhgfotr-gemini-key'] = sanitize_text_field( $mt_settings['hhg-gemini-key'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-gemini-model'] ) ) {
            $settings['hhgfotr-gemini-model'] = sanitize_text_field( $mt_settings['hhgfotr-gemini-model'] );
        } elseif ( isset( $mt_settings['hhg-gemini-model'] ) ) {
            $settings['hhgfotr-gemini-model'] = sanitize_text_field( $mt_settings['hhg-gemini-model'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-hunyuan-secret-id'] ) ) {
            $settings['hhgfotr-hunyuan-secret-id'] = sanitize_text_field( $mt_settings['hhgfotr-hunyuan-secret-id'] );
        } elseif ( isset( $mt_settings['hhg-hunyuan-secret-id'] ) ) {
            $settings['hhgfotr-hunyuan-secret-id'] = sanitize_text_field( $mt_settings['hhg-hunyuan-secret-id'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-hunyuan-secret-key'] ) ) {
            $settings['hhgfotr-hunyuan-secret-key'] = sanitize_text_field( $mt_settings['hhgfotr-hunyuan-secret-key'] );
        } elseif ( isset( $mt_settings['hhg-hunyuan-secret-key'] ) ) {
            $settings['hhgfotr-hunyuan-secret-key'] = sanitize_text_field( $mt_settings['hhg-hunyuan-secret-key'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-hunyuan-model'] ) ) {
            $settings['hhgfotr-hunyuan-model'] = sanitize_text_field( $mt_settings['hhgfotr-hunyuan-model'] );
        } elseif ( isset( $mt_settings['hhg-hunyuan-model'] ) ) {
            $settings['hhgfotr-hunyuan-model'] = sanitize_text_field( $mt_settings['hhg-hunyuan-model'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-openai-key'] ) ) {
            $settings['hhgfotr-openai-key'] = sanitize_text_field( $mt_settings['hhgfotr-openai-key'] );
        } elseif ( isset( $mt_settings['hhg-openai-key'] ) ) {
            $settings['hhgfotr-openai-key'] = sanitize_text_field( $mt_settings['hhg-openai-key'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-openai-model'] ) ) {
            $settings['hhgfotr-openai-model'] = sanitize_text_field( $mt_settings['hhgfotr-openai-model'] );
        } elseif ( isset( $mt_settings['hhg-openai-model'] ) ) {
            $settings['hhgfotr-openai-model'] = sanitize_text_field( $mt_settings['hhg-openai-model'] );
        }
        if ( isset( $mt_settings['hhgfotr-openai-custom-model'] ) ) {
            $settings['hhgfotr-openai-custom-model'] = sanitize_text_field( $mt_settings['hhgfotr-openai-custom-model'] );
        } elseif ( isset( $mt_settings['hhg-openai-custom-model'] ) ) {
            $settings['hhgfotr-openai-custom-model'] = sanitize_text_field( $mt_settings['hhg-openai-custom-model'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-openai-endpoint'] ) ) {
            $settings['hhgfotr-openai-endpoint'] = sanitize_text_field( $mt_settings['hhgfotr-openai-endpoint'] );
        } elseif ( isset( $mt_settings['hhg-openai-endpoint'] ) ) {
            $settings['hhgfotr-openai-endpoint'] = sanitize_text_field( $mt_settings['hhg-openai-endpoint'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-zhipu-key'] ) ) {
            $settings['hhgfotr-zhipu-key'] = sanitize_text_field( $mt_settings['hhgfotr-zhipu-key'] );
        } elseif ( isset( $mt_settings['hhg-zhipu-key'] ) ) {
            $settings['hhgfotr-zhipu-key'] = sanitize_text_field( $mt_settings['hhg-zhipu-key'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-zhipu-model'] ) ) {
            $settings['hhgfotr-zhipu-model'] = sanitize_text_field( $mt_settings['hhgfotr-zhipu-model'] );
        } elseif ( isset( $mt_settings['hhg-zhipu-model'] ) ) {
            $settings['hhgfotr-zhipu-model'] = sanitize_text_field( $mt_settings['hhg-zhipu-model'] );
        }

        if ( isset( $mt_settings['hhgfotr-yandex-key'] ) ) {
            $settings['hhgfotr-yandex-key'] = sanitize_text_field( $mt_settings['hhgfotr-yandex-key'] );
        } elseif ( isset( $mt_settings['hhg-yandex-key'] ) ) {
            $settings['hhgfotr-yandex-key'] = sanitize_text_field( $mt_settings['hhg-yandex-key'] );
        }

        if ( isset( $mt_settings['hhgfotr-yandex-folder-id'] ) ) {
            $settings['hhgfotr-yandex-folder-id'] = sanitize_text_field( $mt_settings['hhgfotr-yandex-folder-id'] );
        } elseif ( isset( $mt_settings['hhg-yandex-folder-id'] ) ) {
            $settings['hhgfotr-yandex-folder-id'] = sanitize_text_field( $mt_settings['hhg-yandex-folder-id'] );
        }

        if ( isset( $mt_settings['hhgfotr-yandex-model'] ) ) {
            $settings['hhgfotr-yandex-model'] = sanitize_text_field( $mt_settings['hhgfotr-yandex-model'] );
        } elseif ( isset( $mt_settings['hhg-yandex-model'] ) ) {
            $settings['hhgfotr-yandex-model'] = sanitize_text_field( $mt_settings['hhg-yandex-model'] );
        }

        return $settings;
    }

    public function extend_machine_translation_keys( $settings, $mt_settings ) {
        if ( isset( $mt_settings['hhgfotr-gemini-key'] ) ) {
            $settings['hhgfotr-gemini-key'] = sanitize_text_field( $mt_settings['hhgfotr-gemini-key'] );
        } elseif ( isset( $mt_settings['hhg-gemini-key'] ) ) {
            $settings['hhgfotr-gemini-key'] = sanitize_text_field( $mt_settings['hhg-gemini-key'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-gemini-model'] ) ) {
            $settings['hhgfotr-gemini-model'] = sanitize_text_field( $mt_settings['hhgfotr-gemini-model'] );
        } elseif ( isset( $mt_settings['hhg-gemini-model'] ) ) {
            $settings['hhgfotr-gemini-model'] = sanitize_text_field( $mt_settings['hhg-gemini-model'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-hunyuan-secret-id'] ) ) {
            $settings['hhgfotr-hunyuan-secret-id'] = sanitize_text_field( $mt_settings['hhgfotr-hunyuan-secret-id'] );
        } elseif ( isset( $mt_settings['hhg-hunyuan-secret-id'] ) ) {
            $settings['hhgfotr-hunyuan-secret-id'] = sanitize_text_field( $mt_settings['hhg-hunyuan-secret-id'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-hunyuan-secret-key'] ) ) {
            $settings['hhgfotr-hunyuan-secret-key'] = sanitize_text_field( $mt_settings['hhgfotr-hunyuan-secret-key'] );
        } elseif ( isset( $mt_settings['hhg-hunyuan-secret-key'] ) ) {
            $settings['hhgfotr-hunyuan-secret-key'] = sanitize_text_field( $mt_settings['hhg-hunyuan-secret-key'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-hunyuan-model'] ) ) {
            $settings['hhgfotr-hunyuan-model'] = sanitize_text_field( $mt_settings['hhgfotr-hunyuan-model'] );
        } elseif ( isset( $mt_settings['hhg-hunyuan-model'] ) ) {
            $settings['hhgfotr-hunyuan-model'] = sanitize_text_field( $mt_settings['hhg-hunyuan-model'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-openai-key'] ) ) {
            $settings['hhgfotr-openai-key'] = sanitize_text_field( $mt_settings['hhgfotr-openai-key'] );
        } elseif ( isset( $mt_settings['hhg-openai-key'] ) ) {
            $settings['hhgfotr-openai-key'] = sanitize_text_field( $mt_settings['hhg-openai-key'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-openai-model'] ) ) {
            $settings['hhgfotr-openai-model'] = sanitize_text_field( $mt_settings['hhgfotr-openai-model'] );
        } elseif ( isset( $mt_settings['hhg-openai-model'] ) ) {
            $settings['hhgfotr-openai-model'] = sanitize_text_field( $mt_settings['hhg-openai-model'] );
        }
        if ( isset( $mt_settings['hhgfotr-openai-custom-model'] ) ) {
            $settings['hhgfotr-openai-custom-model'] = sanitize_text_field( $mt_settings['hhgfotr-openai-custom-model'] );
        } elseif ( isset( $mt_settings['hhg-openai-custom-model'] ) ) {
            $settings['hhgfotr-openai-custom-model'] = sanitize_text_field( $mt_settings['hhg-openai-custom-model'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-openai-endpoint'] ) ) {
            $settings['hhgfotr-openai-endpoint'] = sanitize_text_field( $mt_settings['hhgfotr-openai-endpoint'] );
        } elseif ( isset( $mt_settings['hhg-openai-endpoint'] ) ) {
            $settings['hhgfotr-openai-endpoint'] = sanitize_text_field( $mt_settings['hhg-openai-endpoint'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-zhipu-key'] ) ) {
            $settings['hhgfotr-zhipu-key'] = sanitize_text_field( $mt_settings['hhgfotr-zhipu-key'] );
        } elseif ( isset( $mt_settings['hhg-zhipu-key'] ) ) {
            $settings['hhgfotr-zhipu-key'] = sanitize_text_field( $mt_settings['hhg-zhipu-key'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-zhipu-model'] ) ) {
            $settings['hhgfotr-zhipu-model'] = sanitize_text_field( $mt_settings['hhgfotr-zhipu-model'] );
        } elseif ( isset( $mt_settings['hhg-zhipu-model'] ) ) {
            $settings['hhgfotr-zhipu-model'] = sanitize_text_field( $mt_settings['hhg-zhipu-model'] );
        }

        if ( isset( $mt_settings['hhgfotr-yandex-key'] ) ) {
            $settings['hhgfotr-yandex-key'] = sanitize_text_field( $mt_settings['hhgfotr-yandex-key'] );
        } elseif ( isset( $mt_settings['hhg-yandex-key'] ) ) {
            $settings['hhgfotr-yandex-key'] = sanitize_text_field( $mt_settings['hhg-yandex-key'] );
        }

        if ( isset( $mt_settings['hhgfotr-yandex-folder-id'] ) ) {
            $settings['hhgfotr-yandex-folder-id'] = sanitize_text_field( $mt_settings['hhgfotr-yandex-folder-id'] );
        } elseif ( isset( $mt_settings['hhg-yandex-folder-id'] ) ) {
            $settings['hhgfotr-yandex-folder-id'] = sanitize_text_field( $mt_settings['hhg-yandex-folder-id'] );
        }

        if ( isset( $mt_settings['hhgfotr-yandex-model'] ) ) {
            $settings['hhgfotr-yandex-model'] = sanitize_text_field( $mt_settings['hhgfotr-yandex-model'] );
        } elseif ( isset( $mt_settings['hhg-yandex-model'] ) ) {
            $settings['hhgfotr-yandex-model'] = sanitize_text_field( $mt_settings['hhg-yandex-model'] );
        }

        return $settings;
    }

    public function add_hhg_engines_to_list( $engines ) {
        $engines[] = array(
            'value' => 'hhgfotr_gemini',
            'label' => esc_html__( 'Google Gemini AI', 'hhg-for-translatepress' ),
        );
        
        $engines[] = array(
            'value' => 'hhgfotr_hunyuan',
            'label' => esc_html__( 'Tencent Hunyuan', 'hhg-for-translatepress' ),
        );
        
        $engines[] = array(
            'value' => 'hhgfotr_openai',
            'label' => esc_html__( 'OpenAI GPT', 'hhg-for-translatepress' ),
        );
        
        $engines[] = array(
            'value' => 'hhgfotr_zhipu',
            'label' => esc_html__( 'ZhiPu AI GLM', 'hhg-for-translatepress' ),
        );

        $engines[] = array(
            'value' => 'hhgfotr_yandex',
            'label' => esc_html__( 'Yandex Translation', 'hhg-for-translatepress' ),
        );

        return $engines;
    }

    public function add_default_settings( $default_settings ) {
        $default_settings['hhgfotr-gemini-key'] = '';
        $default_settings['hhgfotr-gemini-model'] = 'gemini-2.5-flash';
        $default_settings['hhgfotr-hunyuan-secret-id'] = '';
        $default_settings['hhgfotr-hunyuan-secret-key'] = '';
        $default_settings['hhgfotr-hunyuan-model'] = 'hunyuan-translation-lite';
        $default_settings['hhgfotr-openai-key'] = '';
        $default_settings['hhgfotr-openai-model'] = 'gpt-4o-mini';
        $default_settings['hhgfotr-openai-endpoint'] = 'https://api.openai.com/v1/chat/completions';
        $default_settings['hhgfotr-zhipu-key'] = '';
        $default_settings['hhgfotr-zhipu-model'] = 'general';
        $default_settings['hhgfotr-yandex-key'] = '';
        $default_settings['hhgfotr-yandex-folder-id'] = '';
        $default_settings['hhgfotr-yandex-model'] = 'yandex';
        
        return $default_settings;
    }

    public function missing_translatepress_notice() {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'HHG for TranslatePress requires TranslatePress to be installed and activated.', 'hhg-for-translatepress' ) . '</p></div>';
    }

    public function handle_zhipu_test_api() {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'hhgfotr_zhipu_test_nonce' ) && ! wp_verify_nonce( $nonce, 'hhg_zhipu_test_nonce' ) ) {
            wp_send_json_error( 'Security Authentication Failure' );
        }

        $model = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';

        $settings = array(
            'hhgfotr-zhipu-model' => $model
        );
        
        $translator = new TRP_HHGFOTR_Zhipu_Machine_Translator( $settings );

        $result = $translator->test_request();
        
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        
        $response_code = wp_remote_retrieve_response_code( $result );
        $response_body = wp_remote_retrieve_body( $result );
        
        if ( $response_code !== 200 ) {
            $error_data = json_decode( $response_body, true );
            $error_message = 'API request failed';
            
            if ( isset( $error_data['error']['message'] ) ) {
                $error_message = $error_data['error']['message'];
            } elseif ( isset( $error_data['error'] ) ) {
                $error_message = $error_data['error'];
            } elseif ( !empty( $response_body ) ) {
                $error_message = 'API Error: ' . $response_body;
            }
            
            wp_send_json_error( $error_message );
        }
        
        $response_data = json_decode( $response_body, true );
        
        if ( ! $response_data || ! isset( $response_data['choices'][0]['message']['content'] ) ) {
            wp_send_json_error( 'API response format error' );
        }
        
        wp_send_json_success( 'The API connection was successful! Model:' . $model );
    }


    public function handle_yandex_test_api() {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'hhgfotr_yandex_test_nonce' ) ) {
            wp_send_json_error( 'Security Authentication Failure' );
        }

        $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
        $folder_id = isset( $_POST['folder_id'] ) ? sanitize_text_field( wp_unslash( $_POST['folder_id'] ) ) : '';
        $model = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : 'yandex';

        $translator_settings = array(
            'trp_machine_translation_settings' => array(
                'translation-engine' => 'hhgfotr_yandex',
                'machine-translation' => 'yes',
                'hhgfotr-yandex-key' => $api_key,
                'hhgfotr-yandex-folder-id' => $folder_id,
                'hhgfotr-yandex-model' => $model,
            ),
        );

        $translator = new TRP_HHGFOTR_Yandex_Machine_Translator( $translator_settings );
        $api_check = $translator->check_api_key_validity();

        if ( ! empty( $api_check['error'] ) ) {
            wp_send_json_error( isset( $api_check['message'] ) ? $api_check['message'] : __( 'Yandex API test failed.', 'hhg-for-translatepress' ) );
        }

        wp_send_json_success( __( 'Yandex API connection successful.', 'hhg-for-translatepress' ) );
    }

    public function enqueue_admin_scripts( $hook ) {

        if ( strpos( $hook, 'translatepress' ) === false && strpos( $hook, 'options-general.php' ) === false ) {
            return;
        }
        
        wp_enqueue_script( 'jquery' );
        wp_enqueue_script( 
            'hhgfotr-admin-engine-switch', 
            HHGFOTR_PLUGIN_URL . 'assets/js/admin-engine-switch.js', 
            array( 'jquery' ), 
            '1.0.4', 
            true 
        );

        wp_enqueue_script( 
            'hhgfotr-openai-model-switch', 
            HHGFOTR_PLUGIN_URL . 'assets/js/openai-model-switch.js', 
            array(), 
            '1.0.4', 
            true 
        );
        
        wp_enqueue_style( 
            'hhgfotr-admin-styles', 
            HHGFOTR_PLUGIN_URL . 'assets/css/admin-styles.css', 
            array(), 
            '1.0.4' 
        );
    }
}

HHGFOTR_TranslatePress::get_instance();