<?php
/**
 * Knowledge-base storage: sources, chunks and vector search.
 *
 * @package WP_AI_Advisor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the two custom tables and the similarity search over them.
 */
class WP_AI_Advisor_Store {

	const DB_VERSION       = '3';
	const DB_VERSION_KEY   = 'wp_ai_advisor_db_version';
	const STATUS_PENDING   = 'pending';
	const STATUS_FETCHED   = 'fetched';
	const STATUS_INDEXED   = 'indexed';
	const STATUS_ERROR     = 'error';
	const TYPE_PAGE        = 'page';
	const TYPE_DOCUMENT    = 'document';
	const TYPE_LOCAL       = 'local';

	/**
	 * Sources table name.
	 *
	 * @return string
	 */
	public static function sources_table() {
		global $wpdb;

		return $wpdb->prefix . 'aiadv_sources';
	}

	/**
	 * Chunks table name.
	 *
	 * @return string
	 */
	public static function chunks_table() {
		global $wpdb;

		return $wpdb->prefix . 'aiadv_chunks';
	}

	/**
	 * Creates or upgrades the tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$sources  = self::sources_table();
		$chunks   = self::chunks_table();

		$sql = array();

		$sql[] = "CREATE TABLE {$sources} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL DEFAULT 'page',
			url varchar(500) NOT NULL DEFAULT '',
			url_hash char(32) NOT NULL DEFAULT '',
			title text NOT NULL,
			content longtext NOT NULL,
			links longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			message text NOT NULL,
			content_hash char(32) NOT NULL DEFAULT '',
			depth tinyint(3) unsigned NOT NULL DEFAULT 0,
			ref bigint(20) unsigned NOT NULL DEFAULT 0,
			language varchar(10) NOT NULL DEFAULT '',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY status (status),
			KEY type (type),
			KEY language (language)
		) {$charset};";

		$sql[] = "CREATE TABLE {$chunks} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			seq smallint(5) unsigned NOT NULL DEFAULT 0,
			content longtext NOT NULL,
			embedding longblob NOT NULL,
			PRIMARY KEY  (id),
			KEY source_id (source_id)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( self::DB_VERSION_KEY, self::DB_VERSION );
	}

	/**
	 * Runs install() when the stored schema version is behind.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( get_option( self::DB_VERSION_KEY ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Drops both tables.
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;

		$sources = self::sources_table();
		$chunks  = self::chunks_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$chunks}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$sources}" );
		// phpcs:enable

		delete_option( self::DB_VERSION_KEY );
	}

	/**
	 * Inserts a URL into the crawl frontier if it is not already known.
	 *
	 * @param string $url   Absolute URL.
	 * @param int    $depth Crawl depth.
	 * @return int Source ID, or 0 when the URL was already queued.
	 */
	public static function queue_url( $url, $depth = 0 ) {
		global $wpdb;

		$url  = self::normalize_url( $url );
		$hash = md5( $url );

		$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::sources_table() . ' WHERE url_hash = %s', $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		if ( $existing ) {
			return 0;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::sources_table(),
			array(
				'type'       => self::TYPE_PAGE,
				'url'        => $url,
				'url_hash'   => $hash,
				'title'      => '',
				'content'    => '',
				'links'      => '',
				'status'     => self::STATUS_PENDING,
				'message'    => '',
				'depth'      => (int) $depth,
				'updated_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Queues a local post for import.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     Permalink.
	 * @param string $title   Post title.
	 * @return int Source ID, or 0 when the post is already queued.
	 */
	public static function queue_post( $post_id, $url, $title ) {
		global $wpdb;

		$hash = md5( 'local:' . (int) $post_id );

		$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::sources_table() . ' WHERE url_hash = %s', $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		if ( $existing ) {
			return 0;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::sources_table(),
			array(
				'type'       => self::TYPE_LOCAL,
				'url'        => self::normalize_url( $url ),
				'url_hash'   => $hash,
				'title'      => $title,
				'content'    => '',
				'links'      => '',
				'status'     => self::STATUS_PENDING,
				'message'    => '',
				'depth'      => 0,
				'ref'        => (int) $post_id,
				'updated_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Counts sources of one type.
	 *
	 * @param string $type Source type.
	 * @return int
	 */
	public static function count_by_type( $type ) {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::sources_table() . ' WHERE type = %s', $type ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Strips fragments, query strings and trailing slashes so URLs dedupe reliably.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	public static function normalize_url( $url ) {
		$url = trim( (string) $url );

		foreach ( array( '#', '?' ) as $separator ) {
			$position = strpos( $url, $separator );

			if ( false !== $position ) {
				$url = substr( $url, 0, $position );
			}
		}

		return untrailingslashit( $url );
	}

	/**
	 * Stores or replaces a document source.
	 *
	 * @param string $title   Document title.
	 * @param string $content Extracted plain text.
	 * @param string $key      Stable identifier, e.g. the attachment URL.
	 * @param string $language Language code, or '' when unknown.
	 * @return int Source ID.
	 */
	public static function put_document( $title, $content, $key, $language = '' ) {
		global $wpdb;

		$hash = md5( 'doc:' . $key );
		$row  = array(
			'type'         => self::TYPE_DOCUMENT,
			'url'          => $key,
			'url_hash'     => $hash,
			'title'        => $title,
			'content'      => $content,
			'links'        => '',
			'status'       => self::STATUS_FETCHED,
			'message'      => '',
			'content_hash' => md5( $content ),
			'depth'        => 0,
			'language'     => $language,
			'updated_at'   => current_time( 'mysql' ),
		);

		$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::sources_table() . ' WHERE url_hash = %s', $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		if ( $existing ) {
			$wpdb->update( self::sources_table(), $row, array( 'id' => $existing ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::delete_chunks( $existing );

			return $existing;
		}

		$wpdb->insert( self::sources_table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return (int) $wpdb->insert_id;
	}

	/**
	 * Saves fetched page content against a queued source.
	 *
	 * @param int   $source_id Source ID.
	 * @param array $data      Fields: title, content, links, language.
	 * @return void
	 */
	public static function save_fetched( $source_id, array $data ) {
		global $wpdb;

		$content = isset( $data['content'] ) ? $data['content'] : '';

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::sources_table(),
			array(
				'title'        => isset( $data['title'] ) ? $data['title'] : '',
				'content'      => $content,
				'links'        => wp_json_encode( isset( $data['links'] ) ? $data['links'] : array() ),
				'status'       => self::STATUS_FETCHED,
				'message'      => '',
				'content_hash' => md5( $content ),
				'language'     => isset( $data['language'] ) ? $data['language'] : '',
				'updated_at'   => current_time( 'mysql' ),
			),
			array( 'id' => (int) $source_id )
		);
	}

	/**
	 * Marks a source as failed.
	 *
	 * @param int    $source_id Source ID.
	 * @param string $message   Failure reason.
	 * @return void
	 */
	public static function mark_error( $source_id, $message ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::sources_table(),
			array(
				'status'     => self::STATUS_ERROR,
				'message'    => mb_substr( (string) $message, 0, 500 ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $source_id )
		);
	}

	/**
	 * Marks a source as fully indexed.
	 *
	 * @param int $source_id Source ID.
	 * @return void
	 */
	public static function mark_indexed( $source_id ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::sources_table(),
			array(
				'status'     => self::STATUS_INDEXED,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $source_id )
		);
	}

	/**
	 * Returns the next sources with a given status.
	 *
	 * @param string $status Status to match.
	 * @param int    $limit  Maximum rows.
	 * @param string $type   Optional type filter.
	 * @return array[]
	 */
	public static function next_by_status( $status, $limit = 1, $type = '' ) {
		global $wpdb;

		$table = self::sources_table();

		if ( $type ) {
			return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = %s AND type = %s ORDER BY depth ASC, id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$status,
					$type,
					(int) $limit
				),
				ARRAY_A
			);
		}

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY depth ASC, id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$status,
				(int) $limit
			),
			ARRAY_A
		);
	}

	/**
	 * Fetches one source row.
	 *
	 * @param int $source_id Source ID.
	 * @return array|null
	 */
	public static function get_source( $source_id ) {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM ' . self::sources_table() . ' WHERE id = %d', (int) $source_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Lists sources for the admin table.
	 *
	 * @param string $type Optional type filter.
	 * @return array[]
	 */
	public static function list_sources( $type = '' ) {
		global $wpdb;

		$table = self::sources_table();

		if ( $type ) {
			return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "SELECT id, type, url, title, status, message, language, updated_at FROM {$table} WHERE type = %s ORDER BY id ASC", $type ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
		}

		return (array) $wpdb->get_results( "SELECT id, type, url, title, status, message, language, updated_at FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Deletes a source and its chunks.
	 *
	 * @param int $source_id Source ID.
	 * @return void
	 */
	public static function delete_source( $source_id ) {
		global $wpdb;

		self::delete_chunks( $source_id );

		$wpdb->delete( self::sources_table(), array( 'id' => (int) $source_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Deletes every chunk belonging to a source.
	 *
	 * @param int $source_id Source ID.
	 * @return void
	 */
	public static function delete_chunks( $source_id ) {
		global $wpdb;

		$wpdb->delete( self::chunks_table(), array( 'source_id' => (int) $source_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Empties both tables.
	 *
	 * @param string $type Optional: only clear sources of this type.
	 * @return void
	 */
	public static function clear( $type = '' ) {
		global $wpdb;

		$sources = self::sources_table();
		$chunks  = self::chunks_table();

		if ( ! $type ) {
			$wpdb->query( "TRUNCATE TABLE {$chunks}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "TRUNCATE TABLE {$sources}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			return;
		}

		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$sources} WHERE type = %s", $type ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $ids as $id ) {
			self::delete_source( (int) $id );
		}
	}

	/**
	 * Stores the embedded chunks of a source, replacing any existing ones.
	 *
	 * Vectors are normalised on write so search is a plain dot product.
	 *
	 * @param int   $source_id Source ID.
	 * @param array $chunks    List of {content, embedding}.
	 * @return void
	 */
	public static function save_chunks( $source_id, array $chunks ) {
		global $wpdb;

		self::delete_chunks( $source_id );

		$seq = 0;

		foreach ( $chunks as $chunk ) {
			if ( empty( $chunk['content'] ) || empty( $chunk['embedding'] ) ) {
				continue;
			}

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::chunks_table(),
				array(
					'source_id' => (int) $source_id,
					'seq'       => $seq,
					'content'   => $chunk['content'],
					'embedding' => self::pack_vector( self::normalize_vector( $chunk['embedding'] ) ),
				),
				array( '%d', '%d', '%s', '%s' )
			);

			$seq++;
		}
	}

	/**
	 * Returns the nearest chunks to a query vector.
	 *
	 * The table is small enough (a site, not a corpus) that scanning it in PHP is
	 * cheaper than adding a vector-database dependency.
	 *
	 * When a language is supplied and the index holds passages in it, only those
	 * are considered — answering a Norwegian question out of English pages reads
	 * as a bug even when the facts are right. If that language has nothing above
	 * the threshold, every language is reconsidered rather than refusing.
	 *
	 * @param float[] $query     Query embedding.
	 * @param int     $top_k     How many chunks to return.
	 * @param float   $min_score Minimum cosine similarity.
	 * @param string  $language  Preferred language code, or '' for no preference.
	 * @return array[] Each: score, content, title, url, language.
	 */
	public static function search( array $query, $top_k = 6, $min_score = 0.2, $language = '' ) {
		global $wpdb;

		$query = self::normalize_vector( $query );

		if ( empty( $query ) ) {
			return array();
		}

		$sources = self::sources_table();
		$chunks  = self::chunks_table();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT c.content, c.embedding, s.title, s.url, s.links, s.language
			 FROM {$chunks} c
			 INNER JOIN {$sources} s ON s.id = c.source_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$scored = array();

		foreach ( $rows as $row ) {
			$vector = self::unpack_vector( $row['embedding'] );

			if ( count( $vector ) !== count( $query ) ) {
				continue;
			}

			$score = 0.0;

			foreach ( $query as $index => $value ) {
				$score += $value * $vector[ $index ];
			}

			if ( $score < $min_score ) {
				continue;
			}

			$links = json_decode( (string) $row['links'], true );

			$scored[] = array(
				'score'    => $score,
				'content'  => $row['content'],
				'title'    => $row['title'],
				'url'      => $row['url'],
				'language' => isset( $row['language'] ) ? $row['language'] : '',
				'links'    => is_array( $links ) ? $links : array(),
			);
		}

		if ( $language ) {
			$preferred = array_values(
				array_filter(
					$scored,
					static function ( $chunk ) use ( $language ) {
						return $chunk['language'] === $language || '' === $chunk['language'];
					}
				)
			);

			if ( ! empty( $preferred ) ) {
				$scored = $preferred;
			}
		}

		usort(
			$scored,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $scored, 0, max( 1, (int) $top_k ) );
	}

	/**
	 * Counts rows for the admin dashboard.
	 *
	 * @return array
	 */
	public static function stats() {
		global $wpdb;

		$sources = self::sources_table();
		$chunks  = self::chunks_table();

		$by_status = (array) $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$sources} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$stats = array(
			'pending' => 0,
			'fetched' => 0,
			'indexed' => 0,
			'error'   => 0,
			'chunks'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$chunks}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		foreach ( $by_status as $row ) {
			if ( isset( $stats[ $row['status'] ] ) ) {
				$stats[ $row['status'] ] = (int) $row['total'];
			}
		}

		$stats['total'] = $stats['pending'] + $stats['fetched'] + $stats['indexed'] + $stats['error'];

		return $stats;
	}

	/**
	 * The distinct language codes present in the index.
	 *
	 * @return string[]
	 */
	public static function languages() {
		global $wpdb;

		$table = self::sources_table();

		$codes = (array) $wpdb->get_col( "SELECT DISTINCT language FROM {$table} WHERE language <> '' ORDER BY language ASC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_values( array_filter( $codes ) );
	}

	/**
	 * Scales a vector to unit length.
	 *
	 * @param array $vector Raw vector.
	 * @return float[]
	 */
	public static function normalize_vector( $vector ) {
		$vector = array_map( 'floatval', (array) $vector );
		$sum    = 0.0;

		foreach ( $vector as $value ) {
			$sum += $value * $value;
		}

		if ( $sum <= 0 ) {
			return array();
		}

		$magnitude = sqrt( $sum );

		foreach ( $vector as $index => $value ) {
			$vector[ $index ] = $value / $magnitude;
		}

		return $vector;
	}

	/**
	 * Packs a vector into a binary blob (4 bytes per dimension).
	 *
	 * @param float[] $vector Unit vector.
	 * @return string
	 */
	private static function pack_vector( array $vector ) {
		return pack( 'g*', ...$vector );
	}

	/**
	 * Unpacks a stored vector.
	 *
	 * @param string $blob Packed vector.
	 * @return float[]
	 */
	private static function unpack_vector( $blob ) {
		if ( '' === $blob || null === $blob ) {
			return array();
		}

		$values = unpack( 'g*', $blob );

		return false === $values ? array() : array_values( $values );
	}
}
