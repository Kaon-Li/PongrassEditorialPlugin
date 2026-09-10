<?php
/*
 COPYRIGHT

Adopted from the code by Sergio Vaccaro in JSON-RPC PHP


You can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

JSON-RPC PHP is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with JSON-RPC PHP; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/**
 * JSON-RPC 1.0 server for the Pongrass editorial integration.
 *
 * @author Ronin <ronin@pongrass.com.au>
 */

// Read by pep_config.php while bootstrapping WordPress.
define( 'PEP_RPC_REQUEST', true );

require_once __DIR__ . '/pep_config.php';
require_once __DIR__ . '/pep_version.php';
require_once __DIR__ . '/pep_security.php';
require_once __DIR__ . '/pep_logfilehandling.php';

class PEPjsonServer {

	/**
	 * @var PEPjsonServer|null
	 */
	public static $instance;

	/**
	 * @var array<string,callable>
	 */
	public $function_array = array();

	/**
	 * Methods that may be reached with a GET request.
	 *
	 * Everything else requires POST, so that a plain link cannot mutate
	 * content.
	 *
	 * @var string[]
	 */
	private static $read_only_methods = array(
		'pep_get_version',
		'pep_get_categories',
		'pep_get_authors',
		'pep_get_upload_dir',
		'pep_get_published_post',
		'pep_get_post_by_id',
		'pep_get_post_by_form_id',
		'pep_get_recent_post',
		'pep_get_ad_dimensions',
		'pep_get_logfile',
	);

	/**
	 * Post fields the client is allowed to set.
	 *
	 * Anything else in the payload is dropped, which stops a caller from
	 * reaching internal wp_insert_post() controls such as 'filter'.
	 *
	 * @var string[]
	 */
	private static $allowed_post_fields = array(
		'ID',
		'post_author',
		'post_date',
		'post_date_gmt',
		'post_content',
		'post_content_filtered',
		'post_title',
		'post_excerpt',
		'post_status',
		'post_type',
		'comment_status',
		'ping_status',
		'post_password',
		'post_name',
		'to_ping',
		'pinged',
		'post_modified',
		'post_modified_gmt',
		'post_parent',
		'menu_order',
		'post_mime_type',
		'guid',
		'post_category',
		'tags_input',
		'tax_input',
		'meta_input',
	);

	private function __construct() {
	}

	public static function getInstance() {
		if ( ! static::$instance ) {
			static::$instance = new self();
		}

		return static::$instance;
	}

	public function register_handler( $name, $callback_function ) {
		// Was assigning to an undefined local, so nothing was ever registered.
		$this->function_array[ $name ] = $callback_function;
	}

	public function has_handler( $name ) {
		return isset( $this->function_array[ $name ] );
	}

	// ---------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------

	/**
	 * Read a key from an array without tripping undefined-key warnings.
	 *
	 * @param mixed  $source  Array to read from.
	 * @param string $key     Key.
	 * @param mixed  $default Value when missing.
	 * @return mixed
	 */
	private function arg( $source, $key, $default = null ) {
		if ( ! is_array( $source ) || ! array_key_exists( $key, $source ) ) {
			return $default;
		}

		return $source[ $key ];
	}

