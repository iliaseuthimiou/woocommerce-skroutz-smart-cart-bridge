<?php
/**
 * Core plugin class.
 *
 * @package SkroutzSmartCartBridge
 */

namespace IliasEuthimiou\SkroutzSmartCartBridge;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Throwable;
use WC_Order;
use WC_Product;
use WC_Product_Variation;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Receives Skroutz order webhook events and mirrors them to WooCommerce.
 */
final class Plugin {

	private const REST_NAMESPACE = 'skroutz-smart-cart-bridge/v1';
	private const REST_ROUTE     = '/orders';
	private const LOG_SOURCE     = 'skroutz-smart-cart-bridge';

	private const OPTION_WEBHOOK_SECRET      = 'sscb_webhook_secret';
	private const OPTION_PRODUCT_MODE        = 'sscb_product_match_mode';
	private const OPTION_PRODUCT_META_KEY    = 'sscb_product_meta_key';
	private const OPTION_VARIATION_MODE      = 'sscb_variation_match_mode';
	private const OPTION_VARIATION_META_KEY  = 'sscb_variation_meta_key';
	private const OPTION_DEBUG_LOGGING       = 'sscb_debug_logging';

	private const META_ORDER_CODE = '_sscb_order_code';
	private const META_STATE      = '_sscb_state';
	private const META_PREFIX     = '_sscb_';

	/** @var self|null */
	private static $instance = null;

	/**
	 * Return the single plugin instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Create the webhook secret during activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( ! get_option( self::OPTION_WEBHOOK_SECRET ) ) {
			add_option( self::OPTION_WEBHOOK_SECRET, self::generate_secret(), '', false );
		}
	}

	/**
	 * Declare compatibility with WooCommerce features used by this plugin.
	 *
	 * @return void
	 */
	public static function declare_woocommerce_compatibility(): void {
		if ( class_exists( FeaturesUtil::class ) ) {
			FeaturesUtil::declare_compatibility( 'custom_order_tables', SSCB_PLUGIN_FILE, true );
		}
	}

	/**
	 * Register hooks.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
		add_action( 'admin_post_sscb_regenerate_secret', array( $this, 'regenerate_webhook_secret' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );

		add_action( 'add_meta_boxes', array( $this, 'add_order_metabox' ) );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_skroutz_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_legacy_order_column' ), 10, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_skroutz_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_hpos_order_column' ), 10, 2 );
		add_action( 'admin_head', array( $this, 'admin_order_styles' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( SSCB_PLUGIN_FILE ), array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'skroutz-smart-cart-bridge',
			false,
			dirname( plugin_basename( SSCB_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Add a settings shortcut to the Plugins screen.
	 *
	 * @param array<string,string> $links Existing plugin links.
	 * @return array<string,string>
	 */
	public function plugin_action_links( array $links ): array {
		$url = admin_url( 'admin.php?page=skroutz-smart-cart-bridge' );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'skroutz-smart-cart-bridge' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Show a dependency notice when WooCommerce is inactive.
	 *
	 * @return void
	 */
	public function woocommerce_missing_notice(): void {
		if ( class_exists( 'WooCommerce' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Skroutz Smart Cart Bridge requires WooCommerce to be installed and active.', 'skroutz-smart-cart-bridge' );
		echo '</p></div>';
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'sscb_settings',
			self::OPTION_PRODUCT_MODE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_match_mode' ),
				'default'           => 'id',
			)
		);

		register_setting(
			'sscb_settings',
			self::OPTION_PRODUCT_META_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_meta_key_setting' ),
				'default'           => '',
			)
		);

		register_setting(
			'sscb_settings',
			self::OPTION_VARIATION_MODE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_match_mode' ),
				'default'           => 'id',
			)
		);

