<?php
/**
 * PEP settings screen.
 *
 * Rendered through pep_render_settings_page(), which checks the capability
 * first. The ABSPATH guard stops the file doing anything when it is requested
 * directly over HTTP, which previously emitted PHP errors disclosing paths.
 *
 * @package PEP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( PEP_ADMIN_CAPABILITY ) ) {
	wp_die( esc_html__( 'You do not have permission to view this page.', EMU2_I18N_DOMAIN ) );
}

/**
 * Registered image sizes with their dimensions.
 *
 * Prefixed and guarded: the old get_image_sizes() sat in the global
 * namespace and was declared at render time, so a second include or a theme
 * of the same name was a fatal redeclaration.
 *
 * @param string $size Optional single size to return.
 * @return array|false
 */
function pep_get_image_sizes( $size = '' ) {
	$wp_additional_image_sizes    = wp_get_additional_image_sizes();
	$sizes                        = array();
	$get_intermediate_image_sizes = get_intermediate_image_sizes();

	foreach ( $get_intermediate_image_sizes as $_size ) {
		if ( in_array( $_size, array( 'thumbnail', 'medium', 'large' ), true ) ) {
			$sizes[ $_size ]['width']  = get_option( $_size . '_size_w' );
			$sizes[ $_size ]['height'] = get_option( $_size . '_size_h' );
			$sizes[ $_size ]['crop']   = (bool) get_option( $_size . '_crop' );
		} elseif ( isset( $wp_additional_image_sizes[ $_size ] ) ) {
			$sizes[ $_size ] = array(
				'width'  => $wp_additional_image_sizes[ $_size ]['width'],
				'height' => $wp_additional_image_sizes[ $_size ]['height'],
				'crop'   => $wp_additional_image_sizes[ $_size ]['crop'],
			);
		}
	}

	if ( $size ) {
		return isset( $sizes[ $size ] ) ? $sizes[ $size ] : false;
	}

	return $sizes;
}

$pep_new_key = get_transient( 'pep_new_api_key_' . get_current_user_id() );
if ( $pep_new_key ) {
	delete_transient( 'pep_new_api_key_' . get_current_user_id() );
}
?>
<div class="wrap">
<h1><?php echo esc_html( PEP_PUGIN_NAME . ' ' . PEP_CURRENT_VERSION ); ?>
	<sub>(<?php echo esc_html( 'Build ' . PEP_CURRENT_BUILD ); ?>)</sub></h1>

<?php settings_errors( 'pep-settings-group' ); ?>

<?php if ( $pep_new_key ) : ?>
	<div class="notice notice-success">
		<p><strong><?php esc_html_e( 'New API key generated.', EMU2_I18N_DOMAIN ); ?></strong>
		<?php esc_html_e( 'Copy it now — only a hash is stored, so it cannot be shown again.', EMU2_I18N_DOMAIN ); ?></p>
		<p><code style="font-size:1.1em;user-select:all;"><?php echo esc_html( $pep_new_key ); ?></code></p>
		<p><?php esc_html_e( 'Send it from the Pongrass client as an X-PEP-Key request header, or as a "key" member of the JSON-RPC envelope.', EMU2_I18N_DOMAIN ); ?></p>
	</div>
<?php endif; ?>

<h2><?php esc_html_e( 'Endpoint access', EMU2_I18N_DOMAIN ); ?></h2>