	/**
	 * Build a JSON-RPC error response.
	 *
	 * @param mixed  $id      Envelope id.
	 * @param int    $code    Error code.
	 * @param string $message Message safe to show the caller.
	 * @return array
	 */
	private function error_response( $id, $code, $message ) {
		return array(
			'id'     => $id,
			'result' => null,
			'error'  => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	/**
	 * Reduce a client payload to fields that are safe to hand to
	 * wp_insert_post() / wp_update_post().
	 *
	 * @param mixed $content Raw params from the request.
	 * @return array
	 */
	private function prepare_post_array( $content ) {
		if ( ! is_array( $content ) ) {
			return array();
		}

		$clean = array();

		foreach ( self::$allowed_post_fields as $field ) {
			if ( array_key_exists( $field, $content ) ) {
				$clean[ $field ] = $content[ $field ];
			}
		}

		foreach ( array( 'ID', 'post_parent', 'menu_order' ) as $int_field ) {
			if ( isset( $clean[ $int_field ] ) ) {
				$clean[ $int_field ] = (int) $clean[ $int_field ];
			}
		}

		// Only accept an author that actually exists, otherwise the post is
		// silently attributed to user 0.
		if ( isset( $clean['post_author'] ) ) {
			$author = (int) $clean['post_author'];
			if ( $author > 0 && get_userdata( $author ) ) {
				$clean['post_author'] = $author;
			} else {
				unset( $clean['post_author'] );
			}
		}

		if ( isset( $clean['post_type'] ) ) {
			$type = sanitize_key( (string) $clean['post_type'] );
			if ( '' === $type || ! post_type_exists( $type ) ) {
				unset( $clean['post_type'] );
			} else {
				$clean['post_type'] = $type;
			}
		}

		if ( isset( $clean['post_status'] ) ) {
			$status = sanitize_key( (string) $clean['post_status'] );
			$known  = get_post_stati();
			if ( '' === $status || ! isset( $known[ $status ] ) ) {
				unset( $clean['post_status'] );
			} else {
				$clean['post_status'] = $status;
			}
		}

		return $clean;
	}

	/**
	 * Turn a wp_insert_post()/wp_update_post() return value into an int,
	 * logging the detail of any WP_Error.
	 *
	 * @param int|WP_Error $result  Return value.
	 * @param string       $context Where it came from, for the log.
	 * @return int Post ID, or 0 on failure.
	 */
	private function post_id_or_zero( $result, $context ) {
		if ( is_wp_error( $result ) ) {
			pep_writelog( $context . ' failed: ' . $result->get_error_message() );
			return 0;
		}

		return (int) $result;
	}

	// ---------------------------------------------------------------
	// Methods
	// ---------------------------------------------------------------

	public function pep_invalid_content() {
		pep_writelog( 'Not a valid json request' );
		header( 'content-type: application/json' );
		echo wp_json_encode( 'Not a valid JSON request' );
	}

	public function pep_submit_post( $request ) {
		$id      = $this->arg( $request, 'id' );
		$params  = $this->arg( $request, 'params', array() );
		$content = $this->prepare_post_array( $params );

		pep_writelog_sensitive( 'Post content is ' . wp_json_encode( $content ) );

		$update_post_id = (int) $this->arg( $params, '@update_post_id', 0 );

		if ( $update_post_id > 0 ) {
			if ( ! get_post( $update_post_id ) ) {
				pep_writelog( 'Update requested for missing post ' . $update_post_id );
				return $this->error_response( $id, -32602, 'Post not found' );
			}

			pep_writelog( 'updating post ' . $update_post_id );

			delete_post_meta( $update_post_id, 'attachment_data' );
			delete_post_meta( $update_post_id, 'image_meta' );

			$content['ID'] = $update_post_id;

			$result = wp_update_post( $content, true, false );
			if ( is_wp_error( $result ) ) {
				pep_writelog( 'Update failed: ' . $result->get_error_message() );
				return $this->error_response( $id, -32000, 'Could not update post' );
			}

			$post_id = $update_post_id;
		} else {
			$post_id = $this->post_id_or_zero( wp_insert_post( $content, true ), 'wp_insert_post' );

			if ( 0 === $post_id ) {
				return $this->error_response( $id, -32000, 'Could not create post' );
			}
		}

		pep_writelog( 'Post ID is ' . $post_id );

		return array(
			'id'     => $id,
			'result' => array(
				'post_id'   => $post_id,
				'permalink' => get_permalink( $post_id ),
				'comment'   => 'post ok',
			),
			'error'  => null,
		);
	}

	public function pep_update_post_status( $request ) {
		$id      = $this->arg( $request, 'id' );
		$content = $this->prepare_post_array( $this->arg( $request, 'params', array() ) );

		pep_writelog_sensitive( 'Post content is ' . wp_json_encode( $content ) );

		if ( empty( $content['ID'] ) || ! get_post( $content['ID'] ) ) {
			return $this->error_response( $id, -32602, 'Post not found' );
		}

		$post_id = $this->post_id_or_zero( wp_update_post( $content, true, false ), 'wp_update_post' );

		if ( 0 === $post_id ) {
			return $this->error_response( $id, -32000, 'Could not update post' );
		}

		return array(
			'id'     => $id,
			'result' => array(
				'post_id' => $post_id,
				'comment' => 'update ok',
			),
			'error'  => null,
		);
	}

	public function startsWith( $haystack, $needle ) {
		return str_starts_with( (string) $haystack, (string) $needle );
	}

	public function get_taxonomies_for_post( $post_id ) {
		$taxonomies = get_object_taxonomies( get_post_type( $post_id ), 'objects' );
		$out        = array();

		foreach ( $taxonomies as $taxonomy_slug => $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy_slug );

			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				$out[ $taxonomy->label ] = $terms;
			}
		}

		return $out;
	}

	public function pep_set_meta_data( $request ) {
		$id              = $this->arg( $request, 'id' );
		$param_meta_data = $this->arg( $request, 'params', array() );

		pep_writelog_sensitive( wp_json_encode( $param_meta_data ), 0 );

		$post_id     = (int) $this->arg( $param_meta_data, 'post_id', 0 );
		$has_term_id = $this->arg( $param_meta_data, 'has_term_id' );
		$meta_info   = $this->arg( $param_meta_data, 'data', array() );
		$cate_added  = false;

		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return $this->error_response( $id, -32602, 'Post not found' );
		}

