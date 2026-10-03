<?php
/**
 * GitHub release updates for the public Pencil plugin.
 *
 * Only a versioned ZIP attached to the latest stable GitHub release can be
 * offered as an update. Ordinary commits and prereleases are ignored.
 *
 * @package PencilByRino
 */

defined( 'ABSPATH' ) || exit;

final class Pencil_Updater {
	const REPO      = 'rinothecoder/pencil-by-rino';
	const CACHE_KEY = 'pencil_github_release';
	const SLUG      = 'pencil-by-rino';

	/**
	 * Register WordPress update hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( PENCIL_PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_post_pencil_check_updates', array( __CLASS__, 'force_check' ) );
	}

	/**
	 * Offer a newer published release to WordPress.
	 *
	 * @param array|false $update     Existing update information.
	 * @param array       $plugin_data Installed plugin headers.
	 * @param string      $plugin_file Installed plugin path.
	 * @param array       $locales     Installed locales.
	 * @return array|false
	 */
	public static function check( $update, $plugin_data, $plugin_file, $locales ) {
		if ( plugin_basename( PENCIL_PLUGIN_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = self::latest_release();
		if ( ! $release || version_compare( $release['version'], $plugin_data['Version'], '<=' ) ) {
			return false;
		}

		return array(
			'version'      => $release['version'],
			'slug'         => self::SLUG,
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires_php' => '7.4',
		);
	}

	/**
	 * Show GitHub release details in WordPress's plugin information dialog.
	 *
	 * @param mixed  $result Existing result.
	 * @param string $action Requested API action.
	 * @param object $args   Requested plugin.
	 * @return mixed
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = self::latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Pencil by Rino',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => 'Rino de Boer',
			'homepage'      => $release['url'],
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => esc_html__( 'Frontend content editing for AI-built WordPress themes.', 'pencil-by-rino' ),
				'changelog'   => wpautop( esc_html( $release['body'] ) ),
			),
		);
	}

	/**
	 * Add a manual check beside the plugin's normal actions.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public static function action_links( $links ) {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}

		$url     = wp_nonce_url( admin_url( 'admin-post.php?action=pencil_check_updates' ), 'pencil_check_updates' );
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'pencil-by-rino' ) . '</a>';
		return $links;
	}

	/**
	 * Clear cached release information and request a fresh WordPress check.
	 *
	 * @return void
	 */
	public static function force_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You cannot update plugins.', 'pencil-by-rino' ) );
		}
		check_admin_referer( 'pencil_check_updates' );
		delete_transient( self::CACHE_KEY );
		delete_site_transient( 'update_plugins' );
		wp_safe_redirect( admin_url( 'update-core.php?force-check=1' ) );
		exit;
	}

	/**
	 * Fetch and cache the latest stable release with an installable ZIP.
	 *
	 * @return array|null
	 */
	private static function latest_release() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'Pencil-by-Rino/' . PENCIL_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY, '', HOUR_IN_SECONDS );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			set_transient( self::CACHE_KEY, '', HOUR_IN_SECONDS );
			return null;
		}

		$tag = (string) $data['tag_name'];
		if ( ! preg_match( '/^v?([0-9]+(?:\.[0-9]+){2})$/', $tag, $matches ) ) {
			set_transient( self::CACHE_KEY, '', HOUR_IN_SECONDS );
			return null;
		}

		$version = $matches[1];
		$filename = 'pencil-by-rino-' . $version . '.zip';
		$package = 'https://github.com/' . self::REPO . '/releases/download/' . $tag . '/' . $filename;
		$found = false;
		foreach ( isset( $data['assets'] ) && is_array( $data['assets'] ) ? $data['assets'] : array() as $asset ) {
			if ( isset( $asset['name'], $asset['browser_download_url'] ) && $filename === $asset['name'] && $package === $asset['browser_download_url'] ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			set_transient( self::CACHE_KEY, '', HOUR_IN_SECONDS );
			return null;
		}

		$release = array(
			'version' => $version,
			'package' => $package,
			'url'     => 'https://github.com/' . self::REPO . '/releases/tag/' . $tag,
			'body'    => isset( $data['body'] ) ? (string) $data['body'] : '',
		);
		set_transient( self::CACHE_KEY, $release, 6 * HOUR_IN_SECONDS );
		return $release;
	}
}
