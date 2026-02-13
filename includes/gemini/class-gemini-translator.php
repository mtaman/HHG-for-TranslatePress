<?php
/**
 * Google Gemini
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_HHGFOTR_Gemini_Machine_Translator extends TRP_Machine_Translator {

    private $api_endpoint;
    private $config;

    public function __construct( $settings ) {
        parent::__construct( $settings );
        
        $selected_model = $this->get_selected_model();
        $this->api_endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . $selected_model . ':generateContent';
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


        if ( strpos( $model, '2.5' ) !== false ) {
            $base_config['chunk_size'] = 24;
            $base_config['timeout'] = 60;
            $base_config['max_tokens'] = 16384;
        }
        if ( strpos( $model, '2.5-flash-lite' ) !== false ) {
            $base_config['temperature'] = 0.004;
            $base_config['chunk_size'] = 30;
        } elseif ( strpos( $model, '2.5-flash' ) !== false ) {
            $base_config['temperature'] = 0.005;
            $base_config['chunk_size'] = 26;
        } elseif ( strpos( $model, '3-pro' ) !== false ) {
            $base_config['temperature'] = 0.02;
            $base_config['chunk_size'] = 14;
        }

        return $base_config;
    }

    public function send_request( $source_language, $target_language, $strings_array ) {
        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'Google Gemini API The key cannot be empty' );
        }

        $prompt = $this->build_translation_prompt( $source_language, $target_language, $strings_array );
        
        $request_body = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array(
                            'text' => $prompt
                        )
                    )
                )
            ),
            'generationConfig' => array(
                'temperature' => $this->config['temperature'],
                'maxOutputTokens' => $this->config['max_tokens'],
                'topP' => $this->config['top_p'],
                'candidateCount' => 1,
                'stopSequences' => array(),
                'responseMimeType' => 'text/plain'
            ),
            'safetySettings' => array(
                array(
                    'category' => 'HARM_CATEGORY_HARASSMENT',
                    'threshold' => $this->config['safety_threshold']
                ),
                array(
                    'category' => 'HARM_CATEGORY_HATE_SPEECH',
                    'threshold' => $this->config['safety_threshold']
                ),
                array(
                    'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                    'threshold' => $this->config['safety_threshold']
                ),
                array(
                    'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                    'threshold' => $this->config['safety_threshold']
                )
            )
        );

        $url = $this->api_endpoint . '?key=' . $api_key;
        $referer = $this->get_referer();

        $response = wp_remote_post( $url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
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

    private function build_translation_prompt( $source_language, $target_language, $strings_array ) {
        $prompt = "Act as a translation assistant, translating {$source_language} to {$target_language}. Must comply:\n";
        $prompt .= "100% retain all original HTML format.\n";  
        $prompt .= "Do not translate URL links, only translate text.\n";
        $prompt .= "Return translated text, one per line.\n";
        $prompt .= "100% Keep the same structure as the original.\n";
        $prompt .= "You only need to translate the text, don't prompt.\n";
        $prompt .= "The text to be translated is as follows:\n\n";
        
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
            
            if ( preg_match( '/^\d+\.\s*(.+)$/', $line, $matches ) && !empty(trim($matches[1])) ) {
                $translation_lines[] = trim($matches[1]);
            }
        }
        
        if ( count($translation_lines) < count($original_strings) ) {
            $translation_lines = array();
            foreach ( $lines as $line ) {
                $line = trim( $line );
                
                if ( empty( $line ) || 
                     preg_match('/^(translate|translation|rules?|from|to|here|output):/i', $line) ||
                     preg_match('/^(translat|rules|preserve|don\'t)/i', $line) ||
                     strlen($line) < 2 ) {
                    continue;
                }
                
                $translation_lines[] = $line;
            }
        }
        
        $original_count = count( $original_strings );
        for ( $i = 0; $i < $original_count; $i++ ) {
            if ( isset( $translation_lines[$i] ) && !empty( trim($translation_lines[$i]) ) ) {
                $cleaned_translation = trim($translation_lines[$i]);

                if ( strlen($cleaned_translation) > 1 && !preg_match('/^\d+\.?$/', $cleaned_translation) ) {
                    $translations[] = $cleaned_translation;
                } else {
                    $translations[] = $original_strings[$i];
                }
            } else {
                $translations[] = $original_strings[$i];
            }
        }
        
        return $translations;
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
                
                if ( isset( $response_body['candidates'][0]['content']['parts'][0]['text'] ) ) {
                    $this->machine_translator_logger->count_towards_quota( $new_strings_chunk );
                    
                    $translation_text = $response_body['candidates'][0]['content']['parts'][0]['text'];
                    $chunk_values = array_values( $new_strings_chunk );
                    $chunk_translations = $this->parse_translation_response( $translation_text, $chunk_values );
                    
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
        return $this->send_request( 'en', 'zh', array( 'Hello, world!' ) );
    }

    public function get_api_key() {
        if ( isset( $this->settings['trp_machine_translation_settings']['hhgfotr-gemini-key'] ) ) {
            return $this->settings['trp_machine_translation_settings']['hhgfotr-gemini-key'];
        }
        return isset( $this->settings['trp_machine_translation_settings']['hhg-gemini-key'] ) ? $this->settings['trp_machine_translation_settings']['hhg-gemini-key'] : false;
    }

    public function get_selected_model() {
        $selected_model = isset( $this->settings['trp_machine_translation_settings']['hhgfotr-gemini-model'] ) ? $this->settings['trp_machine_translation_settings']['hhgfotr-gemini-model'] : ( isset( $this->settings['trp_machine_translation_settings']['hhg-gemini-model'] ) ? $this->settings['trp_machine_translation_settings']['hhg-gemini-model'] : 'gemini-2.5-flash' );
        $available_models = $this->get_available_models();
        if ( !array_key_exists( $selected_model, $available_models ) ) {
            $selected_model = 'gemini-2.5-flash';
        }
        return $selected_model;
    }

    public function get_available_models() {
        return array(
            'gemini-2.5-flash' => 'Gemini 2.5 Flash',
            'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash-Lite',
            'gemini-3-pro-preview' => 'Gemini 3 Pro (Preview)'
        );
    }

    public function get_supported_languages() {
        $supported_languages = array(
            'en', 'zh', 'zh-CN', 'zh-TW', 'ja', 'ko', 'fr', 'de', 'es', 'it', 'pt', 'ru',
            'ar', 'hi', 'th', 'vi', 'id', 'ms', 'tl', 'tr', 'pl', 'nl', 'sv', 'da',
            'no', 'fi', 'hu', 'cs', 'sk', 'ro', 'bg', 'hr', 'sr', 'sl', 'et', 'lv',
            'lt', 'uk', 'be', 'ka', 'am', 'sw', 'zu', 'af', 'sq', 'eu', 'ca', 'gl',
            'is', 'ga', 'mt', 'cy', 'bn', 'gu', 'kn', 'ml', 'mr', 'ne', 'pa', 'si',
            'ta', 'te', 'ur', 'my', 'km', 'lo', 'hy', 'az', 'kk', 'ky', 'mn', 'uz',
            'tk', 'fa', 'ps', 'sd', 'yi', 'he', 'jv', 'su', 'ceb', 'haw', 'mg', 'sm'
        );

        $supported_languages = apply_filters( 'trp_add_hhgfotr_gemini_supported_languages_to_the_array', $supported_languages );
        $supported_languages = apply_filters( 'trp_add_hhg_gemini_supported_languages_to_the_array', $supported_languages );
        return $supported_languages;
    }

    public function get_engine_specific_language_codes( $languages ) {
        $gemini_language_codes = array();
        $iso_codes = $this->trp_languages->get_iso_codes( $languages );
        
        $gemini_language_mapping = array(
            'zh_HK' => 'zh-TW', 'zh_TW' => 'zh-TW', 'zh_CN' => 'zh-CN', 'zh_SG' => 'zh-CN',
            'en_US' => 'en', 'en_GB' => 'en', 'en_CA' => 'en', 'en_AU' => 'en',
            'pt_BR' => 'pt', 'pt_PT' => 'pt', 'es_ES' => 'es', 'es_MX' => 'es',
            'fr_FR' => 'fr', 'fr_CA' => 'fr', 'de_DE' => 'de', 'de_AT' => 'de',
            'nb_NO' => 'no', 'nn_NO' => 'no', 'de_DE_formal' => 'de'
        );
        
        foreach( $languages as $language ) {
            if( isset( $gemini_language_mapping[$language] ) ) {
                $gemini_language_codes[$language] = $gemini_language_mapping[$language];
            } else {
                $gemini_language_codes[$language] = isset( $iso_codes[$language] ) ? $iso_codes[$language] : $language;
            }
        }
        
        return $gemini_language_codes;
    }

    public function check_formality() {
        return array();
    }

    public function check_api_key_validity() {
        $machine_translator = $this;
        $translation_engine = $this->settings['trp_machine_translation_settings']['translation-engine'];
        $api_key = $machine_translator->get_api_key();

        $is_error = false;
        $return_message = '';

        if ( 'hhgfotr_gemini' === $translation_engine && 
             isset($this->settings['trp_machine_translation_settings']['machine-translation']) &&
             $this->settings['trp_machine_translation_settings']['machine-translation'] === 'yes') {

            if ( isset( $this->correct_api_key ) && $this->correct_api_key != null ) {
                return $this->correct_api_key;
            }

            if ( empty( $api_key ) ) {
                $is_error = true;
                $return_message = 'Please enter your Google Gemini API key.';
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
                        $return_message = 'The API key is invalid or the API request failed. Error Code:' . $code . ( $msg ? ('; ' . $msg) : '' );
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
            if ( isset( $strings_array[$i] ) && is_string( $strings_array[$i] ) ) {
                $total_length += strlen( $strings_array[$i] );
            }
        }
        
        $avg_length = $sample_size > 0 ? $total_length / $sample_size : 100;
        
        if ( $avg_length < 50 ) {
            $optimal_size = min( $base_chunk_size * 2, 16 );
        } elseif ( $avg_length < 200 ) {
            $optimal_size = $base_chunk_size;
        } else {
            $optimal_size = max( $base_chunk_size / 2, 4 );
        }
        
        return max( 1, min( $optimal_size, $total_strings ) );
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

    public function get_referer() {
        if ( isset( $_SERVER['HTTP_HOST'] ) ) {
            $protocol = is_ssl() ? 'https://' : 'http://';
            return $protocol . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
        }
        return home_url();
    }
} 
