<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName
/**
 * Plugin Name: Basecamp Pro Automation Suite
 * Description: Professional Basecamp automation system with local indexing, intelligent search, and comprehensive project management capabilities.
 * Version: 5.0.0
 * Author: Wbcom Designs
 * Author URI: https://wbcomdesigns.com
 * License: GPL v2 or later
 * Text Domain: basecamp-cards-reader
 *
 * @package Basecamp_Cards_Reader
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class for Basecamp Cards Reader.
 *
 * Handles OAuth authentication, API communication, and admin interface
 * for reading and interacting with Basecamp cards and todos.
 *
 * @since 1.0.0
 */
class Basecamp_Cards_Reader_Clean {

	/**
	 * Option name for settings.
	 *
	 * @var string
	 */
	const OPT = 'bcr_settings';

	/**
	 * Option name for token data.
	 *
	 * @var string
	 */
	const TOKEN_OPT = 'bcr_token_data';

	/**
	 * Singleton instance.
	 *
	 * @var Basecamp_Cards_Reader_Clean|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @since 1.0.0
	 * @return Basecamp_Cards_Reader_Clean
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_init', array( $this, 'handle_oauth_early' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_post_bcr_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'wp_ajax_bcr_read_card', array( $this, 'handle_read_card' ) );
		add_action( 'wp_ajax_bcr_post_comment', array( $this, 'handle_post_comment' ) );

		// Load API and CLI commands.
		$this->load_dependencies();
	}

	/**
	 * Load plugin dependencies.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function load_dependencies() {
		// Load core classes.
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-basecamp-api.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-basecamp-logger.php';

		// Load CLI commands and professional automation suite.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			// Professional automation classes.
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-basecamp-automation.php';
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-basecamp-pro.php';
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-basecamp-indexer.php';

			// Unified CLI command system.
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-bcr-cli-commands-extended.php';
		}
	}

	/**
	 * Initialize plugin.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function init() {
		// Plugin initialization.
	}

	/**
	 * Handle OAuth callback early before any output.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function handle_oauth_early() {
		// Only process on our admin page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback, nonce not applicable.
		if ( ! isset( $_GET['page'] ) || 'basecamp-reader' !== $_GET['page'] ) {
			return;
		}

		// Handle OAuth callback early before any output.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback from external service.
		if ( ! empty( $_GET['code'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$code    = sanitize_text_field( wp_unslash( $_GET['code'] ) );
			$success = $this->handle_oauth_callback( $code );
			if ( $success ) {
				set_transient( 'bcr_oauth_success', 'Successfully connected to Basecamp!', 60 );
			}
			wp_safe_redirect( admin_url( 'options-general.php?page=basecamp-reader' ) );
			exit;
		}
	}

	/**
	 * Plugin activation hook.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function activate() {
		// Plugin activation.
	}

	/**
	 * Plugin deactivation hook.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function deactivate() {
		// Plugin deactivation.
	}

	/**
	 * Register admin menu.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function admin_menu() {
		add_options_page(
			'Basecamp Cards Reader',
			'Basecamp Reader',
			'manage_options',
			'basecamp-reader',
			array( $this, 'admin_page' )
		);
	}

	/**
	 * Register REST API routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			'bcr/v1',
			'/read-card',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_read_card' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle AJAX request to read a card.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function handle_read_card() {
		// Verify nonce.
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'bcr_ajax_nonce' ) ) {
			wp_send_json_error( 'Security check failed' );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$url = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
		if ( empty( $url ) ) {
			wp_send_json_error( 'URL is required' );
		}

		// Parse Basecamp URL - handle both todos and cards.
		if ( ! preg_match( '/basecamp\.com\/(\d+)\/buckets\/(\d+)\/.*\/(\d+)$/', $url, $matches ) ) {
			wp_send_json_error( 'Invalid Basecamp URL format' );
		}

		$account_id   = $matches[1];
		$project_id   = $matches[2];
		$recording_id = $matches[3];

		// Determine if it's a card or todo.
		$is_card       = false !== strpos( $url, '/card_tables/cards/' );
		$endpoint_type = $is_card ? 'card_tables/cards' : 'todos';

		$token_data = get_option( self::TOKEN_OPT, array() );
		if ( empty( $token_data['access_token'] ) ) {
			wp_send_json_error( 'Not connected to Basecamp. Please connect first.' );
		}

		// Check token expiration.
		if ( ! empty( $token_data['expires_at'] ) && time() >= $token_data['expires_at'] ) {
			// Try to refresh.
			$this->refresh_token();
			$token_data = get_option( self::TOKEN_OPT, array() );
		}

		// Fetch data from Basecamp API - use correct endpoint based on type.
		$api_url = "https://3.basecampapi.com/$account_id/buckets/$project_id/$endpoint_type/$recording_id.json";

		$headers = array(
			'Authorization' => 'Bearer ' . $token_data['access_token'],
			'User-Agent'    => get_bloginfo( 'name' ) . ' (' . get_option( 'admin_email' ) . ')',
		);

		$response = wp_remote_get( $api_url, array( 'headers' => $headers ) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( 'Failed to fetch from Basecamp: ' . $response->get_error_message() );
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			wp_send_json_error( 'Basecamp API error: ' . wp_remote_retrieve_response_code( $response ) );
		}

		$card_data = json_decode( wp_remote_retrieve_body( $response ), true );

		// Fetch comments if record exists.
		$comments = array();
		if ( $card_data && ! empty( $card_data['id'] ) ) {
			$comments = $this->fetch_comments( $account_id, $project_id, $recording_id, $headers );
		}

		wp_send_json_success(
			array(
				'title'          => $card_data['title'] ?? 'Untitled',
				'description'    => $card_data['description'] ?? $card_data['content'] ?? '',
				'type'           => $card_data['type'] ?? 'Unknown',
				'url'            => $url,
				'created_at'     => $card_data['created_at'] ?? '',
				'updated_at'     => $card_data['updated_at'] ?? '',
				'creator'        => $card_data['creator'] ?? array(),
				'assignees'      => $card_data['assignees'] ?? array(),
				'completed'      => $card_data['completed'] ?? false,
				'comments_count' => $card_data['comments_count'] ?? 0,
				'comments'       => $comments,
				'raw'            => $card_data,
			)
		);
	}

	/**
	 * Fetch comments for a recording.
	 *
	 * @since 1.0.0
	 * @param string $account_id   Basecamp account ID.
	 * @param string $project_id   Basecamp project ID.
	 * @param string $recording_id Basecamp recording ID.
	 * @param array  $headers      Request headers.
	 * @return array Array of formatted comments.
	 */
	private function fetch_comments( $account_id, $project_id, $recording_id, $headers ) {
		$url = "https://3.basecampapi.com/$account_id/buckets/$project_id/recordings/$recording_id/comments.json";

		$response = wp_remote_get( $url, array( 'headers' => $headers ) );

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array();
		}