		register_setting(
			'sscb_settings',
			self::OPTION_VARIATION_META_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_meta_key_setting' ),
				'default'           => '',
			)
		);

		register_setting(
			'sscb_settings',
			self::OPTION_DEBUG_LOGGING,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => static function ( $value ): int {
					return empty( $value ) ? 0 : 1;
				},
				'default'           => 0,
			)
		);
	}

	/**
	 * Sanitize a matching mode.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_match_mode( $value ): string {
		$value = is_string( $value ) ? $value : '';

		return in_array( $value, array( 'id', 'sku', 'meta' ), true ) ? $value : 'id';
	}

	/**
	 * Sanitize a configured product meta key without changing its case.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_meta_key_setting( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( sanitize_text_field( wp_unslash( $value ) ) );

		return preg_match( '/^[A-Za-z0-9_:.-]+$/', $value ) ? $value : '';
	}

	/**
	 * Add the settings page.
	 *
	 * @return void
	 */
	public function add_settings_page(): void {
		if ( class_exists( 'WooCommerce' ) ) {
			add_submenu_page(
				'woocommerce',
				__( 'Skroutz Smart Cart Bridge', 'skroutz-smart-cart-bridge' ),
				__( 'Skroutz Bridge', 'skroutz-smart-cart-bridge' ),
				'manage_woocommerce',
				'skroutz-smart-cart-bridge',
				array( $this, 'render_settings_page' )
			);
			return;
		}

		add_options_page(
			__( 'Skroutz Smart Cart Bridge', 'skroutz-smart-cart-bridge' ),
			__( 'Skroutz Bridge', 'skroutz-smart-cart-bridge' ),
			'manage_options',
			'skroutz-smart-cart-bridge',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! $this->current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to manage these settings.', 'skroutz-smart-cart-bridge' ) );
		}

		$product_mode       = (string) get_option( self::OPTION_PRODUCT_MODE, 'id' );
		$product_meta_key   = (string) get_option( self::OPTION_PRODUCT_META_KEY, '' );
		$variation_mode     = (string) get_option( self::OPTION_VARIATION_MODE, 'id' );
		$variation_meta_key = (string) get_option( self::OPTION_VARIATION_META_KEY, '' );
		$debug_logging      = (int) get_option( self::OPTION_DEBUG_LOGGING, 0 );
		$webhook_url        = $this->get_webhook_url();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Skroutz Smart Cart Bridge', 'skroutz-smart-cart-bridge' ); ?></h1>

			<?php if ( isset( $_GET['secret-regenerated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php esc_html_e( 'A new webhook URL was generated. Update it immediately in the Skroutz merchant panel.', 'skroutz-smart-cart-bridge' ); ?>
				</p></div>
			<?php endif; ?>

			<div class="notice notice-warning inline"><p>
				<strong><?php esc_html_e( 'Keep the full webhook URL private.', 'skroutz-smart-cart-bridge' ); ?></strong>
				<?php esc_html_e( 'Its secret key authorizes incoming order events.', 'skroutz-smart-cart-bridge' ); ?>
			</p></div>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sscb-webhook-url"><?php esc_html_e( 'Webhook URL', 'skroutz-smart-cart-bridge' ); ?></label></th>
					<td>
						<input id="sscb-webhook-url" type="text" class="large-text code" readonly value="<?php echo esc_attr( $webhook_url ); ?>" />
						<p class="description"><?php esc_html_e( 'Register this complete HTTPS URL in your Skroutz Marketplace settings.', 'skroutz-smart-cart-bridge' ); ?></p>
					</td>
				</tr>
			</table>

			<form method="post" action="options.php">
				<?php settings_fields( 'sscb_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Product matching', 'skroutz-smart-cart-bridge' ); ?></th>
						<td>
							<?php $this->render_match_mode_fields( self::OPTION_PRODUCT_MODE, $product_mode ); ?>
							<p>
								<label for="sscb-product-meta-key"><?php esc_html_e( 'Product meta key:', 'skroutz-smart-cart-bridge' ); ?></label>
								<input id="sscb-product-meta-key" type="text" class="regular-text code" name="<?php echo esc_attr( self::OPTION_PRODUCT_META_KEY ); ?>" value="<?php echo esc_attr( $product_meta_key ); ?>" placeholder="_skroutz_shop_uid" />
							</p>
							<p class="description"><?php esc_html_e( 'The incoming shop_uid must match the selected identifier.', 'skroutz-smart-cart-bridge' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Variation matching', 'skroutz-smart-cart-bridge' ); ?></th>
						<td>
							<?php $this->render_match_mode_fields( self::OPTION_VARIATION_MODE, $variation_mode ); ?>
							<p>
								<label for="sscb-variation-meta-key"><?php esc_html_e( 'Variation meta key:', 'skroutz-smart-cart-bridge' ); ?></label>
								<input id="sscb-variation-meta-key" type="text" class="regular-text code" name="<?php echo esc_attr( self::OPTION_VARIATION_META_KEY ); ?>" value="<?php echo esc_attr( $variation_meta_key ); ?>" placeholder="_skroutz_variation_uid" />
							</p>
							<p class="description"><?php esc_html_e( 'Used when shop_variation_uid is present in the webhook.', 'skroutz-smart-cart-bridge' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Debug logging', 'skroutz-smart-cart-bridge' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_DEBUG_LOGGING ); ?>" value="1" <?php checked( $debug_logging, 1 ); ?> />
								<?php esc_html_e( 'Write event summaries to WooCommerce logs', 'skroutz-smart-cart-bridge' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Customer names and addresses are never written to the plugin log.', 'skroutz-smart-cart-bridge' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Webhook secret', 'skroutz-smart-cart-bridge' ); ?></h2>
			<p><?php esc_html_e( 'Regenerating the secret immediately invalidates the previous webhook URL.', 'skroutz-smart-cart-bridge' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sscb_regenerate_secret" />
				<?php wp_nonce_field( 'sscb_regenerate_secret' ); ?>
				<?php submit_button( __( 'Generate new webhook URL', 'skroutz-smart-cart-bridge' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render radio buttons for a product matching option.
	 *
	 * @param string $option_name Option name.
	 * @param string $selected    Selected value.
	 * @return void
	 */
	private function render_match_mode_fields( string $option_name, string $selected ): void {
		$choices = array(
			'id'   => __( 'WooCommerce ID', 'skroutz-smart-cart-bridge' ),
			'sku'  => __( 'SKU', 'skroutz-smart-cart-bridge' ),
			'meta' => __( 'Custom meta field', 'skroutz-smart-cart-bridge' ),
		);

		foreach ( $choices as $value => $label ) {
			?>
			<label style="margin-right:18px;">
				<input type="radio" name="<?php echo esc_attr( $option_name ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $selected, $value ); ?> />
				<?php echo esc_html( $label ); ?>
			</label>
			<?php
		}
	}

	/**
	 * Generate a fresh webhook secret.
	 *
	 * @return string
	 */
	private static function generate_secret(): string {
		return wp_generate_password( 48, false, false );
	}

	/**
	 * Return the stored secret, creating one if needed.
	 *
	 * @return string
	 */
	private function get_webhook_secret(): string {
		$secret = (string) get_option( self::OPTION_WEBHOOK_SECRET, '' );

		if ( '' === $secret ) {
			$secret = self::generate_secret();
			if ( ! add_option( self::OPTION_WEBHOOK_SECRET, $secret, '', false ) ) {
				$secret = (string) get_option( self::OPTION_WEBHOOK_SECRET, '' );
			}
		}

		return $secret;
	}

	/**
	 * Build the authenticated webhook URL.
	 *
	 * @return string
	 */
	private function get_webhook_url(): string {
		return add_query_arg(
			'key',
			rawurlencode( $this->get_webhook_secret() ),
			rest_url( self::REST_NAMESPACE . self::REST_ROUTE )
		);
	}

	/**
	 * Regenerate the webhook secret after a verified admin request.
	 *
	 * @return void
	 */
	public function regenerate_webhook_secret(): void {
		if ( ! $this->current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'skroutz-smart-cart-bridge' ) );
		}

		check_admin_referer( 'sscb_regenerate_secret' );
		update_option( self::OPTION_WEBHOOK_SECRET, self::generate_secret(), false );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'               => 'skroutz-smart-cart-bridge',
					'secret-regenerated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Check whether the current administrator can manage the bridge.
	 *
	 * @return bool
	 */
	private function current_user_can_manage(): bool {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Register the order webhook route.
	 *
	 * @return void
	 */
	public function register_rest_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'authorize_webhook' ),
			)
		);
	}

	/**
	 * Authorize an incoming webhook through the secret URL parameter.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return true|WP_Error
	 */
	public function authorize_webhook( WP_REST_Request $request ) {
		$query    = $request->get_query_params();
		$provided = isset( $query['key'] ) && is_string( $query['key'] ) ? $query['key'] : '';
		$expected = $this->get_webhook_secret();

		if ( '' === $provided || ! hash_equals( $expected, $provided ) ) {
			return new WP_Error(
				'sscb_unauthorized',
				__( 'Invalid webhook credentials.', 'skroutz-smart-cart-bridge' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Process an incoming webhook event.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_webhook( WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return new WP_Error(
				'sscb_woocommerce_unavailable',
				__( 'WooCommerce is unavailable.', 'skroutz-smart-cart-bridge' ),
				array( 'status' => 503 )
			);
		}

		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) || empty( $payload['event_type'] ) || empty( $payload['order'] ) || ! is_array( $payload['order'] ) ) {
			return new WP_Error(
				'sscb_invalid_payload',
				__( 'The webhook payload is invalid.', 'skroutz-smart-cart-bridge' ),
				array( 'status' => 400 )
			);
		}

		$event_type = sanitize_key( (string) $payload['event_type'] );
		$order_code = isset( $payload['order']['code'] ) ? sanitize_text_field( (string) $payload['order']['code'] ) : '';

		if ( '' === $order_code ) {
			return new WP_Error(
				'sscb_missing_order_code',
				__( 'The webhook does not contain an order code.', 'skroutz-smart-cart-bridge' ),
				array( 'status' => 400 )
			);
		}

		$this->log_event_summary( $payload );

		try {
			if ( 'new_order' === $event_type ) {
				$result = $this->create_order_from_payload( $payload );
			} elseif ( 'order_updated' === $event_type ) {
				$result = $this->update_order_from_payload( $payload );
			} else {
				return new WP_Error(
					'sscb_unsupported_event',
					__( 'This webhook event type is not supported.', 'skroutz-smart-cart-bridge' ),
					array( 'status' => 400 )
				);
			}
		} catch ( Throwable $exception ) {
			$this->log( 'error', 'Unhandled webhook failure.', array( 'order_code' => $order_code ) );

			return new WP_Error(
				'sscb_processing_failed',
				__( 'The webhook could not be processed.', 'skroutz-smart-cart-bridge' ),
				array( 'status' => 500 )
			);
		}

		if ( is_wp_error( $result ) ) {
			$this->log(
				'error',
				$result->get_error_message(),
				array(
					'order_code' => $order_code,
					'error_code' => $result->get_error_code(),
				)
			);

			return $result;
		}

		return new WP_REST_Response(
			array(
				'status'     => 'ok',
				'event_type' => $event_type,
				'order_code' => $order_code,
			),
			200
		);
	}

	/**
	 * Create a WooCommerce order from a new_order event.
	 *
	 * @param array<string,mixed> $payload Webhook payload.
	 * @return WC_Order|WP_Error
	 */
	private function create_order_from_payload( array $payload ) {
		$order_data = $payload['order'];
		$order_code = sanitize_text_field( (string) $order_data['code'] );
		$existing   = $this->find_order_by_code( $order_code );

		if ( $existing ) {
			$this->synchronize_existing_order( $existing, $payload );
			$this->log( 'info', 'Duplicate new_order event handled idempotently.', array( 'order_code' => $order_code ) );
			return $existing;
		}

		if ( ! $this->acquire_order_lock( $order_code ) ) {
			$existing = $this->find_order_by_code( $order_code );
			if ( $existing ) {
				return $existing;
			}

			return new WP_Error(
				'sscb_order_locked',
				__( 'This order is already being imported. Retry the request.', 'skroutz-smart-cart-bridge' ),
				array( 'status' => 409 )
			);
		}

		$order = null;

		try {
			$existing = $this->find_order_by_code( $order_code );
			if ( $existing ) {
				return $existing;
			}

			$resolved_items = $this->resolve_line_items( $order_data );
			if ( is_wp_error( $resolved_items ) ) {
				return $resolved_items;
			}

			$order = wc_create_order();
			if ( is_wp_error( $order ) ) {
				return $order;
			}

			$order->set_created_via( 'skroutz-smart-cart-bridge' );
			$order->set_currency( 'EUR' );
			$this->set_customer_addresses( $order, $order_data );

			$order->update_meta_data( '_wc_order_attribution_source_type', 'marketplace' );
			$order->update_meta_data( '_wc_order_attribution_utm_source', 'skroutz' );
			$order->update_meta_data( '_wc_order_attribution_session_entry', 'skroutz' );

			foreach ( $resolved_items as $resolved_item ) {
				$order->add_product(
					$resolved_item['product'],
					$resolved_item['quantity'],
					array(
						'subtotal' => $resolved_item['total'],
						'total'    => $resolved_item['total'],
					)
				);
			}

			$this->save_order_metadata( $order, $order_data, $payload );

			if ( ! empty( $order_data['comments'] ) ) {
				$order->add_order_note( sanitize_textarea_field( (string) $order_data['comments'] ) );
			}

			$order->calculate_totals( false );
			$order->save();

			$skroutz_state = isset( $order_data['state'] ) ? sanitize_key( (string) $order_data['state'] ) : 'open';
			$wc_status     = $this->map_state_for_new_order( $skroutz_state );

			if ( $wc_status !== $order->get_status() ) {
				$order->update_status(
					$wc_status,
					sprintf(
						/* translators: %s: Skroutz order state. */
						__( 'Created from a Skroutz Smart Cart webhook with state: %s.', 'skroutz-smart-cart-bridge' ),
						$skroutz_state
					)
				);
			}

			$this->log(
				'info',
				'WooCommerce order created.',
				array(
					'order_code' => $order_code,
					'order_id'   => $order->get_id(),
				)
			);

			return $order;
		} catch ( Throwable $exception ) {
			if ( $order instanceof WC_Order && $order->get_id() ) {
				$order->delete( true );
			}

			return new WP_Error(
				'sscb_order_creation_failed',
				__( 'The WooCommerce order could not be created.', 'skroutz-smart-cart-bridge' ),
				array( 'status' => 500 )
			);
		} finally {
			$this->release_order_lock( $order_code );
		}
	}

	/**
	 * Update an existing order from an order_updated event.
	 *
	 * @param array<string,mixed> $payload Webhook payload.
	 * @return WC_Order|WP_Error
	 */
	private function update_order_from_payload( array $payload ) {
		$order_code = sanitize_text_field( (string) $payload['order']['code'] );
		$order      = $this->find_order_by_code( $order_code );

		if ( ! $order ) {
			return new WP_Error(
				'sscb_order_not_found',
				__( 'The matching WooCommerce order does not exist yet. Retry the request.', 'skroutz-smart-cart-bridge' ),
				array( 'status' => 409 )
			);
		}

		$this->synchronize_existing_order( $order, $payload );

		return $order;
	}

	/**
	 * Synchronize state and shipment metadata on an existing order.
	 *
	 * @param WC_Order           $order   WooCommerce order.
	 * @param array<string,mixed> $payload Webhook payload.
	 * @return void
	 */
	private function synchronize_existing_order( WC_Order $order, array $payload ): void {
		if ( $this->is_stale_event( $order, $payload ) ) {
			$this->log(
				'info',
				'Older webhook event ignored.',
				array( 'order_code' => (string) $order->get_meta( self::META_ORDER_CODE ) )
			);
			return;
		}

		$order_data     = $payload['order'];
		$previous_state = (string) $order->get_meta( self::META_STATE );
		$new_state      = isset( $order_data['state'] ) ? sanitize_key( (string) $order_data['state'] ) : $previous_state;

		if ( isset( $payload['changes']['state']['new'] ) ) {
			$new_state = sanitize_key( (string) $payload['changes']['state']['new'] );
		}

		$this->save_order_metadata( $order, $order_data, $payload );
		$this->save_changed_metadata( $order, $payload );

		if ( '' !== $new_state ) {
			$order->update_meta_data( self::META_STATE, $new_state );
		}

		$order->save();

		if ( $new_state !== $previous_state ) {
			$this->apply_state_transition( $order, $new_state );
			$this->log(
				'info',
				'Skroutz order state synchronized.',
				array(
					'order_code' => (string) $order->get_meta( self::META_ORDER_CODE ),
					'state'      => $new_state,
				)
			);
		}
	}

	/**
	 * Resolve and validate every line item before an order is created.
	 *
	 * @param array<string,mixed> $order_data Skroutz order data.
	 * @return array<int,array{product:WC_Product,quantity:int,total:string}>|WP_Error
	 */
	private function resolve_line_items( array $order_data ) {
		$items = isset( $order_data['line_items'] ) && is_array( $order_data['line_items'] ) ? $order_data['line_items'] : array();

		if ( empty( $items ) ) {
			return new WP_Error(
				'sscb_no_line_items',
				__( 'The order does not contain any line items.', 'skroutz-smart-cart-bridge' ),
				array( 'status' => 422 )
			);
		}

		$resolved = array();

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['shop_uid'] ) || '' === trim( (string) $item['shop_uid'] ) ) {
				return new WP_Error(
					'sscb_missing_shop_uid',
					sprintf(
						/* translators: %d: Line-item position. */
						__( 'Line item %d does not contain shop_uid.', 'skroutz-smart-cart-bridge' ),
						$index + 1
					),
					array( 'status' => 422 )
				);
			}

			$product = $this->resolve_item_product( $item );
			if ( is_wp_error( $product ) ) {
				return $product;
			}

			$quantity = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 1;
			if ( $quantity < 1 ) {
				return new WP_Error(
					'sscb_invalid_quantity',
					__( 'A line item contains an invalid quantity.', 'skroutz-smart-cart-bridge' ),
					array( 'status' => 422 )
				);
			}

			$total = null;
			if ( isset( $item['total_price'] ) && is_numeric( $item['total_price'] ) ) {
				$total = wc_format_decimal( $item['total_price'], wc_get_price_decimals() );
			} elseif ( isset( $item['unit_price'] ) && is_numeric( $item['unit_price'] ) ) {
				$total = wc_format_decimal( (float) $item['unit_price'] * $quantity, wc_get_price_decimals() );
			}

			if ( null === $total || (float) $total < 0 ) {
				return new WP_Error(
					'sscb_invalid_line_total',
					__( 'A line item contains an invalid price.', 'skroutz-smart-cart-bridge' ),
					array( 'status' => 422 )
				);
			}

			$resolved[] = array(
				'product'  => $product,
				'quantity' => $quantity,
				'total'    => $total,
			);
		}

		return $resolved;
	}

	/**
	 * Resolve the product or variation represented by one webhook item.
	 *
	 * @param array<string,mixed> $item Line item.
	 * @return WC_Product|WP_Error
	 */
	private function resolve_item_product( array $item ) {
		$product_uid = trim( (string) $item['shop_uid'] );
		$product     = $this->find_product(
			$product_uid,
			(string) get_option( self::OPTION_PRODUCT_MODE, 'id' ),
			(string) get_option( self::OPTION_PRODUCT_META_KEY, '' )
		);

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		if ( ! $product ) {
			return new WP_Error(
				'sscb_product_not_found',
				sprintf(
					/* translators: %s: Incoming product identifier. */
					__( 'No WooCommerce product matches shop_uid %s.', 'skroutz-smart-cart-bridge' ),
					$product_uid
				),
				array( 'status' => 422 )
			);
		}

		$variation_uid = $this->get_variation_uid( $item );
		if ( '' === $variation_uid ) {
			if ( $product->is_type( 'variable' ) ) {
				return new WP_Error(
					'sscb_variation_uid_missing',
					__( 'A variable product line item does not contain shop_variation_uid.', 'skroutz-smart-cart-bridge' ),
					array( 'status' => 422 )
				);
			}

			return $product;
		}

		$variation = $this->find_product(
			$variation_uid,
			(string) get_option( self::OPTION_VARIATION_MODE, 'id' ),
			(string) get_option( self::OPTION_VARIATION_META_KEY, '' )
		);

		if ( is_wp_error( $variation ) ) {
			return $variation;
		}

		if ( ! $variation instanceof WC_Product_Variation ) {
			return new WP_Error(
				'sscb_variation_not_found',
				sprintf(
					/* translators: %s: Incoming variation identifier. */
					__( 'No WooCommerce variation matches shop_variation_uid %s.', 'skroutz-smart-cart-bridge' ),
					$variation_uid
				),
				array( 'status' => 422 )
			);
		}

		if ( $product instanceof WC_Product_Variation ) {
			if ( $product->get_id() !== $variation->get_id() ) {
				return new WP_Error(
					'sscb_variation_mismatch',
					__( 'The resolved product and variation identifiers do not match.', 'skroutz-smart-cart-bridge' ),
					array( 'status' => 422 )
				);
			}
		} elseif ( $variation->get_parent_id() !== $product->get_id() ) {
			return new WP_Error(
				'sscb_variation_parent_mismatch',
				__( 'The resolved variation does not belong to the resolved parent product.', 'skroutz-smart-cart-bridge' ),
				array( 'status' => 422 )
			);
		}

		return $variation;
	}

	/**
	 * Extract a variation identifier from supported webhook fields.
	 *
	 * @param array<string,mixed> $item Line item.
	 * @return string
	 */
	private function get_variation_uid( array $item ): string {
		if ( isset( $item['shop_variation_uid'] ) && '' !== trim( (string) $item['shop_variation_uid'] ) ) {
			return trim( (string) $item['shop_variation_uid'] );
		}

		if ( isset( $item['size']['shop_variation_uid'] ) && '' !== trim( (string) $item['size']['shop_variation_uid'] ) ) {
			return trim( (string) $item['size']['shop_variation_uid'] );
		}

		return '';
	}

	/**
	 * Find one product using an ID, SKU, or exact custom-meta match.
	 *
	 * @param string $identifier Incoming identifier.
	 * @param string $mode       Matching mode.
	 * @param string $meta_key   Meta key for meta mode.
	 * @return WC_Product|WP_Error|null
	 */
	private function find_product( string $identifier, string $mode, string $meta_key ) {
		$product_id = 0;

		if ( 'id' === $mode ) {
			if ( ! preg_match( '/^[0-9]+$/D', $identifier ) || (int) $identifier < 1 ) {
				return null;
			}
			$product_id = (int) $identifier;
		} elseif ( 'sku' === $mode ) {
			$product_id = (int) wc_get_product_id_by_sku( $identifier );
		} elseif ( 'meta' === $mode ) {
			if ( '' === $meta_key ) {
				return new WP_Error(
					'sscb_meta_key_missing',
					__( 'Custom-meta matching is selected, but its meta key is empty.', 'skroutz-smart-cart-bridge' ),
					array( 'status' => 500 )
				);
			}

			$matches = get_posts(
				array(
					'post_type'      => array( 'product', 'product_variation' ),
					'post_status'    => array( 'publish', 'private' ),
					'fields'         => 'ids',
					'posts_per_page' => 2,
					'no_found_rows'  => true,
					'meta_key'       => $meta_key,
					'meta_value'     => $identifier,
				)
			);

			if ( count( $matches ) > 1 ) {
				return new WP_Error(
					'sscb_ambiguous_product_match',
					__( 'More than one product has the same configured marketplace identifier.', 'skroutz-smart-cart-bridge' ),
					array( 'status' => 422 )
				);
			}

			$product_id = empty( $matches ) ? 0 : (int) $matches[0];
		}

		return $product_id > 0 ? wc_get_product( $product_id ) : null;
	}

	/**
	 * Set billing and shipping details from the webhook customer object.
	 *
	 * @param WC_Order            $order      WooCommerce order.
	 * @param array<string,mixed> $order_data Skroutz order data.
	 * @return void
	 */
	private function set_customer_addresses( WC_Order $order, array $order_data ): void {
		$customer = isset( $order_data['customer'] ) && is_array( $order_data['customer'] ) ? $order_data['customer'] : array();
		$address  = isset( $customer['address'] ) && is_array( $customer['address'] ) ? $customer['address'] : array();

		$street = trim(
			( isset( $address['street_name'] ) ? (string) $address['street_name'] : '' ) . ' ' .
			( isset( $address['street_number'] ) ? (string) $address['street_number'] : '' )
		);

		$billing = array(
			'first_name' => isset( $customer['first_name'] ) ? sanitize_text_field( (string) $customer['first_name'] ) : '',
			'last_name'  => isset( $customer['last_name'] ) ? sanitize_text_field( (string) $customer['last_name'] ) : '',
			'address_1'  => sanitize_text_field( $street ),
			'address_2'  => isset( $address['floor'] ) ? sanitize_text_field( (string) $address['floor'] ) : '',
			'city'       => isset( $address['city'] ) ? sanitize_text_field( (string) $address['city'] ) : '',
			'state'      => isset( $address['region'] ) ? sanitize_text_field( (string) $address['region'] ) : '',
			'postcode'   => isset( $address['zip'] ) ? sanitize_text_field( (string) $address['zip'] ) : '',
			'country'    => isset( $address['country_code'] ) ? strtoupper( sanitize_text_field( (string) $address['country_code'] ) ) : '',
		);

		if ( ! empty( $customer['email'] ) ) {
			$billing['email'] = sanitize_email( (string) $customer['email'] );
		}

		if ( ! empty( $customer['phone'] ) ) {
			$billing['phone'] = sanitize_text_field( (string) $customer['phone'] );
		}

		$order->set_address( $billing, 'billing' );
		$order->set_address( array_diff_key( $billing, array_flip( array( 'email', 'phone' ) ) ), 'shipping' );
	}

	/**
	 * Save supported order metadata.
	 *
	 * @param WC_Order            $order      WooCommerce order.
	 * @param array<string,mixed> $order_data Skroutz order data.
	 * @param array<string,mixed> $payload    Complete webhook payload.
	 * @return void
	 */
	private function save_order_metadata( WC_Order $order, array $order_data, array $payload ): void {
		$fields = array(
			'code'                    => 'order_code',
			'state'                   => 'state',
			'courier'                 => 'courier',
			'courier_voucher'         => 'courier_voucher',
			'courier_tracking_codes'  => 'courier_tracking_codes',
			'created_at'              => 'created_at',
			'expires_at'              => 'expires_at',
			'dispatch_until'          => 'dispatch_until',
			'express'                 => 'express',
			'gift_wrap'               => 'gift_wrap',
			'store_pickup'            => 'store_pickup',
			'fulfilled_by_skroutz'    => 'fulfilled_by_skroutz',
			'invoice'                 => 'invoice',
			'invoice_document'        => 'invoice_document',
			'invoice_details'         => 'invoice_details',
			'uploaded_invoice_file'   => 'uploaded_invoice_file',
			'fulfillment_mode'        => 'fulfillment_mode',
			'pickup_window'           => 'pickup_window',
			'number_of_parcels'       => 'number_of_parcels',
			'fbs_delivery_note'       => 'fbs_delivery_note',
			'fbs_delivery_note_url'   => 'fbs_delivery_note_url',
			'is_ready_for_dispatch'   => 'is_ready_for_dispatch',
			'set_as_ready_required'   => 'set_as_ready_required',
		);

		foreach ( $fields as $payload_key => $meta_suffix ) {
			if ( array_key_exists( $payload_key, $order_data ) ) {
				$order->update_meta_data( self::META_PREFIX . $meta_suffix, $this->sanitize_meta_value( $order_data[ $payload_key ] ) );
			}
		}

		if ( isset( $payload['event_time'] ) ) {
			$order->update_meta_data( self::META_PREFIX . 'last_event_time', sanitize_text_field( (string) $payload['event_time'] ) );
		}
	}

	/**
	 * Save allow-listed metadata contained in the changes object.
	 *
	 * @param WC_Order            $order   WooCommerce order.
	 * @param array<string,mixed> $payload Webhook payload.
	 * @return void
	 */
	private function save_changed_metadata( WC_Order $order, array $payload ): void {
		if ( empty( $payload['changes'] ) || ! is_array( $payload['changes'] ) ) {
			return;
		}

		$allowed = array(
			'expires_at',
			'dispatch_until',
			'courier',
			'courier_voucher',
			'courier_tracking_codes',
			'fbs_delivery_note',
			'fbs_delivery_note_url',
			'pickup_window',
			'number_of_parcels',
			'pickup_address',
			'is_ready_for_dispatch',
		);

		foreach ( $allowed as $key ) {
			if ( isset( $payload['changes'][ $key ] ) && is_array( $payload['changes'][ $key ] ) && array_key_exists( 'new', $payload['changes'][ $key ] ) ) {
				$order->update_meta_data( self::META_PREFIX . $key, $this->sanitize_meta_value( $payload['changes'][ $key ]['new'] ) );
			}
		}
	}

	/**
	 * Sanitize a scalar or nested metadata value while preserving its type.
	 *
	 * @param mixed $value Incoming value.
	 * @return mixed
	 */
	private function sanitize_meta_value( $value ) {
		if ( is_array( $value ) ) {
			$clean = array();
			foreach ( $value as $key => $item ) {
				$clean[ sanitize_key( (string) $key ) ] = $this->sanitize_meta_value( $item );
			}
			return $clean;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Check whether an event predates the last event already applied to an order.
	 *
	 * @param WC_Order            $order   WooCommerce order.
	 * @param array<string,mixed> $payload Webhook payload.
	 * @return bool
	 */
	private function is_stale_event( WC_Order $order, array $payload ): bool {
		if ( empty( $payload['event_time'] ) ) {
			return false;
		}

		$stored_time = (string) $order->get_meta( self::META_PREFIX . 'last_event_time' );
		if ( '' === $stored_time ) {
			return false;
		}

		$incoming_timestamp = strtotime( (string) $payload['event_time'] );
		$stored_timestamp   = strtotime( $stored_time );

		return false !== $incoming_timestamp && false !== $stored_timestamp && $incoming_timestamp < $stored_timestamp;
	}

	/**
	 * Map a new Skroutz order to its initial WooCommerce status.
	 *
	 * @param string $state Skroutz state.
	 * @return string
	 */
	private function map_state_for_new_order( string $state ): string {
		if ( in_array( $state, array( 'cancelled', 'rejected', 'expired' ), true ) ) {
			return 'cancelled';
		}

		if ( 'delivered' === $state ) {
			return 'completed';
		}

		return 'processing';
	}

	/**
	 * Apply a safe WooCommerce status transition.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $state Skroutz state.
	 * @return void
	 */
	private function apply_state_transition( WC_Order $order, string $state ): void {
		$current = $order->get_status();
		$target  = null;

		if ( in_array( $state, array( 'cancelled', 'rejected', 'expired' ), true ) ) {
			$target = 'cancelled';
		} elseif ( 'delivered' === $state ) {
			$target = 'completed';
		} elseif ( in_array( $state, array( 'open', 'accepted', 'dispatched' ), true ) && in_array( $current, array( 'pending', 'on-hold' ), true ) ) {
			$target = 'processing';
		}

		if ( null !== $target && $target !== $current && ! in_array( $current, array( 'refunded', 'cancelled', 'completed' ), true ) ) {
			$order->update_status(
				$target,
				sprintf(
					/* translators: %s: Skroutz order state. */
					__( 'Skroutz order state changed to: %s.', 'skroutz-smart-cart-bridge' ),
					$state
				)
			);
			return;
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: Skroutz order state. */
				__( 'Skroutz order state changed to: %s.', 'skroutz-smart-cart-bridge' ),
				$state
			)
		);
	}

	/**
	 * Find an existing order by its unique Skroutz code.
	 *
	 * @param string $order_code Skroutz order code.
	 * @return WC_Order|null
	 */
	private function find_order_by_code( string $order_code ): ?WC_Order {
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'meta_key'   => self::META_ORDER_CODE,
				'meta_value' => $order_code,
				'return'     => 'objects',
			)
		);

		return ! empty( $orders ) && $orders[0] instanceof WC_Order ? $orders[0] : null;
	}

	/**
	 * Acquire an atomic short-lived lock for one marketplace order code.
	 *
	 * @param string $order_code Skroutz order code.
	 * @return bool
	 */
	private function acquire_order_lock( string $order_code ): bool {
		$key = $this->get_order_lock_key( $order_code );

		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}

		$created_at = (int) get_option( $key, 0 );
		if ( $created_at > 0 && $created_at < ( time() - 300 ) ) {
			delete_option( $key );
			return add_option( $key, time(), '', false );
		}

		return false;
	}

	/**
	 * Release an order-import lock.
	 *
	 * @param string $order_code Skroutz order code.
	 * @return void
	 */
	private function release_order_lock( string $order_code ): void {
		delete_option( $this->get_order_lock_key( $order_code ) );
	}

	/**
	 * Build an order-import lock key.
	 *
	 * @param string $order_code Skroutz order code.
	 * @return string
	 */
	private function get_order_lock_key( string $order_code ): string {
		return 'sscb_order_lock_' . md5( $order_code );
	}

	/**
	 * Log a privacy-safe event summary.
	 *
	 * @param array<string,mixed> $payload Webhook payload.
	 * @return void
	 */
	private function log_event_summary( array $payload ): void {
		$order = $payload['order'];
		$this->log(
			'info',
			'Webhook received.',
			array(
				'event_type' => sanitize_key( (string) $payload['event_type'] ),
				'order_code' => isset( $order['code'] ) ? sanitize_text_field( (string) $order['code'] ) : '',
				'state'      => isset( $order['state'] ) ? sanitize_key( (string) $order['state'] ) : '',
				'item_count' => isset( $order['line_items'] ) && is_array( $order['line_items'] ) ? count( $order['line_items'] ) : 0,
			)
		);
	}

	/**
	 * Write to the WooCommerce logger when debug logging is enabled.
	 *
	 * @param string              $level   Log level.
	 * @param string              $message Message.
	 * @param array<string,mixed> $data    Privacy-safe context.
	 * @return void
	 */
	private function log( string $level, string $message, array $data = array() ): void {
		if ( 1 !== (int) get_option( self::OPTION_DEBUG_LOGGING, 0 ) || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger  = wc_get_logger();
		$context = array_merge( array( 'source' => self::LOG_SOURCE ), $data );

		if ( method_exists( $logger, $level ) ) {
			$logger->{$level}( $message, $context );
		}
	}

	/**
	 * Register the Skroutz information metabox for legacy and HPOS screens.
	 *
	 * @return void
	 */
	public function add_order_metabox(): void {
		if ( ! function_exists( 'wc_get_page_screen_id' ) ) {
			return;
		}

		add_meta_box(
			'sscb-order-info',
			__( 'Skroutz Smart Cart', 'skroutz-smart-cart-bridge' ),
			array( $this, 'render_order_metabox' ),
			wc_get_page_screen_id( 'shop-order' ),
			'side',
			'default'
		);
	}

	/**
	 * Render order marketplace metadata.
	 *
	 * @param WP_Post|WC_Order|mixed $post_or_order_object Current screen object.
	 * @return void
	 */
	public function render_order_metabox( $post_or_order_object ): void {
		$order = $this->normalize_order_object( $post_or_order_object );

		if ( ! $order || ! $this->is_skroutz_order( $order ) ) {
			echo '<p>' . esc_html__( 'This is not a Skroutz Smart Cart order.', 'skroutz-smart-cart-bridge' ) . '</p>';
			return;
		}

		$fields = array(
			'order_code'             => __( 'Order code', 'skroutz-smart-cart-bridge' ),
			'state'                  => __( 'State', 'skroutz-smart-cart-bridge' ),
			'courier'                => __( 'Courier', 'skroutz-smart-cart-bridge' ),
			'courier_voucher'        => __( 'Courier voucher', 'skroutz-smart-cart-bridge' ),
			'courier_tracking_codes' => __( 'Tracking codes', 'skroutz-smart-cart-bridge' ),
			'created_at'             => __( 'Created at', 'skroutz-smart-cart-bridge' ),
			'expires_at'             => __( 'Expires at', 'skroutz-smart-cart-bridge' ),
			'dispatch_until'         => __( 'Dispatch until', 'skroutz-smart-cart-bridge' ),
			'pickup_window'          => __( 'Pickup window', 'skroutz-smart-cart-bridge' ),
			'number_of_parcels'      => __( 'Parcels', 'skroutz-smart-cart-bridge' ),
			'invoice_document'       => __( 'Document', 'skroutz-smart-cart-bridge' ),
			'fulfillment_mode'       => __( 'Fulfillment', 'skroutz-smart-cart-bridge' ),
			'is_ready_for_dispatch'  => __( 'Ready for dispatch', 'skroutz-smart-cart-bridge' ),
		);

		echo '<table class="widefat striped"><tbody>';

		foreach ( $fields as $key => $label ) {
			$value = $order->get_meta( self::META_PREFIX . $key );
			if ( '' === $value || null === $value ) {
				continue;
			}

			echo '<tr><th><strong>' . esc_html( $label ) . '</strong></th><td>';
			echo wp_kses_post( $this->format_admin_meta_value( $value ) );
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Normalize legacy and HPOS order screen objects.
	 *
	 * @param mixed $object Current screen object.
	 * @return WC_Order|null
	 */
	private function normalize_order_object( $object ): ?WC_Order {
		if ( $object instanceof WC_Order ) {
			return $object;
		}

		if ( $object instanceof WP_Post ) {
			$order = wc_get_order( $object->ID );
			return $order instanceof WC_Order ? $order : null;
		}

		if ( is_numeric( $object ) ) {
			$order = wc_get_order( (int) $object );
			return $order instanceof WC_Order ? $order : null;
		}

		return null;
	}

	/**
	 * Format a meta value for safe admin display.
	 *
	 * @param mixed $value Stored value.
	 * @return string
	 */
	private function format_admin_meta_value( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? esc_html__( 'Yes', 'skroutz-smart-cart-bridge' ) : esc_html__( 'No', 'skroutz-smart-cart-bridge' );
		}

		if ( is_array( $value ) ) {
			$value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$value = (string) $value;
		if ( wp_http_validate_url( $value ) ) {
			return '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open link', 'skroutz-smart-cart-bridge' ) . '</a>';
		}

		return esc_html( $value );
	}

	/**
	 * Add a Skroutz state column to order lists.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public function add_skroutz_column( array $columns ): array {
		$result   = array();
		$inserted = false;

		foreach ( $columns as $key => $label ) {
			if ( 'order_status' === $key ) {
				$result['sscb_skroutz'] = __( 'Skroutz', 'skroutz-smart-cart-bridge' );
				$inserted               = true;
			}

			$result[ $key ] = $label;
		}

		if ( ! $inserted ) {
			$result['sscb_skroutz'] = __( 'Skroutz', 'skroutz-smart-cart-bridge' );
		}

		return $result;
	}

	/**
	 * Render the custom column on the legacy order list.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Order post ID.
	 * @return void
	 */
	public function render_legacy_order_column( string $column, int $post_id ): void {
		$this->render_order_column( $column, wc_get_order( $post_id ) );
	}

	/**
	 * Render the custom column on the HPOS order list.
	 *
	 * @param string   $column Column key.
	 * @param WC_Order $order  Order object.
	 * @return void
	 */
	public function render_hpos_order_column( string $column, WC_Order $order ): void {
		$this->render_order_column( $column, $order );
	}

	/**
	 * Render one order-list badge.
	 *
	 * @param string         $column Column key.
	 * @param WC_Order|false $order  Order object.
	 * @return void
	 */
	private function render_order_column( string $column, $order ): void {
		if ( 'sscb_skroutz' !== $column ) {
			return;
		}

		if ( ! $order instanceof WC_Order || ! $this->is_skroutz_order( $order ) ) {
			echo '<span class="sscb-badge sscb-badge-empty">&mdash;</span>';
			return;
		}

		$state = (string) $order->get_meta( self::META_STATE );
		$state = '' === $state ? 'skroutz' : $state;

		echo '<span class="sscb-badge">' . esc_html( ucfirst( $state ) ) . '</span>';
	}

	/**
	 * Determine whether an order came from this bridge.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	private function is_skroutz_order( WC_Order $order ): bool {
		return '' !== (string) $order->get_meta( self::META_ORDER_CODE );
	}

	/**
	 * Print small admin styles on order screens only.
	 *
	 * @return void
	 */
	public function admin_order_styles(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}

		$allowed = array( 'edit-shop_order', 'shop_order', 'woocommerce_page_wc-orders' );
		if ( ! in_array( $screen->id, $allowed, true ) ) {
			return;
		}
		?>
		<style>
			.column-sscb_skroutz { width: 92px; text-align: center; }
			.sscb-badge { display: inline-block; padding: 3px 7px; border-radius: 4px; background: #f08a00; color: #fff; font-size: 11px; font-weight: 600; line-height: 1.4; }
			.sscb-badge-empty { background: transparent; color: #999; }
			#sscb-order-info table th { width: 42%; }
			#sscb-order-info table td { overflow-wrap: anywhere; }
		</style>
		<?php
	}
}
