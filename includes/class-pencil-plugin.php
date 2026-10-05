<?php
/**
 * Plugin bootstrap and editor interface.
 *
 * @package Pencilino
 */

defined( 'ABSPATH' ) || exit;

final class Pencil_Plugin {
	/**
	 * Register plugin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_editor_assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_editor' ), 5 );
	}

	/**
	 * Whether the current visitor may use Pencilino.
	 *
	 * Administrators and Editors by default. Contributors and Authors have
	 * `edit_posts` but not `edit_pages`, so they are left out.
	 *
	 * @return bool
	 */
	public static function can_edit() {
		$can_edit = is_user_logged_in() && ( current_user_can( 'edit_pages' ) || ( Pencil_Client::enabled() && current_user_can( 'pencil_edit_content' ) ) );
		if ( Pencil_Client::is_client() && ! Pencil_Client::enabled() ) {
			$can_edit = false;
		}

		/**
		 * Filter who may open Pencilino and save content.
		 *
		 * @param bool $can_edit Whether the current user may edit.
		 */
		return (bool) apply_filters( 'pencil_can_edit', $can_edit );
	}

	/**
	 * Register save endpoints.
	 *
	 * @return void
	 */
	public static function register_rest_routes() {
		register_rest_route(
			'pencil/v1',
			'/fields/(?P<id>[a-z0-9._-]+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'save_field' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => array(
					'id'      => array(
						'required' => true,
					),
					'value'   => array(
						'required' => true,
					),
					'page_id' => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
				),
			)
		);
	}

	/**
	 * Save an editable field.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_field( WP_REST_Request $request ) {
		$id     = (string) $request->get_param( 'id' );
		$schema = Pencil_Fields::get_schema( $id );

		if ( ! $schema ) {
			return new WP_Error(
				'pencil_unknown_field',
				__( 'This content field is no longer available.', 'pencilino-by-rino' ),
				array( 'status' => 404 )
			);
		}

		$old_value = Pencil_Fields::get_current_value( $id );
		$value     = Pencil_Fields::save( $id, $request->get_param( 'value' ) );

		if ( is_wp_error( $value ) ) {
			return $value;
		}

		Pencil_Activity::record(
			$id,
			$schema,
			$old_value,
			$value,
			absint( $request->get_param( 'page_id' ) )
		);

		$response = array(
			'id'    => $id,
			'type'  => $schema['type'],
			'value' => $value,
		);

		if ( 'image' === $schema['type'] ) {
			$response['image'] = Pencil_Fields::get_image_data(
				$value,
				isset( $schema['size'] ) ? $schema['size'] : 'full'
			);
		}

		return rest_ensure_response(
			$response
		);
	}

	/**
	 * Load editor-only assets for authorised users.
	 *
	 * @return void
	 */
	public static function enqueue_editor_assets() {
		if ( ! self::can_edit() ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'pencil-editor',
			PENCIL_PLUGIN_URL . 'assets/css/editor.css',
			array( 'dashicons' ),
			self::asset_version( 'assets/css/editor.css' )
		);

		wp_enqueue_script(
			'pencil-editor',
			PENCIL_PLUGIN_URL . 'assets/js/editor.js',
			array(),
			self::asset_version( 'assets/js/editor.js' ),
			true
		);

		wp_localize_script(
			'pencil-editor',
			'pencilEditor',
			array(
				'restUrl' => esc_url_raw( rest_url( 'pencil/v1/fields/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'pageId'  => get_queried_object_id(),
				'commentsUrl' => esc_url_raw( rest_url( 'pencil/v1/comments' ) ),
				'clientMode' => Pencil_Client::clean_admin(),
				'labels'  => array(
					'openPencil'  => __( 'Open Pencilino', 'pencilino-by-rino' ),
					'closePencil' => __( 'Close Pencilino', 'pencilino-by-rino' ),
					'saved'       => __( 'Saved', 'pencilino-by-rino' ),
					'error'       => __( 'Something went wrong. Please try again.', 'pencilino-by-rino' ),
					'chooseImage' => __( 'Choose image', 'pencilino-by-rino' ),
					'useImage'    => __( 'Use image', 'pencilino-by-rino' ),
					'postComment' => __( 'Post comment', 'pencilino-by-rino' ),
					'commentSaved' => __( 'Comment posted', 'pencilino-by-rino' ),
					'writeComment' => __( 'Write a comment first.', 'pencilino-by-rino' ),
					'saveChanges' => __( 'Save changes', 'pencilino-by-rino' ),
					'section' => __( 'Page section', 'pencilino-by-rino' ),
					'locationMissing' => __( 'The original element has changed. This comment is still saved with its page context.', 'pencilino-by-rino' ),
					'selectText'  => __( 'Select the text you want to turn into a link first.', 'pencilino-by-rino' ),
				),
			)
		);
	}

	/**
	 * Render the minimal editor interface.
	 *
	 * @return void
	 */
	public static function render_editor() {
		if ( ! self::can_edit() ) {
			return;
		}
		?>
		<div class="pencil-ui pencil-toolbar" data-pencil-toolbar hidden>
			<button class="pencil-toolbar__select" type="button" data-pencil-toggle aria-pressed="false">
				<span data-pencil-toggle-label><?php esc_html_e( 'Open Pencilino', 'pencilino-by-rino' ); ?></span>
				<span class="dashicons dashicons-edit" aria-hidden="true"></span>
			</button>
		</div>
		<?php if ( Pencil_Client::clean_admin() ) : ?>
			<a class="pencil-ui pencil-client-logout" href="<?php echo esc_url( wp_logout_url( Pencil_Client::logged_out_url() ) ); ?>"><?php esc_html_e( 'Log out', 'pencilino-by-rino' ); ?></a>
		<?php endif; ?>

		<div class="pencil-ui pencil-status" data-pencil-status role="status" aria-live="polite" hidden></div>

		<div class="pencil-ui pencil-highlight" data-pencil-highlight hidden>
			<div class="pencil-highlight__label">
				<span class="pencil-highlight__text" data-pencil-highlight-label></span>
				<a
					class="pencil-highlight__action"
					data-pencil-managed-edit
					href="#"

					hidden
				></a>
			</div>
		</div>

		<div
			class="pencil-ui pencil-popup"
			data-pencil-popup
			role="dialog"
			aria-labelledby="pencil-popup-title"
			hidden
		>
			<form data-pencil-form>
				<div class="pencil-popup__label" id="pencil-popup-title" data-pencil-popup-label></div>
				<div class="pencil-popup__switch" role="tablist" aria-label="<?php esc_attr_e( 'Selected element action', 'pencilino-by-rino' ); ?>">
					<button type="button" role="tab" aria-selected="true" data-pencil-mode="edit"><?php esc_html_e( 'Edit content', 'pencilino-by-rino' ); ?></button>
					<button type="button" role="tab" aria-selected="false" data-pencil-mode="comment"><?php esc_html_e( 'Leave a comment', 'pencilino-by-rino' ); ?></button>
				</div>
				<div class="pencil-popup__comment" data-pencil-comment-editor hidden>
					<label class="pencil-popup__field"><span class="pencil-popup__field-label"><?php esc_html_e( 'Comment', 'pencilino-by-rino' ); ?></span><textarea class="pencil-popup__input" rows="4" maxlength="5000" data-pencil-comment-input></textarea></label>
					<p class="pencil-popup__hint"><?php esc_html_e( 'Private feedback for your site team. This does not change the page.', 'pencilino-by-rino' ); ?></p>
				</div>
				<div class="pencil-popup__managed" data-pencil-managed-editor hidden>
					<p class="pencil-popup__hint" data-pencil-managed-description></p>
					<a class="pencil-popup__button pencil-popup__button--primary" data-pencil-popup-managed-link href="#"><?php esc_html_e( 'Open editor', 'pencilino-by-rino' ); ?></a>
				</div>
				<div class="pencil-popup__comment" data-pencil-comment-detail hidden><p data-pencil-comment-author class="pencil-popup__hint"></p><p data-pencil-comment-body class="pencil-popup__note"></p></div>
				<div data-pencil-text-editor>
					<textarea class="pencil-popup__input" id="pencil-popup-value" name="value" rows="1" wrap="soft" autocomplete="off" aria-labelledby="pencil-popup-title" data-pencil-input></textarea>
				</div>
				<div class="pencil-popup__richtext-editor" data-pencil-richtext-editor hidden>
					<div class="pencil-popup__toolbar" role="toolbar" aria-label="<?php esc_attr_e( 'Formatting', 'pencilino-by-rino' ); ?>">
						<?php
						$tools = array(
							'bold'                => array( 'editor-bold', __( 'Bold', 'pencilino-by-rino' ) ),
							'italic'              => array( 'editor-italic', __( 'Italic', 'pencilino-by-rino' ) ),
							'link'                => array( 'admin-links', __( 'Link', 'pencilino-by-rino' ) ),
							'insertUnorderedList' => array( 'editor-ul', __( 'Bulleted list', 'pencilino-by-rino' ) ),
							'insertOrderedList'   => array( 'editor-ol', __( 'Numbered list', 'pencilino-by-rino' ) ),
						);

						foreach ( $tools as $command => $tool ) :
							?>
							<button class="pencil-popup__tool" type="button" data-pencil-command="<?php echo esc_attr( $command ); ?>" aria-label="<?php echo esc_attr( $tool[1] ); ?>" title="<?php echo esc_attr( $tool[1] ); ?>" aria-pressed="false">
								<span class="dashicons dashicons-<?php echo esc_attr( $tool[0] ); ?>" aria-hidden="true"></span>
							</button>
						<?php endforeach; ?>
					</div>
					<div class="pencil-popup__richtext" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="pencil-popup-title" data-pencil-richtext-input></div>
					<div class="pencil-popup__link" data-pencil-link-row hidden>
						<input class="pencil-popup__input" type="text" autocomplete="off" inputmode="url" placeholder="<?php esc_attr_e( 'https://example.com or /contact', 'pencilino-by-rino' ); ?>" aria-label="<?php esc_attr_e( 'Link address', 'pencilino-by-rino' ); ?>" data-pencil-link-input>
						<button class="pencil-popup__button pencil-popup__button--quiet" type="button" data-pencil-link-apply>
							<?php esc_html_e( 'Apply', 'pencilino-by-rino' ); ?>
						</button>
					</div>
				</div>
				<div class="pencil-popup__button-editor" data-pencil-button-editor hidden>
					<label class="pencil-popup__field">
						<span class="pencil-popup__field-label"><?php esc_html_e( 'Text', 'pencilino-by-rino' ); ?></span>
						<input class="pencil-popup__input" type="text" autocomplete="off" data-pencil-button-text>
					</label>
					<label class="pencil-popup__field">
						<span class="pencil-popup__field-label"><?php esc_html_e( 'Link', 'pencilino-by-rino' ); ?></span>
						<input class="pencil-popup__input" type="text" autocomplete="off" inputmode="url" placeholder="<?php esc_attr_e( 'https://example.com or /contact', 'pencilino-by-rino' ); ?>" data-pencil-button-url>
					</label>
				</div>
				<div class="pencil-popup__image-editor" data-pencil-image-editor hidden>
					<div class="pencil-popup__image-preview">
						<img src="" alt="" data-pencil-image-preview>
					</div>
					<button class="pencil-popup__replace" type="button" data-pencil-replace-image>
						<?php esc_html_e( 'Replace image', 'pencilino-by-rino' ); ?>
					</button>
				</div>
				<p class="pencil-popup__error" data-pencil-error hidden></p>
				<div class="pencil-popup__actions">
					<button class="pencil-popup__button pencil-popup__button--quiet" type="button" data-pencil-cancel>
						<?php esc_html_e( 'Close', 'pencilino-by-rino' ); ?>
					</button>
					<button class="pencil-popup__button pencil-popup__button--primary" type="submit" data-pencil-save>
						<?php esc_html_e( 'Save changes', 'pencilino-by-rino' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Return a cache-busting asset version.
	 *
	 * @param string $relative_path Plugin-relative path.
	 * @return string
	 */
	private static function asset_version( $relative_path ) {
		$path = PENCIL_PLUGIN_PATH . $relative_path;

		return file_exists( $path ) ? (string) filemtime( $path ) : PENCIL_VERSION;
	}
}
