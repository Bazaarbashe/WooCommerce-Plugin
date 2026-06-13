<?php
/**
 * Diagnostics classifier.
 *
 * @package BazaarBasheSync
 */

namespace BazaarBashe\Sync\Logger;

defined( 'ABSPATH' ) || exit;

class Diagnostics {

	/**
	 * Build a normalized diagnostics payload.
	 *
	 * @param string $status Status.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @param array  $response Response.
	 * @return array
	 */
	public static function build( $status, $message, array $context = array(), array $response = array() ) {
		$http_status = (int) ( $response['status'] ?? $context['http_status'] ?? 0 );
		$error_code  = (string) ( $context['error_code'] ?? $response['error_code'] ?? '' );
		$endpoint    = (string) ( $context['endpoint'] ?? $response['endpoint'] ?? '' );
		$detail      = (string) ( $context['technical_detail'] ?? $response['detail'] ?? '' );
		$raw_body    = $response['body'] ?? $response;

		$category = 'unknown';
		$title    = 'خطای نامشخص در همگام‌سازی';
		$fix      = 'جزئیات لاگ را بررسی کنید و دوباره تلاش کنید.';
		$retry    = '';

		if ( in_array( $error_code, array( 'bbsync_missing_token' ), true ) ) {
			$category = 'missing_access_token';
			$title    = 'توکن دسترسی وارد نشده است';
			$fix      = 'در بخش تنظیمات افزونه، توکن دسترسی بازارباشه را وارد و ذخیره کنید.';
		} elseif ( in_array( $error_code, array( 'bbsync_invalid_token', 'bbsync_token_expired' ), true ) || 401 === $http_status ) {
			$category = 'invalid_token';
			$title    = 'توکن دسترسی نامعتبر یا منقضی است';
			$fix      = 'یک توکن جدید از پنل توسعه‌دهندگان بازارباشه بسازید و در افزونه ثبت کنید.';
		} elseif ( 'bbsync_missing_vendor' === $error_code ) {
			$category = 'invalid_vendor';
			$title    = 'شناسه یا آدرس فروشگاه ثبت نشده است';
			$fix      = 'در تنظیمات افزونه، Vendor ID یا Vendor URL صحیح را وارد کنید.';
		} elseif ( 'bbsync_vendor_not_found' === $error_code || 404 === $http_status ) {
			$category = 'vendor_not_found';
			$title    = 'فروشگاه مورد نظر برای این توکن پیدا نشد';
			$fix      = 'بررسی کنید شناسه فروشگاه صحیح باشد و این فروشگاه متعلق به همین کاربر باشد.';
		} elseif ( false !== strpos( strtolower( $message . ' ' . $detail ), 'belong' ) || false !== strpos( $message . ' ' . $detail, 'متعلق' ) ) {
			$category = 'vendor_not_owned_by_user';
			$title    = 'این فروشگاه متعلق به همین کاربر نیست';
			$fix      = 'Vendor ID یا Vendor URL را با فروشگاهی که برای همین حساب ثبت شده است جایگزین کنید.';
		} elseif ( false !== strpos( strtolower( $message . ' ' . $detail ), 'website' ) || false !== strpos( $message, 'وب‌سایتی' ) ) {
			$category = 'vendor_not_website_or_approved';
			$title    = 'فروشگاه برای API مجاز نیست';
			$fix      = 'فروشگاه باید از نوع وب‌سایتی و تأییدشده باشد.';
		} elseif ( false !== strpos( strtolower( $message . ' ' . $detail ), 'could not resolve host' ) || false !== strpos( strtolower( $message . ' ' . $detail ), 'dns' ) ) {
			$category = 'api_dns_error';
			$title    = 'سرور هاست شما به API بازارباشه دسترسی DNS ندارد';
			$fix      = 'اتصال DNS سرور هاست را بررسی کنید یا با پشتیبانی هاست تماس بگیرید.';
			$retry    = 'این خطا موقتی هم می‌تواند باشد. بعد از چند دقیقه دوباره تلاش کنید.';
		} elseif ( false !== strpos( strtolower( $message . ' ' . $detail ), 'timed out' ) || false !== strpos( strtolower( $message . ' ' . $detail ), 'timeout' ) ) {
			$category = 'timeout';
			$title    = 'درخواست به API با timeout مواجه شد';
			$fix      = 'بعداً دوباره تلاش کنید یا محدودیت‌های شبکه و فایروال سرور را بررسی کنید.';
			$retry    = 'این خطا معمولاً موقتی است و می‌توانید مجدداً همگام‌سازی را اجرا کنید.';
		} elseif ( false !== strpos( strtolower( $message . ' ' . $detail ), 'ssl' ) ) {
			$category = 'ssl_error';
			$title    = 'ارتباط امن SSL با API برقرار نشد';
			$fix      = 'تنظیمات SSL سرور، کتابخانه cURL و گواهی‌های ریشه را بررسی کنید.';
			$retry    = 'پس از اصلاح تنظیمات SSL دوباره همگام‌سازی را اجرا کنید.';
		} elseif ( 400 === $http_status ) {
			$category = 'bad_request';
			$title    = 'درخواست ارسالی به API معتبر نیست';
			$fix      = 'مقادیر فیلدهای محصول و تنظیمات فروشگاه را بررسی کنید.';
		} elseif ( 403 === $http_status ) {
			$category = 'forbidden';
			$title    = 'توکن برای این عملیات دسترسی ندارد';
			$fix      = 'سطح دسترسی توکن را بررسی کنید و مطمئن شوید permission لازم فعال است.';
		} elseif ( 422 === $http_status ) {
			$category = 'invalid_product_payload';
			$title    = 'اطلاعات محصول برای API ناقص یا نامعتبر است';
			$fix      = 'نام، قیمت، توضیحات، لینک محصول و سایر فیلدهای اجباری را بررسی کنید.';
		} elseif ( 500 === $http_status ) {
			$category = 'api_server_error';
			$title    = 'سرور API بازارباشه با خطا مواجه شد';
			$fix      = 'بعداً دوباره تلاش کنید. اگر ادامه داشت با پشتیبانی بازارباشه تماس بگیرید.';
			$retry    = 'این خطا معمولاً سمت سرور است و بهتر است کمی بعد دوباره امتحان کنید.';
		} elseif ( 'bbsync_image_upload_failed' === $error_code || 'bbsync_missing_file' === $error_code || false !== strpos( strtolower( $message ), 'image' ) ) {
			$category = 'image_upload_failed';
			$title    = 'آپلود تصویر محصول ناموفق بود';
			$fix      = 'صحت فایل تصویر، دسترسی فایل‌ها و محدودیت حجم را بررسی کنید.';
		} elseif ( 'bbsync_product_missing' === $error_code ) {
			$category = 'woocommerce_product_not_found';
			$title    = 'محصول ووکامرس پیدا نشد';
			$fix      = 'محصول ممکن است حذف شده باشد یا هنوز کامل ذخیره نشده باشد. دوباره ذخیره و تلاش کنید.';
		} elseif ( false !== strpos( strtolower( $message ), 'mapping' ) ) {
			$category = 'mapping_missing';
			$title    = 'نگاشت محصول بین ووکامرس و بازارباشه پیدا نشد';
			$fix      = 'برای این محصول یک بار Sync Now اجرا کنید تا نگاشت دوباره ساخته شود.';
		} elseif ( false !== strpos( strtolower( $message ), 'payload' ) ) {
			$category = 'invalid_product_payload';
			$title    = 'ساخت payload محصول ناموفق بود';
			$fix      = 'فیلدهای محصول را بررسی کنید تا مقادیر اجباری کامل باشند.';
		} elseif ( 'skipped' === $status ) {
			$category = 'sync_skipped';
			$title    = 'همگام‌سازی اجرا نشد';
			$fix      = 'دلیل skip را در لاگ بررسی کنید و در صورت نیاز تنظیمات همگام‌سازی را اصلاح کنید.';
		}

		return array(
			'category'       => $category,
			'title'          => $title,
			'user_message'   => $message,
			'technical'      => $detail,
			'endpoint'       => $endpoint,
			'http_status'    => $http_status,
			'raw_response'   => $raw_body,
			'product_id'     => $context['woo_product_id'] ?? null,
			'suggested_fix'  => $fix,
			'retry_guidance' => $retry,
		);
	}
}
