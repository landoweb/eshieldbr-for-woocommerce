<?php
defined( 'DS' ) or define( 'DS', DIRECTORY_SEPARATOR );
define( 'ESHIELDBR_ROOT', WP_CONTENT_DIR . DS . 'uploads' . DS . 'wc-logs' . DS );

if ( ! class_exists( 'WC_EshieldBR' ) ) :

class WC_EshieldBR {

	protected static $instance;

	private $order;

	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	public function __construct() {
		
		// Interrompe carregamento se o WooCommerce não estiver instalado.
		if ( ! function_exists( 'WC' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'woocommerce_missing_notice' ) );
			return;
		}

		// Interrompe carregamento se o Brazilian Market on WooCommerce não estiver instalado.
		if ( ! class_exists( 'Extra_Checkout_Fields_For_Brazil' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'ecfb_missing_notice' ) );
			return;
		}		

		$this->namespace			= 'woocommerce-eshieldbr';
		$this->enabled				= $this->get_setting( 'enabled' );
		$this->username				= $this->get_setting( 'username' );
		$this->password				= $this->get_setting( 'password' );
		$this->validation_sequence	= 'after';
		$this->approve_status		= 'wc-completed';
		$this->review_status		= 'wc-processing';
		$this->reject_status		= 'wc-cancelled';
		$this->db_err_status		= 'wc-failed';
		$this->fraud_message		= $this->get_setting( 'fraud_message' );
		$this->debug_log			= $this->get_setting( 'debug_log' );
		
		if ( ! $this->username || ! $this->password ) {
			add_action( 'admin_notices', array( __CLASS__, 'username_missing_notice' ) );
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );

		// Hooks for WooCommerce
		add_filter( 'manage_shop_order_posts_columns', array( $this, 'add_column' ), 11 );
		add_filter( 'http_request_timeout', array( $this, 'timeout_extend' ), 11 );

		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column' ), 3 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'render_fraud_report' ) );
		add_action( 'woocommerce_after_checkout_form', array( $this, 'javascript_agent' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'checkout_order_processed' ), 99, 3 );
		add_action( 'woocommerce_before_thankyou', array( $this, 'order_status_changed' ), 99, 3 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'order_status_completed' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'order_status_cancelled' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'order_status_changed' ), 99, 3 );
	}

	public static function timeout_extend( $time )
	{
	    // Default timeout is 5
	    return 45;
	}	

	/**
	 * WooCommerce missing notice.
	 */
	public static function woocommerce_missing_notice() {
		include dirname( __FILE__ ) . '/admin/views/html-notice-missing-woocommerce.php';
	}

	/**
	 * WooCommerce Extra Checkout Fields for Brazil notice.
	 */
	public static function ecfb_missing_notice() {
		include dirname( __FILE__ ) . '/admin/views/html-notice-missing-ecfb.php';
	}	

	/**
	 * WooCommerce missing notice.
	 */
	public static function username_missing_notice() {

		$current_screen = get_current_screen();

		if ( 'plugins' == $current_screen->parent_base ) {
			include dirname( __FILE__ ) . '/admin/views/html-notice-missing-username.php';
		}
	}

	/**
	 * Validar o pedido após passar pelo gateway de pagamento (fluxo mais completo).
	 */
	public function order_status_changed( $order_id ) {

		if ( $this->validation_sequence != 'after' ) {
			return;
		}

		$this->order = wc_get_order( $order_id );

		if( $this->order->get_status() != 'cancelled' && $this->order->get_status() != 'completed') {
			$this->validate_order();
		}
	}

	/**
	 * Executar a verificação do pedido.
	 */
	public function validate_order() {
		if ( $this->enabled != 'yes' ) {
			$this->write_debug_log( 'Verificação EshieldBR não habilitada. A verificação do pedido não será realizada.' );
			return;
		}

		// Verifique se o pedido foi rastreado
		// $result = get_post_meta( $this->order->get_id(), '_eshieldbr' );
		// if( count( $result ) > 0 ) {
		// 	return;
		// }

		// Verifique novamente se o pedido foi analisado
		if ( $this->get_order_notes( $this->order->get_id() ) ) {
			$result_order_note = $this->get_order_notes( $this->order->get_id() );
			$this->write_debug_log( 'O pedido foi validado. Pule para a verificação do EshieldBR.' );
			$this->write_debug_log( $result_order_note );
			return;
		}

		// Impedir downloads digitais antes que o pedido seja concluído.
		update_option( 'woocommerce_downloads_grant_access_after_payment', 'no' );

		$this->order->add_order_note( 'Verificação EshieldBR iniciada para o pedido ' . $this->order->get_id() . '.' );
		$this->write_debug_log( 'Verificação EshieldBR iniciada para o pedido ' . $this->order->get_id() . '.' );

		$payment_gateway = wc_get_payment_gateway_by_order( $this->order );
		$qty = 0;

		$item_id = '';
		$items = array();
		foreach ($this->order->get_items() as $key => $item_data) {
			$product = wc_get_product($item_data->get_product_id());
			$items['item_category'.$item_id] 		= get_bloginfo( 'description' );
			$items['item_id'.$item_id]       		= $product->get_sku() != '' ? $product->get_sku() : $item_data->get_product_id();
			$items['item_name'.$item_id]     		= $product->get_name();
			$items['item_price'.$item_id]    		= number_format($product->get_price(), 2, '.', '');
			$items['item_quantity'.$item_id] 		= $item_data->get_quantity();
			$items['item_store'.$item_id]    		= get_bloginfo( 'name' ) . ', '. get_option( 'woocommerce_store_address' );
			$items['item_store_country'.$item_id] 	= "BR";
			$items['item_url'.$item_id]      		= htmlspecialchars_decode( $url = get_permalink($item_data->get_product_id()) );
			
			$item_id++;
		}		

		switch ( $payment_gateway->id ) {
			case 'rede_credit':
				$payment_mode = 'rede_credit';
				break;				

			default:
				$payment_mode = 'others';
		}

		$client_ip = ($_SERVER['REMOTE_ADDR'] == '::1') ? '127.0.0.1' : $_SERVER['REMOTE_ADDR'];

		$credit_card_number = $this->order->get_meta( '_wc_rede_transaction_bin' );

		$current_user = wp_get_current_user();
		if ( $current_user !== '' ) {
			$current_username = $current_user->user_login;
		} else {
			$current_username = '';
		}

		$bill_country = $ship_country = '';
		if ( WC()->version < '2.7.0' ) {
			$bill_country = ( $this->order->billing_country !== "default" ) ? $this->order->billing_country : '';
			$ship_country = ( $this->order->shipping_country !== "default" ) ? $this->order->shipping_country : '';
		} else {
			$bill_country = ( $this->order->get_billing_country() !== "default" ) ? $this->order->get_billing_country() : '';
			$ship_country = ( $this->order->get_shipping_country() !== "default" ) ? $this->order->get_shipping_country() : '';
		}

		$token = $this->getTokenAuthorization();

		// Principal requisição após o login, nela é efetuada a consulta conforme acordado com a EshieldBR para analise de dados do cliente. Podendo retornar três tipos de status na resposta [APPROVE = Aprovado, REVIEW = Em análise, DECLINE = Reprovado]. Onde o status de REVIEW será analisado pela EshieldBR ou manualmente pelo cliente e retornado através do Webhook abaixo. Resposta em JSON. [Dados da requisição como campo de formulário]

		$url = 'https://api.eshieldbr.com.br/api/consulta/';
		$transaction_id = ( WC()->version < '2.7.0' ) ? $this->order->transaction_id : $this->order->get_transaction_id();
		if(empty($transaction_id)) {
			$transaction_id = $this->order->set_transaction_id( $this->order->get_id() );
		}

		$phone_number = ( WC()->version < '2.7.0' ) ? $this->order->billing_phone : $this->order->get_billing_phone();
		$phone_number = '+55' . preg_replace("/[^0-9]/", "", $phone_number);
		$cpf = ( WC()->version < '2.7.0' ) ? $this->order->billing_cpf : $this->order->get_meta( '_billing_cpf' );

		$data = array(
			'action_type'					=> 'purchase',
			'card_bin'						=> ( $credit_card_number ) ? substr( $credit_card_number, 0, 6 ) : '',
			'card_fullname'					=> $this->order->get_meta( '_wc_rede_transaction_holder' ),
			'email'							=> ( WC()->version < '2.7.0' ) ? $this->order->billing_email : $this->order->get_billing_email(),
			'card_hash'						=> ( $credit_card_number ) ? $this->hash_string( $credit_card_number ) : '',
			'card_last'						=> $this->order->get_meta( '_wc_rede_transaction_last4' ),
			'cpf'							=> $this->limpaCPF_CNPJ($cpf),
			'email'							=> ( WC()->version < '2.7.0' ) ? $this->order->billing_email : $this->order->get_billing_email(),
			'ip'							=> $client_ip,
			'merchant_id'					=> get_bloginfo( 'name' ),
			'payment_mode'					=> $payment_gateway->id,
			'phone_number'					=> $phone_number,
			'transaction_amount'			=> $this->order->get_total(),
			'transaction_currency'			=> ( WC()->version < '2.7.0' ) ? $this->order->get_order_currency() : $this->order->get_currency(),
			'transaction_id'				=> "{$this->order->get_order_number()}",			
			'transaction_type'				=> 'purchase',
			'user_city'						=> ( WC()->version < '2.7.0' ) ? $this->order->billing_city : $this->order->get_billing_city(),
			'user_country'					=> $bill_country,
			'user_fullname'					=> ( WC()->version < '2.7.0' ) ? $this->order->billing_first_name . ' ' . $this->order->billing_last_name  : $this->order->get_billing_first_name() . ' ' . $this->order->get_billing_last_name(),
			'user_region'					=> ( WC()->version < '2.7.0' ) ? $this->order->billing_state : $this->order->get_billing_state(),
			'user_street'					=> ( WC()->version < '2.7.0' ) ? $this->order->billing_address_1 : $this->order->get_billing_address_1(),
			'user_street2'					=> ( WC()->version < '2.7.0' ) ? $this->order->billing_address_2 : $this->order->get_billing_address_2(),
			'user_zip'						=> ( WC()->version < '2.7.0' ) ? $this->order->billing_postcode : $this->order->get_billing_postcode(),
		);
		$data = array_merge($data, $items);

		$this->write_debug_log( 'Dados a serem enviado para EshieldBR(validate_order): ' . serialize($data) );

		$repeat = false;
		$request = wp_remote_post( $url, array(
			'timeout' => 45,
		    'body'    => $data,
		    'headers' => array(
		        'Authorization' => $token,
		        'cache-control' => 'no-cache',
		    ),
		) );

		// Give up fraud check if having network issue
		if ( is_wp_error( $request ) ) {
			$this->write_debug_log( 'Falha no servidor de origem para o pedido ' . $this->order->get_id() . '. Erro: ' . $request->get_error_message() );
			$this->order->add_order_note( 'Falha no servidor de origem para o pedido ' . $this->order->get_id() . '. Erro: ' . $request->get_error_message() );

			// Save the static data to prevent duplicate checking
			$add_post_meta_result = add_post_meta( $this->order->get_id(), '_eshieldbr', array(
				'order_id'						=> $this->order->get_id(),
				'ip_address'					=> $client_ip,
				'eshieldbr_id'					=> '',
				'username'						=> $this->username,
				'password'						=> $this->password,
			) );

			if ( ! $add_post_meta_result ) {
				$this->write_debug_log( 'ERROR 103 - a função add_post_meta falhou.' );
			}

			$repeat = true;
		}

		if($repeat) {

			$request = wp_remote_post( $url, array(
				'timeout' => 60,
			    'body'    => $data,
			    'headers' => array(
			        'Authorization' => $token,
			        'cache-control' => 'no-cache',
			    ),
			) );

			// Give up fraud check if having network issue
			if ( is_wp_error( $request ) ) {
				$this->write_debug_log( 'Falha na segunda verificação para o pedido ' . $this->order->get_id() . '. Erro: ' . $request->get_error_message() );
				$this->order->add_order_note( 'Falha na segunda verificação para o pedido ' . $this->order->get_id() . '. Erro: ' . $request->get_error_message() );

				// Save the static data to prevent duplicate checking
				$add_post_meta_result = add_post_meta( $this->order->get_id(), '_eshieldbr', array(
					'order_id'						=> $this->order->get_id(),
					'ip_address'					=> $client_ip,
					'eshieldbr_id'					=> '',
					'username'						=> $this->username,
					'password'						=> $this->password,
				) );

				if ( ! $add_post_meta_result ) {
					$this->write_debug_log( 'ERROR 103 - a função add_post_meta falhou.' );
				}

				return;
			}
		}		

		// Get the HTTP response
		$response = json_decode( wp_remote_retrieve_body( $request ) );

		// Make sure response is an object
		if ( ! is_object( $response ) ) {
			$this->write_debug_log( 'Falha na integração EshieldBR para o pedido ' . $this->order->get_id() . ' devido a um problema de rede.' );
			$this->order->add_order_note( 'Falha na integração EshieldBR para o pedido ' . $this->order->get_id() . ' devido a um problema de rede.' );

			// Save the static data to prevent duplicate checking
			$add_post_meta_result = add_post_meta( $this->order->get_id(), '_eshieldbr', array(
				'order_id'						=> $this->order->get_id(),
				'ip_address'					=> $client_ip,
				'eshieldbr_id'					=> '',
				'username'						=> $this->username,
				'password'						=> $this->password,
			) );

			if ( ! $add_post_meta_result ) {
				$this->write_debug_log( 'ERROR 104 - a função add_post_meta falhou.' );
			}

			return;
		}

		if( is_object($response->error) ) {
			$this->write_debug_log( 'Código de erro: ' . $response->error->code );
			$this->write_debug_log( 'Mensagem de erro: ' . $response->error->message );
			$this->order->add_order_note( 'Mensagem de erro: ' . $response->error->message );
			return;

		} elseif(is_object($response->resposta)) {

			if($response->resposta->erro == 1) {
				$this->write_debug_log( 'Mensagem de resposta: ' . $response->resposta->mensagem );
				$this->order->add_order_note( 'Mensagem de resposta: ' . $response->resposta->mensagem );
				return;

			} else {
				$this->write_debug_log( 'Resposta aprovada: ' . $response->resposta->aprovado );
				$this->order->add_order_note( 'Resposta aprovada: ' . $response->resposta->aprovado );				
			}
		}

		// Save fraud check result
		$add_post_meta_result = add_post_meta( $this->order->get_id(), '_eshieldbr', array(
			'order_id'						=> $this->order->get_id(),
			'ip_address'					=> $client_ip,
			'eshieldbr_error_code'			=> $response->resposta->erro,
			'eshieldbr_transaction'			=> $response->resposta->transaction_id,
			'eshieldbr_id'					=> $response->resposta->eshield_id,
			'eshieldbr_score'				=> $response->resposta->score,
			'eshieldbr_proxy'				=> $response->resposta->proxy,
			'eshieldbr_status'				=> $response->resposta->aprovado,
			'eshieldbr_email'				=> $response->resposta->email,
		) );

		if ( ! $add_post_meta_result ) {
			$this->write_debug_log( 'ERROR 105 - a função add_post_meta falhou.' );
		}

		if ( is_object($response->resposta) && isset($response->resposta->mensagem) && strpos( $response->resposta->mensagem, 'SYSTEM DATABASE ERROR' ) !== false ) {
			$this->write_debug_log( 'Erro do banco de dados do sistema EshieldBR.' . $response->resposta->mensagem );
			$this->order->add_order_note( 'Erro do banco de dados do sistema EshieldBR.' . $response->resposta->mensagem );
		}

		$this->write_debug_log( 'Verificação EshieldBR concluída com status ' . $response->resposta->aprovado . '. Transaction ID = ' . $response->resposta->eshield_id );
		$this->order->add_order_note( 'Verificação EshieldBR concluída com status ' . $response->resposta->aprovado . '. Transaction ID = ' . $response->resposta->eshield_id );

		if ( isset($response->resposta->mensagem) && strpos( $response->resposta->mensagem, 'SYSTEM DATABASE ERROR' ) !== false ) {
			if ( $this->db_err_status && $this->db_err_status != $this->order->get_status() ) {
				$this->order->update_status( $this->db_err_status, '' );
			}
		}
		elseif ( $response->resposta->aprovado == 'DECLINE' ) {
			if ( $this->reject_status && $this->reject_status != $this->order->get_status() ) {
				$this->order->update_status( $this->reject_status, '' );
			}
		}
		elseif ( $response->resposta->aprovado == 'REVIEW' ) {
			if ( $this->review_status && $this->review_status != $this->order->get_status() ) {
				$this->order->update_status( $this->review_status, '' );
			}
		}
		elseif ( $response->resposta->aprovado == 'APPROVE' ) {
			if ( $this->approve_status && $this->approve_status != $this->order->get_status() && $this->order->get_status() != 'wc-completed' ) {
				$this->order->update_status( $this->approve_status, '' );
			}
		}

		if ( $response->resposta->aprovado == 'DECLINE' ) {
			return false;
		}

		return true;
	}

	public function getTokenAuthorization(){

		// Autenticação via credenciais previamente enviadas via Basic Auth. Retorna token de acesso com dados basicos da autenticação. Necessário para chamada das outras APIs
		$url = 'https://api.eshieldbr.com.br/api/usuario/';
		$request = wp_remote_post( $url, array(
		    'headers' => array(
		        'Authorization' => 'Basic ' . base64_encode( $this->username . ':' . $this->password ),
		        'cache-control' => 'no-cache',
		        'Content-Type' => 'application/json',
		        'Accept' => 'application/json',
		    ),
		) );

		// Desista da verificação de fraude se estiver tendo problemas de rede
		if ( is_wp_error( $request ) ) {
			$this->write_debug_log( 'Falha na Autenticação da EshieldBR para o pedido ' . $this->order->get_id() . '. Erro durante a verificação do pedido. Erro: ' . $request->get_error_message() );
			$this->order->add_order_note( 'Falha na Autenticação da EshieldBR para o pedido ' . $this->order->get_id() . '. Erro durante a verificação do pedido. Erro: ' . $request->get_error_message() );

			// Save the static data to prevent duplicate checking
			$add_post_meta_result = add_post_meta( $this->order->get_id(), '_eshieldbr', array(
				'order_id'						=> $this->order->get_id(),
				'ip_address'					=> $client_ip,
				'eshieldbr_id'					=> '',
				'username'						=> $this->username,
				'password'						=> $this->password,
			) );

			if ( ! $add_post_meta_result ) {
				$this->write_debug_log( 'ERROR 101 - a função add_post_meta falhou.' );
			}

			return;
		}

		// Obtenha a resposta HTTP
		$response = json_decode( wp_remote_retrieve_body( $request ) );

		// Certifique-se de que a resposta seja um objeto
		if ( ! is_object( $response ) ) {
			$this->write_debug_log( 'Falha na Autenticação da EshieldBR para o pedido ' . $this->order->get_id() . ' devido a um problema de rede.' );
			$this->order->add_order_note( 'Falha na Autenticação da EshieldBR para o pedido ' . $this->order->get_id() . ' devido a um problema de rede.' );

			// Save the static data to prevent duplicate checking
			$add_post_meta_result = add_post_meta( $this->order->get_id(), '_eshieldbr', array(
				'order_id'						=> $this->order->get_id(),
				'ip_address'					=> $client_ip,
				'eshieldbr_id'					=> '',
				'username'						=> $this->username,
				'password'						=> $this->password,
			) );

			if ( ! $add_post_meta_result ) {
				$this->write_debug_log( 'ERROR 102 - a função add_post_meta falhou.' );
			}

			return;
		}

		$this->write_debug_log( 'Token criado: ' . $response->data->token );

		if (isset($response->data->token) && !empty($response->data->token)){
			return $response->data->token;
		} else {
			return false;
		}
	}

	/**
	 * Includes required scripts and styles.
	 */
	public function admin_enqueue_scripts( $hook ) {
		if ( is_admin() ) {
			wp_enqueue_script( 'eshieldbr_woocommerce_admin_script', plugins_url( '/assets/js/script.js', WC_FLP_DIR ), array( 'jquery' ), '1.0', true );
		}

		wp_enqueue_style( 'eshieldbr_pro_admin_menu_styles', untrailingslashit( plugins_url( '/', WC_FLP_DIR ) ) . '/assets/css/style.css', array() );
	}

	/**
	 * Admin menu.
	 */
	public function admin_menu() {
		add_menu_page( 'EshieldBR', 'EshieldBR', 'manage_options', 'woocommerce-eshieldbr', array( $this, 'settings_page' ), 'dashicons-admin-eshieldbr', 30 );
	}


	/**
	 * Settings page.
	 */
	public function settings_page() {
		if ( !is_admin() ) {
			$this->write_debug_log( 'Não conectado como administrador. A página de configurações não será exibida.' );
			return;
		}

		$form_status = '';

		$wc_order_statuses = wc_get_order_statuses();
		$wc_order_statuses[''] = 'Sem mudança de status';

		$enable_wc_eshieldbr = ( isset( $_POST['submit'] ) && isset( $_POST['enable_wc_eshieldbr'] ) ) ? 'yes' : ( ( ( isset( $_POST['submit'] ) && !isset( $_POST['enable_wc_eshieldbr'] ) ) ) ? 'no' : $this->get_setting( 'enabled' ) );
		$username = ( isset( $_POST['username'] ) ) ? sanitize_text_field($_POST['username']) : $this->get_setting( 'username' );
		$password = ( isset( $_POST['password'] ) ) ? sanitize_text_field($_POST['password']) : $this->get_setting( 'password' );
		$validation_sequence = 'after';
		$approve_status = 'wc-completed';
		$review_status = 'wc-processing';
		$reject_status = 'wc-cancelled';
		$db_err_status = 'wc-failed';
		$fraud_message = ( isset( $_POST['fraud_message'] ) ) ? sanitize_text_field($_POST['fraud_message']) : $this->get_setting( 'fraud_message' );
		$enable_wc_eshieldbr_debug_log = ( isset( $_POST['submit'] ) && isset( $_POST['enable_wc_eshieldbr_debug_log'] ) ) ? 'yes' : ( ( ( isset( $_POST['submit'] ) && !isset( $_POST['enable_wc_eshieldbr_debug_log'] ) ) ) ? 'no' : $this->get_setting( 'debug_log' ) );

		if ( isset( $_POST['submit'] ) ) {

			if ( empty( $form_status ) ) {
				$this->update_setting( 'enabled', $enable_wc_eshieldbr );
				$this->update_setting( 'username', $username );
				$this->update_setting( 'password', $password );
				$this->update_setting( 'validation_sequence', $validation_sequence );
				$this->update_setting( 'approve_status', $approve_status );
				$this->update_setting( 'review_status', $review_status );
				$this->update_setting( 'reject_status', $reject_status );
				$this->update_setting( 'db_err_status', $db_err_status );
				$this->update_setting( 'fraud_message', $fraud_message );
				$this->update_setting( 'debug_log', $enable_wc_eshieldbr_debug_log );

				$form_status = '
				<div id="message" class="updated">
					<p>Alterações salvas.</p>
				</div>';
			}
		}

		if ( isset( $_POST['purge'] ) ) {
			global $wpdb;
			$wpdb->query('DELETE FROM ' . $wpdb->prefix . 'postmeta WHERE meta_key LIKE "%eshieldbr%"');
			$form_status = '
				<div id="message" class="updated">
					<p>Todos os dados foram excluídos.</p>
				</div>';
		}

		wp_enqueue_script( 'jquery' );
		echo '
		<div class="wrap">
			<h1>EshieldBR para WooCommerce</h1>

			' . $form_status . '

			<form id="form_settings" method="post" novalidate="novalidate">
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="enable_wc_eshieldbr">Habilitar Verificação EshieldBR</label>
						</th>
						<td>
							<input type="checkbox" name="enable_wc_eshieldbr" id="enable_wc_eshieldbr"' . ( ( $enable_wc_eshieldbr == 'yes' ) ? ' checked' : '' ) . '>
						</td>
					</tr>

					<tr>
						<td scope="row" colspan="2">
							<h2>Informações da Licença</h2><hr />
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="username">Username</label>
						</th>
						<td>
							<input type="text" name="username" id="username" maxlength="32" value="' . $username . '" class="regular-text code" />
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="password">Password</label>
						</th>
						<td>
							<input type="text" name="password" id="password" maxlength="32" value="' . $password . '" class="regular-text code" />
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="enable_wc_eshieldbr_debug_log">Habilitar registro de depuração para fins de desenvolvimento</label>
						</th>
						<td>
							<input type="checkbox" name="enable_wc_eshieldbr_debug_log" id="enable_wc_eshieldbr_debug_log"' . ( ( $enable_wc_eshieldbr_debug_log == 'yes' ) ? ' checked' : '' ) . '>
						</td>
					</tr>

				</table>

				<p class="submit">
					<input type="hidden" name="validation_sequence" id="validation_sequence" value="' . $validation_sequence . '" />
					<input type="hidden" name="approve_status" id="approve_status" value="' . $approve_status . '" />
					<input type="hidden" name="review_status" id="review_status" value="' . $review_status . '" />
					<input type="hidden" name="reject_status" id="reject_status" value="' . $reject_status . '" />
					<input type="hidden" name="db_err_status" id="db_err_status" value="' . $db_err_status . '" />
					<input type="hidden" name="fraud_message" id="fraud_message" value="' . $fraud_message . '" />
					<input type="submit" name="submit" id="submit" class="button button-primary" value="Salvar Alterações" />
				</p>
			</form>

			<p>
				<form id="form-purge" method="post">
					<h2>Gerenciamento de Dados</h2><hr />
					<input type="hidden" name="purge" value="true">
					<p>Remova <strong>todos os registros do plugin EshieldBR para WooCommerce</strong> do banco de dados.</p>
					<input type="button" name="button" id="button-purge" class="button button-primary" value="Excluir Todos os Dados" />
				</form>
			</p>
		</div>
		<script>
			jQuery("#approve_status").change(function (e) {
				if ((jQuery("#validation_sequence").val() == "before") && (jQuery("#approve_status").val() == "wc-completed")) {
					if (!confirm("Você definiu a alteração do Status Aprovado para \"Concluído\" e isso concluirá o pedido sem enviar ao gateway de pagamento. Você ainda deseja continuar?")) {
						jQuery("#approve_status").val("' . $approve_status . '");
					} else {
						e.preventDefault();
					}
				}
			});

			jQuery("#validation_sequence").change(function (e) {
				if ((jQuery("#validation_sequence").val() == "before") && (jQuery("#approve_status").val() == "wc-completed")) {
					if (!confirm("Você definiu a alteração do Status Aprovado para \"Concluído\" e isso concluirá o pedido sem enviar ao gateway de pagamento. Você ainda deseja continuar?")) {
						jQuery("#validation_sequence").val("' . $validation_sequence . '");
					} else {
						e.preventDefault();
					}
				}
			});
		</script>';
	}


	/**
	 * Javascript agent.
	 */
	public function javascript_agent() {
		echo '<script>!function(){function t(){var t=document.createElement("script");t.type="text/javascript",t.async=!0,t.src="https://cdn.eshieldbr.com.br/s.js";var e=document.getElementsByTagName("script")[0];e.parentNode.insertBefore(t,e)}window.attachEvent?window.attachEvent("onload",t):window.addEventListener("load",t,!1)}();</script>';
	}


	/**
	 * Add risk score column to order list.
	 */
	public function add_column( $columns ) {
		if ( $this->enabled != 'yes' ) {
			return $columns;
		}

		$columns = array_merge( array_slice( $columns, 0, 5 ), array( 'eshieldbr_score' => 'Pontuação de risco' ), array_slice( $columns, 5 ) );
		return $columns;
	}


	/**
	 * Fill in EshieldBR score into risk score column.
	 */
	public function render_column( $column ) {
		if ( $this->enabled != 'yes' ) {
			return;
		}

		if ( $column != 'eshieldbr_score' ) {
			return;
		}

		global $post;

		$result = get_post_meta( $post->ID, '_eshieldbr' );

		if ( count( $result ) > 0 ) {
			if ( !is_array( $result[0] ) && !is_object( $result[0] ) && strpos( $result[0], '\\' ) ) {
				$result[0] = str_replace( '\\', '', $result[0] );
			}

			if( is_array( $result[0] ) ){
				if ( is_null( $row = $result[0] ) === FALSE ) {
					if ( $row['eshieldbr_score'] > 80 ) {
						echo '<div style="color:#ff0000"><span class="dashicons dashicons-warning"></span> <strong>' . $row['eshieldbr_score'] . '</strong></div>';
					}
					elseif ( $row['eshieldbr_score'] > 60 ) {
						echo '<div style="color:#f0c850"><span class="dashicons dashicons-warning"></span> <strong>' . $row['eshieldbr_score'] . '</strong></div>';
					}
					else {
						echo '<div style="color:#66cc00"><span class="dashicons dashicons-thumbs-up"></span> <strong>' . $row['eshieldbr_score'] . '</strong></div>';
					}
				}
			} else {
				if( is_object( $result[0] ) ){
					$row = $result[0];
				} else {
					$row = json_decode( $result[0] );
				}
				if ( is_null( $row ) === FALSE ) {
					if ( $row->eshieldbr_score > 80 ) {
						echo '<div style="color:#ff0000"><span class="dashicons dashicons-warning"></span> <strong>' . $row->eshieldbr_score . '</strong></div>';
					}
					elseif ( $row->eshieldbr_score > 60 ) {
						echo '<div style="color:#f0c850"><span class="dashicons dashicons-warning"></span> <strong>' . $row->eshieldbr_score . '</strong></div>';
					}
					else {
						echo '<div style="color:#66cc00"><span class="dashicons dashicons-thumbs-up"></span> <strong>' . $row->eshieldbr_score . '</strong></div>';
					}
				}
			}
		}
	}


	/**
	 * Append EshieldBR report to order details.
	 */
	public function render_fraud_report() {
		if ( $this->enabled != 'yes' ) {
			return;
		}

		wp_enqueue_script( 'jquery' );

		if ( isset( $_POST['order_id'] ) ) {
			$order = wc_get_order( sanitize_text_field($_POST['order_id']) );
		}

		if ( isset( $_POST['approve-flp'] ) ) {

			$order->add_order_note( 'Verificação EshieldBR (fraud_report) iniciada para o pedido ' . $order->get_id() . '.' );
			$this->write_debug_log( 'Verificação EshieldBR (fraud_report) iniciada para o pedido ' . $order->get_id() . '.' );			

			$token = $this->getTokenAuthorization();

			$url = 'https://api.eshieldbr.com.br/api/retorno/';

			$data = array(
				'eshield_id' => sanitize_text_field($_POST['eshield_id']),
				'transaction_id' => sanitize_text_field($_POST['order_id']),
			);

			$this->write_debug_log( 'Dados a serem enviado para EshieldBR(fraud_report): ' . serialize($data) );

			$request = wp_remote_post( $url, array(
				'timeout' => 45,
			    'body'    => $data,
			    'headers' => array(
			        'Authorization' => $token,
			        'cache-control' => 'no-cache',
			    ),
			) );

			if ( ! is_wp_error( $request ) ) {
				// Get the HTTP response
				$response = json_decode( wp_remote_retrieve_body( $request ) );

				// $response->resposta->erro = 0
				// $response->resposta->transaction_id = 16
				// 2020-11-27 12:52:43	$response->resposta->aprovado: REVIEW

				if ( is_object( $response ) ) {
					if( isset($response->resposta->aprovado) && $response->resposta->aprovado == 'APPROVE' ) {

						if( $this->approve_status && $order->get_status() != 'wc-completed' ) {
							$this->write_debug_log( 'O status da EshieldBR mudou de Em Análise para Aprovado e o status do pedido foi alterado.' );
							$order->add_order_note( 'O status da EshieldBR mudou de Em Análise para Aprovado e o status do pedido foi alterado.' );
							$order->update_status( $this->approve_status, '' );

							echo '<script>window.location.href = window.location.href;</script>';
						} else {
							//only add the note
							$this->write_debug_log( 'O status da EshieldBR mudou de Em Análise para Aprovado.' );
							$order->add_order_note( 'O status da EshieldBR mudou de Em Análise para Aprovado.' );

							echo '<script>window.location.href = window.location.href;</script>';
						}

						$result = get_post_meta( sanitize_text_field($_GET['post']), '_eshieldbr' );

						if ( !is_array( $result[0] ) && !is_object( $result[0] ) ) {
							$row = json_decode( $result[0] );
						} else {
							$row = $result[0];
						}

						if ( is_array( $result[0] ) ) {
							$row['eshieldbr_status'] = 'APPROVE';
						} else {
							$row->eshieldbr_status = 'APPROVE';
						}
						update_post_meta( sanitize_text_field($_GET['post']), '_eshieldbr', $row );
					}

					if( isset($response->resposta->aprovado) && $response->resposta->aprovado == 'DECLINE' ) {

						if( $this->reject_status ) {
							$this->write_debug_log( 'O status da EshieldBR mudou de Em Análise para Reprovado e o status do pedido foi alterado.' );
							$order->add_order_note( 'O status da EshieldBR mudou de Em Análise para Reprovado e o status do pedido foi alterado.' );
							$order->update_status( $this->reject_status, '' );

							echo '<script>window.location.href = window.location.href;</script>';
						} else {
							//just add the note
							$this->write_debug_log( 'O status da EshieldBR mudou de Em Análise para Reprovado.' );
							$order->add_order_note( 'O status da EshieldBR mudou de Em Análise para Reprovado.' );

							echo '<script>window.location.href = window.location.href;</script>';
						}

						$result = get_post_meta( sanitize_text_field($_GET['post']), '_eshieldbr' );

						$this->write_debug_log( 'Retorno da consulta da EshieldBR: ' . serialize($result) );

						if ( !is_array( $result[0] ) && !is_object( $result[0] ) ) {
							$row = json_decode( $result[0] );
						} else {
							$row = $result[0];
						}

						if ( is_array( $result[0] ) ) {
							$row['eshieldbr_status'] = 'DECLINE';
						} else {
							$row->eshieldbr_status = 'DECLINE';
						}
						update_post_meta( sanitize_text_field($_GET['post']), '_eshieldbr', $row );
					}

					if( isset($response->resposta->aprovado) && $response->resposta->aprovado == 'REVIEW' ) {
						$this->write_debug_log( 'O status da EshieldBR continua Em Análise.' );
						$order->add_order_note( 'O status da EshieldBR continua Em Análise.' );
					}
				}
			}
		}

		if ( isset( $_GET['post'] ) ) {
			$result = get_post_meta( sanitize_text_field($_GET['post']), '_eshieldbr' );

			if ( count( $result ) > 0 ) {
				if ( !is_array( $result[0] ) && !is_object( $result[0] ) && strpos( $result[0], '\\' ) ) {
					$result[0] = str_replace( '\\', '', $result[0] );
				}

				if ( !is_array( $result[0] ) && !is_object( $result[0] ) ) {
					$row = json_decode( $result[0] );
				} else {
					// recupera informações gravadas
					$row = $result[0];
				}

				if( is_array( $result[0] ) ) {
					if ( $row['eshieldbr_id'] != '' ) {
						$table = '
						<style type="text/css">
							.eshieldbr {width:100%;}
							.eshieldbr td{padding:10px 0; vertical-align:top}
							.flp-helper{text-decoration:none}

							/* color: Approve - #45b6af, Reject - #f3565d, Review - #dfba49 */
						</style>

						<table class="eshieldbr">
							<tr>
								<td colspan="2" style="text-align:center; background-color:#ab1b1c; border:1px solid #ab1b1c; padding-top:10px; padding-bottom:10px;">
									<a href="https://www.eshieldbr.com.br" target="_blank"><img src="'. plugins_url( '/assets/images/logo_200.png', WC_FLP_DIR ) .'" alt="EshieldBR" /></a>
								</td>
							</tr>';

						$location = array();
						if ( strlen( $row['ip_country'] ) == 2 ) {
							$location = array(
								$row['ip_continent'],
								$this->get_country_by_code( $row['ip_country'] ),
								$row['ip_region'],
								$row['ip_city']
							);

							$location = array_unique( $location );
						}

						switch( $row['eshieldbr_status'] ) {
							case 'REVIEW':
								$eshieldbr_status_display = "EM ANÁLISE";
								$color = 'dfba49';
								break;

							case 'DECLINE':
								$eshieldbr_status_display = "REPROVADO";
								$color = 'f3565d';
								break;

							case 'APPROVE':
								$eshieldbr_status_display = "APROVADO";
								$color = '45b6af';
								break;
						}

						$table .= '
							<tr>
								<td style="width:50%;" rowspan="2">
									<b>Score (de 0 a 100)</b> <a href="javascript:;" class="flp-helper" title="Risk score, 0 (low risk) - 100 (high risk)."><span class="dashicons dashicons-editor-help"></span></a><br/>
									<span style="color:#' . $color . ';font-size:28px; display:block;">' . $row['eshieldbr_score'] . '</span>
								</td>
								<td style="width:50%;">
									<b>Status EshieldBR</b> <a href="javascript:;" class="flp-helper" title="EshieldBR status."><span class="dashicons dashicons-editor-help"></span></a>
									<span style="color:#' . $color . ';font-size:28px; display:block;">' . $eshieldbr_status_display . '</span>
								</td>
							</tr>
							<tr>
								<td>
									<b>Transaction ID</b> <a href="javascript:;" class="flp-helper" title="Unique identifier for a transaction screened by EshieldBR system."><span class="dashicons dashicons-editor-help"></span></a>
									<p><a href="https://www.eshieldbr.com.br/merchant/transaction-details/' . $row['eshieldbr_id'] . '" target="_blank">' . $row['eshieldbr_id'] . '</a></p>
								</td>
							</tr>
							<tr>
								<td>
									<b>Endereço de IP</b>
									<p>' . $row['ip_address'] . '</p>
								</td>
								<td>
									<b>Localização do IP</b> <a href="javascript:;" class="flp-helper" title="Location of the IP address."><span class="dashicons dashicons-editor-help"></span></a>
									<p>' . implode( ', ', $location ) . ' <a href="https://www.geolocation.com/' . $row['ip_address'] . '" target="_blank">[Map]</a></p>
								</td>
							</tr>
							<tr>
								<td>
									<b>Mensagem</b> <a href="javascript:;" class="flp-helper" title="EshieldBR error message description."><span class="dashicons dashicons-editor-help"></span></a>
									<p>' . ( ( $row['eshieldbr_message'] ) ? $row['eshieldbr_error_code'] . ':' . $row['eshieldbr_message'] : '-' ) . '</p>
								</td>
							</tr>
							<tr>
								<td colspan="3">
									<p>Por favor, faça login na <a href="https://eshieldbrantifraude.freshdesk.com/support/login" target="_blank">Área Restrita EshieldBR</a> para obter mais informações sobre este pedido.</p>
								</td>
							</tr>
						</table>
						<form id="review-action" method="post">
							<p align="center">
								<input type="hidden" name="eshield_id" value="' . $row['eshieldbr_id'] . '" >
								<input type="hidden" name="order_id" value="' . $row['order_id'] . '" >
								<input type="hidden" id="new_status" name="new_status" value="" />
								<input type="hidden" id="feedback_note" name="feedback_note" value="" />';

						if( $row['eshieldbr_status'] == 'REVIEW' ) {
							$table .= '
							<input type="submit" name="approve-flp" id="approve-order" value="Consultar Novamente" style="padding:10px 5px; background:#89c2ff; color:#1e7482; border:1px solid #ccc; min-width:100px; cursor: pointer;" />';
						}

						$table .= '
							</p>
						</form>';

						echo '
						<script>
						jQuery(function(){
							jQuery("#woocommerce-order-items").before(\'<div class="metabox-holder"><div class="postbox"><h2>Detalhes EshieldBR</h2><blockquote>' . preg_replace( '/[\n]*/is', '', str_replace( '\'', '\\\'', $table ) ) . '</blockquote></div></div>\');

							jQuery("#reject-blacklist-order").click(function(){
								var note = prompt("Please enter the reason(s) for blacklisting this order. (Optional)");
								if(note !== null){
									jQuery("#feedback_note").val(note);
									jQuery("#new_status").val("reject_blacklist");
									jQuery("#review-action").submit();
								}
							});
						});
						</script>';
					} else {
						echo '
						<script>
						jQuery(function(){
							jQuery("#woocommerce-order-items").before(\'<div class="metabox-holder"><div class="postbox"><h2>Detalhes EshieldBR</h2><blockquote>Este pedido não foi rastreado pela EshieldBR1.</blockquote></div></div>\');
						});
						</script>';
					}
				}
			} else {
				echo '
				<script>
				jQuery(function(){
					jQuery("#woocommerce-order-items").before(\'<div class="metabox-holder"><div class="postbox"><h2>Detalhes EshieldBR</h2><blockquote>Este pedido não foi rastreado pela EshieldBR2.</blockquote></div></div>\');
				});
				</script>';
			}
		} else {
			echo '
			<script>
			jQuery(function(){
				jQuery("#woocommerce-order-items").before(\'<div class="metabox-holder"><div class="postbox"><h2>Detalhes EshieldBR</h2><blockquote>Este pedido não foi rastreado pela EshieldBR3.</blockquote></div></div>\');
			});
			</script>';
		}
	}


	/**
	 * Auto approve the order as the merchant mark the order as completed.
	 */
	public function order_status_completed( $order_id ) {
		$result = get_post_meta( $order_id, '_eshieldbr' );

		if ( !is_array( $result[0] ) && !is_object( $result[0] ) ) {
			$row = json_decode( $result[0] );
		} else {
			$row = $result[0];
		}

		$flp_id = is_array( $result[0] ) ? $row['eshieldbr_id'] : $row->eshieldbr_id;
		$request = wp_remote_get( 'https://api.eshieldbr.com.br/v1/order/feedback?' . http_build_query( array(
			'username'		=> $this->username,
			'password'		=> $this->password,
			'action'	=> 'APPROVE',
			'id'		=> $flp_id,
			'format'	=> 'json'
		) ) );

		if ( ! is_wp_error( $request ) ) {
			// Get the HTTP response
			$response = json_decode( wp_remote_retrieve_body( $request ) );

			if ( is_object( $response ) ) {
				if( $response->eshieldbr_error_code == '' || $response->eshieldbr_error_code == '304' || $response->eshieldbr_error_code == '305' ) {
					if ( is_array( $result[0] ) ) {
						$row['eshieldbr_status'] = 'APPROVE';
					} else {
						$row->eshieldbr_status = 'APPROVE';
					}
					update_post_meta( $order_id, '_eshieldbr', $row );
				}
			}
		}
	}


	/**
	 * Auto reject the order as the merchant mark the order as cancelled.
	 */
	public function order_status_cancelled( $order_id ) {
		$result = get_post_meta( $order_id, '_eshieldbr' );

		if ( !is_array( $result[0] ) && !is_object( $result[0] ) ) {
			$row = json_decode( $result[0] );
		} else {
			$row = $result[0];
		}

		$flp_id = is_array( $result[0] ) ? $row['eshieldbr_id'] : $row->eshieldbr_id;
		$request = wp_remote_get( 'https://api.eshieldbr.com.br/v1/order/feedback?' . http_build_query( array(
				'username'		=> $this->username,
				'password'		=> $this->password,
				'action'	=> 'DECLINE',
				'id'		=> $flp_id,
				'format'	=> 'json'
			) ) );

		if ( ! is_wp_error( $request ) ) {
			// Get the HTTP response
			$response = json_decode( wp_remote_retrieve_body( $request ) );

			if ( is_object( $response ) ) {
				if( $response->eshieldbr_error_code == '' || $response->eshieldbr_error_code == '304' || $response->eshieldbr_error_code == '306' ) {
					if ( is_array( $result[0] ) ) {
						$row['eshieldbr_status'] = 'DECLINE';
					} else {
						$row->eshieldbr_status = 'DECLINE';
					}
					update_post_meta( $order_id, '_eshieldbr', $row );
				}
			}
		}
	}


	/**
	 * Write to debug log to record details of process.
	 */
	public function write_debug_log( $message ) {
		if ( $this->debug_log != 'yes' ) {
			return;
		}

		if ( is_array( $message ) || is_object( $message ) ) {
			file_put_contents( ESHIELDBR_ROOT . 'eshieldbr.log', gmdate('Y-m-d H:i:s') . "\t" . print_r( $message, true ) . "\n", FILE_APPEND );
		} else {
			file_put_contents( ESHIELDBR_ROOT . 'eshieldbr.log', gmdate('Y-m-d H:i:s') . "\t" . $message . "\n", FILE_APPEND );
		}
	}

	/**
	 * Parse EshieldBR API result.
	 */
	private function parse_fraud_result( $result ) {
		if ( $result == 'Y' )
			return 'Yes';

		if ( $result == 'N' )
			return 'No';

		if ( $result == 'NA' )
			return '-';

		return $result;
	}


	/**
	 * Get country name by country code.
	 */
	private function get_country_by_code( $code ) {
		$countries = array( 'AF' => 'Afghanistan','AL' => 'Albania','DZ' => 'Algeria','AS' => 'American Samoa','AD' => 'Andorra','AO' => 'Angola','AI' => 'Anguilla','AQ' => 'Antarctica','AG' => 'Antigua and Barbuda','AR' => 'Argentina','AM' => 'Armenia','AW' => 'Aruba','AU' => 'Australia','AT' => 'Austria','AZ' => 'Azerbaijan','BS' => 'Bahamas','BH' => 'Bahrain','BD' => 'Bangladesh','BB' => 'Barbados','BY' => 'Belarus','BE' => 'Belgium','BZ' => 'Belize','BJ' => 'Benin','BM' => 'Bermuda','BT' => 'Bhutan','BO' => 'Bolivia','BA' => 'Bosnia and Herzegovina','BW' => 'Botswana','BV' => 'Bouvet Island','BR' => 'Brazil','IO' => 'British Indian Ocean Territory','BN' => 'Brunei Darussalam','BG' => 'Bulgaria','BF' => 'Burkina Faso','BI' => 'Burundi','KH' => 'Cambodia','CM' => 'Cameroon','CA' => 'Canada','CV' => 'Cape Verde','KY' => 'Cayman Islands','CF' => 'Central African Republic','TD' => 'Chad','CL' => 'Chile','CN' => 'China','CX' => 'Christmas Island','CC' => 'Cocos (Keeling) Islands','CO' => 'Colombia','KM' => 'Comoros','CG' => 'Congo','CK' => 'Cook Islands','CR' => 'Costa Rica','CI' => 'Cote D\'Ivoire','HR' => 'Croatia','CU' => 'Cuba','CY' => 'Cyprus','CZ' => 'Czech Republic','CD' => 'Democratic Republic of Congo','DK' => 'Denmark','DJ' => 'Djibouti','DM' => 'Dominica','DO' => 'Dominican Republic','TP' => 'East Timor','EC' => 'Ecuador','EG' => 'Egypt','SV' => 'El Salvador','GQ' => 'Equatorial Guinea','ER' => 'Eritrea','EE' => 'Estonia','ET' => 'Ethiopia','FK' => 'Falkland Islands (Malvinas)','FO' => 'Faroe Islands','FJ' => 'Fiji','FI' => 'Finland','FR' => 'France','FX' => 'France, Metropolitan','GF' => 'French Guiana','PF' => 'French Polynesia','TF' => 'French Southern Territories','GA' => 'Gabon','GM' => 'Gambia','GE' => 'Georgia','DE' => 'Germany','GH' => 'Ghana','GI' => 'Gibraltar','GR' => 'Greece','GL' => 'Greenland','GD' => 'Grenada','GP' => 'Guadeloupe','GU' => 'Guam','GT' => 'Guatemala','GN' => 'Guinea','GW' => 'Guinea-bissau','GY' => 'Guyana','HT' => 'Haiti','HM' => 'Heard and Mc Donald Islands','HN' => 'Honduras','HK' => 'Hong Kong','HU' => 'Hungary','IS' => 'Iceland','IN' => 'India','ID' => 'Indonesia','IR' => 'Iran (Islamic Republic of)','IQ' => 'Iraq','IE' => 'Ireland','IL' => 'Israel','IT' => 'Italy','JM' => 'Jamaica','JP' => 'Japan','JO' => 'Jordan','KZ' => 'Kazakhstan','KE' => 'Kenya','KI' => 'Kiribati','KR' => 'Korea, Republic of','KW' => 'Kuwait','KG' => 'Kyrgyzstan','LA' => 'Lao People\'s Democratic Republic','LV' => 'Latvia','LB' => 'Lebanon','LS' => 'Lesotho','LR' => 'Liberia','LY' => 'Libyan Arab Jamahiriya','LI' => 'Liechtenstein','LT' => 'Lithuania','LU' => 'Luxembourg','MO' => 'Macau','MK' => 'Macedonia','MG' => 'Madagascar','MW' => 'Malawi','MY' => 'Malaysia','MV' => 'Maldives','ML' => 'Mali','MT' => 'Malta','MH' => 'Marshall Islands','MQ' => 'Martinique','MR' => 'Mauritania','MU' => 'Mauritius','YT' => 'Mayotte','MX' => 'Mexico','FM' => 'Micronesia, Federated States of','MD' => 'Moldova, Republic of','MC' => 'Monaco','MN' => 'Mongolia','MS' => 'Montserrat','MA' => 'Morocco','MZ' => 'Mozambique','MM' => 'Myanmar','NA' => 'Namibia','NR' => 'Nauru','NP' => 'Nepal','NL' => 'Netherlands','AN' => 'Netherlands Antilles','NC' => 'New Caledonia','NZ' => 'New Zealand','NI' => 'Nicaragua','NE' => 'Niger','NG' => 'Nigeria','NU' => 'Niue','NF' => 'Norfolk Island','KP' => 'North Korea','MP' => 'Northern Mariana Islands','NO' => 'Norway','OM' => 'Oman','PK' => 'Pakistan','PW' => 'Palau','PA' => 'Panama','PG' => 'Papua New Guinea','PY' => 'Paraguay','PE' => 'Peru','PH' => 'Philippines','PN' => 'Pitcairn','PL' => 'Poland','PT' => 'Portugal','PR' => 'Puerto Rico','QA' => 'Qatar','RE' => 'Reunion','RO' => 'Romania','RU' => 'Russian Federation','RW' => 'Rwanda','KN' => 'Saint Kitts and Nevis','LC' => 'Saint Lucia','VC' => 'Saint Vincent and the Grenadines','WS' => 'Samoa','SM' => 'San Marino','ST' => 'Sao Tome and Principe','SA' => 'Saudi Arabia','SN' => 'Senegal','SC' => 'Seychelles','SL' => 'Sierra Leone','SG' => 'Singapore','SK' => 'Slovak Republic','SI' => 'Slovenia','SB' => 'Solomon Islands','SO' => 'Somalia','ZA' => 'South Africa','GS' => 'South Georgia And The South Sandwich Islands','ES' => 'Spain','LK' => 'Sri Lanka','SH' => 'St. Helena','PM' => 'St. Pierre and Miquelon','SD' => 'Sudan','SR' => 'Suriname','SJ' => 'Svalbard and Jan Mayen Islands','SZ' => 'Swaziland','SE' => 'Sweden','CH' => 'Switzerland','SY' => 'Syrian Arab Republic','TW' => 'Taiwan','TJ' => 'Tajikistan','TZ' => 'Tanzania, United Republic of','TH' => 'Thailand','TG' => 'Togo','TK' => 'Tokelau','TO' => 'Tonga','TT' => 'Trinidad and Tobago','TN' => 'Tunisia','TR' => 'Turkey','TM' => 'Turkmenistan','TC' => 'Turks and Caicos Islands','TV' => 'Tuvalu','UG' => 'Uganda','UA' => 'Ukraine','AE' => 'United Arab Emirates','GB' => 'United Kingdom','US' => 'United States','UM' => 'United States Minor Outlying Islands','UY' => 'Uruguay','UZ' => 'Uzbekistan','VU' => 'Vanuatu','VA' => 'Vatican City State (Holy See)','VE' => 'Venezuela','VN' => 'Viet Nam','VG' => 'Virgin Islands (British)','VI' => 'Virgin Islands (U.S.)','WF' => 'Wallis and Futuna Islands','EH' => 'Western Sahara','YE' => 'Yemen','YU' => 'Yugoslavia','ZM' => 'Zambia','ZW' => 'Zimbabwe' );

		return ( isset( $countries[$code] ) ) ? $countries[$code] : NULL;
	}


	/**
	 * Get plugin settings.
	 */
	private function get_setting( $key ) {
		return get_option( 'wc_settings_woocommerce-eshieldbr_' . $key );
	}


	/**
	 * Update plugin settings.
	 */
	private function update_setting( $key, $value = null ) {
		return update_option( 'wc_settings_woocommerce-eshieldbr_' . $key, $value );
	}


	/**
	 * Hash a string to send to EshieldBR API.
	 */
	private function hash_string( $s ) {
		$hash = 'eshieldbr_' . $s;

		for( $i = 0; $i < 65536; $i++ )
			$hash = sha1( 'eshieldbr_' . $hash );

		return $hash;
	}

	private function limpaCPF_CNPJ($valor) {
		$valor = preg_replace('/[^0-9]/', '', $valor);
	   return $valor;
	}

	/**
	 * Validate a credit card number.
	 */
	private function is_credit_card( $number ) {
		$card_type = null;

		$patterns = array(
			'/^4\d{12}(\d\d\d){0,1}$/'			=> 'visa',
			'/^(5[12345]|2[234567])\d{14}$/'	=> 'mastercard',
			'/^3[47]\d{13}$/'					=> 'amex',
			'/^6011\d{12}$/'					=> 'discover',
			'/^30[012345]\d{11}$/'				=> 'diners',
			'/^3[68]\d{12}$/'					=> 'diners'
		);

		foreach ( $patterns as $regex => $type ) {
			if ( @preg_match( $regex, (string)$number ) ) {
				$card_type = $type;
				break;
			}
		}

		if ( !$card_type ) {
			return false;
		}

		$rev_code = strrev( $number );
		$checksum = 0;

		for ( $i = 0; $i < strlen( $rev_code ); $i++ ) {
			$current = intval ( $rev_code[$i] );

			if ( $i & 1 ) {
				$current *= 2;
			}

			$checksum += $current % 10;

			if ( $current > 9 ) {
				$checksum += 1;
			}
		}

		return ( $checksum % 10 == 0 ) ? true : false;
	}


	/**
	 * Get order notes details.
	 */
	private function get_order_notes( $order_id ){
		global $wpdb;

		$table_perfixed = $wpdb->prefix . 'comments';
		$results = $wpdb->get_results("
			SELECT * FROM $table_perfixed
			WHERE `comment_post_ID` = $order_id
			AND `comment_type` LIKE 'order_note'
			AND `comment_content` LIKE 'EshieldBR validation completed%' 
		");

		if ( count( $results ) > 0 ) {
			foreach ( $results as $note ) {
				$order_note[] = array(
					'note_id'      => $note->comment_ID,
					'note_date'    => $note->comment_date,
					'note_author'  => $note->comment_author,
					'note_content' => $note->comment_content,
				);
			}
			return $order_note;
		} else {
			return false;
		}
	}

	private function http($url, $fields = ''){
		$ch = curl_init();

		if ($fields) {
			$data_string = json_encode($fields);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
		}

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FAILONERROR, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_AUTOREFERER, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_HTTP_VERSION, '1.1');
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'Content-Length: ' . strlen($data_string))
		);

		$response = curl_exec($ch);

		if (!curl_errno($ch)) {
			return $response;
		}

		return false;
	}
}

endif;
