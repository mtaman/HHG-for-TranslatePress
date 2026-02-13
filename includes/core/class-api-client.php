<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HHG_API_Client {
    private static $instance = null;
    private $max_retries = 3;
    private $retry_delay = 1000;

    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
            self::$instance->max_retries = apply_filters( 'hhgfotr_retry_max', self::$instance->max_retries );
            self::$instance->retry_delay = apply_filters( 'hhgfotr_retry_delay_ms', self::$instance->retry_delay );
        }
        return self::$instance;
    }

    public function request_async( $requests ) {
        $mh = curl_multi_init();
        $handles = array();
        $results = array();

        foreach ( $requests as $key => $request ) {
            $ch = curl_init();
            $this->setup_curl_handle( $ch, $request );
            curl_multi_add_handle( $mh, $ch );
            $handles[$key] = $ch;
        }

        $active = null;
        do {
            $mrc = curl_multi_exec( $mh, $active );
        } while ( $mrc == CURLM_CALL_MULTI_PERFORM );

        while ( $active && $mrc == CURLM_OK ) {
            if ( curl_multi_select( $mh ) == -1 ) {
                usleep( 100000 );
            } else {
                do {
                    $mrc = curl_multi_exec( $mh, $active );
                } while ( $mrc == CURLM_CALL_MULTI_PERFORM );
            }
        }

        foreach ( $handles as $key => $ch ) {
            $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            $body = curl_multi_getcontent( $ch );
            $error = curl_error( $ch );
            
            if ( $this->should_retry( $http_code, $error ) ) {
                if ( class_exists( 'HHG_Logger' ) ) {
                    HHG_Logger::log( 'Retrying request due to transient error', array( 'http_code' => $http_code, 'error' => $error, 'url' => $requests[$key]['url'] ) );
                }
                $retry_result = $this->retry_request_sync( $requests[$key] );
                $results[$key] = $retry_result;
            } else {
                $results[$key] = array(
                    'response_code' => $http_code,
                    'body' => $body,
                    'error' => $error
                );
            }

            curl_multi_remove_handle( $mh, $ch );
            curl_close( $ch );
        }

        curl_multi_close( $mh );
        if ( class_exists( 'HHG_Logger' ) ) {
            HHG_Logger::log( 'Async requests completed', array( 'count' => count( $results ) ) );
        }
        return $results;
    }

    private function setup_curl_handle( $ch, $request ) {
        curl_setopt( $ch, CURLOPT_URL, $request['url'] );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_TIMEOUT, isset($request['timeout']) ? $request['timeout'] : 30 );
        if ( defined('CURL_HTTP_VERSION_2_0') ) { curl_setopt( $ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0 ); }
        curl_setopt( $ch, CURLOPT_ENCODING, 'gzip' );
        if ( defined('CURLOPT_TCP_NODELAY') ) { curl_setopt( $ch, CURLOPT_TCP_NODELAY, true ); }
        if ( defined('CURLOPT_NOSIGNAL') ) { curl_setopt( $ch, CURLOPT_NOSIGNAL, true ); }
        
        if ( isset($request['method']) && $request['method'] === 'POST' ) {
            curl_setopt( $ch, CURLOPT_POST, true );
            if ( isset($request['body']) ) {
                $body = is_array($request['body']) ? json_encode($request['body']) : $request['body'];
                curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
            }
        }

        if ( isset($request['headers']) ) {
            $headers = array();
            foreach ( $request['headers'] as $k => $v ) {
                if ( is_numeric($k) ) {
                    $headers[] = $v;
                } else {
                    $headers[] = $k . ': ' . $v;
                }
            }
            curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
        }
        
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
    }

    private function should_retry( $http_code, $curl_error ) {

        if ( $curl_error ) return true;
        if ( $http_code == 429 ) return true;
        if ( $http_code >= 500 ) return true;
        return false;
    }

    private function retry_request_sync( $request ) {
        $attempts = 0;
        $result = array();

        while ( $attempts < $this->max_retries ) {
            $attempts++;
            usleep( $this->retry_delay * 1000 * $attempts );

            $response = wp_remote_request( $request['url'], array(
                'method' => isset($request['method']) ? $request['method'] : 'GET',
                'headers' => isset($request['headers']) ? $request['headers'] : array(),
                'body' => isset($request['body']) ? $request['body'] : null,
                'timeout' => isset($request['timeout']) ? $request['timeout'] : 30,
            ));

            if ( ! is_wp_error( $response ) ) {
                $code = wp_remote_retrieve_response_code( $response );
                if ( ! $this->should_retry( $code, '' ) ) {
                    return array(
                        'response_code' => $code,
                        'body' => wp_remote_retrieve_body( $response ),
                        'error' => ''
                    );
                }
            }
        }

        return array(
            'response_code' => isset($code) ? $code : 0,
            'body' => isset($response) && !is_wp_error($response) ? wp_remote_retrieve_body($response) : '',
            'error' => is_wp_error($response) ? $response->get_error_message() : 'Max retries exceeded'
        );
    }
}
