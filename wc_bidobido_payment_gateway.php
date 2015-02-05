<?php
/**
 * Plugin Name: WooCommerce Bidobido Payment Gateway
 * Plugin URI: https://github.com/donmik/wc-bidobido
 * Description: Bidobido.com payment gateway.
 * Version: 1.0
 * Author: donmik.com
 * Author URI: http://donmik.com
 * License: GPL3
 *
 * Text Domain: wc_bidobido_payment_gateway
 * Domain Path: /languages/
 *
 */

if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

	add_action('plugins_loaded', 'init_wc_bidobido_payment_gateway', 0);
    
    $bidobidopg_version = '1.0';
	
	function init_wc_bidobido_payment_gateway() {
        $installed_version = get_option('bidobidopg_version');
        if ($installed_version != $bidobidopg_version) {
            update_option('bidobidopg_version', $installed_version);
        }
	 
	    if ( ! class_exists( 'WC_Payment_Gateway' ) ) { return; }
	    
		class WC_Bidobido extends WC_Payment_Gateway {
			
			var $notify_url;
		
			public function __construct() {
				global $woocommerce;
		
				$this->id			= 'bidobido';
				$this->has_fields 	= false;
				$this->method_title     = __( 'Bidobido', 'wc_bidobido_payment_gateway' );
				$this->method_description = __( 'Pagar con tarjeta de crédito utilizando Bidobido', 'wc_bidobido_payment_gateway' );
				$this->notify_url   = str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'WC_Bidobido', home_url( '/' ) ) );
	
	            $this->load_plugin_textdomain();
				$this->init_form_fields();
				$this->init_settings();
		
				$this->title                    = $this->get_option('title');
                $this->description              = $this->get_option('description');
                $this->identificador_bidobido   = $this->get_option('identificador_bidobido');
                $this->contrasena_metodo_pago   = $this->get_option('contrasena_metodo_pago');
                $this->terminal                 = $this->get_option('terminal');
                $this->testmode                 = $this->get_option('testmode');	
                $this->urlEnvio                 = $this->get_option('urlEnvio');
		
                if ($this->testmode) {
                    $this->debug = 'yes';
                } else {
                    $this->debug = 'no';
                }
				if ( 'yes' == $this->debug ) {
					$this->log = $woocommerce->logger();
                }
		
				// Actions
                add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'check_notification' ) );
                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
				add_action( 'valid-bidobido-standard-notification', array( $this, 'successful_request' ) );				
				add_action( 'woocommerce_receipt_bidobido', array( $this, 'receipt_page' ) );
		    }
		    
	        function load_plugin_textdomain() {
                $locale = apply_filters( 'plugin_locale', get_locale(), 'woocommerce' );
                load_textdomain( 'wc_bidobido_payment_gateway', WP_LANG_DIR.'/wc_bidobido_payment_gateway/wc_bidobido_payment_gateway-'.$locale.'.mo' );
	        }
		
			public function admin_options() {
                parent::admin_options();
		    }
		
		    function init_form_fields() {
                parent::init_form_fields();
                $this->form_fields = array(
                    'enabled' => array(
                        'title' => __( 'Activar/Desactivar', 'wc_bidobido_payment_gateway' ),
                        'type' => 'checkbox',
                        'label' => __( 'Activar Bidobido', 'wc_bidobido_payment_gateway' ),
                        'default' => 'no'
                    ),
                    'title' => array(
                        'title' => __( 'Título', 'wc_bidobido_payment_gateway' ),
                        'type' => 'text',
                        'description' => __( 'Esto controla el nombre de la forma de pago que verá el usuario en el carrito.', 'wc_bidobido_payment_gateway' ),
                        'default' => __( 'Bidobido', 'wc_bidobido_payment_gateway' ),
                        'desc_tip'      => true,
                    ),
                    'description' => array(
                        'title' => __( 'Mensaje para el comprador', 'wc_bidobido_payment_gateway' ),
                        'type' => 'textarea',
                        'default' => ''
                    ),
                    'identificador_bidobido' => array(
                        'title' => __( 'Identificador bidobido', 'wc_bidobido_payment_gateway'),
                        'type'  => 'text',
                        'label' => __('Identificador bidobido', 'wc_bidobido_payment_gateway'),
                    ),
                    'contrasena_metodo_pago' => array(
                        'title' => __( 'Contraseña bidobido', 'wc_bidobido_payment_gateway'),
                        'type'  => 'text',
                        'label' => __('Contraseña bidobido', 'wc_bidobido_payment_gateway'),
                    ),
                    'terminal' => array(
                        'title' => __( 'Terminal bidobido', 'wc_bidobido_payment_gateway'),
                        'type'  => 'text',
                        'label' => __('Terminal bidobido', 'wc_bidobido_payment_gateway'),
                        'default' => 1,
                    ),
                    'urlEnvio' => array(
                        'title' => __( 'Url bidobido', 'wc_bidobido_payment_gateway'),
                        'type'  => 'text',
                        'label' => __('Url bidobido', 'wc_bidobido_payment_gateway'),
                    ),
                    'testmode' => array(
                        'title'       => __( 'Activar modo debug bidobido', 'wc_bidobido_payment_gateway' ),
                        'type'        => 'checkbox',
                        'label'       => __( 'Activar modo debug bidobido', 'wc_bidobido_payment_gateway' ),
                        'default'     => 'no',
                    ),
                );
		    }
		
			function get_bidobido_args( $order ) {
                global $wpdb;

                $order_id = $order->id;

                if ( 'yes' == $this->debug )
                    $this->log->add( 'bidobido', 'Generando pago para el pedido #' . $order_id . '. Url oculta de notificación: ' . $this->notify_url );

                $importe = $order->get_total();

                // Bidobido Args
                $bidobido_args = array(
                    'cantidad'                  => str_replace('.', '', number_format($importe, 2, '.', '')),
                    'moneda'                    => 1,
                    'referencia'                => rand(10,99).time(),
                    'empresa_id'                => $this->identificador_bidobido,
                    'ref_descrip'               => $this->identificador_bidobido,
                    'comercio'                  => home_url(),
                    'terminal'                  => $this->terminal,
                    'tipo_transaccion'          => "0",
                    'URL_respuesta'             => $this->notify_url,
                    'UrlOK'                     => $this->get_return_url($order),
                    'UrlKO'                     => $order->get_cancel_order_url(),
                    'idioma'                    => "195",
                    'test'                      => 0,
                );

                $bidobido_args['firma'] = sha1($bidobido_args['cantidad'].$bidobido_args['referencia'].$bidobido_args['moneda'].$bidobido_args['tipo_transaccion'].$bidobido_args['empresa_id'].$this->contrasena_metodo_pago.$bidobido_args['test']);

                $bidobido_args = apply_filters( 'woocommerce_bidobido_args', $bidobido_args );
                
                // Insertamos en transacciones.
                $wpdb->insert(
                    $wpdb->prefix . 'bidobido_transacciones',
                    array(
                        'transaction_id'    => $bidobido_args['referencia'],
                        'cantidad'          => $bidobido_args['cantidad'],
                        'moneda'            => $bidobido_args['moneda'],
                        'fecha_transaccion' => date('Y-m-d G:i:s'),
                        'estado'            => '0',
                        'order_id'          => $order_id,
                    )
                );

                return $bidobido_args;
			}
            
            function generaCadenaPost( $args ) {
                $cadena = '';
                foreach ($args as $key => $value) {
                    if ($key == '_wpnonce') {
                        continue;
                    }
                    if ($cadena != '') {
                        $cadena .= '&';
                    }
                    $cadena .= $key . '=' . $value;
                }
                return $cadena;
            }
		
		    function generate_bidobido_form( $order_id ) {
				global $woocommerce;
		
				$order = new WC_Order( $order_id );
		
				$bidobido_args = $this->get_bidobido_args( $order );
		
				$bidobido_adr = $this->sendPeticion($this->generaCadenaPost($bidobido_args));
				
				if ( 'yes' == $this->debug ) {
					$this->log->add( 'bidobido', 'Enviando datos a Bidobido ' . print_r( $bidobido_args, true ));
                }
		
				$woocommerce->add_inline_js('
					jQuery("body").block({
							message: "<img src=\"' . esc_url( apply_filters( 'woocommerce_ajax_loader_url', $woocommerce->plugin_url() . '/assets/images/ajax-loader.gif' ) ) . '\" alt=\"Redirecting&hellip;\" style=\"float:left; margin-right: 10px;\" />'.__('Gracias por tu pedido. Estamos redireccionando tu navegador hacia Bidobido para realizar el pago.', 'wc_bidobido_payment_gateway').'",
							overlayCSS:
							{
								background: "#fff",
								opacity: 0.6
							},
							css: {
						        padding:        20,
						        textAlign:      "center",
						        color:          "#555",
						        border:         "3px solid #aaa",
						        backgroundColor:"#fff",
						        cursor:         "wait",
						        lineHeight:		"32px"
						    }
						});
					setTimeout(function () { jQuery("#submit_bidobido_payment_form").click(); }, 5000);
				');
		
				return '<form action="'.esc_url( $bidobido_adr ).'" method="post" id="bidobido_payment_form" target="_top">
						<input type="submit" class="button-alt" id="submit_bidobido_payment_form" value="'.__('Pagar con Bidobido', 'wc_bidobido_payment_gateway').'" /> <a class="button cancel" href="'.esc_url( $order->get_cancel_order_url() ).'">'.__('Cancelar pedido &amp; restaurar mi carrito', 'wc_bidobido_payment_gateway').'</a>
					</form>';
		
			}
    
            function sendPeticion( $cadena ) {
                $url=$this->urlEnvio;
                
                if ( 'yes' == $this->debug ) {
					$this->log->add( 'bidobido', 'Enviando petición a ' . $url . ' con los siguientes datos: ' . $cadena);
                }

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $cadena);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $resultado=curl_exec ($ch);
                curl_close ($ch);
                if(function_exists('simplexml_load_string')){
                    $respuesta = simplexml_load_string($resultado);
                    if ($respuesta) {
                        $error=$respuesta->xpath('/respuesta/error');
                        if (isset($error[0])) {
                            if ( 'yes' == $this->debug ) {
                                $this->log->add( 'bidobido', 'Error con petición. Respuesta: ' . $error[0]);
                            }
                            die('error: '.$error[0]);
                        } else {
                            $url_peticion=$respuesta->xpath('/respuesta/url');
                            if (!isset($url_peticion[0])) {
                                die('error');
                            }
                            if ( 'yes' == $this->debug ) {
                                $this->log->add( 'bidobido', 'Éxito con petición. Respuesta: ' . $url_peticion[0]);
                            }
                        }
                    }
                    return $url_peticion[0];
                }else{
                    if(strpos('\<error\>',$resultado) !== false){
                        preg_match_all ("/<error>(.*?)<\/error>/",$resultado,$error);
                        $error=str_replace('<![CDATA[','',$error[1][0]);
                        $error=str_replace(']]>','',$error);
                        if ( 'yes' == $this->debug ) {
                            $this->log->add( 'bidobido', 'Error con petición. Respuesta: ' . $error[0]);
                        }
                        die('error: '.$error);
                    }elseif(strpos('\<url\>',$resultado) !== false){
                        preg_match_all ("/<url>(.*?)<\/url>/",$resultado,$url_peticion);
                        $url_peticion=str_replace('<![CDATA[','',$url_peticion[1][0]);
                        $url_peticion=str_replace(']]>','',$url_peticion);
                        if ( 'yes' == $this->debug ) {
                            $this->log->add( 'bidobido', 'Éxito con petición. Respuesta: ' . $url_peticion[0]);
                        }
                        return $url_peticion;
                    }else{
                        die('error');
                    }
                }
            }
		
			function process_payment( $order_id ) {
		
				$order = new WC_Order( $order_id );
		
                return array(
                    'result' 	=> 'success',
                    'redirect'	=> add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
                );
		
			}
		
			function receipt_page( $order ) {
		
				echo '<p>'.__('Gracias por hacer tu pedido, por favor pincha en el siguiente botón para pagar con Bidobido', 'wc_bidobido_payment_gateway').'</p>';
		
				echo $this->generate_bidobido_form( $order );
		
			}
		
			function check_notification() {
				global $woocommerce;
                global $wpdb;
		
				if ( 'yes' == $this->debug ) {
					$this->log->add( 'bidobido', 'Comprobando notificación bidobido...' );
                }
		
		    	// Get received values from post data
				$received_values = (array) stripslashes_deep( $_POST );
			
		        if ( 'yes' == $this->debug ) {
		        	$this->log->add( 'bidobido', 'Datos recibidos: ' . print_r($received_values, true) );
                }
		
                if (isset($received_values['referencia'])           && 
                    isset($received_values['cantidad'])             && 
                    isset($received_values['moneda'])    && 
                    isset($received_values['empresa_id'])           && 
                    isset($received_values['resultado'])            && 
                    $received_values['referencia']!=''              && 
                    $received_values['cantidad']!=''                && 
                    $received_values['moneda']!=''                  && 
                    $received_values['empresa_id']!=''              && 
                    $received_values['resultado']!='') {

                    $transaccion = $wpdb->get_row(
                        'SELECT * FROM ' . $wpdb->prefix . 'bidobido_transacciones ' .
                        'WHERE transaction_id = ' . $received_values['referencia'] . 
                        ' AND estado=0');
                    if ($transaccion) {
                        if ( 'yes' == $this->debug ) {
                            $this->log->add( 'bidobido', 'Transaccion: ' . print_r($transaccion, true) );
                        }
                        $cantidad               = $transaccion->cantidad;
                        $cantidad_carrito       = $cantidad/100;
                        $contrasena             = $this->contrasena_metodo_pago;
                        $identificador_bidobido = $this->identificador_bidobido;
                        $order_id               = $transaccion->order_id;
                        $firma_calculada        = sha1($cantidad . $received_values['referencia'] . $transaccion->moneda. $identificador_bidobido . $contrasena . $received_values['resultado']);
                        if ($firma_calculada === $received_values['firma']) {
                            if ($received_values['resultado'] == 'ok' && $received_values['cantidad'] == $cantidad) {
                                $wpdb->query(
                                    $wpdb->prepare(
                                        'UPDATE ' . $wpdb->prefix . 'bidobido_transacciones ' .
                                        'SET estado = "1" WHERE transaction_id = ' . $received_values['referencia'], null
                                    )
                                );
                                if ( 'yes' == $this->debug ) {
                                    $this->log->add( 'bidobido', 'Notificación recibida válida.' );
                                }
                                $order = new WC_Order( $transaccion->order_id );
                                // Store payment Details
                                update_post_meta( $order_id, 'Fecha pago', date('Y-m-d H:i') );
                                // Payment completed
                                $order->add_order_note( __('Pago completado con Bidobido', 'wc_bidobido_payment_gateway') );
                                $order->payment_complete();
                                return true;
                            } else {
                                $wpdb->query(
                                    $wpdb->prepare(
                                        'UPDATE ' . $wpdb->prefix . 'bidobido_transacciones ' .
                                        'SET estado = "-1" WHERE transaction_id = ' . $received_values['referencia'], null
                                    )
                                );
                                if($received_values['resultado']=='ok') {
                                    $mensaje='payment id:'.$transaccion->transaction_id.' hay diferencias entre la cantidad pagada y la del carrito';
                                }else{
                                    $mensaje='payment id:'.$transaccion->transaction_id;
                                }
                                if ( 'yes' == $this->debug ) {
                                    $this->log->add( 'bidobido', 'Notificación recibida NO válida. Mensaje: ' . $mensaje );
                                }
                            }
                        }
                    }
                }
                            
                return false;
		    }
		}
	}
    
    function add_bidobido_gateway( $methods ) {
        $methods[] = 'WC_Bidobido';
        return $methods;
    }
    add_filter('woocommerce_payment_gateways', 'add_bidobido_gateway' );
        
    function activate_bidobido_gateway() {
        global $wpdb;
        global $bidobidopg_version;
        
        $table_name = $wpdb->prefix . 'bidobido_transacciones';
        
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `$table_name` (
			  `transaction_id` bigint(20) NOT NULL,
			  `cantidad` int(11) NOT NULL,
			  `moneda` varchar(3) collate utf8_unicode_ci NOT NULL,
			  `fecha_transaccion` datetime NOT NULL,
			  `estado` tinyint(4) NOT NULL default '0' COMMENT '0:sin realizar,-1:cancelado,1:finalizado',
			  `order_id` int(11) NOT NULL
			  PRIMARY KEY  (`transaction_id`),
  			  KEY `estado` (`estado`)
			) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );

        add_option( 'bidobidopg_version', $bidobidopg_version );
    }
    
    function deactivate_bidobido_gateway() {
        
    }
    
    register_activation_hook(__FILE__, 'activate_bidobido_gateway');
    register_deactivation_hook(__FILE__, 'deactivate_bidobido_gateway');

}