<table class="form-table">
	<tr>
		<th scope="row"><?php esc_html_e( 'API key', EMU2_I18N_DOMAIN ); ?></th>
		<td>
			<?php if ( pep_has_api_key() ) : ?>
				<p><span class="dashicons dashicons-yes" style="color:#46b450;"></span>
				<?php esc_html_e( 'An API key is configured.', EMU2_I18N_DOMAIN ); ?></p>
			<?php else : ?>
				<p><span class="dashicons dashicons-warning" style="color:#dba617;"></span>
				<?php esc_html_e( 'No API key has been generated yet.', EMU2_I18N_DOMAIN ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pep_regenerate_key" />
				<?php wp_nonce_field( 'pep_regenerate_key' ); ?>
				<button type="submit" class="button">
					<?php
					echo pep_has_api_key()
						? esc_html__( 'Generate a replacement key', EMU2_I18N_DOMAIN )
						: esc_html__( 'Generate an API key', EMU2_I18N_DOMAIN );
					?>
				</button>
				<p class="description">
					<?php esc_html_e( 'Generating a replacement immediately invalidates the previous key.', EMU2_I18N_DOMAIN ); ?>
				</p>
			</form>
		</td>
	</tr>
</table>

<form method="post" action="options.php">
	<?php settings_fields( 'pep-settings-group' ); ?>

	<table class="form-table">
		<tr>
			<th scope="row"><?php esc_html_e( 'Legacy keyless access', EMU2_I18N_DOMAIN ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( PEP_OPT_LEGACY_MODE ); ?>" value="1"
						<?php checked( pep_legacy_mode_enabled() ); ?> />
					<?php esc_html_e( 'Allow requests with no API key from whitelisted addresses', EMU2_I18N_DOMAIN ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'For existing Pongrass clients that do not send a key yet. IP addresses can be spoofed, so turn this off once every client has been updated. With this off, a valid API key is required for every request.', EMU2_I18N_DOMAIN ); ?>
				</p>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Suppress other plugins', EMU2_I18N_DOMAIN ); ?></th>
			<td>
				<?php if ( defined( 'PEP_DISABLE_OTHER_PLUGINS' ) ) : ?>
					<p>
						<em>
						<?php
						printf(
							/* translators: %s: on or off */
							esc_html__( 'Overridden in wp-config.php by PEP_DISABLE_OTHER_PLUGINS, currently %s. This setting is ignored.', EMU2_I18N_DOMAIN ),
							PEP_DISABLE_OTHER_PLUGINS
								? esc_html__( 'on', EMU2_I18N_DOMAIN )
								: esc_html__( 'off', EMU2_I18N_DOMAIN )
						);
						?>
						</em>
					</p>
				<?php else : ?>
					<label>
						<input type="checkbox" name="pep_disable_other_plugins" value="1"
							<?php checked( (bool) get_option( 'pep_disable_other_plugins', 1 ) ); ?> />
						<?php esc_html_e( 'Unload all other plugins during RPC requests', EMU2_I18N_DOMAIN ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'On by default: the endpoint only needs core, and skipping the plugin stack makes article pushes faster. It also unloads your security plugins for those requests, so turn it off if you want a firewall or malware scanner to see RPC traffic. Expect a slower bootstrap, and test on staging first in case another plugin hooks post creation.', EMU2_I18N_DOMAIN ); ?>
					</p>
				<?php endif; ?>
			</td>
		</tr>

		<?php for ( $pep_i = 1; $pep_i <= PEP_WHITELIST_SLOTS; $pep_i++ ) : ?>
		<tr>
			<th scope="row">
				<?php
				printf(
					/* translators: %d: whitelist slot number */
					esc_html__( 'IP white list %d', EMU2_I18N_DOMAIN ),
					(int) $pep_i
				);
				?>
			</th>
			<td>
				<input type="text" class="regular-text"
					name="<?php echo esc_attr( 'ip_white_list_' . $pep_i ); ?>"
					value="<?php echo esc_attr( get_option( 'ip_white_list_' . $pep_i, '' ) ); ?>" />
			</td>
		</tr>
		<?php endfor; ?>

		<tr>
			<th scope="row"><?php esc_html_e( 'Your current IP', EMU2_I18N_DOMAIN ); ?></th>
			<td><code><?php echo esc_html( pep_client_ip() ); ?></code></td>
		</tr>
	</table>

	<p class="description">
		<?php esc_html_e( 'Accepts a single address (203.0.113.9), a CIDR range (203.0.113.0/24) or a trailing wildcard (203.0.113.*). An empty whitelist matches nothing.', EMU2_I18N_DOMAIN ); ?>
	</p>

	<?php submit_button(); ?>
</form>

<h2><?php esc_html_e( 'Diagnostics', EMU2_I18N_DOMAIN ); ?></h2>

<table class="widefat striped" style="max-width:60em;">
	<tbody>
		<tr>
			<td><?php esc_html_e( 'RPC endpoint', EMU2_I18N_DOMAIN ); ?></td>
			<td><code><?php echo esc_html( plugins_url( 'pep_json.php', __FILE__ ) ); ?></code></td>
		</tr>
		<tr>
			<td><?php esc_html_e( 'WordPress root', EMU2_I18N_DOMAIN ); ?></td>
			<td><code><?php echo esc_html( ABSPATH ); ?></code></td>
		</tr>
		<tr>
			<td><?php esc_html_e( 'Log path', EMU2_I18N_DOMAIN ); ?></td>
			<td><code><?php echo esc_html( PEP_LOGPATH ); ?></code></td>
		</tr>
		<tr>
			<td><?php esc_html_e( 'Log writable', EMU2_I18N_DOMAIN ); ?></td>
			<td><?php echo is_writable( PEP_LOGPATH ) ? esc_html__( 'yes', EMU2_I18N_DOMAIN ) : esc_html__( 'no', EMU2_I18N_DOMAIN ); ?></td>
		</tr>
		<tr>
			<td><?php esc_html_e( 'Payload logging', EMU2_I18N_DOMAIN ); ?></td>
			<td>
				<?php
				echo ( defined( 'PEP_LOG_PAYLOADS' ) && PEP_LOG_PAYLOADS )
					? esc_html__( 'on (request bodies are written to the log)', EMU2_I18N_DOMAIN )
					: esc_html__( 'off', EMU2_I18N_DOMAIN );
				?>
			</td>
		</tr>
		<tr>
			<td><?php esc_html_e( 'PHP version', EMU2_I18N_DOMAIN ); ?></td>
			<td><code><?php echo esc_html( PHP_VERSION ); ?></code></td>
		</tr>
	</tbody>
</table>

<h3><?php esc_html_e( 'Registered image sizes', EMU2_I18N_DOMAIN ); ?></h3>

<table class="widefat striped" style="max-width:40em;">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Size', EMU2_I18N_DOMAIN ); ?></th>
			<th><?php esc_html_e( 'Width', EMU2_I18N_DOMAIN ); ?></th>
			<th><?php esc_html_e( 'Height', EMU2_I18N_DOMAIN ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ( pep_get_image_sizes() as $pep_size_name => $pep_size_entry ) : ?>
		<tr>
			<td><?php echo esc_html( $pep_size_name ); ?></td>
			<td><?php echo esc_html( (string) $pep_size_entry['width'] ); ?></td>
			<td><?php echo esc_html( (string) $pep_size_entry['height'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

</div>
