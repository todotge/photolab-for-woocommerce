<?php
/**
 * PHPUnit bootstrap for Photolab.
 *
 * Defines WordPress stubs so pure unit tests run without a full WP install.
 *
 * @package Photolab
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', true );
}

defined( 'PHOTOLAB_VERSION' ) || define( 'PHOTOLAB_VERSION', '2.2.0' );
defined( 'PHOTOLAB_DB_VERSION' ) || define( 'PHOTOLAB_DB_VERSION', '1.4.0' );
defined( 'PHOTOLAB_PLUGIN_FILE' ) || define( 'PHOTOLAB_PLUGIN_FILE', dirname( __DIR__ ) . '/photolab.php' );
defined( 'PHOTOLAB_PLUGIN_DIR' ) || define( 'PHOTOLAB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
defined( 'PHOTOLAB_PLUGIN_URL' ) || define( 'PHOTOLAB_PLUGIN_URL', 'http://example.com/wp-content/plugins/photolab/' );
defined( 'PHOTOLAB_MIN_PHP' ) || define( 'PHOTOLAB_MIN_PHP', '8.1' );
defined( 'PHOTOLAB_MIN_WP' ) || define( 'PHOTOLAB_MIN_WP', '6.5' );
defined( 'PHOTOLAB_MIN_WC' ) || define( 'PHOTOLAB_MIN_WC', '8.0' );

defined( 'KB_IN_BYTES' ) || define( 'KB_IN_BYTES', 1024 );
defined( 'MB_IN_BYTES' ) || define( 'MB_IN_BYTES', 1024 * KB_IN_BYTES );
defined( 'GB_IN_BYTES' ) || define( 'GB_IN_BYTES', 1024 * MB_IN_BYTES );

defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );
defined( 'OBJECT' ) || define( 'OBJECT', 'OBJECT' );
defined( 'OBJECT_K' ) || define( 'OBJECT_K', 'OBJECT_K' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'ARRAY_N' ) || define( 'ARRAY_N', 'ARRAY_N' );

// phpcs:disable NeutronStandard.Functions.TypeHint.NoArgumentType -- stubs

if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new class() {
		public $last_error = '';
		public $prefix = 'wp_';
		public $blogs = [];
		public $update_return = 1;

		public function prepare( $q, ...$args ) { return $q; }
		public function update( $table, $data, $where, $format = null ) {
			if ( $this->update_return === 0 ) return 0;
			return $this->update_return;
		}
		public function get_row( $q, $output = OBJECT ) { return null; }
		public function get_var( $q ) { return 0; }
		public function get_results( $q ) { return []; }
		public function insert( $table, $data, $format = null ) { return 1; }
		public function delete( $table, $where, $format = null ) { return 1; }
		public function query( $q ) { return true; }
		public function _insert_replace_helper( $table, $data, $format = null, $type = 'INSERT' ) { return 1; }
		public function esc_like( $str ) { return $str; }
		public function remove_placeholder_escape( $str ) { return $str; }
	};
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( $method, $message, $version ) {}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, ...$args ) {
		return $args[0] ?? null;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callable, $priority = 10, $accepted_args = 1 ) {}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callable, $priority = 10, $accepted_args = 1 ) {}
}

if ( ! function_exists( '__' ) ) {
	function __( $t, $d = '' ) { return $t; }
}

if ( ! function_exists( '_e' ) ) {
	function _e( $t, $d = '' ) { echo $t; }
}

if ( ! function_exists( '_n' ) ) {
	function _n( $s, $p, $n, $d = '' ) { return $n > 1 ? $p : $s; }
}

if ( ! function_exists( 'wp_convert_hr_to_bytes' ) ) {
	function wp_convert_hr_to_bytes( $value ) {
		$value = strtolower( trim( $value ) );
		$bytes = (int) $value;
		if ( str_contains( $value, 'g' ) ) {
			$bytes *= GB_IN_BYTES;
		} elseif ( str_contains( $value, 'm' ) ) {
			$bytes *= MB_IN_BYTES;
		} elseif ( str_contains( $value, 'k' ) ) {
			$bytes *= KB_IN_BYTES;
		}
		return $bytes;
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
}

if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
	function wp_using_ext_object_cache() { return false; }
}

if ( ! function_exists( 'wp_cache_add' ) ) {
	function wp_cache_add( $key, $data, $group = '', $expire = 0 ) { return true; }
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) { return true; }
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag, ...$args ) {}
}

if ( ! function_exists( 'size_format' ) ) {
	function size_format( $bytes, $decimals = 2 ) {
		return number_format( $bytes, $decimals ) . ' B';
	}
}

if ( ! function_exists( 'remove_all_filters' ) ) {
	$GLOBALS['wp_filter'] = array();
	function remove_all_filters( $tag, $priority = false ) {
		unset( $GLOBALS['wp_filter'][ $tag ] );
	}
}

if ( ! isset( $GLOBALS['_photolab_test_options'] ) ) {
	$GLOBALS['_photolab_test_options'] = array();
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['_photolab_test_options'] ) ? $GLOBALS['_photolab_test_options'][ $key ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['_photolab_test_options'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $key ) {
		unset( $GLOBALS['_photolab_test_options'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		update_option( '_transient_' . $key, $value );
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return get_option( '_transient_' . $key, false );
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		delete_option( '_transient_' . $key );
		return true;
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		return [
			'dir'     => '/tmp',
			'url'     => 'http://example.com/wp-content/uploads',
			'basedir' => '/tmp',
			'baseurl' => 'http://example.com/wp-content/uploads',
		];
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $path ) {
		return is_dir( $path ) || mkdir( $path, 0755, true );
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '', $filter = 'raw' ) {
		if ( 'version' === $show ) {
			return '8.0';
		}
		return '';
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $name ) {
		return preg_replace( '/[^a-zA-Z0-9._-]/', '_', $name );
	}
}

if ( ! function_exists( 'wp_basename' ) ) {
	function wp_basename( $path, $suffix = '' ) {
		return basename( $path, $suffix );
	}
}

if ( ! function_exists( 'wp_unique_filename' ) ) {
	function wp_unique_filename( $dir, $filename ) {
		return $filename;
	}
}

if ( ! function_exists( 'wp_check_filetype' ) ) {
	function wp_check_filetype( $filename, $mimes = null ) {
		return [ 'ext' => 'jpg', 'type' => 'image/jpeg' ];
	}
}

if ( ! function_exists( 'wp_insert_attachment' ) ) {
	function wp_insert_attachment( $args, $file = '', $parent = 0 ) {
		return 100;
	}
}

if ( ! function_exists( 'wp_delete_attachment' ) ) {
	$GLOBALS['_photolab_deleted_attachments'] = [];
	function wp_delete_attachment( $attachment_id, $force = false ) {
		$GLOBALS['_photolab_deleted_attachments'][] = $attachment_id;
		return (object) [ 'ID' => $attachment_id ];
	}
}

if ( ! function_exists( 'wp_delete_post' ) ) {
	$GLOBALS['_photolab_deleted_posts'] = [];
	function wp_delete_post( $post_id, $force = false ) {
		$GLOBALS['_photolab_deleted_posts'][] = $post_id;
		return (object) [ 'ID' => $post_id ];
	}
}

if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $data, $e = false ) {
		return 1000 + ( $data['ID'] ?? count( $GLOBALS ) );
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $data, $e = false ) {
		return $data['ID'] ?? 0;
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id, $o = OBJECT ) {
		return (object) [ 'ID' => $id, 'post_type' => 'product' ];
	}
}

if ( ! function_exists( 'set_post_thumbnail' ) ) {
	function set_post_thumbnail( $post, $thumbnail_id ) {}
}

if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( $attachment_id ) {
		return '/tmp/test-watermarked-' . $attachment_id . '.jpg';
	}
}

if ( ! function_exists( 'wp_get_image_editor' ) ) {
	function wp_get_image_editor( $file ) {
		return new class() {
			public function resize( $width, $height, $crop = false ) { return true; }
			public function save( $dest = null ) {
				return [
					'path'   => '/tmp/thumb.jpg',
					'file'   => 'thumb.jpg',
					'width'  => 300,
					'height' => 300,
					'mime'   => 'image/jpeg',
				];
			}
		};
	}
}

if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
	function wp_generate_attachment_metadata( $attachment_id, $file ) {
		return [
			'width'  => 1200,
			'height' => 800,
			'file'   => basename( $file ),
			'sizes'  => [
				'thumbnail'                      => [ 'file' => 'thumb.jpg', 'width' => 150, 'height' => 150, 'mime-type' => 'image/jpeg' ],
				'medium'                         => [ 'file' => 'medium.jpg', 'width' => 300, 'height' => 200, 'mime-type' => 'image/jpeg' ],
				'medium_large'                   => [ 'file' => 'medium.jpg', 'width' => 768, 'height' => 512, 'mime-type' => 'image/jpeg' ],
				'large'                          => [ 'file' => 'large.jpg', 'width' => 1024, 'height' => 683, 'mime-type' => 'image/jpeg' ],
				'1536x1536'                      => [ 'file' => '1536.jpg', 'width' => 1536, 'height' => 1024, 'mime-type' => 'image/jpeg' ],
				'2048x2048'                      => [ 'file' => '2048.jpg', 'width' => 2048, 'height' => 1365, 'mime-type' => 'image/jpeg' ],
				'woocommerce_thumbnail'           => [ 'file' => 'shop.jpg', 'width' => 300, 'height' => 300, 'mime-type' => 'image/jpeg' ],
				'woocommerce_single'              => [ 'file' => 'single.jpg', 'width' => 600, 'height' => 600, 'mime-type' => 'image/jpeg' ],
				'woocommerce_gallery_thumbnail'   => [ 'file' => 'gallery.jpg', 'width' => 100, 'height' => 100, 'mime-type' => 'image/jpeg' ],
			],
		];
	}
}

if ( ! function_exists( 'wp_update_attachment_metadata' ) ) {
	function wp_update_attachment_metadata( $id, $meta ) {}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql', $gmt = false ) {
		return $gmt ? gmdate( 'Y-m-d H:i:s' ) : date( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'wp_insert_term' ) ) {
	$GLOBALS['_photolab_terms'] = [];
	function wp_insert_term( $name, $taxonomy, $args = [] ) {
		$id = count( $GLOBALS['_photolab_terms'] ) + 100;
		$GLOBALS['_photolab_terms'][ $id ] = [ 'name' => $name, 'taxonomy' => $taxonomy ];
		return [ 'term_id' => $id, 'term_taxonomy_id' => $id ];
	}
}

if ( ! function_exists( 'wp_delete_term' ) ) {
	function wp_delete_term( $term_id, $taxonomy ) {
		unset( $GLOBALS['_photolab_terms'][ $term_id ] );
		return true;
	}
}

if ( ! function_exists( 'term_exists' ) ) {
	function term_exists( $name, $taxonomy = '', $parent = null ) {
		return null;
	}
}

if ( ! function_exists( 'wp_set_object_terms' ) ) {
	function wp_set_object_terms( $o, $t, $tx, $a = false ) {
		return [ $t ];
	}
}

if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $i, $tx, $a = [] ) {
		return [];
	}
}

if ( ! function_exists( 'has_term' ) ) {
	function has_term( $t = '', $tx = '', $p = null ) {
		return false;
	}
}

if ( ! function_exists( 'wp_mail' ) ) {
	$GLOBALS['_photolab_mail_log'] = [];
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = [] ) {
		$GLOBALS['_photolab_mail_log'][] = compact( 'to', 'subject', 'message' );
		return true;
	}
}

if ( ! class_exists( 'WC_Product_Simple' ) ) {
	class WC_Product_Simple {
		private array $props = [];

		public function set_name( $v ) { $this->props['name'] = $v; return $this; }
		public function set_virtual( $v ) { $this->props['virtual'] = $v; return $this; }
		public function set_downloadable( $v ) { $this->props['downloadable'] = $v; return $this; }
		public function set_downloads( $v ) { $this->props['downloads'] = $v; return $this; }
		public function set_regular_price( $v ) { $this->props['price'] = $v; return $this; }
		public function set_status( $v ) { $this->props['status'] = $v; return $this; }
		public function set_category_ids( $v ) { $this->props['category_ids'] = $v; return $this; }
		public function save() { return 9999; }
		public function get_id() { return $this->props['id'] ?? 9999; }
	}
}

if ( ! function_exists( 'wc_get_logger' ) ) {
	function wc_get_logger() {
		return new class() {
			public array $logs = [];

			public function __call( $name, $args ) {
				$this->logs[] = [ 'level' => $name, 'message' => $args[0], 'context' => $args[1] ?? [] ];
			}
		};
	}
}

if ( ! function_exists( 'wc_get_image_size' ) ) {
	function wc_get_image_size( $name ) {
		return [ 'width' => 300, 'height' => 300, 'crop' => 1 ];
	}
}

if ( ! function_exists( 'WC' ) ) {
	function WC() {
		return new class() {
			public function queue() {
				return new class() {
					public function schedule_single( $timestamp, $hook, $args = [], $group = '' ) {
						return 8888;
					}
				};
			}
		};
	}
}

if ( ! function_exists( 'as_schedule_single_action' ) ) {
	function as_schedule_single_action( $timestamp, $hook, $args = [], $group = '', $priority = 10, $unique = false ) {
		return 9999;
	}
}

if ( ! function_exists( 'as_has_scheduled_action' ) ) {
	function as_has_scheduled_action( $hook, $args = [], $group = '' ) {
		return false;
	}
}

if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
	function as_unschedule_all_actions( $hook, $args = [], $group = '' ) {}
}

if ( ! function_exists( 'as_next_scheduled_action' ) ) {
	function as_next_scheduled_action( $hook, $args = [], $group = '' ) {
		return false;
	}
}

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( $title, $menu, $cap, $slug, $cb = '', $icon = '', $pos = null ) {
		return $slug;
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $h, $s = '', $d = [], $v = null ) {}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $h, $s = '', $d = [], $v = null, $in_f = false ) {}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $h, $n, $d ) {}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability, ...$args ) {
		return true;
	}
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( $action, $query_arg = false, $die = true ) {
		return true;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $a = -1 ) {
		return 'test_nonce';
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $p = '', $s = '' ) {
		return 'http://example.com/wp-admin/' . $p;
	}
}

if ( ! function_exists( 'wp_hash' ) ) {
	function wp_hash( string $data ): string {
		return md5( $data );
	}
}

if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
	function wp_check_filetype_and_ext( string $file, string $filename ): array {
		return array(
			'ext'             => 'jpg',
			'type'            => 'image/jpeg',
			'proper_filename' => $filename,
		);
	}
}

if ( ! function_exists( 'wp_handle_upload' ) ) {
	function wp_handle_upload( array $file, array $overrides ): array {
		return array(
			'file' => '/tmp/Photolab/photos/' . ( $file['name'] ?? 'photo.jpg' ),
			'url'  => 'http://example.com/wp-content/uploads/Photolab/photos/' . ( $file['name'] ?? 'photo.jpg' ),
			'type' => 'image/jpeg',
		);
	}
}

if ( ! function_exists( 'wp_image_editor_supports' ) ) {
	function wp_image_editor_supports( $args ) { return false; }
}

if ( ! function_exists( 'wp_max_upload_size' ) ) {
	function wp_max_upload_size() { return 268435456; }
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) { return 'http://example.com/wp-json/' . ltrim( $path, '/' ); }
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return 1; }
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $params = array();
		private array $headers = array();
		private string $method = 'GET';

		public function __construct( $method = 'GET', $route = '' ) {
			$this->method = is_string( $method ) ? $method : 'GET';
		}

		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}

		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function get_header( string $key ): string {
			return $this->headers[ $key ] ?? '';
		}

		public function set_header( string $key, string $value ): void {
			$this->headers[ $key ] = $value;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private mixed $data;
		private int $status;

		public function __construct( mixed $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data(): mixed {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}
}

if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen() {
		return null;
	}
}

if ( ! function_exists( 'get_admin_page_parent' ) ) {
	function get_admin_page_parent() {
		return '';
	}
}

if ( ! function_exists( 'register_setting' ) ) {
	function register_setting( $g, $n, $a = [] ) {}
}

if ( ! function_exists( 'settings_fields' ) ) {
	function settings_fields( $g ) {
		echo '';
	}
}

if ( ! function_exists( 'do_settings_sections' ) ) {
	function do_settings_sections( $p ) {
		echo '';
	}
}

if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $t ) {
		return htmlspecialchars( $t, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $t, $d = '' ) {
		return $t;
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $t, $d = '' ) {
		echo $t;
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $u, $p = null, $w = '' ) {
		return $u;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $t ) {
		return htmlspecialchars( $t, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) {
		return $s;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $k ) {
		return preg_replace( '/[^a-zA-Z0-9_\-]/', '', $k );
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $f ) {
		return dirname( $f ) . '/';
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $f ) {
		return 'http://example.com/wp-content/plugins/' . basename( dirname( $f ) ) . '/';
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $f ) {
		return basename( dirname( $f ) ) . '/' . basename( $f );
	}
}

if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( $f, $cb ) {}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( $f, $cb ) {}
}

if ( ! function_exists( 'deactivate_plugins' ) ) {
	function deactivate_plugins( $p, $s = false, $n = false ) {}
}

if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( $p ) {
		return true;
	}
}

if ( ! function_exists( 'flush_rewrite_rules' ) ) {
	function flush_rewrite_rules( $h = true ) {}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $u, $a = [] ) {
		return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $u, $a = [] ) {
		return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $r ) {
		return $r['body'] ?? '';
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $t ) {
		return $t instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $m = '', $t = '', $a = [] ) {
		throw new \RuntimeException( $m ?: 'wp_die called' );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private array $errors = [];

		public function __construct( $c = '', $m = '', $d = '' ) {
			if ( $c ) {
				$this->errors[ $c ] = [ $m ];
			}
		}

		public function get_error_message() {
			return current( $this->errors )[0] ?? '';
		}

		public function get_error_code() {
			return key( $this->errors ) ?: '';
		}

		public function has_errors() {
			return ! empty( $this->errors );
		}
	}
}

if ( ! class_exists( 'WP_REST_Controller' ) ) {
	class WP_REST_Controller {
		protected $namespace;
		protected $rest_base;

		public function __construct() {}
		public function register_routes() {}
		public function get_items( $request ) { return new WP_Error(); }
		public function get_item( $request ) { return new WP_Error(); }
		public function create_item( $request ) { return new WP_Error(); }
		public function update_item( $request ) { return new WP_Error(); }
		public function delete_item( $request ) { return new WP_Error(); }
		public function get_items_permissions_check( $request ) { return true; }
		public function get_item_permissions_check( $request ) { return true; }
		public function create_item_permissions_check( $request ) { return true; }
		public function update_item_permissions_check( $request ) { return true; }
		public function delete_item_permissions_check( $request ) { return true; }
		public function get_endpoint_args_for_item_schema( $method = 'GET', $args = [] ) { return []; }
		public function get_item_schema() { return []; }
		public function get_collection_params() { return []; }
		public function prepare_item_for_response( $item, $request ) { return new WP_Error(); }
		public function prepare_response_for_collection( $response ) { return $response; }
	}
}

// REST API stubs needed by controller tests.
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '/' ) { return 'http://example.com/wp-json/' . ltrim( $path, '/' ); }
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $ns, $route, $args = array() ) { return true; }
}
if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $response ) { return $response; }
}
if ( ! function_exists( 'rest_do_request' ) ) {
	function rest_do_request( $request ) {
		return new WP_REST_Response( array( 'success' => true ), 200 );
	}
}

require_once PHOTOLAB_PLUGIN_DIR . 'includes/class-state-machine.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/class-lock.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/class-logger.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/class-watermark-processor.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/class-watermark-job.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/class-cleanup-scheduler.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/class-recovery-scheduler.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/class-download-guard.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/class-admin-notices.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/class-activator.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/class-admin.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/class-database.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/rest/class-upload-controller.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/rest/class-album-controller.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/rest/class-photo-controller.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/rest/class-watermark-controller.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/rest/class-settings-controller.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/rest/class-heartbeat-controller.php';
require_once PHOTOLAB_PLUGIN_DIR . 'photolab.php';