		$comments = json_decode( wp_remote_retrieve_body( $response ), true );

		// Process comments to extract images and format content.
		$formatted_comments = array();
		foreach ( $comments as $comment ) {
			$formatted_comment = array(
				'id'          => $comment['id'],
				'content'     => $comment['content'] ?? '',
				'creator'     => $comment['creator'] ?? array(),
				'created_at'  => $comment['created_at'] ?? '',
				'updated_at'  => $comment['updated_at'] ?? '',
				'attachments' => array(),
				'images'      => array(),
			);

			// Extract attachments and images from content.
			if ( ! empty( $comment['content'] ) ) {
				// Look for bc-attachment tags - extract both href and filename.
				if ( preg_match_all( '/<bc-attachment([^>]*)>.*?<\/bc-attachment>/i', $comment['content'], $matches ) ) {
					foreach ( $matches[1] as $attrs ) {
						$attachment = array();

						// Extract href (download URL).
						if ( preg_match( '/href="([^"]+)"/', $attrs, $href_match ) ) {
							$attachment['url'] = html_entity_decode( $href_match[1] );
						}

						// Extract filename.
						if ( preg_match( '/filename="([^"]+)"/', $attrs, $filename_match ) ) {
							$attachment['filename'] = $filename_match[1];
						}

						// Extract content-type.
						if ( preg_match( '/content-type="([^"]+)"/', $attrs, $type_match ) ) {
							$attachment['content_type'] = $type_match[1];
						}

						// Check if it's an image based on content-type or extension.
						if ( ! empty( $attachment['url'] ) ) {
							$is_image = false;
							if ( ! empty( $attachment['content_type'] ) && 0 === strpos( $attachment['content_type'], 'image/' ) ) {
								$is_image = true;
							} elseif ( preg_match( '/\.(jpg|jpeg|png|gif|webp)$/i', $attachment['url'] ) ) {
								$is_image = true;
							}

							if ( $is_image ) {
								$formatted_comment['images'][] = $attachment['url'];
							} else {
								$formatted_comment['attachments'][] = array(
									'url'  => $attachment['url'],
									'type' => 'file',
									'name' => $attachment['filename'] ?? basename( $attachment['url'] ),
								);
							}
						}
					}
				}
			}

			$formatted_comments[] = $formatted_comment;
		}