		/**
		 * Protected meta keys the editorial client is nevertheless allowed
		 * to write.
		 *
		 * Protected meta (a leading underscore) drives internal WordPress
		 * behaviour such as _thumbnail_id, _wp_page_template and _edit_lock,
		 * so it is refused by default. Add specific keys here if an existing
		 * integration relies on setting one.
		 *
		 * @param string[] $allowed Meta keys to permit.
		 */
		$allowed_protected = (array) apply_filters( 'pep_allowed_protected_meta', array() );

		foreach ( (array) $meta_info as $key => $value_array ) {
			$key = (string) $key;

			if ( is_protected_meta( $key, 'post' ) && ! in_array( $key, $allowed_protected, true ) ) {
				pep_writelog( 'Refusing to set protected meta key ' . $key, 0 );
				continue;
			}

			pep_writelog( 'Setting meta for key ' . $key, 0 );
			delete_post_meta( $post_id, $key );

			foreach ( (array) $value_array as $value ) {

				if ( 'category' === $key ) {
					if ( isset( $has_term_id ) ) {
						pep_writelog( 'Assigning category ' . $value, 0 );
						$data_category = get_term_by( 'name', $value, 'category' );

						if ( $data_category ) {
							pep_writelog( 'Category number for ' . $value . ' is ' . $data_category->term_id );
							wp_set_object_terms( $post_id, (int) $data_category->term_id, 'category', $cate_added );
							$cate_added = true;
						} else {
							pep_writelog( 'Category ' . $value . ' not found', 0 );
						}
					} else {
						pep_writelog( 'Bypassing category since termid is set', 0 );
					}
				} elseif ( 'category_term_id' === $key ) {
					pep_writelog( 'Assigning category by id ' . $value, 0 );
					wp_set_object_terms( $post_id, (int) $value, 'category', $cate_added );
					$cate_added = true;
				} elseif ( 'ad_cat' === $key ) {
					if ( taxonomy_exists( 'ad_cat' ) ) {
						pep_writelog( 'Assigning ad categories by id ' . $value, 0 );
						wp_set_object_terms( $post_id, (int) $value, 'ad_cat', $cate_added );
						$cate_added = true;
					}
				} elseif ( $this->startsWith( $key, 'press_class' ) ) {
					$taxonomy = sanitize_key( $key );

					// Only ever write into a taxonomy that already exists.
					// The old code created one on demand from the payload.
					if ( ! taxonomy_exists( $taxonomy ) ) {
						pep_writelog( 'Taxonomy ' . $taxonomy . ' does not exist, skipping', 0 );
						continue;
					}

					pep_writelog( 'Assigning press class ' . $value . ' for key ' . $key, 0 );
					$data_class = get_term_by( 'name', $value, $taxonomy );

					if ( $data_class ) {
						pep_writelog( 'Press Class term number for ' . $key . ' and value ' . $value . ' is ' . $data_class->term_id );
						wp_set_object_terms( $post_id, (int) $data_class->term_id, $taxonomy, $cate_added );
						$cate_added = true;
					} elseif ( null !== $value && '' !== $value ) {
						pep_writelog( $taxonomy . ' term does not exist, will create', 0 );
						$new_term = wp_insert_term( $value, $taxonomy );

						if ( ! is_wp_error( $new_term ) ) {
							wp_set_object_terms( $post_id, (int) $new_term['term_id'], $taxonomy, $cate_added );
							$cate_added = true;
						} else {
							pep_writelog( 'Creation of term in ' . $taxonomy . ' for value ' . $value . ' failed', 0 );
						}
					}
				} elseif ( taxonomy_exists( $key ) ) {
					if ( 'post_tag' !== $key ) {
						wp_set_post_terms( $post_id, array( $value ), $key );
					} else {
						wp_set_post_terms( $post_id, $value, $key );
					}
					pep_writelog( 'Inserting taxonomy value key=' . $key, 0 );
				} else {
					pep_writelog( 'Inserting Meta value key=' . $key, 0 );
					add_post_meta( $post_id, strtolower( $key ), $value, false );
				}
			}
		}

		$response = array(
			'id'     => $id,
			'result' => array(
				'post_id'  => $post_id,
				'comment'  => 'post ok',
				'taxonomy' => $this->get_taxonomies_for_post( $post_id ),
				'meta'     => get_post_meta( $post_id ),
			),
			'error'  => null,
		);

		pep_writelog_sensitive( wp_json_encode( $response ), 0 );

