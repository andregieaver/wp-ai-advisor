<?php
/**
 * Admin screen: settings and the knowledge base.
 *
 * @package WP_AI_Advisor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the options page, its fields and its assets.
 */
class WP_AI_Advisor_Admin {

	const PAGE_SLUG  = 'wp-ai-advisor';
	const GROUP      = 'wp_ai_advisor';
	const CAPABILITY = 'manage_options';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WP_AI_ADVISOR_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Adds the options page.
	 *
	 * @return void
	 */
	public function add_page() {
		add_options_page(
			__( 'AI Advisor', 'wp-ai-advisor' ),
			__( 'AI Advisor', 'wp-ai-advisor' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Adds a Settings link to the plugin list row.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'wp-ai-advisor' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Loads the knowledge-base script on this screen only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wp-ai-advisor-admin',
			WP_AI_ADVISOR_URL . 'assets/css/admin.css',
			array(),
			WP_AI_ADVISOR_VERSION
		);

		wp_enqueue_script(
			'wp-ai-advisor-admin',
			WP_AI_ADVISOR_URL . 'assets/js/admin.js',
			array(),
			WP_AI_ADVISOR_VERSION,
			true
		);

		wp_localize_script(
			'wp-ai-advisor-admin',
			'wpAiAdvisorAdmin',
			array(
				'root'    => esc_url_raw( rest_url( WP_AI_Advisor_REST_Controller::NAMESPACE_V1 ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'strings' => array(
					'testing'    => __( 'Testing…', 'wp-ai-advisor' ),
					'preparing'  => __( 'Preparing…', 'wp-ai-advisor' ),
					'retrying'   => __( 'Retrying after: %s', 'wp-ai-advisor' ),
					'resumeHint' => __( 'Nothing was lost — press Resume to carry on from here.', 'wp-ai-advisor' ),
					'selected'   => __( '%d selected', 'wp-ai-advisor' ),
					'nothingSelected' => __( 'Choose an action and at least one source first.', 'wp-ai-advisor' ),
					'confirmDelete'   => __( 'Delete %d sources from the knowledge base?', 'wp-ai-advisor' ),
					'confirmDuplicates' => __( 'Delete every duplicate source? One copy of each URL is kept.', 'wp-ai-advisor' ),
					'crawling'   => __( 'Crawling %s', 'wp-ai-advisor' ),
					'importing'  => __( 'Importing %s', 'wp-ai-advisor' ),
					'indexing'   => __( 'Indexing %s', 'wp-ai-advisor' ),
					'done'       => __( 'Done.', 'wp-ai-advisor' ),
					'stopped'    => __( 'Stopped.', 'wp-ai-advisor' ),
					'failed'     => __( 'Request failed.', 'wp-ai-advisor' ),
					'uploading'  => __( 'Uploading…', 'wp-ai-advisor' ),
					'confirm'    => __( 'This deletes the whole knowledge base. Continue?', 'wp-ai-advisor' ),
					'noKey'      => __( 'Add an API key first.', 'wp-ai-advisor' ),
				),
			)
		);
	}

	/**
	 * Registers the settings, sections and fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::GROUP,
			WP_AI_Advisor_Settings::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'WP_AI_Advisor_Settings', 'sanitize' ),
				'default'           => WP_AI_Advisor_Settings::defaults(),
			)
		);

		$sections = array(
			'api'        => __( 'OpenAI connection', 'wp-ai-advisor' ),
			'sources'    => __( 'Knowledge source', 'wp-ai-advisor' ),
			'grounding'  => __( 'Answering', 'wp-ai-advisor' ),
			'estimates'  => __( 'Estimates', 'wp-ai-advisor' ),
			'language'   => __( 'Language', 'wp-ai-advisor' ),
			'access'     => __( 'Access', 'wp-ai-advisor' ),
			'appearance' => __( 'Appearance', 'wp-ai-advisor' ),
		);

		foreach ( $sections as $id => $title ) {
			add_settings_section(
				'wp_ai_advisor_' . $id,
				$title,
				'api' === $id ? array( $this, 'render_api_section' ) : '__return_false',
				self::PAGE_SLUG
			);
		}

		$fields = array(
			array( 'api_key', __( 'API key', 'wp-ai-advisor' ), 'render_api_key', 'api' ),
			array( 'model', __( 'Chat model', 'wp-ai-advisor' ), 'render_model', 'api' ),
			array( 'embedding_model', __( 'Embedding model', 'wp-ai-advisor' ), 'render_embedding_model', 'api' ),
			array( 'temperature', __( 'Temperature', 'wp-ai-advisor' ), 'render_temperature', 'api' ),
			array( 'max_tokens', __( 'Max answer tokens', 'wp-ai-advisor' ), 'render_max_tokens', 'api' ),

			array( 'source_mode', __( 'Build the index from', 'wp-ai-advisor' ), 'render_source_mode', 'sources' ),
			array( 'local_post_types', __( 'Local post types', 'wp-ai-advisor' ), 'render_local_post_types', 'sources' ),
			array( 'site_url', __( 'Site URL to crawl', 'wp-ai-advisor' ), 'render_site_url', 'sources' ),
			array( 'crawl_max_pages', __( 'Maximum pages', 'wp-ai-advisor' ), 'render_crawl_max_pages', 'sources' ),
			array( 'crawl_exclude', __( 'Skip URLs containing', 'wp-ai-advisor' ), 'render_crawl_exclude', 'sources' ),
			array( 'skip_crawled', __( 'Avoid duplicates', 'wp-ai-advisor' ), 'render_skip_crawled', 'sources' ),
			array( 'render_filters', __( 'Render with theme filters', 'wp-ai-advisor' ), 'render_render_filters', 'sources' ),

			array( 'strict_mode', __( 'Restrict to site content', 'wp-ai-advisor' ), 'render_strict_mode', 'grounding' ),
			array( 'refusal_message', __( 'Off-topic reply', 'wp-ai-advisor' ), 'render_refusal_message', 'grounding' ),
			array( 'system_prompt', __( 'System prompt', 'wp-ai-advisor' ), 'render_system_prompt', 'grounding' ),
			array( 'top_k', __( 'Context passages', 'wp-ai-advisor' ), 'render_top_k', 'grounding' ),
			array( 'min_score', __( 'Relevance threshold', 'wp-ai-advisor' ), 'render_min_score', 'grounding' ),

			array( 'enable_calculator', __( 'Work out estimates', 'wp-ai-advisor' ), 'render_enable_calculator', 'estimates' ),
			array( 'assumptions', __( 'Assumptions', 'wp-ai-advisor' ), 'render_assumptions', 'estimates' ),

			array( 'reply_language', __( 'Reply language', 'wp-ai-advisor' ), 'render_reply_language', 'language' ),

			array( 'admin_only', __( 'Admin-only', 'wp-ai-advisor' ), 'render_admin_only', 'access' ),
			array( 'rate_limit', __( 'Questions per hour', 'wp-ai-advisor' ), 'render_rate_limit', 'access' ),

			array( 'eyebrow', __( 'Eyebrow', 'wp-ai-advisor' ), 'render_eyebrow', 'appearance' ),
			array( 'heading', __( 'Heading', 'wp-ai-advisor' ), 'render_heading', 'appearance' ),
			array( 'placeholder', __( 'Input placeholder', 'wp-ai-advisor' ), 'render_placeholder', 'appearance' ),
			array( 'suggestions', __( 'Suggested questions', 'wp-ai-advisor' ), 'render_suggestions', 'appearance' ),
			array( 'cta_label', __( 'Call to action', 'wp-ai-advisor' ), 'render_cta', 'appearance' ),
			array( 'accent', __( 'Accent colour', 'wp-ai-advisor' ), 'render_accent', 'appearance' ),
		);

		foreach ( $fields as $field ) {
			list( $id, $label, $callback, $section ) = $field;

			add_settings_field(
				'wp_ai_advisor_' . $id,
				$label,
				array( $this, $callback ),
				self::PAGE_SLUG,
				'wp_ai_advisor_' . $section,
				array( 'label_for' => 'wp_ai_advisor_' . $id )
			);
		}
	}

	/**
	 * Renders the options page with its two tabs.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch.
		$tab = isset( $_GET['tab'] ) && 'knowledge' === $_GET['tab'] ? 'knowledge' : 'settings';
		?>
		<div class="wrap wp-ai-advisor-admin">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ); ?>"
					class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Settings', 'wp-ai-advisor' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=knowledge' ) ); ?>"
					class="nav-tab <?php echo 'knowledge' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Knowledge base', 'wp-ai-advisor' ); ?>
				</a>
			</h2>

			<?php if ( 'knowledge' === $tab ) : ?>
				<?php $this->render_knowledge_tab(); ?>
			<?php else : ?>
				<p>
					<?php
					printf(
						/* translators: %s: shortcode example. */
						esc_html__( 'Place the advisor on any page with %s.', 'wp-ai-advisor' ),
						'<code>[ai_advisor]</code>'
					);
					?>
				</p>
				<form action="options.php" method="post">
					<?php
					settings_fields( self::GROUP );
					printf(
						'<input type="hidden" name="%s[_form]" value="settings" />',
						esc_attr( WP_AI_Advisor_Settings::OPTION_KEY )
					);
					do_settings_sections( self::PAGE_SLUG );
					submit_button();
					?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the knowledge-base tab.
	 *
	 * @return void
	 */
	private function render_knowledge_tab() {
		$stats = WP_AI_Advisor_Store::stats();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only table filter.
		$filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$filter = in_array( $filter, array( 'pending', 'fetched', 'indexed', 'error', 'duplicates' ), true ) ? $filter : '';

		$duplicates = WP_AI_Advisor_Store::duplicate_ids();

		if ( 'duplicates' === $filter ) {
			$lookup  = array_flip( $duplicates );
			$sources = array_values(
				array_filter(
					WP_AI_Advisor_Store::list_sources(),
					static function ( $source ) use ( $lookup ) {
						return isset( $lookup[ (int) $source['id'] ] );
					}
				)
			);
		} else {
			$sources = WP_AI_Advisor_Store::list_sources( '', $filter );
		}
		?>
		<p class="description"><?php echo esc_html( $this->source_summary() ); ?></p>

		<div class="aiadv-admin__stats" id="aiadv-stats">
			<span><?php esc_html_e( 'Sources', 'wp-ai-advisor' ); ?>: <strong data-stat="total"><?php echo (int) $stats['total']; ?></strong></span>
			<span><?php esc_html_e( 'Indexed', 'wp-ai-advisor' ); ?>: <strong data-stat="indexed"><?php echo (int) $stats['indexed']; ?></strong></span>
			<span><?php esc_html_e( 'To fetch', 'wp-ai-advisor' ); ?>: <strong data-stat="pending"><?php echo (int) $stats['pending']; ?></strong></span>
			<span><?php esc_html_e( 'To index', 'wp-ai-advisor' ); ?>: <strong data-stat="fetched"><?php echo (int) $stats['fetched']; ?></strong></span>
			<span><?php esc_html_e( 'Failed', 'wp-ai-advisor' ); ?>: <strong data-stat="error"><?php echo (int) $stats['error']; ?></strong></span>
			<span><?php esc_html_e( 'Passages', 'wp-ai-advisor' ); ?>: <strong data-stat="chunks"><?php echo (int) $stats['chunks']; ?></strong></span>
			<?php $languages = WP_AI_Advisor_Language::indexed(); ?>
			<?php if ( $languages ) : ?>
				<span><?php esc_html_e( 'Languages', 'wp-ai-advisor' ); ?>: <strong><?php echo esc_html( implode( ', ', $languages ) ); ?></strong></span>
			<?php endif; ?>
		</div>

		<p class="aiadv-admin__actions">
			<button type="button" class="button" id="aiadv-test"><?php esc_html_e( 'Test connection', 'wp-ai-advisor' ); ?></button>
			<button type="button" class="button button-primary" id="aiadv-build"><?php esc_html_e( 'Build knowledge base', 'wp-ai-advisor' ); ?></button>
			<?php if ( WP_AI_Advisor_Settings::uses( 'crawl' ) ) : ?>
				<button type="button" class="button" id="aiadv-crawl"><?php esc_html_e( 'Crawl only', 'wp-ai-advisor' ); ?></button>
			<?php endif; ?>
			<?php if ( WP_AI_Advisor_Settings::uses( 'local' ) ) : ?>
				<button type="button" class="button" id="aiadv-local"><?php esc_html_e( 'Import local content only', 'wp-ai-advisor' ); ?></button>
			<?php endif; ?>
			<button type="button" class="button" id="aiadv-resume"><?php esc_html_e( 'Resume', 'wp-ai-advisor' ); ?></button>
			<?php if ( $stats['error'] > 0 ) : ?>
				<button type="button" class="button" id="aiadv-retry"><?php esc_html_e( 'Retry failed', 'wp-ai-advisor' ); ?></button>
			<?php endif; ?>
			<button type="button" class="button" id="aiadv-stop" disabled><?php esc_html_e( 'Stop', 'wp-ai-advisor' ); ?></button>
			<button type="button" class="button button-link-delete" id="aiadv-clear"><?php esc_html_e( 'Clear everything', 'wp-ai-advisor' ); ?></button>
		</p>

		<p class="aiadv-admin__log" id="aiadv-log" role="status" aria-live="polite"></p>

		<h2><?php esc_html_e( 'Additional documents', 'wp-ai-advisor' ); ?></h2>
		<p class="description">
			<?php
			printf(
				/* translators: %s: comma-separated list of file extensions. */
				esc_html__( 'Upload price lists, menus or policies the website does not spell out. Allowed: %s. Scanned PDFs hold images rather than text and cannot be read.', 'wp-ai-advisor' ),
				esc_html( implode( ', ', WP_AI_Advisor_Documents::allowed_extensions() ) )
			);
			?>
		</p>
		<p>
			<input type="file" id="aiadv-file" accept=".txt,.md,.markdown,.csv,.json,.html,.htm,.docx,.pdf" />
			<label for="aiadv-file-language" class="screen-reader-text"><?php esc_html_e( 'Document language', 'wp-ai-advisor' ); ?></label>
			<select id="aiadv-file-language">
				<?php $site_language = WP_AI_Advisor_Language::site(); ?>
				<?php foreach ( WP_AI_Advisor_Language::names() as $code => $name ) : ?>
					<option value="<?php echo esc_attr( $code ); ?>"<?php selected( $site_language, $code ); ?>>
						<?php echo esc_html( $name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button" id="aiadv-upload"><?php esc_html_e( 'Upload and index', 'wp-ai-advisor' ); ?></button>
		</p>

		<h2><?php esc_html_e( 'Sources', 'wp-ai-advisor' ); ?></h2>

		<ul class="subsubsub aiadv-admin__filters">
			<?php
			$filters = array(
				''        => array( __( 'All', 'wp-ai-advisor' ), $stats['total'] ),
				'error'   => array( __( 'Failed', 'wp-ai-advisor' ), $stats['error'] ),
				'pending' => array( __( 'To fetch', 'wp-ai-advisor' ), $stats['pending'] ),
				'fetched' => array( __( 'To index', 'wp-ai-advisor' ), $stats['fetched'] ),
				'indexed' => array( __( 'Indexed', 'wp-ai-advisor' ), $stats['indexed'] ),
				'duplicates' => array( __( 'Duplicates', 'wp-ai-advisor' ), count( $duplicates ) ),
			);
			$last    = array_key_last( $filters );
			?>
			<?php foreach ( $filters as $value => $info ) : ?>
				<li>
					<a
						href="<?php echo esc_url( $this->tab_url( 'knowledge', $value ) ); ?>"
						class="<?php echo $filter === $value ? 'current' : ''; ?>"
					>
						<?php echo esc_html( $info[0] ); ?>
						<span class="count">(<?php echo (int) $info[1]; ?>)</span>
					</a>
					<?php echo $value === $last ? '' : ' |'; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<div class="tablenav top aiadv-admin__bulk">
			<select id="aiadv-bulk-action">
				<option value=""><?php esc_html_e( 'Bulk actions', 'wp-ai-advisor' ); ?></option>
				<option value="requeue"><?php esc_html_e( 'Queue for crawling again', 'wp-ai-advisor' ); ?></option>
				<option value="delete"><?php esc_html_e( 'Delete', 'wp-ai-advisor' ); ?></option>
			</select>
			<button type="button" class="button" id="aiadv-bulk-apply"><?php esc_html_e( 'Apply', 'wp-ai-advisor' ); ?></button>
			<span class="aiadv-admin__selected" id="aiadv-selected"></span>
			<?php if ( ! empty( $duplicates ) ) : ?>
				<button type="button" class="button button-link-delete" id="aiadv-dedupe">
					<?php
					printf(
						/* translators: %d: number of duplicate sources. */
						esc_html__( 'Delete all %d duplicates', 'wp-ai-advisor' ),
						count( $duplicates )
					);
					?>
				</button>
			<?php endif; ?>
		</div>

		<table class="widefat striped aiadv-admin__table">
			<thead>
				<tr>
					<td class="check-column">
						<input type="checkbox" id="aiadv-select-all" aria-label="<?php esc_attr_e( 'Select all shown', 'wp-ai-advisor' ); ?>" />
					</td>
					<th><?php esc_html_e( 'Title', 'wp-ai-advisor' ); ?></th>
					<th><?php esc_html_e( 'Type', 'wp-ai-advisor' ); ?></th>
					<th><?php esc_html_e( 'Language', 'wp-ai-advisor' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wp-ai-advisor' ); ?></th>
					<th><?php esc_html_e( 'Updated', 'wp-ai-advisor' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $sources ) ) : ?>
					<tr>
						<td colspan="7">
							<?php
							echo $filter
								? esc_html__( 'No sources with this status.', 'wp-ai-advisor' )
								: esc_html__( 'Nothing indexed yet.', 'wp-ai-advisor' );
							?>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $sources as $source ) : ?>
						<tr>
							<th scope="row" class="check-column">
								<input
									type="checkbox"
									class="aiadv-admin__select"
									value="<?php echo (int) $source['id']; ?>"
									aria-label="<?php echo esc_attr( $source['title'] ? $source['title'] : $source['url'] ); ?>"
								/>
							</th>
							<td>
								<?php if ( WP_AI_Advisor_Store::TYPE_DOCUMENT !== $source['type'] && $source['url'] ) : ?>
									<a href="<?php echo esc_url( $source['url'] ); ?>" target="_blank" rel="noopener">
										<?php echo esc_html( $source['title'] ? $source['title'] : $source['url'] ); ?>
									</a>
								<?php else : ?>
									<?php echo esc_html( $source['title'] ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $source['type'] ); ?></td>
							<td><?php echo esc_html( $source['language'] ? $source['language'] : '—' ); ?></td>
							<td>
								<?php echo esc_html( $source['status'] ); ?>
								<?php if ( $source['message'] ) : ?>
									<br /><span class="aiadv-admin__error"><?php echo esc_html( $source['message'] ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $source['updated_at'] ); ?></td>
							<td>
								<button type="button" class="button-link aiadv-admin__delete" data-id="<?php echo (int) $source['id']; ?>">
									<?php esc_html_e( 'Delete', 'wp-ai-advisor' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Fields
	 * ------------------------------------------------------------------ */

	/**
	 * URL for a tab, optionally filtered by source status.
	 *
	 * @param string $tab    Tab slug.
	 * @param string $status Status filter, or '' for all.
	 * @return string
	 */
	private function tab_url( $tab = 'settings', $status = '' ) {
		$args = array( 'page' => self::PAGE_SLUG );

		if ( 'settings' !== $tab ) {
			$args['tab'] = $tab;
		}

		if ( $status ) {
			$args['status'] = $status;
		}

		return add_query_arg( $args, admin_url( 'options-general.php' ) );
	}

	/**
	 * One line describing what a build will do under the current mode.
	 *
	 * @return string
	 */
	private function source_summary() {
		$post_types = implode( ', ', WP_AI_Advisor_Settings::local_post_types() );

		switch ( WP_AI_Advisor_Settings::source_mode() ) {
			case 'local':
				return sprintf(
					/* translators: %s: comma-separated post type names. */
					__( 'Local mode: reads published %s from this install, with no HTTP requests. Re-run after you change content.', 'wp-ai-advisor' ),
					$post_types ? $post_types : __( '(no post types selected)', 'wp-ai-advisor' )
				);

			case 'both':
				return sprintf(
					/* translators: 1: site URL, 2: comma-separated post type names. */
					__( 'Both modes: crawls %1$s for rendered navigation and reads published %2$s locally. Re-run after the site changes.', 'wp-ai-advisor' ),
					WP_AI_Advisor_Settings::site_url(),
					$post_types ? $post_types : __( '(no post types selected)', 'wp-ai-advisor' )
				);

			default:
				return sprintf(
					/* translators: %s: site URL being crawled. */
					__( 'Crawl mode: fetches %s, follows its internal links and stores the text. Re-run after the site changes.', 'wp-ai-advisor' ),
					WP_AI_Advisor_Settings::site_url()
				);
		}
	}

	/**
	 * Intro copy for the API section.
	 *
	 * @return void
	 */
	public function render_api_section() {
		printf(
			'<p>%s</p>',
			esc_html__( 'Create a key at platform.openai.com. For production sites, define WP_AI_ADVISOR_API_KEY in wp-config.php instead of storing the key in the database.', 'wp-ai-advisor' )
		);
	}

	/**
	 * Field name attribute for a setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private function name( $key ) {
		return WP_AI_Advisor_Settings::OPTION_KEY . '[' . $key . ']';
	}

	/**
	 * Renders a text-ish input.
	 *
	 * @param string $key   Setting key.
	 * @param string $type  Input type.
	 * @param string $class CSS class.
	 * @param array  $attrs Extra attributes.
	 * @return void
	 */
	private function text_field( $key, $type = 'text', $class = 'regular-text', $attrs = array() ) {
		$extra = '';

		foreach ( $attrs as $attr => $value ) {
			$extra .= sprintf( ' %s="%s"', esc_attr( $attr ), esc_attr( $value ) );
		}

		printf(
			'<input type="%1$s" id="wp_ai_advisor_%2$s" name="%3$s" value="%4$s" class="%5$s"%6$s />',
			esc_attr( $type ),
			esc_attr( $key ),
			esc_attr( $this->name( $key ) ),
			esc_attr( (string) WP_AI_Advisor_Settings::get( $key ) ),
			esc_attr( $class ),
			$extra // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
		);
	}

	/**
	 * Renders a description paragraph.
	 *
	 * @param string $text Description text.
	 * @return void
	 */
	private function description( $text ) {
		printf( '<p class="description">%s</p>', esc_html( $text ) );
	}

	/**
	 * API key field.
	 *
	 * @return void
	 */
	public function render_api_key() {
		if ( WP_AI_Advisor_Settings::api_key_is_constant() ) {
			printf(
				'<p><code>WP_AI_ADVISOR_API_KEY</code> %s</p>',
				esc_html__( 'is defined in wp-config.php and takes precedence over this field.', 'wp-ai-advisor' )
			);

			return;
		}

		$has_key = '' !== WP_AI_Advisor_Settings::get( 'api_key', '' );

		printf(
			'<input type="password" id="wp_ai_advisor_api_key" name="%1$s" value="" class="regular-text" autocomplete="off" placeholder="%2$s" />',
			esc_attr( $this->name( 'api_key' ) ),
			esc_attr( $has_key ? __( 'A key is saved. Enter a new key to replace it.', 'wp-ai-advisor' ) : 'sk-…' )
		);

		$this->description( __( 'Leave blank to keep the saved key.', 'wp-ai-advisor' ) );
	}

	/**
	 * Chat model field.
	 *
	 * @return void
	 */
	public function render_model() {
		$this->text_field( 'model' );
		$this->description( __( 'Any chat model your account can use, for example gpt-4o-mini or gpt-4o.', 'wp-ai-advisor' ) );
	}

	/**
	 * Embedding model field.
	 *
	 * @return void
	 */
	public function render_embedding_model() {
		$this->text_field( 'embedding_model' );
		$this->description( __( 'Changing this invalidates the stored vectors — re-crawl afterwards.', 'wp-ai-advisor' ) );
	}

	/**
	 * Temperature field.
	 *
	 * @return void
	 */
	public function render_temperature() {
		$this->text_field( 'temperature', 'number', 'small-text', array( 'min' => '0', 'max' => '2', 'step' => '0.1' ) );
		$this->description( __( 'Lower is more literal. 0.2 suits factual answers.', 'wp-ai-advisor' ) );
	}

	/**
	 * Max tokens field.
	 *
	 * @return void
	 */
	public function render_max_tokens() {
		$this->text_field( 'max_tokens', 'number', 'small-text', array( 'min' => '128', 'max' => '4000', 'step' => '64' ) );
	}

	/**
	 * Knowledge source mode.
	 *
	 * @return void
	 */
	public function render_source_mode() {
		$current = WP_AI_Advisor_Settings::source_mode();

		$modes = array(
			'crawl' => array(
				__( 'Crawl the site over HTTP', 'wp-ai-advisor' ),
				__( 'Sees the site exactly as a visitor does, including rendered navigation. Needs the site to be reachable from the server.', 'wp-ai-advisor' ),
			),
			'local' => array(
				__( 'Read posts from this WordPress install', 'wp-ai-advisor' ),
				__( 'No HTTP requests and no page limit. Reaches unlinked pages a crawl would never find, but sees only post content, not theme-rendered menus.', 'wp-ai-advisor' ),
			),
			'both'  => array(
				__( 'Both', 'wp-ai-advisor' ),
				__( 'Crawl for navigation, local posts for completeness. Pages covered twice cost extra tokens to index and may return near-duplicate passages.', 'wp-ai-advisor' ),
			),
		);

		echo '<fieldset>';

		foreach ( $modes as $value => $mode ) {
			printf(
				'<label><input type="radio" name="%1$s" value="%2$s"%3$s /> <strong>%4$s</strong></label><p class="description" style="margin:0 0 .75rem 1.85rem;">%5$s</p>',
				esc_attr( $this->name( 'source_mode' ) ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $mode[0] ),
				esc_html( $mode[1] )
			);
		}

		echo '</fieldset>';
	}

	/**
	 * Post types indexed in local mode.
	 *
	 * @return void
	 */
	public function render_local_post_types() {
		$selected   = WP_AI_Advisor_Settings::local_post_types();
		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		echo '<fieldset>';

		foreach ( $post_types as $post_type ) {
			if ( 'attachment' === $post_type->name ) {
				continue;
			}

			printf(
				'<label><input type="checkbox" name="%1$s[]" value="%2$s"%3$s /> %4$s</label><br />',
				esc_attr( $this->name( 'local_post_types' ) ),
				esc_attr( $post_type->name ),
				checked( in_array( $post_type->name, $selected, true ), true, false ),
				esc_html( $post_type->labels->name )
			);
		}

		echo '</fieldset>';

		$this->description( __( 'Only used in local mode. Published posts only; drafts, private and password-protected posts are skipped.', 'wp-ai-advisor' ) );
	}

	/**
	 * Crawl base URL.
	 *
	 * @return void
	 */
	public function render_site_url() {
		$this->text_field( 'site_url', 'url', 'regular-text', array( 'placeholder' => home_url() ) );
		$this->description( __( 'Leave blank to crawl this site. Only used in crawl mode.', 'wp-ai-advisor' ) );
	}

	/**
	 * Crawl page budget.
	 *
	 * @return void
	 */
	public function render_crawl_max_pages() {
		$this->text_field( 'crawl_max_pages', 'number', 'small-text', array( 'min' => '1', 'max' => '2000' ) );
		$this->description( __( 'Caps how many pages a crawl fetches. Each page costs an embedding call.', 'wp-ai-advisor' ) );
	}

	/**
	 * Crawl exclusions.
	 *
	 * @return void
	 */
	public function render_crawl_exclude() {
		printf(
			'<textarea id="wp_ai_advisor_crawl_exclude" name="%1$s" rows="4" class="large-text code">%2$s</textarea>',
			esc_attr( $this->name( 'crawl_exclude' ) ),
			esc_textarea( WP_AI_Advisor_Settings::get( 'crawl_exclude' ) )
		);

		$this->description( __( 'One fragment per line. Any URL containing one is skipped.', 'wp-ai-advisor' ) );
	}

	/**
	 * Duplicate-avoidance checkbox.
	 *
	 * @return void
	 */
	public function render_skip_crawled() {
		printf(
			'<label><input type="checkbox" id="wp_ai_advisor_skip_crawled" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( $this->name( 'skip_crawled' ) ),
			checked( (bool) WP_AI_Advisor_Settings::get( 'skip_crawled' ), true, false ),
			esc_html__( 'Skip local posts whose URL the crawl already covered.', 'wp-ai-advisor' )
		);

		$this->description( __( 'Only affects both-modes. Without it the same page is indexed twice, which costs tokens and returns near-duplicate passages.', 'wp-ai-advisor' ) );
	}

	/**
	 * Theme-filter rendering checkbox.
	 *
	 * @return void
	 */
	public function render_render_filters() {
		printf(
			'<label><input type="checkbox" id="wp_ai_advisor_render_filters" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( $this->name( 'render_filters' ) ),
			checked( (bool) WP_AI_Advisor_Settings::get( 'render_filters' ), true, false ),
			esc_html__( 'Run local content through the_content when importing.', 'wp-ai-advisor' )
		);

		$this->description( __( 'Resolves shortcodes and page-builder markup the way the theme renders it. Turn it off if importing fails on a plugin or theme: blocks are still rendered, but shortcode output is dropped.', 'wp-ai-advisor' ) );
	}

	/**
	 * Strict-mode checkbox.
	 *
	 * @return void
	 */
	public function render_strict_mode() {
		printf(
			'<label><input type="checkbox" id="wp_ai_advisor_strict_mode" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( $this->name( 'strict_mode' ) ),
			checked( (bool) WP_AI_Advisor_Settings::get( 'strict_mode' ), true, false ),
			esc_html__( 'Only answer from indexed site content and uploaded documents.', 'wp-ai-advisor' )
		);

		$this->description( __( 'With this off, the model may also answer from general knowledge.', 'wp-ai-advisor' ) );
	}

	/**
	 * Refusal message field.
	 *
	 * @return void
	 */
	public function render_refusal_message() {
		printf(
			'<textarea id="wp_ai_advisor_refusal_message" name="%1$s" rows="3" class="large-text">%2$s</textarea>',
			esc_attr( $this->name( 'refusal_message' ) ),
			esc_textarea( WP_AI_Advisor_Settings::get( 'refusal_message' ) )
		);

		$this->description( __( 'Shown when a question is outside the knowledge base. Leave blank for the default.', 'wp-ai-advisor' ) );
	}

	/**
	 * System prompt field.
	 *
	 * @return void
	 */
	public function render_system_prompt() {
		printf(
			'<textarea id="wp_ai_advisor_system_prompt" name="%1$s" rows="5" class="large-text code">%2$s</textarea>',
			esc_attr( $this->name( 'system_prompt' ) ),
			esc_textarea( WP_AI_Advisor_Settings::get( 'system_prompt' ) )
		);

		$this->description( __( 'Sets tone and role. Grounding rules are appended automatically. Leave blank for the default.', 'wp-ai-advisor' ) );
	}

	/**
	 * Top-k field.
	 *
	 * @return void
	 */
	public function render_top_k() {
		$this->text_field( 'top_k', 'number', 'small-text', array( 'min' => '1', 'max' => '20' ) );
		$this->description( __( 'How many matching passages are sent with each question.', 'wp-ai-advisor' ) );
	}

	/**
	 * Minimum score field.
	 *
	 * @return void
	 */
	public function render_min_score() {
		$this->text_field( 'min_score', 'number', 'small-text', array( 'min' => '0', 'max' => '1', 'step' => '0.05' ) );
		$this->description( __( 'Passages scoring below this are ignored. Raise it if answers drift, lower it if the advisor refuses too often.', 'wp-ai-advisor' ) );
	}

	/**
	 * Calculator toggle.
	 *
	 * @return void
	 */
	public function render_enable_calculator() {
		printf(
			'<label><input type="checkbox" id="wp_ai_advisor_enable_calculator" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( $this->name( 'enable_calculator' ) ),
			checked( (bool) WP_AI_Advisor_Settings::get( 'enable_calculator' ), true, false ),
			esc_html__( 'Let the advisor answer "what would this cost" questions with a worked estimate.', 'wp-ai-advisor' )
		);

		$this->description( __( 'Prices still have to come from your indexed content — the advisor may not invent one. Arithmetic is done by the plugin, not guessed by the model.', 'wp-ai-advisor' ) );
	}

	/**
	 * Estimate assumptions.
	 *
	 * @return void
	 */
	public function render_assumptions() {
		printf(
			'<textarea id="wp_ai_advisor_assumptions" name="%1$s" rows="5" class="large-text">%2$s</textarea>',
			esc_attr( $this->name( 'assumptions' ) ),
			esc_textarea( WP_AI_Advisor_Settings::get( 'assumptions' ) )
		);

		$this->description( __( 'One per line. Figures the advisor may use when your content cannot supply them, such as cups per person per day. Every answer must say which numbers were assumptions. Leave blank for the defaults.', 'wp-ai-advisor' ) );
	}

	/**
	 * Reply language selector.
	 *
	 * @return void
	 */
	public function render_reply_language() {
		$current = (string) WP_AI_Advisor_Settings::get( 'reply_language', 'auto' );

		$options = array(
			'auto' => __( 'Match the visitor (recommended)', 'wp-ai-advisor' ),
			'page' => __( 'Always use the language of the page', 'wp-ai-advisor' ),
		);

		foreach ( WP_AI_Advisor_Language::names() as $code => $name ) {
			/* translators: 1: language name, 2: language code. */
			$options[ $code ] = sprintf( __( 'Always %1$s (%2$s)', 'wp-ai-advisor' ), $name, $code );
		}

		echo '<select id="wp_ai_advisor_reply_language" name="' . esc_attr( $this->name( 'reply_language' ) ) . '">';

		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';

		$this->description(
			sprintf(
				/* translators: %s: language name of the WordPress install. */
				__( 'This site runs in %s. On a translated site the widget reports the language of the page it sits on, which is what "match the visitor" falls back to.', 'wp-ai-advisor' ),
				WP_AI_Advisor_Language::name( WP_AI_Advisor_Language::site() )
			)
		);
	}

	/**
	 * Admin-only checkbox.
	 *
	 * @return void
	 */
	public function render_admin_only() {
		printf(
			'<label><input type="checkbox" id="wp_ai_advisor_admin_only" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( $this->name( 'admin_only' ) ),
			checked( (bool) WP_AI_Advisor_Settings::get( 'admin_only' ), true, false ),
			esc_html__( 'Show the widget to administrators only.', 'wp-ai-advisor' )
		);

		$this->description( __( 'Use this to test on a live site: visitors see nothing and the endpoint refuses them.', 'wp-ai-advisor' ) );
	}

	/**
	 * Rate limit field.
	 *
	 * @return void
	 */
	public function render_rate_limit() {
		$this->text_field( 'rate_limit', 'number', 'small-text', array( 'min' => '0', 'max' => '500' ) );
		$this->description( __( 'Per visitor, per hour. 0 disables the limit. Administrators are never limited.', 'wp-ai-advisor' ) );
	}

	/**
	 * Eyebrow field.
	 *
	 * @return void
	 */
	public function render_eyebrow() {
		$this->text_field( 'eyebrow' );
		$this->description( __( 'Small label above the heading, e.g. "Ask us".', 'wp-ai-advisor' ) );
	}

	/**
	 * Heading field.
	 *
	 * @return void
	 */
	public function render_heading() {
		$this->text_field( 'heading', 'text', 'large-text' );
	}

	/**
	 * Placeholder field.
	 *
	 * @return void
	 */
	public function render_placeholder() {
		$this->text_field( 'placeholder' );
	}

	/**
	 * Suggested questions field.
	 *
	 * @return void
	 */
	public function render_suggestions() {
		printf(
			'<textarea id="wp_ai_advisor_suggestions" name="%1$s" rows="5" class="large-text">%2$s</textarea>',
			esc_attr( $this->name( 'suggestions' ) ),
			esc_textarea( implode( "\n", (array) WP_AI_Advisor_Settings::get( 'suggestions' ) ) )
		);

		$this->description( __( 'One per line, up to six. Shown as buttons on the closed card.', 'wp-ai-advisor' ) );
	}

	/**
	 * Call-to-action fields.
	 *
	 * @return void
	 */
	public function render_cta() {
		$this->text_field( 'cta_label' );
		echo ' ';
		printf(
			'<input type="url" id="wp_ai_advisor_cta_url" name="%1$s" value="%2$s" class="regular-text" placeholder="https://" />',
			esc_attr( $this->name( 'cta_url' ) ),
			esc_attr( (string) WP_AI_Advisor_Settings::get( 'cta_url' ) )
		);

		$this->description( __( 'Label and URL for the highlighted button under each answer, e.g. "Book a table". Leave blank to hide it.', 'wp-ai-advisor' ) );
	}

	/**
	 * Accent colour field.
	 *
	 * @return void
	 */
	public function render_accent() {
		$this->text_field( 'accent', 'color', 'small-text' );
	}
}
