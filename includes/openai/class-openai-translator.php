<?php
/**
 * OpenAI Class
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_HHGFOTR_OpenAI_Machine_Translator extends TRP_Machine_Translator {

    private $api_endpoint;
    private $config;

    public function __construct( $settings ) {
        parent::__construct( $settings );
        
        $selected_model = $this->get_selected_model();
        $this->api_endpoint = $this->get_api_endpoint();
        $this->config = $this->get_optimized_config( $selected_model );
    }

    private function get_optimized_config( $model ) {
        $base_config = array(
            'chunk_size' => 15,
            'timeout' => 45,
            'max_tokens' => 8192,
            'temperature' => 0.01,
            'top_p' => 0.95
        );


        if ( strpos( $model, 'gpt-4o' ) !== false ) {
            $base_config['chunk_size'] = 20;
            $base_config['timeout'] = 60;
            $base_config['max_tokens'] = 16384;
        }

        if ( strpos( $model, 'mini' ) !== false ) {
            $base_config['temperature'] = 0.004;
            $base_config['chunk_size'] = 30;
        }

        if ( strpos( $model, 'gpt-4' ) !== false && strpos( $model, 'mini' ) === false ) {
            $base_config['temperature'] = 0.02;
            $base_config['chunk_size'] = 15;
        }

        return $base_config;
    }

    public function send_request( $source_language, $target_language, $strings_array ) {
        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'OpenAI API key cannot be null' );
        }

        $prompt = $this->build_translation_prompt( $source_language, $target_language, $strings_array );
        
        $request_body = array(
            'model' => $this->get_selected_model(),
            'messages' => array(
                array(
                    'role' => 'system',
                    'content' => 'Your a professional translation assistant specializing in translating text from ' . $source_language . ' Translate into ' . $target_language . '. Please strictly follow the following rules: 1. 100% retain all original HTML formatting; 2. do not translate the URL links, only the text content; 3. return the translated text, one per line; 4. 100% maintain the same structure as the original text; 5. you only need to translate the text, do not add any explanations or hints.'
                ),
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
            'max_tokens' => $this->config['max_tokens'],
            'temperature' => $this->config['temperature'],
            'top_p' => $this->config['top_p'],
            'stream' => false
        );

        $referer = $this->get_referer();

        $response = wp_remote_post( $this->api_endpoint, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
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
        $prompt = "Please translate the following {$source_language} Text translation into {$target_language}：\n\n";
        
        $counter = 1;
        foreach ( $strings_array as $string ) {
            $prompt .= $counter . ". " . $string . "\n";
            $counter++;
        }
        
        $prompt .= "\nPlease return the translations in strict numbered order, one translation per line.。";
        
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
        $api_client = class_exists('HHG_API_Client') ? HHG_API_Client::get_instance() : null;
        $requests = array();
        $chunks_index_map = array();

        foreach( $new_strings_chunks as $idx => $new_strings_chunk ) {
            $prompt = $this->build_translation_prompt( $source_language, $target_language, $new_strings_chunk );
            $request_body = array(
                'model' => $this->get_selected_model(),
                'messages' => array(
                    array(
                        'role' => 'system',
                        'content' => 'You are a professional translation assistant. Translate strictly from ' . $source_language . ' to ' . $target_language . '. Requirements: 1) retain all original HTML; 2) do not translate URLs; 3) return one translated line per input line; 4) keep structure; 5) no explanations.'
                    ),
                    array(
                        'role' => 'user',
                        'content' => $prompt
                    )
                ),
                'max_tokens' => $this->config['max_tokens'],
                'temperature' => $this->config['temperature'],
                'top_p' => $this->config['top_p'],
                'stream' => false
            );
            if ( $api_client ) {
                $requests[] = array(
                    'url' => $this->get_api_endpoint(),
                    'method' => 'POST',
                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'Authorization' => 'Bearer ' . $this->get_api_key(),
                        'User-Agent' => 'TranslatePress/1.0'
                    ),
                    'body' => wp_json_encode( $request_body ),
                    'timeout' => $this->config['timeout']
                );
                $chunks_index_map[] = $idx;
            } else {
                $response = $this->send_request( $source_language, $target_language, $new_strings_chunk );
                $this->machine_translator_logger->log(array(
                    'strings'   => serialize( $new_strings_chunk),
                    'response'  => serialize( $response ),
                    'lang_source'  => $source_language,
                    'lang_target'  => $target_language,
                ));
                if ( is_array( $response ) && ! is_wp_error( $response ) && isset( $response['response'] ) && isset( $response['response']['code']) && $response['response']['code'] == 200 ) {
                    $response_body = json_decode( $response['body'], true );
                    if ( isset( $response_body['choices'][0]['message']['content'] ) ) {
                        $this->machine_translator_logger->count_towards_quota( $new_strings_chunk );
                        $response_text = $response_body['choices'][0]['message']['content'];
                        $chunk_values = array_values( $new_strings_chunk );
                        $chunk_translations = $this->parse_translation_response( $response_text, $chunk_values );
                        $i = 0;
                        foreach ( $new_strings_chunk as $key => $old_string ) {
                            $translated_strings[ $key ] = ( isset( $chunk_translations[$i] ) && !empty( $chunk_translations[$i] ) ) ? $chunk_translations[$i] : $old_string;
                            $i++;
                        }
                    } else {
                        foreach ( $new_strings_chunk as $key => $old_string ) {
                            $translated_strings[ $key ] = $old_string;
                        }
                    }
                } else {
                    foreach ( $new_strings_chunk as $key => $old_string ) {
                        $translated_strings[ $key ] = $old_string;
                    }
                }
            }
        }

        if ( $api_client && !empty( $requests ) ) {
            if ( class_exists( 'HHG_Logger' ) ) {
                HHG_Logger::log( 'Dispatching OpenAI translation requests', array( 'chunks' => count( $requests ), 'target_lang' => $target_language ) );
            }
            $results = $api_client->request_async( $requests );
            foreach ( $results as $ridx => $result ) {
                $chunk = $new_strings_chunks[ $chunks_index_map[$ridx] ];
                if ( is_array( $result ) && isset( $result['response_code'] ) && $result['response_code'] == 200 ) {
                    $response_body = json_decode( $result['body'], true );
                    if ( isset( $response_body['choices'][0]['message']['content'] ) ) {
                        $this->machine_translator_logger->count_towards_quota( $chunk );
                        $this->machine_translator_logger->log(array(
                            'strings'   => serialize( $chunk ),
                            'response'  => serialize( $result ),
                            'lang_source'  => $source_language,
                            'lang_target'  => $target_language,
                        ));
                        $response_text = $response_body['choices'][0]['message']['content'];
                        $chunk_values = array_values( $chunk );
                        $chunk_translations = $this->parse_translation_response( $response_text, $chunk_values );
                        $i = 0;
                        foreach ( $chunk as $key => $old_string ) {
                            $translated_strings[ $key ] = ( isset( $chunk_translations[$i] ) && !empty( $chunk_translations[$i] ) ) ? $chunk_translations[$i] : $old_string;
                            $i++;
                        }
                    } else {
                        foreach ( $chunk as $key => $old_string ) {
                            $translated_strings[ $key ] = $old_string;
                        }
                    }
                } else {
                    if ( class_exists( 'HHG_Logger' ) ) {
                        HHG_Logger::log( 'OpenAI API error', array( 'code' => isset($result['response_code']) ? $result['response_code'] : 'N/A', 'error' => isset($result['error']) ? $result['error'] : 'unknown' ) );
                    }
                    foreach ( $chunk as $key => $old_string ) {
                        $translated_strings[ $key ] = $old_string;
                    }
                }

                $missing_keys = array();
                foreach ( $chunk as $key => $original_string ) {
                    if ( $translated_strings[$key] === $original_string ) {
                        $missing_keys[$key] = $original_string;
                    }
                }
                if ( !empty( $missing_keys ) ) {
                    $retry_prompt = $this->build_translation_prompt( $source_language, $target_language, $missing_keys );
                    $retry_body = array(
                        'model' => $this->get_selected_model(),
                        'messages' => array(
                            array(
                                'role' => 'system',
                                'content' => 'You are a professional translation assistant. Translate strictly from ' . $source_language . ' to ' . $target_language . '. Requirements: 1) retain all original HTML; 2) do not translate URLs; 3) return one translated line per input line; 4) keep structure; 5) no explanations.'
                            ),
                            array(
                                'role' => 'user',
                                'content' => $retry_prompt
                            )
                        ),
                        'max_tokens' => $this->config['max_tokens'],
                        'temperature' => $this->config['temperature'],
                        'top_p' => $this->config['top_p'],
                        'stream' => false
                    );
                    $retry_req = array(
                        'url' => $this->get_api_endpoint(),
                        'method' => 'POST',
                        'headers' => array(
                            'Content-Type' => 'application/json',
                            'Accept' => 'application/json',
                            'Authorization' => 'Bearer ' . $this->get_api_key(),
                            'User-Agent' => 'TranslatePress/1.0'
                        ),
                        'body' => wp_json_encode( $retry_body ),
                        'timeout' => $this->config['timeout']
                    );
                    $retry_results = $api_client->request_async( array( $retry_req ) );
                    if ( isset( $retry_results[0] ) && $retry_results[0]['response_code'] == 200 ) {
                        $retry_data = json_decode( $retry_results[0]['body'], true );
                        if ( isset( $retry_data['choices'][0]['message']['content'] ) ) {
                            $this->machine_translator_logger->count_towards_quota( $missing_keys );
                            $this->machine_translator_logger->log(array(
                                'strings'   => serialize( $missing_keys ),
                                'response'  => serialize( $retry_results[0] ),
                                'lang_source'  => $source_language,
                                'lang_target'  => $target_language,
                            ));
                            $retry_text = $retry_data['choices'][0]['message']['content'];
                            $reparsed = $this->parse_translation_response( $retry_text, array_values( $missing_keys ) );
                            $j = 0;
                            foreach ( $missing_keys as $mkey => $morig ) {
                                if ( isset( $reparsed[$j] ) && !empty( $reparsed[$j] ) ) {
                                    $translated_strings[$mkey] = $reparsed[$j];
                                }
                                $j++;
                            }
                        }
                    }
                }
            }
        }

        return $translated_strings;
    }

    public function test_request() {
        $response = $this->send_request( 'English', 'Chinese', array( 'Hello Huaiyin Blog' ) );
        return $response;
    }

    public function get_api_key() {
        if ( isset( $this->settings['trp_machine_translation_settings']['hhgfotr-openai-key'] ) ) {
            return $this->settings['trp_machine_translation_settings']['hhgfotr-openai-key'];
        }
        return isset( $this->settings['trp_machine_translation_settings']['hhg-openai-key'] ) ? $this->settings['trp_machine_translation_settings']['hhg-openai-key'] : '';
    }

    public function get_selected_model() {
        $selected_model = isset( $this->settings['trp_machine_translation_settings']['hhgfotr-openai-model'] ) ? $this->settings['trp_machine_translation_settings']['hhgfotr-openai-model'] : ( isset( $this->settings['trp_machine_translation_settings']['hhg-openai-model'] ) ? $this->settings['trp_machine_translation_settings']['hhg-openai-model'] : 'gpt-4o-mini' );
        if ($selected_model === 'custom') {
            $custom_model = isset($this->settings['trp_machine_translation_settings']['hhgfotr-openai-custom-model']) ? trim($this->settings['trp_machine_translation_settings']['hhgfotr-openai-custom-model']) : ( isset($this->settings['trp_machine_translation_settings']['hhg-openai-custom-model']) ? trim($this->settings['trp_machine_translation_settings']['hhg-openai-custom-model']) : '' );
            if (!empty($custom_model)) {
                return $custom_model;
            }
        }
        
        return $selected_model;
    }

    public function get_api_endpoint() {
        $endpoint = isset( $this->settings['trp_machine_translation_settings']['hhgfotr-openai-endpoint'] ) ? $this->settings['trp_machine_translation_settings']['hhgfotr-openai-endpoint'] : ( isset( $this->settings['trp_machine_translation_settings']['hhg-openai-endpoint'] ) ? $this->settings['trp_machine_translation_settings']['hhg-openai-endpoint'] : 'https://api.openai.com/v1/chat/completions' );
        
        if ( empty( $endpoint ) || !filter_var( $endpoint, FILTER_VALIDATE_URL ) ) {
        $endpoint = 'https://api.openai.com/v1/chat/completions';
        }
        
        return $endpoint;
    }

    public function get_available_models() {
        return array(
            'gpt-4o-mini' => 'GPT-4o Mini (Fast)',
            'gpt-4o' => 'GPT-4o (Quality)',
            'gpt-4-turbo' => 'GPT-4 Turbo',
            'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
        );
    }

    public function get_supported_languages() {
        return array(
            'en' => 'English',
            'zh' => 'Chinese',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'ru' => 'Russian',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'ar' => 'Arabic',
            'hi' => 'Hindi',
            'th' => 'Thai',
            'vi' => 'Vietnamese',
            'nl' => 'Dutch',
            'pl' => 'Polish',
            'tr' => 'Turkish',
            'sv' => 'Swedish',
            'da' => 'Danish',
            'no' => 'Norwegian',
            'fi' => 'Finnish',
            'cs' => 'Czech',
            'hu' => 'Hungarian',
            'ro' => 'Romanian',
            'bg' => 'Bulgarian',
            'hr' => 'Croatian',
            'sk' => 'Slovak',
            'sl' => 'Slovenian',
            'et' => 'Estonian',
            'lv' => 'Latvian',
            'lt' => 'Lithuanian',
            'mt' => 'Maltese',
            'el' => 'Greek',
            'he' => 'Hebrew',
            'id' => 'Indonesian',
            'ms' => 'Malay',
            'tl' => 'Filipino',
            'bn' => 'Bengali',
            'ur' => 'Urdu',
            'fa' => 'Persian',
            'am' => 'Amharic',
            'sw' => 'Swahili',
            'zu' => 'Zulu',
            'af' => 'Afrikaans',
            'sq' => 'Albanian',
            'hy' => 'Armenian',
            'az' => 'Azerbaijani',
            'eu' => 'Basque',
            'be' => 'Belarusian',
            'bs' => 'Bosnian',
            'ca' => 'Catalan',
            'cy' => 'Welsh',
            'eo' => 'Esperanto',
            'fo' => 'Faroese',
            'gl' => 'Galician',
            'ka' => 'Georgian',
            'gu' => 'Gujarati',
            'ha' => 'Hausa',
            'is' => 'Icelandic',
            'ig' => 'Igbo',
            'ga' => 'Irish',
            'jv' => 'Javanese',
            'kn' => 'Kannada',
            'kk' => 'Kazakh',
            'km' => 'Khmer',
            'ky' => 'Kyrgyz',
            'lo' => 'Lao',
            'la' => 'Latin',
            'mk' => 'Macedonian',
            'mg' => 'Malagasy',
            'ml' => 'Malayalam',
            'mi' => 'Maori',
            'mr' => 'Marathi',
            'mn' => 'Mongolian',
            'ne' => 'Nepali',
            'or' => 'Odia',
            'ps' => 'Pashto',
            'pa' => 'Punjabi',
            'qu' => 'Quechua',
            'sm' => 'Samoan',
            'gd' => 'Scottish Gaelic',
            'sr' => 'Serbian',
            'st' => 'Sesotho',
            'sn' => 'Shona',
            'sd' => 'Sindhi',
            'si' => 'Sinhala',
            'so' => 'Somali',
            'su' => 'Sundanese',
            'tg' => 'Tajik',
            'ta' => 'Tamil',
            'te' => 'Telugu',
            'uk' => 'Ukrainian',
            'uz' => 'Uzbek',
            'xh' => 'Xhosa',
            'yi' => 'Yiddish',
            'yo' => 'Yoruba'
        );
    }

    public function get_engine_specific_language_codes( $languages ) {
        $languages_with_region = array();
        foreach ( $languages as $language ) {
            $languages_with_region[$language] = $language;
        }
        return $languages_with_region;
    }

    public function check_formality() {
        return false;
    }

    public function check_api_key_validity() {
        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            return array(
                'error' => true,
                'message' => 'OpenAI API The key cannot be empty'
            );
        }

        $response = $this->test_request();
        
        if ( is_wp_error( $response ) ) {
            return array(
                'error' => true,
                'message' => $response->get_error_message()
            );
        }

        if ( is_array( $response ) && isset( $response['response'] ) && isset( $response['response']['code'] ) ) {
            if ( $response['response']['code'] == 200 ) {
                return array(
                    'error' => false,
                    'message' => 'OpenAI API Key validity'
                );
            } else {
                $response_body = json_decode( $response['body'], true );
                $error_message = isset( $response_body['error']['message'] ) ? $response_body['error']['message'] : '未知错误';
                return array(
                    'error' => true,
                    'message' => 'OpenAI API error: ' . $error_message
                );
            }
        }

        return array(
            'error' => true,
            'message' => 'Unable to connect to OpenAI API'
        );
    }

    private function get_optimal_chunk_size( $strings_array ) {
        $total_length = 0;
        foreach ( $strings_array as $string ) {
            $total_length += strlen( $string );
        }
        
        $average_length = $total_length / count( $strings_array );
        
        if ( $average_length < 50 ) {
            return min( 30, $this->config['chunk_size'] );
        } elseif ( $average_length < 200 ) {
            return min( 20, $this->config['chunk_size'] );
        } else {
            return min( 10, $this->config['chunk_size'] );
        }
    }

    public function check_languages_availability( $languages, $force_recheck = false ) {
        $available_languages = $this->get_supported_languages();
        $available_languages = array_keys( $available_languages );
        
        $languages_availability = array();
        foreach ( $languages as $language ) {
            $languages_availability[$language] = in_array( $language, $available_languages );
        }
        
        return $languages_availability;
    }

    public function get_referer() {
        return is_ssl() ? home_url( '/', 'https' ) : home_url( '/', 'http' );
    }
} 
