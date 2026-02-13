<?php
/**
 * Tencent Hunyuan Translation
 */


if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_HHGFOTR_Hunyuan_Machine_Translator extends TRP_Machine_Translator {

    private $api_endpoint = 'https://hunyuan.tencentcloudapi.com/';
    private $region = 'ap-beijing';
    private $service = 'hunyuan';
    private $version = '2023-09-01';
    private $action = 'ChatTranslations';
    private $config;

    public function __construct( $settings ) {
        parent::__construct( $settings );
        
        $this->service = 'hunyuan';
        $this->action = 'ChatTranslations';
        $this->version = '2023-09-01';
        $this->region = 'ap-beijing';
        $this->api_endpoint = 'https://hunyuan.tencentcloudapi.com/';
        
        $this->config = $this->get_optimized_config( $this->get_selected_model() );
    }

    private function get_optimized_config( $model ) {

        $base_config = array(
            'chunk_size' => 30,
            'timeout' => 60,
            'max_tokens' => 8192,
            'temperature' => 0.01,
            'top_p' => 0.95
        );
        
        if ( $model === 'hunyuan-translation-lite' ) {
            $base_config['chunk_size'] = 30;
            $base_config['timeout'] = 60;
        } else {
            $base_config['chunk_size'] = 25;
            $base_config['timeout'] = 60;
        }
        
        return $base_config;
    }

    public function get_selected_model() {
        $selected_model = isset( $this->settings['trp_machine_translation_settings']['hhgfotr-hunyuan-model'] ) ? $this->settings['trp_machine_translation_settings']['hhgfotr-hunyuan-model'] : ( isset( $this->settings['trp_machine_translation_settings']['hhg-hunyuan-model'] ) ? $this->settings['trp_machine_translation_settings']['hhg-hunyuan-model'] : 'hunyuan-translation-lite' );

        if ( in_array( $selected_model, array( 'hunyuan-translation', 'hunyuan-translation-lite' ), true ) ) {
            return $selected_model;
        }

        if ( $selected_model === 'hunyuan-pro' ) { return 'hunyuan-translation'; }
        if ( $selected_model === 'hunyuan-lite' ) { return 'hunyuan-translation-lite'; }
        return 'hunyuan-translation-lite'; 
    }

    public function get_available_models() {
        return array(
            'hunyuan-translation' => 'Hunyuan Translation',
            'hunyuan-translation-lite' => 'Hunyuan Translation Lite',
        );
    }

    public function get_secret_id() {
        return isset( $this->settings['trp_machine_translation_settings']['hhgfotr-hunyuan-secret-id'] ) ? $this->settings['trp_machine_translation_settings']['hhgfotr-hunyuan-secret-id'] : ( isset( $this->settings['trp_machine_translation_settings']['hhg-hunyuan-secret-id'] ) ? $this->settings['trp_machine_translation_settings']['hhg-hunyuan-secret-id'] : '' );
    }

    public function get_secret_key() {
        return isset( $this->settings['trp_machine_translation_settings']['hhgfotr-hunyuan-secret-key'] ) ? $this->settings['trp_machine_translation_settings']['hhgfotr-hunyuan-secret-key'] : ( isset( $this->settings['trp_machine_translation_settings']['hhg-hunyuan-secret-key'] ) ? $this->settings['trp_machine_translation_settings']['hhg-hunyuan-secret-key'] : '' );
    }

    public function get_api_key() {
        $secret_id = $this->get_secret_id();
        $secret_key = $this->get_secret_key();
        return !empty( $secret_id ) && !empty( $secret_key ) ? $secret_id : false;
    }

    public function get_referer() {
        return is_ssl() ? home_url( '/', 'https' ) : home_url( '/', 'http' );
    }

    private function generate_signature( $secret_key, $string_to_sign ) {
        return base64_encode( hash_hmac( 'sha256', $string_to_sign, $secret_key, true ) );
    }

    private function generate_auth_headers( $payload ) {
        $secret_id = $this->get_secret_id();
        $secret_key = $this->get_secret_key();
        $timestamp = time();
        $date = gmdate( 'Y-m-d', $timestamp );
        
        $http_request_method = 'POST';
        $canonical_uri = '/';
        $canonical_querystring = '';
        $canonical_headers = "content-type:application/json; charset=utf-8\n" .
                           "host:hunyuan.tencentcloudapi.com\n" .
                           "x-tc-action:" . strtolower( $this->action ) . "\n";
        $signed_headers = 'content-type;host;x-tc-action';
        $hashed_request_payload = hash( 'sha256', $payload );
        $canonical_request = $http_request_method . "\n" .
                           $canonical_uri . "\n" .
                           $canonical_querystring . "\n" .
                           $canonical_headers . "\n" .
                           $signed_headers . "\n" .
                           $hashed_request_payload;
        
        $algorithm = 'TC3-HMAC-SHA256';
        $credential_scope = $date . '/' . $this->service . '/tc3_request';
        $string_to_sign = $algorithm . "\n" .
                         $timestamp . "\n" .
                         $credential_scope . "\n" .
                         hash( 'sha256', $canonical_request );
        
        $secret_date = hash_hmac( 'sha256', $date, 'TC3' . $secret_key, true );
        $secret_service = hash_hmac( 'sha256', $this->service, $secret_date, true );
        $secret_signing = hash_hmac( 'sha256', 'tc3_request', $secret_service, true );
        $signature = hash_hmac( 'sha256', $string_to_sign, $secret_signing );
        
        $authorization = $algorithm . ' ' .
                        'Credential=' . $secret_id . '/' . $credential_scope . ', ' .
                        'SignedHeaders=' . $signed_headers . ', ' .
                        'Signature=' . $signature;
        
        return array(
            'Authorization' => $authorization,
            'Content-Type' => 'application/json; charset=utf-8',
            'Host' => 'hunyuan.tencentcloudapi.com',
            'X-TC-Action' => $this->action,
            'X-TC-Timestamp' => $timestamp,
            'X-TC-Version' => $this->version,
            'X-TC-Region' => $this->region
        );
    }

    public function send_request( $source_language, $target_language, $strings_array ) {
        $secret_id = $this->get_secret_id();
        $secret_key = $this->get_secret_key();
        
        if ( empty( $secret_id ) || empty( $secret_key ) ) {
            return new WP_Error( 'no_credentials', 'Tencent hunyuan API credentials can not be empty' );
        }

        $prompt = $this->build_translation_prompt( $source_language, $target_language, $strings_array );
        
        $request_body = array(
            'Model' => $this->get_selected_model(),
            'Stream' => false,
            'Text' => $strings_array[0],
            'Source' => $source_language,
            'Target' => $target_language
        );
        
        $payload = wp_json_encode( $request_body );
        $headers = $this->generate_auth_headers( $payload );
        
        $response = wp_remote_post( $this->api_endpoint, array(
            'headers' => $headers,
            'body' => $payload,
            'timeout' => $this->config['timeout'],
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking' => true,
            'sslverify' => true
        ));

        return $response;
    }

    private function build_translation_prompt( $source_language, $target_language, $strings_array ) {
        $prompt = "Please translate the following text from {$source_language} to {$target_language}. Requirement:\n";
        $prompt .= "1.Keep all HTML tags and formatting intact\n";
        $prompt .= "2.Do not translate URL links\n";
        $prompt .= "3.Returns only the translated text, one per line of the\n";
        $prompt .= "4.Maintaining the original structure\n\n";
        
        $counter = 1;
        foreach ( $strings_array as $string ) {
            $prompt .= $counter . ". " . $string . "\n";
            $counter++;
        }
        
        return $prompt;
    }

    private function parse_translation_response( $response_text, $original_strings ) {
        $translations = array();
        $lines = explode( "\n", trim( $response_text ) );
        $translation_lines = array();
        
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) continue;
            
            if ( preg_match( '/^\d+\.\s*(.+)$/', $line, $matches ) ) {
                $translation_lines[] = $matches[1];
            } else {
                $translation_lines[] = $line;
            }
        }
        
        if ( count( $translation_lines ) !== count( $original_strings ) ) {
            $translation_lines = array_filter( array_map( 'trim', $lines ) );
        }
        
        $count = min( count( $original_strings ), count( $translation_lines ) );
        for ( $i = 0; $i < $count; $i++ ) {
            $translations[] = $translation_lines[$i];
        }
        
        while ( count( $translations ) < count( $original_strings ) ) {
            $translations[] = $original_strings[count( $translations )];
        }
        
        return $translations;
    }

    public function translate_array( $strings_array, $target_language_code, $source_language_code ) {
        if ( empty( $strings_array ) ) {
            return array();
        }

        $source_language = $this->get_language_code_mapping( $source_language_code );
        $target_language = $this->get_language_code_mapping( $target_language_code );

        $chunk_size = $this->config['chunk_size'];
        $translated_strings = array();
        $api_client = class_exists('HHG_API_Client') ? HHG_API_Client::get_instance() : null;
        $requests = array();
        $keys = array();

        for ( $i = 0; $i < count( $strings_array ); $i += $chunk_size ) {
            $chunk = array_slice( $strings_array, $i, $chunk_size, true );
            foreach ( $chunk as $key => $string ) {
                $request_body = array(
                    'Model' => $this->get_selected_model(),
                    'Stream' => false,
                    'Text' => $string,
                    'Source' => $source_language,
                    'Target' => $target_language
                );
                $payload = wp_json_encode( $request_body );
                $headers = $this->generate_auth_headers( $payload );
                $headers['Content-Type'] = 'application/json; charset=utf-8';
                $headers['Host'] = 'hunyuan.tencentcloudapi.com';
                if ( $api_client ) {
                    $requests[] = array(
                        'url' => $this->api_endpoint,
                        'method' => 'POST',
                        'headers' => $headers,
                        'body' => $payload,
                        'timeout' => $this->config['timeout']
                    );
                    $keys[] = $key;
                } else {
                    $response = wp_remote_post( $this->api_endpoint, array(
                        'headers' => $headers,
                        'body' => $payload,
                        'timeout' => $this->config['timeout'],
                        'redirection' => 5,
                        'httpversion' => '1.1',
                        'blocking' => true,
                        'sslverify' => true
                    ));
                    $resp_code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code( $response );
                    $resp_body = is_wp_error($response) ? '' : wp_remote_retrieve_body( $response );
                    $resp_data = json_decode( $resp_body, true );
                    if ( $resp_code === 200 ) {
                        if ( isset($resp_data['Response']['Choices'][0]['Message']) ) {
                            $translated_strings[$key] = $resp_data['Response']['Choices'][0]['Message'];
                        } elseif ( isset($resp_data['Response']['TargetText']) ) {
                            $translated_strings[$key] = $resp_data['Response']['TargetText'];
                        } else {
                            $translated_strings[$key] = $strings_array[$key];
                        }
                        if ( isset($this->machine_translator_logger) ) {
                            $this->machine_translator_logger->count_towards_quota( array( $strings_array[$key] ) );
                            $this->machine_translator_logger->log( array(
                                'strings' => serialize( array( $strings_array[$key] ) ),
                                'response' => serialize( array( 'response_code' => $resp_code, 'body' => $resp_body ) ),
                                'lang_source' => $source_language,
                                'lang_target' => $target_language
                            ) );
                        }
                    } else {
                        $translated_strings[$key] = $strings_array[$key];
                        if ( isset($this->machine_translator_logger) ) {
                            $this->machine_translator_logger->log( array(
                                'strings' => serialize( array( $strings_array[$key] ) ),
                                'response' => serialize( array( 'response_code' => $resp_code, 'body' => $resp_body ) ),
                                'lang_source' => $source_language,
                                'lang_target' => $target_language
                            ) );
                        }
                    }
                }
            }
        }

        if ( $api_client && !empty($requests) ) {
            if ( class_exists('HHG_Logger') ) {
                HHG_Logger::log( 'Dispatching Hunyuan translation requests', array( 'count' => count($requests), 'source' => $source_language, 'target' => $target_language ) );
            }
            $max_parallel = apply_filters( 'hhgfotr_hunyuan_max_parallel', 5 );
            $offset = 0;
            while ( $offset < count( $requests ) ) {
                $batch = array_slice( $requests, $offset, $max_parallel );
                $batch_keys = array_slice( $keys, $offset, $max_parallel );
                $results = $api_client->request_async( $batch );
                foreach ( $results as $idx => $result ) {
                    $key = $batch_keys[$idx];
                    if ( $result && $result['response_code'] == 200 ) {
                        $data = json_decode( $result['body'], true );
                    if ( isset($data['Response']['Choices'][0]['Message']) ) {
                        $translated_strings[$key] = $data['Response']['Choices'][0]['Message'];
                    } elseif ( isset($data['Response']['TargetText']) ) {
                        $translated_strings[$key] = $data['Response']['TargetText'];
                    } else {
                        $translated_strings[$key] = $strings_array[$key];
                    }
                    if ( isset($this->machine_translator_logger) ) {
                        $this->machine_translator_logger->count_towards_quota( array( $strings_array[$key] ) );
                        $this->machine_translator_logger->log( array(
                            'strings' => serialize( array( $strings_array[$key] ) ),
                            'response' => serialize( $result ),
                            'lang_source' => $source_language,
                            'lang_target' => $target_language
                        ) );
                    }
                } else {
                    $translated_strings[$key] = $strings_array[$key];
                    if ( isset($this->machine_translator_logger) ) {
                        $this->machine_translator_logger->log( array(
                            'strings' => serialize( array( $strings_array[$key] ) ),
                            'response' => serialize( $result ),
                            'lang_source' => $source_language,
                            'lang_target' => $target_language
                        ) );
                    }
                }
            }
            $offset += $max_parallel;
            if ( class_exists('HHG_Logger') ) {
                HHG_Logger::log( 'Hunyuan async batch completed', array( 'batch_size' => count($batch) ) );
            }
        }
        }

        ksort( $translated_strings );
        return array_values( $translated_strings );
    }

    private function send_single_request( $source_language, $target_language, $text ) {
        $secret_id = $this->get_secret_id();
        $secret_key = $this->get_secret_key();
        
        if ( empty( $secret_id ) || empty( $secret_key ) ) {
            return new WP_Error( 'no_credentials', 'Tencent hunyuan API credentials can not be empty' );
        }

        $translation_endpoint = 'https://hunyuan.tencentcloudapi.com/';
        $request_body = array(
            'Model' => $this->get_selected_model(),
            'Stream' => false,
            'Text' => $text,
            'Source' => $source_language,
            'Target' => $target_language
        );
        
        $payload = wp_json_encode( $request_body );
        $headers = $this->generate_auth_headers( $payload );
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        $headers['Host'] = 'hunyuan.tencentcloudapi.com';
        
        $response = wp_remote_post( $translation_endpoint, array(
            'headers' => $headers,
            'body' => $payload,
            'timeout' => $this->config['timeout'],
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking' => true,
            'sslverify' => true
        ));

        return $response;
    }

    private function get_language_code_mapping( $language_code ) {
        $mapping = array(
            'zh' => 'zh',
            'zh_CN' => 'zh',
            'zh_TW' => 'zh-TR',
            'yue' => 'yue',
            'en' => 'en',
            'en_US' => 'en',
            'fr' => 'fr',
            'pt' => 'pt',
            'es' => 'es',
            'ja' => 'ja',
            'tr' => 'tr',
            'ru' => 'ru',
            'ru_RU' => 'ru',
            'ar' => 'ar',
            'ko' => 'ko',
            'th' => 'th',
            'it' => 'it',
            'de' => 'de',
            'vi' => 'vi',
            'ms' => 'ms',
            'id' => 'id'
        );
        
        return isset( $mapping[$language_code] ) ? $mapping[$language_code] : $language_code;
    }

    public function test_request() {
        $test_strings = array( 'Hello world' );
        $response = $this->send_request( 'en', 'zh', $test_strings );
        
        if ( is_wp_error( $response ) ) {
            return array(
                'error' => true,
                'message' => $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        
        if ( $response_code !== 200 ) {
            return array(
                'error' => true,
                'message' => 'API request failed with response code: ' . $response_code
            );
        }
        
        $response_data = json_decode( $response_body, true );
        
        if ( isset( $response_data['Response']['Error'] ) ) {
            $err = $response_data['Response']['Error'];
            return array(
                'error' => true,
                'message' => ( isset($err['Message']) ? $err['Message'] : 'API error' )
            );
        }
        if ( !isset( $response_data['Response']['Choices'][0]['Message'] ) && !isset( $response_data['Response']['TargetText'] ) ) {
            return array(
                'error' => true,
                'message' => 'API response format error'
            );
        }
        
        return array(
            'error' => false,
            'message' => 'API Connection Successful'
        );
    }

    public function check_api_key_validity() {
        $secret_id = $this->get_secret_id();
        $secret_key = $this->get_secret_key();
        
        if ( empty( $secret_id ) || empty( $secret_key ) ) {
            return array(
                'error' => true,
                'message' => 'Please enter Tencent hunyuan API credentials'
            );
        }
        
        return $this->test_request();
    }

    public function get_supported_languages() {
        return array(
            'zh', 'zh-TR', 'yue', 'en', 'fr', 'pt', 'es', 'ja', 'tr', 'ru', 'ar', 'ko', 'th', 'it', 'de', 'vi', 'ms', 'id'
        );
    }

    public function check_languages_availability( $languages, $force_recheck = false ) {
        if ( !method_exists( $this, 'get_supported_languages' ) || !method_exists( $this, 'get_engine_specific_language_codes' ) ) {
            return true;
        }
        
        $force_recheck = ( current_user_can('manage_options') &&
            !empty( $_GET['trp_recheck_supported_languages']) && $_GET['trp_recheck_supported_languages'] === '1' &&
            isset( $_GET['trp_recheck_supported_languages_nonce'] ) &&
            wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['trp_recheck_supported_languages_nonce'] ) ), 'trp_recheck_supported_languages' ) ) ? true : $force_recheck;
        
        $data = get_option('trp_db_stored_data', array() );
        if ( isset( $_GET['trp_recheck_supported_languages'] ) ) {
            unset($_GET['trp_recheck_supported_languages'] );
        }

        if ( empty( $data['trp_mt_supported_languages'][$this->settings['trp_machine_translation_settings']['translation-engine']]['last-checked'] ) || $force_recheck ) {
            if ( empty( $data['trp_mt_supported_languages'] ) ) {
                $data['trp_mt_supported_languages'] = array();
            }
            if ( empty( $data['trp_mt_supported_languages'][ $this->settings['trp_machine_translation_settings']['translation-engine'] ] ) ) {
                $data['trp_mt_supported_languages'][ $this->settings['trp_machine_translation_settings']['translation-engine'] ] = array( 'languages' => array() );
            }

            $data['trp_mt_supported_languages'][ $this->settings['trp_machine_translation_settings']['translation-engine'] ]['languages'] = $this->get_supported_languages();
            
            if ( method_exists( $this, 'check_formality' ) ) {
                $data['trp_mt_supported_languages'][ $this->settings['trp_machine_translation_settings']['translation-engine'] ]['formality-supported-languages'] = $this->check_formality();
            } else {
                $data['trp_mt_supported_languages'][ $this->settings['trp_machine_translation_settings']['translation-engine'] ]['formality-supported-languages'] = array();
            }
            
            $data['trp_mt_supported_languages'][$this->settings['trp_machine_translation_settings']['translation-engine']]['last-checked'] = gmdate("Y-m-d H:i:s" );
            update_option('trp_db_stored_data', $data );
        }

        $languages_iso_to_check = $this->get_engine_specific_language_codes( $languages );

        $all_are_available = !array_diff($languages_iso_to_check, $data['trp_mt_supported_languages'][$this->settings['trp_machine_translation_settings']['translation-engine']]['languages']);

        return apply_filters('trp_mt_available_supported_languages', $all_are_available, $languages, $this->settings );
    }

    public function check_formality() {
        return array();
    }

    public function get_engine_specific_language_codes( $languages ) {
        $mapped_languages = array();
        
        foreach ( $languages as $language ) {
            $mapped_languages[] = $this->get_language_code_mapping( $language );
        }
        
        return $mapped_languages;
    }
} 