		return $response;
	}

	public function pep_link_uploaded_file( $request ) {
		$id       = $this->arg( $request, 'id' );
		$fileinfo = $this->arg( $request, 'params', array() );

		// basename() strips any directory component, so a filename such as
		// ../../../wp-content/plugins/evil.php cannot escape the uploads
		// directory. sanitize_file_name() then removes the rest.
		$raw_filename = (string) $this->arg( $fileinfo, 'filename', '' );
		$filename     = sanitize_file_name( basename( $raw_filename ) );

		if ( '' === $filename ) {
			return $this->error_response( $id, -32602, 'A filename is required' );
		}

		$caption               = (string) $this->arg( $fileinfo, 'caption', '' );
		$use_caption_shortcode = (string) $this->arg( $fileinfo, 'use_caption_shortcode', '' );
		$upload_as_block       = (string) $this->arg( $fileinfo, 'upload_as_block', '' );
		$featured              = (bool) $this->arg( $fileinfo, 'featured', false );

		$image_size   = $this->arg( $fileinfo, 'image_size' );
		$image_width  = 640;
		$image_height = 400;

		if ( isset( $image_size ) ) {
			$image_size   = sanitize_key( (string) $image_size );
			$image_width  = (int) $this->arg( $fileinfo, 'image_width', 640 );
			$image_height = (int) $this->arg( $fileinfo, 'image_height', 400 );

			// Clamp so a payload cannot ask for a 100000px render.
			$image_width  = max( 1, min( 5000, $image_width ) );
			$image_height = max( 1, min( 5000, $image_height ) );
		}

		$pid = (int) $this->arg( $fileinfo, 'post_id', 0 );

		if ( $pid <= 0 || ! get_post( $pid ) ) {
			return $this->error_response( $id, -32602, 'Post not found' );
		}

		$upload_dir = trailingslashit( WP_CONTENT_DIR . '/uploads' );

		if ( ! is_writable( $upload_dir ) ) {
			pep_writelog( $upload_dir . ' is not writable' );
		}

		$dest_path = $upload_dir . $filename;

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attach_id = 0;

		if ( ! empty( $_FILES ) ) {
			foreach ( $_FILES as $attrName => $valuesArray ) {
				pep_writelog( 'processing files ID ' . $attrName . ' for post ' . $pid );

				if ( ! empty( $image_size ) ) {
					add_image_size( $image_size, $image_width, $image_height, true );
				}

				$post_data = array(
					'post_excerpt' => $caption,
					'post_content' => $caption,
				);

				$attach_id = media_handle_upload( $attrName, $pid, $post_data );

				if ( is_wp_error( $attach_id ) ) {
					$error_string = $attach_id->get_error_message();
					pep_writelog( 'Error with media upload ' . $error_string );

					return $this->error_response( $id, -32002, 'Cannot copy uploaded file: ' . $error_string );
				}

				if ( ! $attach_id ) {
					pep_writelog( 'File cannot be moved, error code ' . $this->arg( $valuesArray, 'error', 'unknown' ) );

					return $this->error_response( $id, -32002, 'Cannot copy uploaded file' );
				}
			}
		} else {
			// Pull the file from the configured FTP drop instead.
			if ( ! defined( 'FTP_ROOT' ) ) {
				pep_writelog( 'FTP_ROOT is not defined and no file was posted' );

				return $this->error_response( $id, -32001, 'No file supplied and no FTP root configured' );
			}

			$source = trailingslashit( FTP_ROOT ) . $filename;

			// Confirm the resolved source really sits under FTP_ROOT.
			$real_source = realpath( $source );
			$real_root   = realpath( FTP_ROOT );

			if ( false === $real_source || false === $real_root
				|| ! str_starts_with( $real_source, trailingslashit( $real_root ) ) ) {
				pep_writelog( 'Refusing to copy from outside the FTP root' );

				return $this->error_response( $id, -32001, 'Source file not found' );
			}

			if ( ! copy( $real_source, $dest_path ) ) {
				pep_writelog( 'File cannot be found at ' . $real_source );
				pep_writelog( 'Cannot copy to ' . $dest_path );

				return $this->error_response( $id, -32001, 'Cannot copy source file into the uploads directory' );
			}
		}

		if ( $featured ) {
			$res = set_post_thumbnail( $pid, $attach_id );
			pep_writelog( 'set_post_thumbnail result is ' . var_export( $res, true ) );

			if ( false === $res ) {
				pep_writelog( 'Using set post meta instead' );
				add_post_meta( $pid, '_thumbnail_id', $attach_id, true );
			}
		} else {
			pep_writelog( 'trying to link the image to attachment' );

			$content_post = get_post( $pid );
			$content      = $content_post->post_content;

			$attachment_url = wp_get_attachment_url( $attach_id );
			$attachment_url = $attachment_url ? $attachment_url : '';

			$picture_marker = '<!-- PictureMarker[' . $filename . ']-->';

			$image_prefix = chr( 0x0A ) . '<!-- wp:image {"className":"aligncenter"} -->' . chr( 0x0A )
				. '<figure class="wp-block-image aligncenter">';
			$image_suffix = '</figure>' . chr( 0x0A ) . '<!-- /wp:image -->' . chr( 0x0A );

			// Caption text lands in post content and in HTML attributes, so
			// escape it for each context rather than concatenating it raw.
			$caption_html = esc_html( $caption );
			$caption_attr = esc_attr( $caption );
			$url_attr     = esc_url( $attachment_url );

			// strpos() returns 0 for a match at offset 0, which the old
			// truthiness check treated as "not found".
			if ( false !== strpos( $content, $picture_marker ) ) {
				pep_writelog( 'picture marker found' );

				$text_to_replace = '<img class="aligncenter wp-image-' . (int) $attach_id . ' size-large" src="' . $url_attr . '" alt="" />';

				if ( '' !== $caption ) {
					$text_to_replace .= '<figcaption class="tdb-caption-text">' . $caption_html . '</figcaption>';
				}

				$content = str_replace( $picture_marker, $text_to_replace, $content );
			} elseif ( '1' === $use_caption_shortcode ) {
				pep_writelog( 'using shortcode for images' );

				$i_size = '';
				if ( ! empty( $image_size ) && 'custom' !== $image_size ) {
					$i_size = ' size-' . $image_size;
				}

				$attachment_post_id = (int) attachment_url_to_postid( $attachment_url );

				if ( '' !== $caption ) {
					$content .= '[caption id="' . (int) $attach_id . '" align="aligncenter" width="'
						. (int) $image_width . '" caption="' . $caption_attr . '"]';
				}

				$content .= '<img class="' . ( '' === $caption ? 'aligncenter ' : '' ) . 'wp-image-'
					. $attachment_post_id . esc_attr( $i_size ) . '" src="' . $url_attr
					. '" alt="" width="' . (int) $image_width . '" height="' . (int) $image_height . '">';

				if ( '' !== $caption ) {
					$content .= '[/caption]';
				}
			} elseif ( '1' === $upload_as_block ) {
				$content .= $image_prefix . '<img src="' . $url_attr . '" alt="" />';

				if ( '' !== $caption ) {
					$content .= '<figcaption>' . $caption_html . '</figcaption>';
				}

				$content .= $image_suffix;
			} else {
				$content .= '<img class="aligncenter wp-image-' . (int) $attach_id . ' size-large" src="' . $url_attr . '" alt="" />';

				if ( '' !== $caption ) {
					$content .= '<figcaption class="tdb-caption-text">' . $caption_html . '</figcaption>';
				}
			}

			wp_update_post(
				array(
					'ID'           => $pid,
					'post_content' => $content,
				),
				false,
				false
			);
		}

		$res_object = array(
			'data' => wp_get_attachment_metadata( $attach_id ),
		);

		$response = array(
			'id'     => $id,
			'result' => $res_object,
			'error'  => null,
		);

		pep_writelog_sensitive( wp_json_encode( $response ), 0 );

		return $response;
	}

	public function pep_get_authors( $request ) {
		pep_writelog( 'obtaining authors' );

		$id = $this->arg( $request, 'id' );

		// The old implementation took a 'login' from the request body and
		// called wp_set_current_user() on it, which let an unauthenticated
		// caller assume any account, then called wp_logout(), destroying
		// that user's session. Neither affected the result, so both are gone.
		$args = array(
			'role__in' => array( 'editor', 'author', 'contributor' ),
			'orderby'  => 'display_name',
			'fields'   => array( 'display_name', 'user_login', 'ID', 'user_email' ),
		);

		$authors = get_users( $args );

		pep_writelog( 'authors OK, ' . count( $authors ) . ' found' );

		return array(
			'id'     => $id,
			'result' => $authors,
			'error'  => null,
		);
	}

	public function pep_get_post_by_id( $request ) {
		pep_writelog( 'obtaining post by id' );

		$id     = $this->arg( $request, 'id' );
		$params = $this->arg( $request, 'params', array() );

		// The envelope id has always doubled as the post id here; a explicit
		// params.post_id now takes precedence when the client sends one.
		$post_id = (int) $this->arg( $params, 'post_id', $id );

		$post = get_post( $post_id );

		if ( ! $post ) {
			return $this->error_response( $id, -32602, 'Post not found' );
		}

		$articles = array( $post );

		$thumbnail_id = get_post_meta( $post_id, '_thumbnail_id', true );
		$attach_data  = array();

		if ( $thumbnail_id ) {
			$attach_data = wp_get_attachment_metadata( (int) $thumbnail_id );
		}

		return array(
			'id'     => $id,
			'result' => array(
				'articles'        => $articles,
				'meta'            => get_post_meta( $post_id ),
				'taxonomy'        => $this->get_taxonomies_for_post( $post_id ),
				'attachment_data' => $attach_data,
			),
			'error'  => null,
		);
	}

	public function pep_get_post_by_form_id( $request ) {
		pep_writelog( 'obtaining post by form id' );

		$id         = $this->arg( $request, 'id' );
		$meta_value = $this->arg( $request, 'params', array() );
		$meta_value = $this->arg( $meta_value, 'form_number' );

		if ( null === $meta_value || '' === $meta_value ) {
			return $this->error_response( $id, -32602, 'A form_number is required' );
		}

		$articles = get_posts(
			array(
				'meta_key'     => 'form_number',
				'meta_value'   => $meta_value,
				'meta_compare' => '=',
				'numberposts'  => 10,
				'post_type'    => get_post_types( '', 'names' ),
				'lang'         => array( 'en', 'zh' ),
			)
		);

		pep_writelog( 'obtain ' . count( $articles ) . ' posts' );

		$metaarray = array();
		foreach ( $articles as $mypost ) {
			$metaarray[] = get_post_meta( $mypost->ID );
		}

		return array(
			'id'     => $id,
			'result' => array(
				'articles' => $articles,
				'meta'     => $metaarray,
			),
			'error'  => null,
		);
	}

	public function pep_cancel_post_by_form_id( $request ) {
		pep_writelog( 'cancel post by form id' );

		$id         = $this->arg( $request, 'id' );
		$meta_value = $this->arg( $request, 'params', array() );
		$meta_value = $this->arg( $meta_value, 'form_number' );

		// An empty form_number used to match every post with an empty
		// form_number meta and delete all of them.
		if ( null === $meta_value || '' === $meta_value ) {
			return $this->error_response( $id, -32602, 'A form_number is required' );
		}

		$articles = get_posts(
			array(
				'meta_key'     => 'form_number',
				'meta_value'   => $meta_value,
				'meta_compare' => '=',
				'numberposts'  => 10,
				'post_type'    => get_post_types( '', 'names' ),
				'lang'         => array( 'en', 'zh' ),
			)
		);

		pep_writelog( 'obtain ' . count( $articles ) . ' posts' );

		foreach ( $articles as $mypost ) {
			pep_writelog( 'Cancelling post ' . $mypost->ID );
			wp_delete_post( $mypost->ID, true );
		}

		return array(
			'id'     => $id,
			'result' => array( 'result' => 'Removed ' . count( $articles ) . ' posts' ),
			'error'  => null,
		);
	}

	public function pep_get_published_post( $request ) {
		pep_writelog( 'obtaining all published posts' );

		$id = $this->arg( $request, 'id' );

		$articles = get_posts(
			array(
				'numberposts' => -1,
				'post_status' => array( 'publish' ),
				'post_type'   => get_post_types( '', 'names' ),
				'lang'        => array( 'en', 'zh' ),
			)
		);

		pep_writelog( 'obtain ' . count( $articles ) . ' posts' );

		$result = array();
		foreach ( $articles as $mypost ) {
			$media    = get_attached_media( '', $mypost );
			$result[] = array(
				$mypost->post_title,
				$mypost->post_content,
				$mypost->ID,
				get_the_post_thumbnail( $mypost ),
				$media,
			);
		}

		return array(
			'id'     => $id,
			'result' => $result,
			'error'  => null,
		);
	}

	public function pep_get_categories( $request ) {
		pep_writelog( 'obtaining categories' );

		$id = $this->arg( $request, 'id' );

		$categories = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $categories ) ) {
			return $this->error_response( $id, -32000, 'Could not read categories' );
		}

		pep_writelog( 'categories OK' );

		return array(
			'id'     => $id,
			'result' => $categories,
			'error'  => null,
		);
	}

	public function pep_get_version( $request ) {
		pep_writelog( 'obtaining version number' );

		// All three come from pep_version.php, which is required at the top
		// of this file, so there is nothing to bump here at release time.
		return array(
			'id'     => $this->arg( $request, 'id' ),
			'result' => array(
				'version'      => PEP_CURRENT_VERSION,
				'build'        => PEP_CURRENT_BUILD,
				'version_date' => PEP_VERSION_DATE,
			),
			'error'  => null,
		);
	}

	public function pep_publish_acd_classified( $request ) {
		// Posts the data, updates the meta and removes the old post in one go.
		$id      = $this->arg( $request, 'id' );
		$params  = $this->arg( $request, 'params', array() );
		$content = $this->prepare_post_array( $params );

		pep_writelog_sensitive( 'Post content is ' . wp_json_encode( $content ) );

		$form_number = $this->arg( $params, 'form_number' );

		if ( null !== $form_number && '' !== $form_number ) {
			$articles = get_posts(
				array(
					'meta_key'     => 'form_number',
					'meta_value'   => $form_number,
					'meta_compare' => '=',
					'numberposts'  => 1,
					'post_type'    => get_post_types( '', 'names' ),
					'lang'         => array( 'en', 'zh' ),
				)
			);

			foreach ( $articles as $mypost ) {
				// get_posts() returns WP_Post objects; the array access here
				// was a fatal Error on PHP 8.
				wp_delete_post( $mypost->ID, true );
				pep_writelog( 'Deleted post ' . $mypost->ID );
			}
		}

		$post_id = $this->post_id_or_zero( wp_insert_post( $content, true ), 'wp_insert_post' );

		if ( 0 === $post_id ) {
			return $this->error_response( $id, -32000, 'Could not create post' );
		}

		pep_writelog( 'Post ID is ' . $post_id );

		return array(
			'id'     => $id,
			'result' => array(
				'post_id'   => $post_id,
				'permalink' => get_permalink( $post_id ),
				'comment'   => 'post ok',
			),
			'error'  => null,
		);
	}

	public function pep_publish_advert( $request ) {
		$id      = $this->arg( $request, 'id' );
		$content = $this->prepare_post_array( $this->arg( $request, 'params', array() ) );

		$post_id = $this->post_id_or_zero( wp_insert_post( $content, true ), 'wp_insert_post' );

		if ( 0 === $post_id ) {
			return $this->error_response( $id, -32000, 'Could not create advert' );
		}

		// Set the location to unallocated.
		wp_set_object_terms( $post_id, 'unallocated', 'post_tag', false );

		return array(
			'id'     => $id,
			'result' => array(
				'post_id' => $post_id,
				'comment' => 'post ok',
			),
			'error'  => null,
		);
	}

	public function pep_get_logfile( $request ) {
		return array(
			'id'     => $this->arg( $request, 'id' ),
			'result' => pep_getcurrentlog(),
			'error'  => null,
		);
	}

	public function pep_clear_logfile( $request ) {
		pep_clearcurrentlog();

		return array(
			'id'     => $this->arg( $request, 'id' ),
			'result' => 'log is cleared',
			'error'  => null,
		);
	}

	public function pep_get_recent_post( $request ) {
		// The parameter used to be spelled $requesst, so every read of
		// $request in here hit an undefined variable.
		$id     = $this->arg( $request, 'id' );
		$params = $this->arg( $request, 'params', array() );

		$supplied = $this->arg( $params, 'filter' );

		$post_filter = array(
			'numberposts' => 20,
			'post_status' => 'publish',
		);

		if ( is_array( $supplied ) ) {
			// Only accept a known set of query vars. Passing the raw filter
			// through let a caller read drafts, private posts and any
			// author's unpublished work.
			$allowed = array( 'numberposts', 'offset', 'category', 'orderby', 'order', 'post_type', 'post_status' );

			foreach ( $allowed as $arg_name ) {
				if ( isset( $supplied[ $arg_name ] ) ) {
					$post_filter[ $arg_name ] = $supplied[ $arg_name ];
				}
			}

			$post_filter['numberposts'] = max( 1, min( 100, (int) $post_filter['numberposts'] ) );

			if ( isset( $post_filter['post_status'] ) ) {
				$status = sanitize_key( (string) $post_filter['post_status'] );
				$known  = get_post_stati( array( 'public' => true ) );

				$post_filter['post_status'] = isset( $known[ $status ] ) ? $status : 'publish';
			}
		}

		return array(
			'id'     => $id,
			'result' => wp_get_recent_posts( $post_filter ),
			'error'  => null,
		);
	}

	// ---------------------------------------------------------------
	// Dispatch
	// ---------------------------------------------------------------

	/**
	 * Send a JSON response and stop.
	 *
	 * @param array $response JSON-RPC response.
	 * @param int   $status   HTTP status code.
	 * @return bool
	 */
	private function send( $response, $status = 200 ) {
		http_response_code( $status );
		header( 'Content-Type: application/json' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo wp_json_encode( $response );

		return true;
	}

	public function PerformAction() {
		ignore_user_abort( true );
		set_time_limit( 120 );
		nocache_headers();

		$method_is_post = isset( $_SERVER['REQUEST_METHOD'] )
			&& 0 === strcasecmp( (string) $_SERVER['REQUEST_METHOD'], 'POST' );

		// ---- read the payload ----------------------------------------
		$content_type = isset( $_SERVER['CONTENT_TYPE'] ) ? (string) $_SERVER['CONTENT_TYPE'] : '';
		$jsontext     = '';

		// The old exact comparison against 'application/json' failed as soon
		// as a client appended a charset.
		if ( str_starts_with( strtolower( trim( $content_type ) ), 'application/json' ) ) {
			pep_writelog( 'JSON request received', 'Perform Action', '117' );
			$jsontext = file_get_contents( 'php://input' );
		} else {
			pep_writelog( 'File Upload Request', 'Perform File Upload', '118' );
			pep_writelog( 'Content Type received ' . $content_type );

			// Multipart form data. Read from POST/GET explicitly rather than
			// $_REQUEST, which also folds in cookies depending on
			// request_order.
			if ( isset( $_POST['request'] ) ) {
				$jsontext = wp_unslash( $_POST['request'] );
			} elseif ( isset( $_GET['request'] ) ) {
				$jsontext = wp_unslash( $_GET['request'] );
			}
		}

		pep_writelog_sensitive( $jsontext );

		$request = json_decode( (string) $jsontext, true );

		if ( ! is_array( $request ) ) {
			pep_writelog( 'Payload was not valid JSON' );

			return $this->send( $this->error_response( 0, -32700, 'Parse error' ), 400 );
		}

		// ---- authorise -----------------------------------------------
		$auth = pep_authorize_request( $request );

		if ( ! $auth['allowed'] ) {
			pep_writelog(
				sprintf(
					'Rejected RPC request from %s: %s',
					pep_client_ip(),
					$auth['reason']
				),
				'Perform Action',
				'auth'
			);

			return $this->send(
				array(
					'id'     => $this->arg( $request, 'id', 0 ),
					'result' => null,
					'error'  => 'Not authorised',
				),
				403
			);
		}

		if ( 'legacy_ip' === $auth['mode'] || 'legacy_open' === $auth['mode'] ) {
			pep_writelog(
				'DEPRECATED: keyless request accepted from ' . pep_client_ip()
					. '. Configure an API key and switch legacy mode off.',
				'Perform Action',
				'auth'
			);
		}

		// ---- dispatch -------------------------------------------------
		$switched = false;
		$id       = $this->arg( $request, 'id' );
		$method   = (string) $this->arg( $request, 'method', '' );

		if ( ! $method_is_post && ! in_array( $method, self::$read_only_methods, true ) ) {
			pep_writelog( 'Refusing ' . $method . ' over a non-POST request' );

			return $this->send(
				$this->error_response( $id, -32600, 'This method requires POST' ),
				405
			);
		}

		try {
			$params  = $this->arg( $request, 'params', array() );
			$blog_id = (int) $this->arg( $params, 'blog', 0 );

			if ( $blog_id > 0 && is_multisite() && get_site( $blog_id ) ) {
				$switched = true;
				switch_to_blog( $blog_id );
			}

			pep_writelog( 'Json method name is ' . $method );

			switch ( $method ) {
				case 'pep_submit_post':
					$response = $this->pep_submit_post( $request );
					break;

				case 'pep_update_post_status':
					$response = $this->pep_update_post_status( $request );
					break;

				case 'pep_set_meta_data':
					$response = $this->pep_set_meta_data( $request );
					break;

				case 'pep_link_uploaded_file':
					$response = $this->pep_link_uploaded_file( $request );
					break;

				case 'pep_get_upload_dir':
					$response = array(
						'id'     => $id,
						'result' => wp_upload_dir(),
						'error'  => null,
					);
					break;

				case 'pep_get_categories':
					$response = $this->pep_get_categories( $request );
					break;

				case 'pep_get_logfile':
					$response = $this->pep_get_logfile( $request );
					break;

				case 'pep_clear_logfile':
					$response = $this->pep_clear_logfile( $request );
					break;

				case 'pep_get_recent_post':
					$response = $this->pep_get_recent_post( $request );
					break;

				case 'pep_get_authors':
					$response = $this->pep_get_authors( $request );
					break;

				case 'pep_get_published_post':
					$response = $this->pep_get_published_post( $request );
					break;

				case 'pep_get_post_by_id':
					$response = $this->pep_get_post_by_id( $request );
					break;

				case 'pep_get_post_by_form_id':
					$response = $this->pep_get_post_by_form_id( $request );
					break;

				case 'pep_cancel_post_by_form_id':
					$response = $this->pep_cancel_post_by_form_id( $request );
					break;

				case 'pep_publish_acd_classified':
					$response = $this->pep_publish_acd_classified( $request );
					break;

				case 'pep_get_version':
					$response = $this->pep_get_version( $request );
					break;

				case 'pep_get_ad_dimensions':
					$response = array(
						'id'     => $id,
						'result' => wp_get_sidebars_widgets(),
						'error'  => null,
					);
					break;

				case 'pep_publish_advert':
					$response = $this->pep_publish_advert( $request );
					break;

				default:
					$response = array(
						'id'     => $id,
						'result' => null,
						'error'  => 'unknown method or incorrect parameters',
					);
					break;
			}
		} catch ( Throwable $e ) {
			// Catch Throwable, not Exception: on PHP 7+ engine failures are
			// Errors and were escaping this handler entirely.
			pep_writelog(
				sprintf(
					'Unhandled %s in %s: %s at %s:%d',
					get_class( $e ),
					$method,
					$e->getMessage(),
					$e->getFile(),
					$e->getLine()
				)
			);

			// The message can carry file paths and SQL, so it is logged
			// rather than returned.
			$response = array(
				'id'     => $id,
				'result' => null,
				'error'  => 'Internal error',
			);
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}

		// Notifications do not want a response.
		if ( ! empty( $request['id'] ) ) {
			$this->send( $response );
			pep_writelog_sensitive( 'creating response' . wp_json_encode( $response ) );
		} else {
			pep_writelog( 'request id is empty' );
		}

		return true;
	}
}

$jsonserver = PEPjsonServer::getInstance();

$jsonserver->PerformAction();
