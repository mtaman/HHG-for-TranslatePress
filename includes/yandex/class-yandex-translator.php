<?php
/**
 * Yandex Translation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_HHGFOTR_Yandex_Machine_Translator extends TRP_Machine_Translator {

    private $api_endpoint;
    private $config;

    public function __construct( $settings ) {
        parent::__construct( $settings );
        
        $selected_model = $this->get_selected_model();
        $this->api_endpoint = 'https://translate.api.cloud.yandex.net/translate/v2/translate';
        $this->config = $this->get_optimized_config( $selected_model );
    }

    private function get_optimized_config( $model ) {

        $base_config = array(
            'chunk_size' => 15,
            'timeout' => 45,
            'max_tokens' => 8192,
            'temperature' => 0.01,
            'top_p' => 0.95,
            'safety_threshold' => 'BLOCK_ONLY_HIGH'
        );


        if ( strpos( $model, 'pro' ) !== false ) {
            $base_config['chunk_size'] = 14;
            $base_config['temperature'] = 0.02;
        }

        return $base_config;
    }

    public function send_request( $source_language, $target_language, $strings_array ) {
        $api_key = $this->get_api_key();
        $folder_id = $this->get_folder_id();
        
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'Yandex API key cannot be empty' );
        }
        
        if ( empty( $folder_id ) ) {
            return new WP_Error( 'no_folder_id', 'Yandex folder ID cannot be empty' );
        }

        $request_body = array(
            'targetLanguageCode' => $target_language,
            'sourceLanguageCode' => $source_language,
            'format' => 'HTML',
            'texts' => $strings_array
        );

        if ( !empty( $folder_id ) ) {
            $request_body['folderId'] = $folder_id;
        }

        $referer = $this->get_referer();

        $response = wp_remote_post( $this->api_endpoint, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'Referer' => $referer,
                'User-Agent' => 'TranslatePress/1.0'
            ),
            'body' => wp_json_encode( $request_body ),
            'timeout' => $this->config['timeout'],
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking' => true,
            'sslverify' => true
        ));

        return $response;
    }

    public function translate_array( $new_strings, $target_language_code, $source_language_code = null ) {
        if ( $source_language_code == null ) {
            $source_language_code = $this->settings['default-language'];
        }
        
        if( empty( $new_strings ) || !$this->verify_request_parameters( $target_language_code, $source_language_code ) )
            return array();

        $source_language = $this->machine_translation_codes[$source_language_code];
        $target_language = $this->machine_translation_codes[$target_language_code];

        $translated_strings = array();

        $chunk_size = $this->get_optimal_chunk_size( $new_strings );
        $new_strings_chunks = array_chunk( $new_strings, $chunk_size, true );
        
        foreach( $new_strings_chunks as $new_strings_chunk ) {
            $response = $this->send_request( $source_language, $target_language, $new_strings_chunk );

            $this->machine_translator_logger->log(array(
                'strings'   => serialize( $new_strings_chunk),
                'response'  => serialize( $response ),
                'lang_source'  => $source_language,
                'lang_target'  => $target_language,
            ));

            if ( is_array( $response ) && ! is_wp_error( $response ) && isset( $response['response'] ) &&
                isset( $response['response']['code']) && $response['response']['code'] == 200 ) {

                $response_body = json_decode( $response['body'], true );
                
                if ( isset( $response_body['translations'] ) && is_array( $response_body['translations'] ) ) {
                    $this->machine_translator_logger->count_towards_quota( $new_strings_chunk );
                    
                    $chunk_values = array_values( $new_strings_chunk );
                    $chunk_translations = array();
                    
                    foreach ($response_body['translations'] as $index => $translation_data) {
                        if (isset($translation_data['text'])) {
                            $chunk_translations[] = $translation_data['text'];
                        } else {
                            $chunk_translations[] = $chunk_values[$index]; // fallback to original if no translation
                        }
                    }
                    
                    $i = 0;
                    foreach ( $new_strings_chunk as $key => $old_string ) {
                        if ( isset( $chunk_translations[$i] ) && !empty( $chunk_translations[$i] ) ) {
                            $translated_strings[ $key ] = $chunk_translations[$i];
                        } else {
                            $translated_strings[ $key ] = $old_string;
                        }
                        $i++;
                    }
                } else {
                    foreach ( $new_strings_chunk as $key => $old_string ) {
                        $translated_strings[ $key ] = $old_string;
                    }
                }

                if( $this->machine_translator_logger->quota_exceeded() )
                    break;
            } else {
                foreach ( $new_strings_chunk as $key => $old_string ) {
                    $translated_strings[ $key ] = $old_string;
                }
            }
        }

        return $translated_strings;
    }

    public function test_request() {
        return $this->send_request( 'en', 'ru', array( 'Hello, world!' ) );
    }

    public function get_api_key() {
        if ( isset( $this->settings['trp_machine_translation_settings']['hhgfotr-yandex-key'] ) ) {
            return $this->settings['trp_machine_translation_settings']['hhgfotr-yandex-key'];
        }
        return isset( $this->settings['trp_machine_translation_settings']['hhg-yandex-key'] ) ? $this->settings['trp_machine_translation_settings']['hhg-yandex-key'] : false;
    }
    
    public function get_folder_id() {
        if ( isset( $this->settings['trp_machine_translation_settings']['hhgfotr-yandex-folder-id'] ) ) {
            return $this->settings['trp_machine_translation_settings']['hhgfotr-yandex-folder-id'];
        }
        return isset( $this->settings['trp_machine_translation_settings']['hhg-yandex-folder-id'] ) ? $this->settings['trp_machine_translation_settings']['hhg-yandex-folder-id'] : false;
    }

    public function get_selected_model() {
        $selected_model = isset( $this->settings['trp_machine_translation_settings']['hhgfotr-yandex-model'] ) ? $this->settings['trp_machine_translation_settings']['hhgfotr-yandex-model'] : ( isset( $this->settings['trp_machine_translation_settings']['hhg-yandex-model'] ) ? $this->settings['trp_machine_translation_settings']['hhg-yandex-model'] : 'yandex' );
        $available_models = $this->get_available_models();
        if ( !array_key_exists( $selected_model, $available_models ) ) {
            $selected_model = 'yandex';
        }
        return $selected_model;
    }

    public function get_available_models() {
        return array(
            'yandex' => 'Yandex Translator',
            'yandex-pro' => 'Yandex Pro Translator'
        );
    }

    public function get_supported_languages() {
        $supported_languages = array(
            'en', 'ru', 'de', 'es', 'fr', 'it', 'pl', 'pt', 'zh', 'ja', 'ko', 
            'tr', 'ar', 'hi', 'th', 'vi', 'id', 'uk', 'kk', 'tg', 'uz', 'ky', 
            'az', 'hy', 'ka', 'he', 'la', 'mn', 'mr', 'bn', 'ta', 'te', 'si', 
            'my', 'km', 'ne', 'am', 'ku', 'ps', 'sd', 'ur', 'fa', 'ku', 'ug', 
            'tt', 'ba', 'cv', 'os', 'myv', 'mdf', 'kbd', 'ady', 'kum', 'ava', 
            'agx', 'abq', 'xal', 'crh', 'sah', 'bua', 'tyv', 'mon', 'sr', 'bs', 
            'bg', 'mk', 'kaa', 'crs', 'sw', 'rw', 'rn', 'lg', 'nyn', 'wo', 'tw', 
            'ak', 'sn', 'nd', 'st', 'ti', 'am', 'so', 'ii', 'ch', 'na', 'mi', 
            'sm', 'to', 'ht', 'qu', 'ay', 'gn', 'su', 'jv', 'ilo', 'ceb', 'war', 
            'mrj', 'sa', 'sat', 'shi', 'tzm', 'ff', 'bm', 'dyu', 'ln', 'sg', 
            'nqo', 'ks', 'sdh', 'ckb', 'mzn', 'glk', 'prs', 'dv', 'sk', 'cs', 
            'ro', 'nl', 'da', 'sv', 'no', 'fi', 'et', 'lv', 'lt', 'el', 'hu', 
            'sk', 'cs', 'ro', 'bg', 'hr', 'sl', 'mt', 'ga', 'eu', 'ca', 'gl', 
            'af', 'zu', 'xh', 'tn', 'ts', 've', 'ny', 'mg', 'rn', 'rw', 'sn', 
            'so', 'ti', 'am', 'wo', 'yo', 'ig', 'ha', 'sw', 'zu', 'st', 'ts', 
            've', 'xh', 'tn', 'nr', 'ss', 'ho', 'kj', 'lu', 'lg', 'nso', 'nr', 
            'ss', 'ho', 'kj', 'lu', 'lg', 'nso'
        );

        $supported_languages = apply_filters( 'trp_add_hhgfotr_yandex_supported_languages_to_the_array', $supported_languages );
        $supported_languages = apply_filters( 'trp_add_hhg_yandex_supported_languages_to_the_array', $supported_languages );
        return $supported_languages;
    }

    public function get_engine_specific_language_codes( $languages ) {
        $yandex_language_codes = array();
        $iso_codes = $this->trp_languages->get_iso_codes( $languages );
        
        $yandex_language_mapping = array(
            'zh_HK' => 'zh', 'zh_TW' => 'zh', 'zh_CN' => 'zh', 'zh_SG' => 'zh',
            'en_US' => 'en', 'en_GB' => 'en', 'en_CA' => 'en', 'en_AU' => 'en',
            'pt_BR' => 'pt', 'pt_PT' => 'pt', 'es_ES' => 'es', 'es_MX' => 'es',
            'fr_FR' => 'fr', 'fr_CA' => 'fr', 'de_DE' => 'de', 'de_AT' => 'de',
            'nb_NO' => 'no', 'nn_NO' => 'no', 'de_DE_formal' => 'de'
        );
        
        foreach( $languages as $language ) {
            if( isset( $yandex_language_mapping[$language] ) ) {
                $yandex_language_codes[$language] = $yandex_language_mapping[$language];
            } else {
                $yandex_language_codes[$language] = isset( $iso_codes[$language] ) ? $iso_codes[$language] : $language;
            }
        }
        
        return $yandex_language_codes;
    }

    public function check_formality() {
        return array();
    }

    public function check_api_key_validity() {
        $machine_translator = $this;
        $translation_engine = $this->settings['trp_machine_translation_settings']['translation-engine'];
        $api_key = $machine_translator->get_api_key();
        $folder_id = $machine_translator->get_folder_id();

        $is_error = false;
        $return_message = '';

        if ( 'hhgfotr_yandex' === $translation_engine && 
             isset($this->settings['trp_machine_translation_settings']['machine-translation']) &&
             $this->settings['trp_machine_translation_settings']['machine-translation'] === 'yes') {

            if ( isset( $this->correct_api_key ) && $this->correct_api_key != null ) {
                return $this->correct_api_key;
            }

            if ( empty( $api_key ) ) {
                $is_error = true;
                $return_message = 'Please enter your Yandex API key.';
            } elseif ( empty( $folder_id ) ) {
                $is_error = true;
                $return_message = 'Please enter your Yandex folder ID.';
            } else {
                $response = $machine_translator->test_request();
                if ( is_wp_error( $response ) ) {
                    $is_error = true;
                    $return_message = $response->get_error_message();
                } else {
                    $code = wp_remote_retrieve_response_code( $response );
                    if ( 200 !== $code ) {
                        $body = wp_remote_retrieve_body( $response );
                        $decoded = json_decode( $body, true );
                        $msg = '';
                        if ( isset( $decoded['error']['message'] ) ) {
                            $msg = $decoded['error']['message'];
                        } elseif ( ! empty( $body ) ) {
                            $msg = $body;
                        }
                        $is_error = true;
                        $return_message = 'The API key or folder ID is invalid or the API request failed. Error Code:' . $code . ( $msg ? ('; ' . $msg) : '' );
                    }
                }
            }
            
            $this->correct_api_key = array(
                'message' => $return_message,
                'error' => $is_error,
            );
        }

        return array(
            'message' => $return_message,
            'error' => $is_error,
        );
    }

    private function get_optimal_chunk_size( $strings_array ) {
        $base_chunk_size = $this->config['chunk_size'];
        $total_strings = count( $strings_array );
        
        if ( $total_strings === 0 || !is_array( $strings_array ) ) {
            return $base_chunk_size;
        }
        
        $total_length = 0;
        $sample_size = min( 5, $total_strings );
        
        for ( $i = 0; $i < $sample_size; $i++ ) {
            $total_length += strlen( $strings_array[array_keys($strings_array)[$i]] );
        }
        
        $avg_length = $total_length / $sample_size;
        
        if ( $avg_length > 200 ) {
            $base_chunk_size = max( 5, intval( $base_chunk_size * 0.6 ) );
        } elseif ( $avg_length > 100 ) {
            $base_chunk_size = max( 10, intval( $base_chunk_size * 0.8 ) );
        }
        
        return min( $base_chunk_size, $total_strings );
    }
}