		return $formatted_comments;
	}

	/**
	 * Refresh the access token using the refresh token.
	 *
	 * @since 1.0.0
	 * @return bool True on success, false on failure.
	 */
	public function refresh_token() {
		$token_data = get_option( self::TOKEN_OPT, array() );
		if ( empty( $token_data['refresh_token'] ) ) {
			return false;
		}

		$opt = get_option( self::OPT, array() );

		$response = wp_remote_post(
			'https://launchpad.37signals.com/authorization/token',
			array(
				'body' => array(
					'type'          => 'refresh',
					'refresh_token' => $token_data['refresh_token'],
					'client_id'     => $opt['client_id'],
					'client_secret' => $opt['client_secret'],
				),
			)
		);

		if ( ! is_wp_error( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! empty( $body['access_token'] ) ) {
				update_option(
					self::TOKEN_OPT,
					array(
						'access_token'  => $body['access_token'],
						'refresh_token' => $body['refresh_token'] ?? $token_data['refresh_token'],
						'expires_at'    => time() + ( $body['expires_in'] ?? 1209600 ),
					)
				);
				return true;
			}
		}

		return false;
	}

	/**
	 * Render the admin settings page.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function admin_page() {
		$opt        = get_option( self::OPT, array() );
		$token_data = get_option( self::TOKEN_OPT, array() );

		// Handle form submissions.
		if ( isset( $_POST['save_settings'] ) ) {
			check_admin_referer( 'bcr_save_settings' );

			$new_opt = array(
				'client_id'     => isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '',
				'client_secret' => isset( $_POST['client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) : '',
			);

			update_option( self::OPT, $new_opt );
			$opt = $new_opt;

			echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
		}

		$is_connected = ! empty( $token_data['access_token'] );
		$is_expired   = ! empty( $token_data['expires_at'] ) && time() >= $token_data['expires_at'];

		?>
		<div class="wrap">
			<h1>Basecamp Cards Reader</h1>

			<?php
			// Display OAuth messages.
			$error = get_transient( 'bcr_oauth_error' );
			if ( $error ) :
				delete_transient( 'bcr_oauth_error' );
				?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
				<?php
			endif;

			$success = get_transient( 'bcr_oauth_success' );
			if ( $success ) :
				delete_transient( 'bcr_oauth_success' );
				?>
				<div class="notice notice-success"><p><?php echo esc_html( $success ); ?></p></div>
				<?php
			endif;
			?>

			<div class="card">
				<h2>OAuth 2.0 Setup</h2>
				<p>To use this plugin, you need to create a Basecamp app:</p>
				<ol>
					<li>Go to <a href="https://launchpad.37signals.com/integrations" target="_blank">Basecamp Integrations</a></li>
					<li>Register a new app with these settings:</li>
					<li><strong>Redirect URI:</strong> <code><?php echo esc_url( admin_url( 'options-general.php?page=basecamp-reader' ) ); ?></code></li>
				</ol>
			</div>

			<form method="post">
				<?php wp_nonce_field( 'bcr_save_settings' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="client_id">Client ID</label></th>
						<td><input type="text" id="client_id" name="client_id" value="<?php echo esc_attr( $opt['client_id'] ?? '' ); ?>" class="regular-text" required></td>
					</tr>
					<tr>
						<th><label for="client_secret">Client Secret</label></th>
						<td><input type="password" id="client_secret" name="client_secret" value="<?php echo esc_attr( $opt['client_secret'] ?? '' ); ?>" class="regular-text" required></td>
					</tr>
				</table>

				<?php submit_button( 'Save Settings', 'primary', 'save_settings' ); ?>
			</form>

			<div class="card">
				<h2>Connection Status</h2>
				<?php if ( $is_connected && ! $is_expired ) : ?>
					<p style="color: green;">✅ Connected to Basecamp</p>
					<p>Token expires: <?php echo esc_html( gmdate( 'Y-m-d H:i:s', $token_data['expires_at'] ) ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 10px;">
						<input type="hidden" name="action" value="bcr_disconnect">
						<?php wp_nonce_field( 'bcr_disconnect' ); ?>
						<?php submit_button( 'Disconnect', 'secondary', 'disconnect', false ); ?>
					</form>
				<?php elseif ( $is_connected && $is_expired ) : ?>
					<p style="color: orange;">⚠️ Token expired</p>
					<a href="<?php echo esc_url( $this->get_oauth_url() ); ?>" class="button button-primary">Reconnect to Basecamp</a>
				<?php else : ?>
					<p>❌ Not connected</p>
					<?php if ( ! empty( $opt['client_id'] ) && ! empty( $opt['client_secret'] ) ) : ?>
						<a href="<?php echo esc_url( $this->get_oauth_url() ); ?>" class="button button-primary">Connect to Basecamp</a>
					<?php else : ?>
						<p>Please enter your Client ID and Client Secret above first.</p>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<!-- Standalone Plugin Features -->
			<div class="card" style="max-width: 900px; border-left: 4px solid #46b450;">
				<h2 style="color: #2e7d32;">✅ Plugin Standalone Features</h2>
				<p style="color: #666;">This plugin works independently without any MCP integration. The features below work right out of the box:</p>

				<table style="width: 100%; border-collapse: collapse; margin-top: 15px;">
					<tr style="background: #f9f9f9;">
						<td style="padding: 12px; border: 1px solid #ddd; width: 200px;"><strong>Read Cards/Todos</strong></td>
						<td style="padding: 12px; border: 1px solid #ddd;">Fetch and display Basecamp cards and todos directly in WordPress admin</td>
						<td style="padding: 12px; border: 1px solid #ddd; width: 100px; text-align: center;"><?php echo $is_connected ? '✅ Ready' : '❌ Connect first'; ?></td>
					</tr>
					<tr>
						<td style="padding: 12px; border: 1px solid #ddd;"><strong>Post Comments</strong></td>
						<td style="padding: 12px; border: 1px solid #ddd;">Add comments to any Basecamp card or todo with HTML formatting support</td>
						<td style="padding: 12px; border: 1px solid #ddd; text-align: center;"><?php echo $is_connected ? '✅ Ready' : '❌ Connect first'; ?></td>
					</tr>
					<tr style="background: #f9f9f9;">
						<td style="padding: 12px; border: 1px solid #ddd;"><strong>REST API</strong></td>
						<td style="padding: 12px; border: 1px solid #ddd;">Access via <code><?php echo esc_url( rest_url( 'bcr/v1/read-card' ) ); ?></code></td>
						<td style="padding: 12px; border: 1px solid #ddd; text-align: center;"><?php echo $is_connected ? '✅ Ready' : '❌ Connect first'; ?></td>
					</tr>
					<tr>
						<td style="padding: 12px; border: 1px solid #ddd;"><strong>WP-CLI Commands</strong></td>
						<td style="padding: 12px; border: 1px solid #ddd;">Full CLI support: <code>wp basecamp read</code>, <code>wp basecamp comment</code>, etc.</td>
						<td style="padding: 12px; border: 1px solid #ddd; text-align: center;"><?php echo $is_connected ? '✅ Ready' : '❌ Connect first'; ?></td>
					</tr>
					<tr style="background: #f9f9f9;">
						<td style="padding: 12px; border: 1px solid #ddd;"><strong>Auto Token Refresh</strong></td>
						<td style="padding: 12px; border: 1px solid #ddd;">Tokens automatically refresh before expiry - no manual intervention needed</td>
						<td style="padding: 12px; border: 1px solid #ddd; text-align: center;"><?php echo ! empty( $token_data['refresh_token'] ) ? '✅ Enabled' : '⚠️ No refresh token'; ?></td>
					</tr>
				</table>

				<div style="background: #e8f5e9; padding: 15px; margin-top: 15px; border-radius: 4px;">
					<strong>💡 No MCP Required:</strong> All features above work without any MCP configuration. MCP integration (below) is only needed if you want to use Basecamp with Claude Code AI assistant.
				</div>
			</div>

			<?php if ( $is_connected && ! $is_expired ) : ?>
			<div class="card" style="max-width: 900px;">
				<h2>🔌 MCP Integration for Claude Code</h2>
				<p style="color: #666; margin-bottom: 20px;">Use these configurations to integrate Basecamp with Claude Code via MCP (Model Context Protocol).</p>

				<!-- Tab Navigation -->
				<div style="border-bottom: 2px solid #ddd; margin-bottom: 20px;">
					<button type="button" class="mcp-tab active" onclick="showMcpTab('claude-settings')" style="padding: 10px 20px; border: none; background: #0073aa; color: white; cursor: pointer; margin-right: 5px; border-radius: 4px 4px 0 0;">Claude Code Settings</button>
					<button type="button" class="mcp-tab" onclick="showMcpTab('mcp-config')" style="padding: 10px 20px; border: none; background: #f0f0f0; cursor: pointer; margin-right: 5px; border-radius: 4px 4px 0 0;">MCP Server config.json</button>
					<button type="button" class="mcp-tab" onclick="showMcpTab('sync-script')" style="padding: 10px 20px; border: none; background: #f0f0f0; cursor: pointer; border-radius: 4px 4px 0 0;">Sync Script</button>
				</div>

				<!-- Tab 1: Claude Code Settings -->
				<div id="tab-claude-settings" class="mcp-tab-content">
					<h3 style="margin-top: 0; color: #1e3a5f;">📁 Add to Claude Code Settings File</h3>
					<p style="margin-bottom: 10px;"><strong>File Location:</strong> <code style="background: #f5f5f5; padding: 4px 8px; border-radius: 3px;">~/.claude/settings.json</code> or via Claude Code's MCP settings</p>

					<div style="position: relative;">
						<textarea id="claude-settings-config" readonly style="width: 100%; height: 320px; font-family: 'Monaco', 'Menlo', monospace; font-size: 12px; background: #1e1e1e; color: #d4d4d4; padding: 15px; border: none; border-radius: 4px; resize: vertical;">
						<?php
						$claude_config = array(
							'mcpServers' => array(
								'basecamp' => array(
									'command' => 'node',
									'args'    => array( '~/.mcp-servers/basecamp-mcp-server/build/index.js' ),
									'env'     => array(
										'BASECAMP_ACCOUNT_ID'     => '5798509',
										'BASECAMP_ACCESS_TOKEN'   => $token_data['access_token'],
										'BASECAMP_REFRESH_TOKEN'  => $token_data['refresh_token'] ?? '',
										'BASECAMP_CLIENT_ID'      => $opt['client_id'] ?? '',
										'BASECAMP_CLIENT_SECRET'  => $opt['client_secret'] ?? '',
									),
								),
							),
						);
						echo wp_json_encode( $claude_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
						?>
						</textarea>
						<button type="button" class="button button-primary" onclick="copyToClipboard('claude-settings-config')" style="margin-top: 10px;">
							📋 Copy Configuration
						</button>
					</div>

					<div style="background: #e8f4fd; padding: 15px; margin-top: 15px; border-left: 4px solid #0073aa; border-radius: 0 4px 4px 0;">
						<h4 style="margin: 0 0 10px 0;">📝 Instructions:</h4>
						<ol style="margin: 0; padding-left: 20px;">
							<li>Copy the JSON above</li>
							<li>Merge it into your Claude Code settings file</li>
							<li>Restart Claude Code to load the MCP server</li>
						</ol>
					</div>
				</div>

				<!-- Tab 2: MCP Server config.json -->
				<div id="tab-mcp-config" class="mcp-tab-content" style="display: none;">
					<h3 style="margin-top: 0; color: #1e3a5f;">⚙️ MCP Server Standalone Configuration</h3>
					<p style="margin-bottom: 10px;"><strong>File Location:</strong> <code style="background: #f5f5f5; padding: 4px 8px; border-radius: 3px;">~/.mcp-servers/basecamp-mcp-server/config.json</code></p>

					<div style="position: relative;">
						<textarea id="mcp-server-config" readonly style="width: 100%; height: 280px; font-family: 'Monaco', 'Menlo', monospace; font-size: 12px; background: #1e1e1e; color: #d4d4d4; padding: 15px; border: none; border-radius: 4px; resize: vertical;">
						<?php
						$mcp_server_config = array(
							'accountId'    => '5798509',
							'accessToken'  => $token_data['access_token'],
							'refreshToken' => $token_data['refresh_token'] ?? '',
							'clientId'     => $opt['client_id'] ?? '',
							'clientSecret' => $opt['client_secret'] ?? '',
						);
						echo wp_json_encode( $mcp_server_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
						?>
						</textarea>
						<button type="button" class="button button-primary" onclick="copyToClipboard('mcp-server-config')" style="margin-top: 10px;">
							📋 Copy Configuration
						</button>
					</div>

					<div style="background: #fff3cd; padding: 15px; margin-top: 15px; border-left: 4px solid #ffc107; border-radius: 0 4px 4px 0;">
						<h4 style="margin: 0 0 10px 0;">⚠️ When to Use This:</h4>
						<p style="margin: 0;">Use this config.json if your MCP server reads from a config file instead of environment variables. Most MCP servers prefer environment variables (Tab 1).</p>
					</div>
				</div>

				<!-- Tab 3: Sync Script -->
				<div id="tab-sync-script" class="mcp-tab-content" style="display: none;">
					<h3 style="margin-top: 0; color: #1e3a5f;">🔄 Token Sync Script</h3>
					<p style="margin-bottom: 10px;">Run this script when tokens expire to sync from WordPress database:</p>

					<div style="position: relative;">
						<textarea id="sync-script-content" readonly style="width: 100%; height: 200px; font-family: 'Monaco', 'Menlo', monospace; font-size: 12px; background: #1e1e1e; color: #d4d4d4; padding: 15px; border: none; border-radius: 4px; resize: vertical;">#!/bin/bash
# Sync Basecamp tokens from WordPress to MCP config.
# Location: ~/.mcp-servers/basecamp-mcp-server/sync-token.sh

WP_PATH="<?php echo esc_html( ABSPATH ); ?>"

cd "$WP_PATH" && wp eval '
$data = get_option("bcr_token_data");
echo "ACCESS_TOKEN=" . $data["access_token"] . "\n";
echo "REFRESH_TOKEN=" . $data["refresh_token"] . "\n";
'</textarea>
						<button type="button" class="button button-primary" onclick="copyToClipboard('sync-script-content')" style="margin-top: 10px;">
							📋 Copy Script
						</button>
					</div>

					<div style="background: #d4edda; padding: 15px; margin-top: 15px; border-left: 4px solid #28a745; border-radius: 0 4px 4px 0;">
						<h4 style="margin: 0 0 10px 0;">💡 Quick Token Refresh:</h4>
						<p style="margin: 0;">If tokens expire, simply click "Reconnect to Basecamp" above, then copy the new configuration.</p>
					</div>
				</div>

				<!-- Token Info Summary -->
				<div style="background: #f8f9fa; padding: 15px; margin-top: 20px; border: 1px solid #dee2e6; border-radius: 4px;">
					<h4 style="margin: 0 0 15px 0;">🔑 Current Token Status</h4>
					<table style="width: 100%; font-size: 13px;">
						<tr>
							<td style="padding: 5px 10px 5px 0; width: 140px;"><strong>Access Token:</strong></td>
							<td><code style="background: #e9ecef; padding: 2px 6px; border-radius: 3px;"><?php echo esc_html( substr( $token_data['access_token'], 0, 20 ) ); ?>...<?php echo esc_html( substr( $token_data['access_token'], -10 ) ); ?></code></td>
						</tr>
						<tr>
							<td style="padding: 5px 10px 5px 0;"><strong>Expires:</strong></td>
							<td><?php echo esc_html( gmdate( 'M j, Y g:i A', $token_data['expires_at'] ) ); ?> <span style="color: #666;">(<?php echo esc_html( human_time_diff( $token_data['expires_at'] ) ); ?> <?php echo time() < $token_data['expires_at'] ? 'from now' : 'ago'; ?>)</span></td>
						</tr>
						<tr>
							<td style="padding: 5px 10px 5px 0;"><strong>Refresh Token:</strong></td>
							<td><?php echo ! empty( $token_data['refresh_token'] ) ? '✅ Available' : '❌ Not available'; ?></td>
						</tr>
					</table>
				</div>

				<script>
				function showMcpTab(tabId) {
					document.querySelectorAll('.mcp-tab-content').forEach(function(tab) {
						tab.style.display = 'none';
					});
					document.querySelectorAll('.mcp-tab').forEach(function(btn) {
						btn.style.background = '#f0f0f0';
						btn.style.color = '#333';
						btn.classList.remove('active');
					});
					document.getElementById('tab-' + tabId).style.display = 'block';
					event.target.style.background = '#0073aa';
					event.target.style.color = 'white';
					event.target.classList.add('active');
				}

				function copyToClipboard(elementId) {
					const textarea = document.getElementById(elementId);
					textarea.select();
					textarea.setSelectionRange(0, 99999);
					document.execCommand('copy');
					const button = event.target;
					const originalText = button.textContent;
					button.textContent = '✅ Copied!';
					button.style.background = '#46b450';
					button.style.borderColor = '#46b450';
					setTimeout(function() {
						button.textContent = originalText;
						button.style.background = '';
						button.style.borderColor = '';
					}, 2000);
				}
				</script>
			</div>
			<?php endif; ?>

			<?php if ( $is_connected && ! $is_expired ) : ?>
			<div class="card">
				<h2>Read Card/Todo</h2>
				<form id="test-form">
					<p>
						<label for="card-url">Basecamp Card/Todo URL:</label><br>
						<input type="url" id="card-url" placeholder="https://3.basecamp.com/..." style="width: 400px;" value="https://3.basecamp.com/5798509/buckets/37557560/card_tables/cards/9010883489">
						<button type="submit" class="button button-primary">Read Card</button>
					</p>
				</form>
				<div id="result" style="margin-top: 20px;"></div>
			</div>

			<div class="card">
				<h2>Post Comment</h2>
				<form id="comment-form">
					<p>
						<label for="comment-url">Basecamp Card/Todo URL:</label><br>
						<input type="url" id="comment-url" placeholder="https://3.basecamp.com/..." style="width: 400px;" value="https://3.basecamp.com/5798509/buckets/37557560/card_tables/cards/9010883489">
					</p>
					<p>
						<label for="comment-text">Comment:</label><br>
						<textarea id="comment-text" rows="5" style="width: 400px;" placeholder="Enter your comment here..."></textarea>
					</p>
					<p>
						<label>
							<input type="checkbox" id="use-html"> Use HTML formatting
						</label>
					</p>
					<p>
						<button type="submit" class="button button-primary">Post Comment</button>
					</p>
				</form>
				<div id="comment-result" style="margin-top: 20px;"></div>
			</div>

			<script>
			(function() {
				const bcr_nonce = '<?php echo esc_js( wp_create_nonce( 'bcr_ajax_nonce' ) ); ?>';

				function escapeHtml(text) {
					const div = document.createElement('div');
					div.textContent = text;
					return div.innerHTML;
				}

				function renderCardResult(data, container) {
					container.textContent = '';

					const wrapper = document.createElement('div');
					wrapper.style.cssText = 'border: 1px solid #ddd; padding: 15px; background: white;';

					const title = document.createElement('h3');
					title.textContent = data.title;
					wrapper.appendChild(title);

					const typeP = document.createElement('p');
					typeP.innerHTML = '<strong>Type:</strong> ' + escapeHtml(data.type);
					wrapper.appendChild(typeP);

					const createdP = document.createElement('p');
					createdP.innerHTML = '<strong>Created:</strong> ' + escapeHtml(new Date(data.created_at).toLocaleString());
					wrapper.appendChild(createdP);

					if (data.description) {
						const descLabel = document.createElement('p');
						descLabel.innerHTML = '<strong>Description:</strong>';
						wrapper.appendChild(descLabel);
						const descDiv = document.createElement('div');
						descDiv.textContent = data.description.replace(/<[^>]*>/g, '');
						wrapper.appendChild(descDiv);
					}

					if (data.comments && data.comments.length > 0) {
						const commentsHeader = document.createElement('h4');
						commentsHeader.textContent = 'Comments (' + data.comments.length + ')';
						wrapper.appendChild(commentsHeader);

						data.comments.forEach(function(comment) {
							const commentDiv = document.createElement('div');
							commentDiv.style.cssText = 'margin-bottom: 15px; padding: 10px; background: #f9f9f9; border-left: 3px solid #0073aa;';

							if (comment.creator && comment.creator.name) {
								const authorStrong = document.createElement('strong');
								authorStrong.textContent = comment.creator.name;
								commentDiv.appendChild(authorStrong);
							}

							if (comment.created_at) {
								const dateSpan = document.createElement('span');
								dateSpan.style.cssText = 'color: #666; font-size: 12px;';
								dateSpan.textContent = ' • ' + new Date(comment.created_at).toLocaleString();
								commentDiv.appendChild(dateSpan);
							}

							const contentDiv = document.createElement('div');
							contentDiv.style.marginTop = '8px';
							contentDiv.textContent = comment.content.replace(/<[^>]*>/g, '');
							commentDiv.appendChild(contentDiv);

							if (comment.images && comment.images.length > 0) {
								const imagesDiv = document.createElement('div');
								imagesDiv.style.marginTop = '10px';
								imagesDiv.innerHTML = '<strong>📷 Images (' + comment.images.length + '):</strong><br>';
								comment.images.forEach(function(imgUrl, idx) {
									const link = document.createElement('a');
									link.href = imgUrl;
									link.target = '_blank';
									link.textContent = 'Image ' + (idx + 1);
									imagesDiv.appendChild(link);
									imagesDiv.appendChild(document.createElement('br'));
								});
								commentDiv.appendChild(imagesDiv);
							}

							wrapper.appendChild(commentDiv);
						});
					}

					container.appendChild(wrapper);
				}

				document.getElementById('test-form').addEventListener('submit', function(e) {
					e.preventDefault();
					const url = document.getElementById('card-url').value;
					const resultDiv = document.getElementById('result');

					if (!url) {
						resultDiv.textContent = 'Please enter a Basecamp card URL';
						resultDiv.style.color = 'red';
						return;
					}

					resultDiv.textContent = 'Loading...';
					resultDiv.style.color = '';

					fetch(ajaxurl, {
						method: 'POST',
						headers: {'Content-Type': 'application/x-www-form-urlencoded'},
						body: 'action=bcr_read_card&url=' + encodeURIComponent(url) + '&_wpnonce=' + bcr_nonce
					})
					.then(function(response) { return response.json(); })
					.then(function(data) {
						if (data.success) {
							renderCardResult(data.data, resultDiv);
						} else {
							resultDiv.textContent = 'Error: ' + data.data;
							resultDiv.style.color = 'red';
						}
					});
				});

				document.getElementById('comment-form').addEventListener('submit', function(e) {
					e.preventDefault();
					const url = document.getElementById('comment-url').value;
					const comment = document.getElementById('comment-text').value;
					const useHtml = document.getElementById('use-html').checked;
					const resultDiv = document.getElementById('comment-result');

					if (!url || !comment) {
						resultDiv.textContent = 'Please enter both URL and comment';
						resultDiv.style.color = 'red';
						return;
					}

					resultDiv.textContent = 'Posting comment...';
					resultDiv.style.color = '';

					fetch(ajaxurl, {
						method: 'POST',
						headers: {'Content-Type': 'application/x-www-form-urlencoded'},
						body: 'action=bcr_post_comment&url=' + encodeURIComponent(url) +
								'&comment=' + encodeURIComponent(comment) +
								'&use_html=' + (useHtml ? '1' : '0') +
								'&_wpnonce=' + bcr_nonce
					})
					.then(function(response) { return response.json(); })
					.then(function(data) {
						if (data.success) {
							resultDiv.textContent = '✅ Comment posted successfully!';
							resultDiv.style.color = 'green';
							document.getElementById('comment-text').value = '';
						} else {
							resultDiv.textContent = '❌ Error: ' + (data.data || 'Failed to post comment');
							resultDiv.style.color = 'red';
						}
					})
					.catch(function(error) {
						console.error('Error:', error);
						resultDiv.textContent = '❌ Error posting comment';
						resultDiv.style.color = 'red';
					});
				});
			})();
			</script>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get the OAuth authorization URL.
	 *
	 * @since 1.0.0
	 * @return string OAuth URL.
	 */
	private function get_oauth_url() {
		$opt = get_option( self::OPT, array() );
		// Use the API class to get OAuth URL with all scopes.
		return Basecamp_API::get_oauth_url(
			$opt['client_id'],
			admin_url( 'options-general.php?page=basecamp-reader' )
		);
	}

	/**
	 * Handle OAuth callback and exchange code for tokens.
	 *
	 * @since 1.0.0
	 * @param string $code Authorization code from OAuth callback.
	 * @return bool True on success, false on failure.
	 */
	private function handle_oauth_callback( $code ) {
		$opt = get_option( self::OPT, array() );

		$response = wp_remote_post(
			'https://launchpad.37signals.com/authorization/token',
			array(
				'body' => array(
					'type'          => 'web_server',
					'client_id'     => $opt['client_id'],
					'client_secret' => $opt['client_secret'],
					'redirect_uri'  => admin_url( 'options-general.php?page=basecamp-reader' ),
					'code'          => $code,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			set_transient( 'bcr_oauth_error', 'Connection error: ' . $response->get_error_message(), 60 );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['access_token'] ) ) {
			update_option(
				self::TOKEN_OPT,
				array(
					'access_token'  => $body['access_token'],
					'refresh_token' => $body['refresh_token'] ?? '',
					'expires_at'    => time() + ( $body['expires_in'] ?? 1209600 ),
				)
			);
			return true;
		} elseif ( ! empty( $body['error'] ) ) {
			set_transient( 'bcr_oauth_error', 'OAuth error: ' . $body['error'] . ' - ' . ( $body['error_description'] ?? '' ), 60 );
		}

		return false;
	}

	/**
	 * Handle disconnect action.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function handle_disconnect() {
		check_admin_referer( 'bcr_disconnect' );
		delete_option( self::TOKEN_OPT );
		wp_safe_redirect( admin_url( 'admin.php?page=basecamp-reader' ) );
		exit;
	}

	/**
	 * Handle REST API request to read a card.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request REST request object.
	 * @return array|WP_Error Response data or error.
	 */
	public function rest_read_card( $request ) {
		$url = $request->get_param( 'url' );

		if ( ! $url ) {
			return new WP_Error( 'no_url', 'URL parameter is required', array( 'status' => 400 ) );
		}

		// Simulate the AJAX call.
		$_POST['url'] = $url;

		ob_start();
		$this->handle_read_card();
		$output = ob_get_clean();

		return json_decode( $output, true );
	}

	/**
	 * Handle AJAX request to post a comment.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function handle_post_comment() {
		// Verify nonce.
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'bcr_ajax_nonce' ) ) {
			wp_send_json_error( 'Security check failed' );
		}

		// Check user permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$url          = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
		$comment_text = isset( $_POST['comment'] ) ? wp_kses_post( wp_unslash( $_POST['comment'] ) ) : '';
		$use_html     = isset( $_POST['use_html'] ) ? sanitize_text_field( wp_unslash( $_POST['use_html'] ) ) : '0';

		if ( ! $url || ! $comment_text ) {
			wp_send_json_error( 'URL and comment are required' );
		}

		// Parse URL to get account, project, and card/todo ID.
		$pattern_card = '/basecamp\.com\/(\d+)\/buckets\/(\d+)\/card_tables\/cards\/(\d+)/';
		$pattern_todo = '/basecamp\.com\/(\d+)\/buckets\/(\d+)\/todos\/(\d+)/';

		$account_id   = '';
		$project_id   = '';
		$recording_id = '';

		if ( preg_match( $pattern_card, $url, $matches ) ) {
			$account_id   = $matches[1];
			$project_id   = $matches[2];
			$recording_id = $matches[3];
		} elseif ( preg_match( $pattern_todo, $url, $matches ) ) {
			$account_id   = $matches[1];
			$project_id   = $matches[2];
			$recording_id = $matches[3];
		} else {
			wp_send_json_error( 'Invalid Basecamp URL' );
		}

		// Get token.
		$token_data = get_option( self::TOKEN_OPT, array() );
		if ( empty( $token_data['access_token'] ) ) {
			wp_send_json_error( 'Not authenticated' );
		}

		// Build comment API URL.
		$comments_url = "https://3.basecampapi.com/{$account_id}/buckets/{$project_id}/recordings/{$recording_id}/comments.json";

		// Format comment based on HTML option.
		if ( '1' === $use_html ) {
			// Allow HTML formatting.
			$comment_content = $comment_text;
		} else {
			// Convert line breaks to br for plain text.
			$comment_content = nl2br( esc_html( $comment_text ) );
		}

		// Post comment.
		$response = wp_remote_post(
			$comments_url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token_data['access_token'],
					'User-Agent'    => get_bloginfo( 'name' ) . ' (' . get_option( 'admin_email' ) . ')',
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'content' => $comment_content ) ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 201 === $code ) {
			wp_send_json_success( 'Comment posted successfully' );
		} else {
			$body      = json_decode( wp_remote_retrieve_body( $response ), true );
			$error_msg = $body['error'] ?? 'Failed to post comment';
			wp_send_json_error( $error_msg );
		}
	}
}

// Initialize the plugin.
$bcr_instance = Basecamp_Cards_Reader_Clean::get_instance();

// Activation/Deactivation hooks.
register_activation_hook( __FILE__, array( $bcr_instance, 'activate' ) );
register_deactivation_hook( __FILE__, array( $bcr_instance, 'deactivate' ) );
