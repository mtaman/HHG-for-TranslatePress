<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_HHGFOTR_Zhipu_Machine_Translator extends TRP_Machine_Translator {

    private $api_endpoint;
    private $agent_id;

    public function __construct( $settings ) {
        parent::__construct( $settings );
        
        $this->api_endpoint = 'https://open.bigmodel.cn/api/v1/agents';
        $this->agent_id = 'general_translation';
    }


    public function translate_array( $new_strings, $target_language_code, $source_language_code = null ) {
        if ( $source_language_code == null ) {
            $source_language_code = $this->settings['default-language'];
        }
        
        if( empty( $new_strings ) || !$this->verify_request_parameters( $target_language_code, $source_language_code ) )
            return array();

        $source_language = $this->machine_translation_codes[$source_language_code];
        $target_language = $this->machine_translation_codes[$target_language_code];

        $chunk_size = apply_filters( 'hhgfotr_zhipu_chunk_size', 50 ); 
        $chunks = array_chunk( $new_strings, $chunk_size, true );
        
        $requests = array();
        $api_key = $this->get_api_key();
        
        if ( empty( $api_key ) ) {
            return array();
        }

        foreach ( $chunks as $chunk_index => $chunk ) {

            $cache_key = 'zhipu_' . md5( implode("\n", array_values($chunk)) . '|' . $this->map_lang_code($target_language_code) );
            $cached = class_exists('HHG_Cache') ? HHG_Cache::get( $cache_key ) : false;
            if ( $cached ) {
                $requests[$chunk_index] = null;
                $results[$chunk_index] = array('response_code' => 200, 'body' => wp_json_encode($cached), 'error' => '');
                continue;
            }
            $prompt = $this->build_translation_prompt( $source_language, $target_language, $chunk );
            
            $body = array(
                'agent_id' => $this->agent_id,
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => array(
                            array(
                                'type' => 'text',
                                'text' => $prompt
                            )
                        )
                    )
                ),
                'custom_variables' => array(
                    'source_lang' => 'auto',
                    'target_lang' => $this->map_lang_code($target_language_code),
                    'strategy' => $this->get_selected_strategy()
                ),
                'stream' => false
            );

            $requests[$chunk_index] = array(
                'url' => $this->api_endpoint,
                'method' => 'POST',
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key
                ),
                'body' => $body,
                'timeout' => apply_filters( 'hhgfotr_request_timeout', 60 )
            );
        }

        $api_client = HHG_API_Client::get_instance();
        if ( class_exists( 'HHG_Logger' ) ) {
            HHG_Logger::log( 'Dispatching Zhipu translation requests', array( 'chunks' => count( $requests ), 'target_lang' => $this->map_lang_code($target_language_code) ) );
        }
        if ( isset($results) && !empty($results) ) {

        } else {
            $results = $api_client->request_async( $requests );
        }

        $translated_strings = array();
        
        foreach ( $chunks as $chunk_index => $chunk ) {
            $result = isset($results[$chunk_index]) ? $results[$chunk_index] : null;
            $chunk_translated = array();

            if ( $result && $result['response_code'] == 200 ) {
                $response_data = json_decode( $result['body'], true );
                $translation_text = '';

                $choice = isset($response_data['choices'][0]) ? $response_data['choices'][0] : null;
                
                if ( $choice ) {
                    if ( isset($choice['messages'][0]['content']) ) {
                        $content = $choice['messages'][0]['content'];
                        if ( is_array($content) && isset($content['text']) ) {
                            $translation_text = $content['text'];
                        } elseif ( is_string($content) ) {
                            $translation_text = $content;
                        }
                    } 

                    elseif ( isset($choice['message']['content']) ) {
                        $content = $choice['message']['content'];
                        if ( is_array($content) && isset($content['text']) ) {
                            $translation_text = $content['text'];
                        } elseif ( is_string($content) ) {
                            $translation_text = $content;
                        }
                    }
                }

                if ( !empty($translation_text) ) {
                    $chunk_translated = $this->parse_translation_response( $translation_text, array_values($chunk) );
                    if ( isset($this->machine_translator_logger) ) {
                        $this->machine_translator_logger->count_towards_quota( $chunk );
                        $this->machine_translator_logger->log( array(
                            'strings' => serialize( $chunk ),
                            'response' => serialize( $result ),
                            'lang_source' => $source_language,
                            'lang_target' => $target_language
                        ) );
                    }

                    if ( count($chunk_translated) === count($chunk) && !in_array('', $chunk_translated, true) ) {
                        $cache_key = 'zhipu_' . md5( implode("\n", array_values($chunk)) . '|' . $this->map_lang_code($target_language_code) );
                        if ( class_exists('HHG_Cache') ) {
                            HHG_Cache::set( $cache_key, array('text' => $translation_text), 1800 );
                        }
                    }
                }
            } else {
                if ( class_exists( 'HHG_Logger' ) ) {
                    HHG_Logger::log( 'Zhipu API error', array( 'code' => $result ? $result['response_code'] : 'N/A', 'error' => $result ? $result['error'] : 'no_result' ) );
                }
                if ( isset($this->machine_translator_logger) ) {
                    $this->machine_translator_logger->log( array(
                        'strings' => serialize( $chunk ),
                        'response' => serialize( $result ),
                        'lang_source' => $source_language,
                        'lang_target' => $target_language
                    ) );
                }
            }

            $i = 0;
            foreach ( $chunk as $key => $original_string ) {
                if ( isset($chunk_translated[$i]) && !empty($chunk_translated[$i]) ) {
                    $translated_strings[$key] = $chunk_translated[$i];
                } else {
                    $translated_strings[$key] = $original_string;
                }
                $i++;
            }

            $missing_keys = array();
            $i = 0;
            foreach ( $chunk as $key => $original_string ) {
                if ( $translated_strings[$key] === $original_string ) {
                    $missing_keys[$key] = $original_string;
                }
                $i++;
            }

            if ( !empty($missing_keys) ) {
                $retry_prompt = $this->build_translation_prompt( $source_language, $target_language, $missing_keys );
                $retry_body = array(
                    'agent_id' => $this->agent_id,
                    'messages' => array(
                        array(
                            'role' => 'user',
                            'content' => array(
                                array(
                                    'type' => 'text',
                                    'text' => $retry_prompt
                                )
                            )
                        )
                    ),
                    'custom_variables' => array(
                        'source_lang' => 'auto',
                        'target_lang' => $this->map_lang_code($target_language_code),
                        'strategy' => $this->get_selected_strategy()
                    ),
                    'stream' => false
                );
                $retry_requests = array(
                    array(
                        'url' => $this->api_endpoint,
                        'method' => 'POST',
                        'headers' => array(
                            'Content-Type' => 'application/json',
                            'Accept' => 'application/json',
                            'Authorization' => 'Bearer ' . $api_key
                        ),
                        'body' => $retry_body,
                        'timeout' => apply_filters( 'hhgfotr_request_timeout', 60 )
                    )
                );
                $retry_results = $api_client->request_async( $retry_requests );
                if ( isset($retry_results[0]) && $retry_results[0]['response_code'] == 200 ) {
                    $retry_data = json_decode( $retry_results[0]['body'], true );
                    $retry_text = '';
                    $choice_r = isset($retry_data['choices'][0]) ? $retry_data['choices'][0] : null;
                    if ( $choice_r ) {
                        if ( isset($choice_r['messages'][0]['content']) ) {
                            $content_r = $choice_r['messages'][0]['content'];
                            if ( is_array($content_r) && isset($content_r['text']) ) {
                                $retry_text = $content_r['text'];
                            } elseif ( is_string($content_r) ) {
                                $retry_text = $content_r;
                            }
                        } elseif ( isset($choice_r['message']['content']) ) {
                            $content_r = $choice_r['message']['content'];
                            if ( is_array($content_r) && isset($content_r['text']) ) {
                                $retry_text = $content_r['text'];
                            } elseif ( is_string($content_r) ) {
                                $retry_text = $content_r;
                            }
                        }
                    }
                    if ( !empty($retry_text) ) {
                        $reparsed = $this->parse_translation_response( $retry_text, array_values($missing_keys) );
                        if ( isset($this->machine_translator_logger) ) {
                            $this->machine_translator_logger->count_towards_quota( $missing_keys );
                            $this->machine_translator_logger->log( array(
                                'strings' => serialize( $missing_keys ),
                                'response' => serialize( $retry_results[0] ),
                                'lang_source' => $source_language,
                                'lang_target' => $target_language
                            ) );
                        }
                        $j = 0;
                        foreach ( $missing_keys as $mkey => $morig ) {
                            if ( isset($reparsed[$j]) && !empty($reparsed[$j]) ) {
                                $translated_strings[$mkey] = $reparsed[$j];
                            }
                            $j++;
                        }
                    }
                }
            }
        }

        return $translated_strings;
    }


    private function map_lang_code( $code ) {

        if ( $code === 'zh_CN' || $code === 'zh' ) {
            return 'zh-CN';
        }
        if ( $code === 'zh_TW' ) {
            return 'zh-TW';
        }
        $code = str_replace( '_', '-', $code );
        if ( strpos( $code, 'ru' ) === 0 ) { return 'ru'; }
        return $code;
    }

    private function map_source_lang_code( $code ) { return 'auto'; }

    private function map_target_lang_code( $code ) { return $this->map_lang_code( $code ); }

    private function build_translation_prompt( $source_language, $target_language, $strings_array ) {

        $prompt = "";
        
        $counter = 1;
        foreach ( $strings_array as $string ) {
            $prompt .= $counter . ". " . $string . "\n";
            $counter++;
        }
        
        return $prompt;
    }

    private function parse_translation_response( $response_text, $original_strings ) {
        $assembled = array();
        $lines = preg_split('/\r?\n/', trim($response_text));
        $current = '';
        $started = false;

        foreach ( $lines as $line ) {
            $line = trim($line);
            if ( $line === '' ) { continue; }
            if ( preg_match('/^\s*(\d+)[\.]\)\s*(.*)$/', $line, $m) ) {
                if ( $started && $current !== '' ) { $assembled[] = $current; }
                $current = trim($m[2]);
                $started = true;
                continue;
            }
            if ( preg_match('/^\s*(\d+)[\.|\)]\s*(.*)$/', $line, $m) ) {
                if ( $started && $current !== '' ) { $assembled[] = $current; }
                $current = trim($m[2]);
                $started = true;
            } else {
                if ( $started ) {
                    $current = $current === '' ? $line : ($current . " " . $line);
                }
            }
        }
        if ( $started && $current !== '' ) { $assembled[] = $current; }

        if ( count($assembled) < count($original_strings) ) {
            $fallback = array();
            foreach ( $lines as $line ) {
                $line = trim($line);
                if ( $line === '' ) { continue; }
                if ( preg_match('/^(Here is|Sure|Translate)/i', $line) ) { continue; }
                $fallback[] = $line;
            }
            if ( count($fallback) >= count($original_strings) ) {
                $assembled = $fallback;
            }
        }

        $out = array();
        $count = count($original_strings);
        for ( $i = 0; $i < $count; $i++ ) {
            $out[] = isset($assembled[$i]) ? $assembled[$i] : $original_strings[$i];
        }
        return $out;
    }

    public function test_request() {
        $api_client = HHG_API_Client::get_instance();
        $body = array(
            'agent_id' => $this->agent_id,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => array(
                        array('type' => 'text', 'text' => 'Hello')
                    )
                )
            ),
            'custom_variables' => array(
                'source_lang' => 'auto',
                'target_lang' => 'zh-CN',
                'strategy' => $this->get_selected_strategy()
            )
        );
        
        $requests = array(
            array(
                'url' => $this->api_endpoint,
                'method' => 'POST',
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->get_api_key()
                ),
                'body' => $body
            )
        );

        $results = $api_client->request_async($requests);
        
        if ( !empty($results[0]['error']) ) {
            return new WP_Error( 'api_error', $results[0]['error'] );
        }
        
        $response = array(
            'body' => $results[0]['body'],
            'response' => array('code' => $results[0]['response_code'])
        );
        return $response;
    }

    public function get_api_key() {
        if ( isset( $this->settings['trp_machine_translation_settings']['hhgfotr-zhipu-key'] ) ) {
            return $this->settings['trp_machine_translation_settings']['hhgfotr-zhipu-key'];
        }
        return isset( $this->settings['trp_machine_translation_settings']['hhg-zhipu-key'] ) ? $this->settings['trp_machine_translation_settings']['hhg-zhipu-key'] : false;
    }

    public function get_selected_model() { return 'general_translation'; }

    private function get_selected_agent_id() { return 'general_translation'; }

    public function get_selected_strategy() {
        if ( isset( $this->settings['hhgfotr-zhipu-model'] ) && !empty( $this->settings['hhgfotr-zhipu-model'] ) ) {
            return $this->settings['hhgfotr-zhipu-model'];
        }
        
        if ( isset( $this->settings['trp_machine_translation_settings']['hhgfotr-zhipu-model'] ) && !empty( $this->settings['trp_machine_translation_settings']['hhgfotr-zhipu-model'] ) ) {
            return $this->settings['trp_machine_translation_settings']['hhgfotr-zhipu-model'];
        }

        return 'general';
    }

    public function get_available_models() { return array('general_translation' => 'General Translation Agent'); }

    public function get_supported_languages() { 

        return array(); 
    } 

    public function check_languages_availability( $languages, $force_recheck = false ) {

        return true;
    }

    public function get_engine_specific_language_codes( $languages ) { 
        $mapped = array();
        foreach ( $languages as $code ) {
            $mapped[] = $this->map_lang_code($code);
        }
        return $mapped; 
    }

    public function check_formality() { return false; }
    public function check_api_key_validity() {
        $response = $this->test_request();
        if ( is_wp_error( $response ) ) {
            return array('error' => true, 'message' => $response->get_error_message());
        }
        if ( $response['response']['code'] != 200 ) {
            return array('error' => true, 'message' => 'API Error: ' . $response['response']['code']);
        }

        $response_body = $response['body'];
        $response_data = json_decode( $response_body, true );
        
        if ( ! $response_data || ! isset( $response_data['choices'][0] ) ) {
             return array(
                'error' => true,
                'message' => 'API response format error: No choices found'
            );
        }

        $choice = $response_data['choices'][0];
        $has_content = false;

        if ( isset($choice['messages'][0]['content']) || isset($choice['message']['content']) ) {
            $has_content = true;
        }

        if ( ! $has_content ) {
            return array(
                'error' => true,
                'message' => 'API response format error: No content found'
            );
        }

        return array(
            'error' => false,
            'message' => 'Valid'
        );
    }
}
