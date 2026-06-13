<?php
/**
 * Admin menu and pages.
 *
 * @package BazaarBasheSync
 */

namespace BazaarBashe\Sync\Admin;

use BazaarBashe\Sync\Api\Client;
use BazaarBashe\Sync\Logger\Diagnostics;
use BazaarBashe\Sync\Models\Log_Repository;
use BazaarBashe\Sync\Models\Product_Map_Repository;
use BazaarBashe\Sync\Models\Settings;
use BazaarBashe\Sync\Queue\Queue_Manager;

defined( 'ABSPATH' ) || exit;

class Admin_Menu {

	protected $settings;
	protected $client;
	protected $maps;
	protected $logs;
	protected $queue;

	public function __construct( Settings $settings, Client $client, Product_Map_Repository $maps, Log_Repository $logs, Queue_Manager $queue ) {
		$this->settings = $settings;
		$this->client   = $client;
		$this->maps     = $maps;
		$this->logs     = $logs;
		$this->queue    = $queue;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
		add_action( 'admin_head', array( $this, 'render_menu_icon_styles' ) );
		add_action( 'admin_post_bbsync_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_bbsync_full_sync', array( $this, 'handle_full_sync' ) );
		add_action( 'admin_post_bbsync_run_product_sync', array( $this, 'handle_run_product_sync' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'bbsync' ) ) {
			return;
		}

		wp_enqueue_style( 'bbsync-admin', BBSYNC_URL . 'assets/css/admin.css', array(), BBSYNC_VERSION );
	}

	public function register_settings() {
		register_setting( 'bbsync_settings_group', Settings::OPTION_KEY, array( $this, 'sanitize_settings' ) );
	}

	public function sanitize_settings( $input ) {
		$current = $this->settings->all();
		return array_merge( $current, $this->settings->sanitize( (array) $input ) );
	}

	public function register_menu() {
		$capability = 'manage_woocommerce';
		$icon_url   = $this->get_menu_icon_url();

		add_menu_page( 'بازارباشه', 'بازارباشه', $capability, 'bbsync-dashboard', array( $this, 'render_dashboard' ), $icon_url, 56 );
		add_submenu_page( 'bbsync-dashboard', 'داشبورد', 'داشبورد', $capability, 'bbsync-dashboard', array( $this, 'render_dashboard' ) );
		add_submenu_page( 'bbsync-dashboard', 'تنظیمات', 'تنظیمات', $capability, 'bbsync-settings', array( $this, 'render_settings' ) );
		add_submenu_page( 'bbsync-dashboard', 'وضعیت همگام‌سازی', 'وضعیت همگام‌سازی', $capability, 'bbsync-status', array( $this, 'render_status' ) );
		add_submenu_page( 'bbsync-dashboard', 'گزارش‌ها', 'گزارش‌ها', $capability, 'bbsync-logs', array( $this, 'render_logs' ) );
	}

	protected function get_menu_icon_url() {
		$icon_path = BBSYNC_PATH . 'assets/images/bazaarbashe_logo_white.svg';

		if ( ! file_exists( $icon_path ) ) {
			return 'dashicons-update';
		}

		return BBSYNC_URL . 'assets/images/bazaarbashe_logo_white.svg';
	}

	public function render_menu_icon_styles() {
		echo '<style>
			#adminmenu .toplevel_page_bbsync-dashboard .wp-menu-image,
			#adminmenu .toplevel_page_bbsync-dashboard:hover .wp-menu-image,
			#adminmenu .toplevel_page_bbsync-dashboard.current .wp-menu-image,
			#adminmenu .toplevel_page_bbsync-dashboard.wp-has-current-submenu .wp-menu-image,
			#adminmenu .toplevel_page_bbsync-dashboard.focus .wp-menu-image {
				background: transparent !important;
				border-radius: 0 !important;
				width: 20px !important;
				height: 20px !important;
				margin: 6px 0 0 14px !important;
				padding: 0 !important;
				display: inline-block !important;
				box-shadow: none !important;
				overflow: visible !important;
			}

			#adminmenu .toplevel_page_bbsync-dashboard .wp-menu-image img {
				display: block;
				width: 25px;
				height: 25px;
				margin: 0 auto 0 !important;
				padding: 0 !important;
				opacity: 1 !important;
				filter: none !important;
				background: transparent !important;
				border-radius: 0 !important;
				box-shadow: none !important;
				max-width: none !important;
			}

			#adminmenu .toplevel_page_bbsync-dashboard:hover .wp-menu-image,
			#adminmenu .toplevel_page_bbsync-dashboard.focused .wp-menu-image,
			#adminmenu .toplevel_page_bbsync-dashboard.current .wp-menu-image,
			#adminmenu .toplevel_page_bbsync-dashboard.wp-has-current-submenu .wp-menu-image {
				background: transparent !important;
				box-shadow: none !important;
			}
		</style>';
	}

	public function render_dashboard() {
		$settings = $this->settings->all();
		$issue    = is_array( $settings['latest_issue'] ?? null ) ? $settings['latest_issue'] : array();
		?>
		<div class="wrap bbsync-admin" dir="rtl">
			<div class="bbsync-page-head">
				<h1>داشبورد همگام‌سازی بازارباشه</h1>
				<p>وضعیت اتصال، آمار عملیات، آخرین خطا و ابزارهای اجرای همگام‌سازی را از این بخش مدیریت کنید.</p>
			</div>

			<div class="bbsync-cards">
				<div class="bbsync-card"><h2>وضعیت اتصال</h2><p><span class="bbsync-badge <?php echo 'connected' === $settings['connection_status'] ? 'is-success' : 'is-error'; ?>"><?php echo esc_html( 'connected' === $settings['connection_status'] ? 'متصل' : 'نامتصل' ); ?></span></p></div>
				<div class="bbsync-card"><h2>محصولات نگاشت‌شده</h2><p><strong><?php echo esc_html( number_format_i18n( $this->maps->count_all() ) ); ?></strong></p></div>
				<div class="bbsync-card"><h2>همگام‌سازی موفق</h2><p><strong><?php echo esc_html( number_format_i18n( $this->logs->count_successful_syncs() ) ); ?></strong></p></div>
				<div class="bbsync-card"><h2>عملیات ناموفق</h2><p><strong><?php echo esc_html( number_format_i18n( $this->logs->count_failed() ) ); ?></strong></p></div>
			</div>

			<div class="bbsync-diagnostics-card <?php echo empty( $issue ) ? 'is-success' : 'is-error'; ?>">
				<div class="bbsync-card-head">
					<h2>عیب‌یابی همگام‌سازی</h2>
					<span class="bbsync-badge <?php echo empty( $issue ) ? 'is-success' : 'is-error'; ?>"><?php echo empty( $issue ) ? 'بدون خطای فعال' : 'نیازمند بررسی'; ?></span>
				</div>
				<?php if ( empty( $issue ) ) : ?>
					<p>در حال حاضر خطای فعالی ثبت نشده است. اگر همگام‌سازی انجام نشود، آخرین مشکل و راه‌حل آن در این بخش نمایش داده می‌شود.</p>
				<?php else : ?>
					<div class="bbsync-diagnostic-grid">
						<div><strong><?php echo esc_html( $issue['title'] ?? 'خطای همگام‌سازی' ); ?></strong><p><?php echo esc_html( $issue['user_message'] ?? '' ); ?></p></div>
						<div><strong>راه‌حل پیشنهادی</strong><p><?php echo esc_html( $issue['suggested_fix'] ?? '' ); ?></p></div>
						<?php if ( ! empty( $issue['retry_guidance'] ) ) : ?><div><strong>راهنمای تلاش مجدد</strong><p><?php echo esc_html( $issue['retry_guidance'] ); ?></p></div><?php endif; ?>
						<?php if ( ! empty( $issue['endpoint'] ) ) : ?><div><strong>اندپوینت</strong><p><code><?php echo esc_html( $issue['endpoint'] ); ?></code></p></div><?php endif; ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="bbsync-actions-panel">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'bbsync_full_sync' ); ?>
					<input type="hidden" name="action" value="bbsync_full_sync">
					<button type="submit" class="button button-primary button-large">همگام‌سازی همه محصولات</button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bbsync-inline-form">
					<?php wp_nonce_field( 'bbsync_run_product_sync' ); ?>
					<input type="hidden" name="action" value="bbsync_run_product_sync">
					<label for="bbsync-product-id"><strong>همگام‌سازی فوری یک محصول</strong></label>
					<div class="bbsync-inline-row">
						<input id="bbsync-product-id" name="woo_product_id" type="number" min="1" class="small-text" placeholder="شناسه محصول ووکامرس" required>
						<button type="submit" class="button button-secondary">اجرای فوری</button>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	public function render_settings() {
		$settings = $this->settings->all();
		$payload  = is_array( $settings['connection_payload'] ) ? $settings['connection_payload'] : array();
		$store    = $payload['store'] ?? array();
		?>
		<div class="wrap bbsync-admin" dir="rtl">
			<div class="bbsync-page-head">
				<h1>تنظیمات افزونه بازارباشه</h1>
				<p>اطلاعات اتصال، فروشگاه هدف و رفتار همگام‌سازی خودکار را از این بخش تنظیم کنید.</p>
			</div>
			<form method="post" action="options.php" class="bbsync-surface">
				<?php settings_fields( 'bbsync_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="bbsync-access-token">توکن دسترسی</label></th><td><input id="bbsync-access-token" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[access_token]" type="text" class="regular-text" value="<?php echo esc_attr( $settings['access_token'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="bbsync-vendor-identifier">شناسه یا آدرس فروشگاه</label></th><td><input id="bbsync-vendor-identifier" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[vendor_identifier]" type="text" class="regular-text" value="<?php echo esc_attr( $settings['vendor_identifier'] ); ?>"></td></tr>
				</table>
				<h2>گزینه‌های همگام‌سازی</h2>
				<div class="bbsync-options-grid">
					<?php $this->render_checkbox( 'enable_create', 'ایجاد محصول', $settings ); ?>
					<?php $this->render_checkbox( 'enable_update', 'بروزرسانی محصول', $settings ); ?>
					<?php $this->render_checkbox( 'enable_delete', 'حذف محصول', $settings ); ?>
					<?php $this->render_checkbox( 'enable_images', 'همگام‌سازی تصاویر', $settings ); ?>
					<?php $this->render_checkbox( 'enable_price', 'همگام‌سازی قیمت', $settings ); ?>
					<?php $this->render_checkbox( 'enable_inventory', 'همگام‌سازی موجودی', $settings ); ?>
					<?php $this->render_checkbox( 'enable_discount', 'همگام‌سازی تخفیف', $settings ); ?>
					<?php $this->render_checkbox( 'enable_immediate_sync', 'همگام‌سازی فوری', $settings ); ?>
				</div>
				<?php submit_button( 'ذخیره تنظیمات' ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bbsync-inline-form">
				<?php wp_nonce_field( 'bbsync_test_connection' ); ?>
				<input type="hidden" name="action" value="bbsync_test_connection">
				<?php submit_button( 'تست اتصال', 'secondary', 'submit', false ); ?>
			</form>

			<?php if ( 'connected' === $settings['connection_status'] && ! empty( $store ) ) : ?>
				<div class="notice notice-success inline"><p><?php echo esc_html( $this->build_connection_message( $store ) ); ?></p></div>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_status() {
		$settings = $this->settings->all();
		?>
		<div class="wrap bbsync-admin" dir="rtl">
			<div class="bbsync-page-head">
				<h1>وضعیت همگام‌سازی</h1>
				<p>مرور سریع روی اتصال، آخرین زمان اجرا و تعداد عملیات انجام‌شده.</p>
			</div>
			<table class="widefat striped bbsync-table">
				<tbody>
					<tr><th>وضعیت اتصال</th><td><?php echo esc_html( $this->format_connection_status( $settings['connection_status'] ) ); ?></td></tr>
					<tr><th>آخرین همگام‌سازی</th><td><?php echo esc_html( $settings['last_sync_at'] ?: get_option( 'bbsync_last_sync_at', '-' ) ); ?></td></tr>
					<tr><th>تعداد محصولات نگاشت‌شده</th><td><?php echo esc_html( number_format_i18n( $this->maps->count_all() ) ); ?></td></tr>
					<tr><th>تعداد خطاها</th><td><?php echo esc_html( number_format_i18n( $this->logs->count_failed() ) ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function render_logs() {
		$logs           = $this->logs->get_logs( 200 );
		$current_status = sanitize_key( $_GET['log_status'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap bbsync-admin" dir="rtl">
			<div class="bbsync-page-head">
				<h1>گزارش‌های همگام‌سازی</h1>
				<p>جزئیات هر عملیات، علت خطا، وضعیت پاسخ API و داده‌های فنی در این بخش قابل بررسی است.</p>
			</div>
			<div class="bbsync-log-filters">
				<?php foreach ( array( '' => 'همه', 'success' => 'موفق', 'failed' => 'ناموفق', 'started' => 'در حال اجرا', 'skipped' => 'رد شده' ) as $status_key => $label ) : ?>
					<a class="bbsync-filter <?php echo $current_status === $status_key ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'bbsync-logs', 'log_status' => $status_key ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</div>
			<div class="bbsync-log-list">
				<?php $has_visible_logs = false; ?>
				<?php foreach ( $logs as $log ) : ?>
					<?php
					if ( '' !== $current_status && $log->status !== $current_status ) {
						continue;
					}
					$has_visible_logs = true;
					$context = json_decode( (string) $log->context, true );
					$diag    = is_array( $context['diagnostics'] ?? null ) ? $context['diagnostics'] : array();
					?>
					<div class="bbsync-log-card status-<?php echo esc_attr( $log->status ); ?>">
						<div class="bbsync-log-top">
							<div><span class="bbsync-badge status-<?php echo esc_attr( $log->status ); ?>"><?php echo esc_html( $this->format_status( $log->status ) ); ?></span> <strong><?php echo esc_html( $this->format_action( $log->action ) ); ?></strong></div>
							<span class="bbsync-log-time"><?php echo esc_html( $log->created_at ); ?></span>
						</div>
						<p class="bbsync-log-message">
							<?php
							echo esc_html(
								'success' === $log->status
									? 'عملیات با موفقیت انجام شد.'
									: ( $diag['user_message'] ?? ( $diag['title'] ?? wp_strip_all_tags( $log->message ) ) )
							);
							?>
						</p>
						<div class="bbsync-log-meta">
							<span>محصول: <?php echo esc_html( $log->woo_product_id ?: '-' ); ?></span>
							<?php if ( ! empty( $diag['category'] ) ) : ?><span>دسته خطا: <?php echo esc_html( $diag['category'] ); ?></span><?php endif; ?>
							<?php if ( ! empty( $diag['http_status'] ) ) : ?><span>HTTP: <?php echo esc_html( $diag['http_status'] ); ?></span><?php endif; ?>
						</div>
						<?php if ( 'success' !== $log->status && ! empty( $diag['suggested_fix'] ) ) : ?>
							<div class="bbsync-help-box">
								<strong>راه‌حل پیشنهادی</strong>
								<p><?php echo esc_html( $diag['suggested_fix'] ); ?></p>
								<?php if ( ! empty( $diag['retry_guidance'] ) ) : ?><p><?php echo esc_html( $diag['retry_guidance'] ); ?></p><?php endif; ?>
							</div>
						<?php endif; ?>
						<details class="bbsync-log-details">
							<summary>نمایش جزئیات فنی</summary>
							<?php if ( ! empty( $diag['endpoint'] ) ) : ?><p><strong>اندپوینت:</strong> <code><?php echo esc_html( $diag['endpoint'] ); ?></code></p><?php endif; ?>
							<?php if ( ! empty( $diag['technical'] ) ) : ?><p><strong>جزئیات فنی:</strong> <?php echo esc_html( $diag['technical'] ); ?></p><?php endif; ?>
							<p><strong>پیام ثبت‌شده:</strong> <?php echo esc_html( wp_strip_all_tags( $log->message ) ); ?></p>
							<p><strong>اطلاعات زمینه:</strong></p>
							<pre><?php echo esc_html( wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
							<p><strong>پاسخ خام API:</strong></p>
							<pre><?php echo esc_html( $log->response ); ?></pre>
						</details>
					</div>
				<?php endforeach; ?>
				<?php if ( ! $has_visible_logs ) : ?>
					<div class="bbsync-empty-state">
						<h3>هنوز گزارشی ثبت نشده است</h3>
						<p>بعد از اولین تست اتصال یا همگام‌سازی، جزئیات عملیات در این بخش نمایش داده می‌شود.</p>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public function handle_test_connection() {
		$this->guard_admin_post( 'bbsync_test_connection' );

		$settings          = $this->settings->all();
		$vendor_identifier = (string) $settings['vendor_identifier'];
		$masked_token      = '' !== (string) $settings['access_token'] ? '***configured***' : '';
		$result            = $this->client->test_connection();

		if ( is_wp_error( $result ) ) {
			$diagnostics = Diagnostics::build(
				'failed',
				$result->get_error_message(),
				array(
					'vendor_identifier' => $vendor_identifier,
					'access_token'      => $masked_token,
					'error_code'        => $result->get_error_code(),
					'technical_detail'  => wp_json_encode( $result->get_error_data(), JSON_UNESCAPED_UNICODE ),
				),
				(array) $result->get_error_data()
			);
			$this->logs->insert(
				array(
					'action'   => 'test-connection',
					'status'   => 'failed',
					'message'  => $result->get_error_message(),
					'context'  => array(
						'vendor_identifier' => $vendor_identifier,
						'access_token'      => $masked_token,
						'error_code'        => $result->get_error_code(),
						'technical_detail'  => wp_json_encode( $result->get_error_data(), JSON_UNESCAPED_UNICODE ),
						'diagnostics'       => $diagnostics,
					),
					'response' => (array) $result->get_error_data(),
				)
			);
			$this->settings->update(
				array(
					'connection_status'  => 'failed',
					'connection_payload' => array( 'message' => $result->get_error_message() ),
					'latest_issue'       => array_merge(
						$diagnostics,
						array(
							'action'     => 'test-connection',
							'status'     => 'failed',
							'created_at' => current_time( 'mysql', true ),
						)
					),
				)
			);
			wp_safe_redirect( add_query_arg( array( 'page' => 'bbsync-settings', 'bbsync_notice' => 'connection_failed', 'bbsync_msg' => rawurlencode( $result->get_error_message() ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$this->logs->insert(
			array(
				'action'   => 'test-connection',
				'status'   => 'success',
				'message'  => 'تست اتصال با موفقیت انجام شد.',
				'context'  => array( 'vendor_identifier' => $vendor_identifier, 'access_token' => $masked_token ),
				'response' => $result,
			)
		);
		$this->settings->update( array( 'connection_status' => 'connected', 'connection_payload' => $result, 'latest_issue' => array() ) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'bbsync-settings', 'bbsync_notice' => 'connection_ok', 'bbsync_msg' => rawurlencode( $this->build_connection_message( $result['store'] ?? array() ) ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_full_sync() {
		$this->guard_admin_post( 'bbsync_full_sync' );
		$this->queue->enqueue_full_sync();
		wp_safe_redirect( add_query_arg( array( 'page' => 'bbsync-dashboard', 'bbsync_notice' => 'full_sync_queued' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_run_product_sync() {
		$this->guard_admin_post( 'bbsync_run_product_sync' );
		$product_id = isset( $_POST['woo_product_id'] ) ? absint( wp_unslash( $_POST['woo_product_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( $product_id <= 0 ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'bbsync-dashboard', 'bbsync_notice' => 'manual_sync_failed', 'bbsync_msg' => rawurlencode( 'شناسه محصول ووکامرس معتبر نیست.' ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$result = $this->queue->run_now( 'sync', $product_id );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'bbsync-dashboard', 'bbsync_notice' => 'manual_sync_failed', 'bbsync_msg' => rawurlencode( $result->get_error_message() ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'bbsync-dashboard', 'bbsync_notice' => 'manual_sync_ok', 'bbsync_msg' => rawurlencode( 'همگام‌سازی دستی با موفقیت انجام شد.' ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	protected function render_checkbox( $key, $label, array $settings ) {
		?>
		<label class="bbsync-checkbox">
			<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( 'yes', $settings[ $key ] ?? 'no' ); ?>>
			<span><?php echo esc_html( $label ); ?></span>
		</label>
		<?php
	}

	protected function guard_admin_post( $nonce_action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'شما دسترسی لازم برای این عملیات را ندارید.' );
		}

		check_admin_referer( $nonce_action );
	}

	protected function format_status( $status ) {
		$map = array(
			'success'   => 'موفق',
			'queued'    => 'در صف',
			'scheduled' => 'زمان‌بندی شده',
			'started'   => 'در حال اجرا',
			'retry'     => 'تلاش مجدد',
			'skipped'   => 'رد شده',
			'failed'    => 'ناموفق',
		);
		return $map[ $status ] ?? $status;
	}

	protected function format_action( $action ) {
		$map = array(
			'create'          => 'ایجاد محصول',
			'update'          => 'بروزرسانی محصول',
			'delete'          => 'حذف محصول',
			'sync'            => 'همگام‌سازی',
			'test-connection' => 'تست اتصال',
		);
		return $map[ $action ] ?? $action;
	}

	protected function format_connection_status( $status ) {
		$map = array(
			'connected'    => 'متصل',
			'disconnected' => 'نامتصل',
			'failed'       => 'خطادار',
		);

		return $map[ $status ] ?? $status;
	}

	public function render_admin_notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$transient_notice = get_transient( 'bbsync_admin_notice' );
		if ( is_array( $transient_notice ) && ! empty( $transient_notice['message'] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $transient_notice['type'] ?? 'error' ), esc_html( $transient_notice['message'] ) );
			delete_transient( 'bbsync_admin_notice' );
		}

		$page   = sanitize_key( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = sanitize_key( $_GET['bbsync_notice'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg    = sanitize_text_field( wp_unslash( $_GET['bbsync_msg'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $notice || 0 !== strpos( $page, 'bbsync' ) ) {
			return;
		}

		$class = in_array( $notice, array( 'connection_failed', 'manual_sync_failed' ), true ) ? 'notice notice-error is-dismissible' : 'notice notice-success is-dismissible';
		if ( '' === $msg ) {
			$messages = array(
				'connection_ok'      => 'تست اتصال با موفقیت انجام شد.',
				'connection_failed'  => 'تست اتصال ناموفق بود.',
				'full_sync_queued'   => 'همگام‌سازی همه محصولات آغاز شد.',
				'manual_sync_ok'     => 'همگام‌سازی دستی با موفقیت انجام شد.',
				'manual_sync_failed' => 'همگام‌سازی دستی ناموفق بود.',
			);
			$msg = $messages[ $notice ] ?? 'عملیات انجام شد.';
		}

		printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( rawurldecode( $msg ) ) );
	}

	protected function build_connection_message( array $store ) {
		$parts  = array( 'اتصال با موفقیت برقرار شد.' );
		$name   = $store['name'] ?? '';
		$vendor = $store['website_domain'] ?? ( $store['id'] ?? '' );
		if ( '' !== (string) $name ) {
			$parts[] = sprintf( 'نام فروشگاه: %s', $name );
		}
		if ( '' !== (string) $vendor ) {
			$parts[] = sprintf( 'فروشگاه: %s', $vendor );
		}
		return implode( ' | ', $parts );
	}
}
