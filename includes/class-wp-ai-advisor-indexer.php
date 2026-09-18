<?php
/**
 * Turns fetched sources into embedded chunks.
 *
 * @package WP_AI_Advisor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Splits source text into overlapping chunks and embeds them.
 */
class WP_AI_Advisor_Indexer {

	const CHUNK_CHARS   = 1400;
	const CHUNK_OVERLAP = 200;
	const MAX_CHUNKS    = 80;

	/**
	 * Embeds the next fetched source.
	 *
	 * @return array|null Progress info, or null when nothing is waiting.
	 */
	public function index_next() {
		$rows = WP_AI_Advisor_Store::next_by_status( WP_AI_Advisor_Store::STATUS_FETCHED, 1 );

		if ( empty( $rows ) ) {
			return null;
		}

		$source = $rows[0];
		$chunks = $this->split( $source['content'], $source['title'] );

		if ( empty( $chunks ) ) {
			WP_AI_Advisor_Store::mark_error( $source['id'], __( 'Nothing to index.', 'wp-ai-advisor' ) );

			return array(
				'title'  => $source['title'],
				'status' => 'skipped',
			);
		}

		$client  = new WP_AI_Advisor_OpenAI_Client();
		$vectors = $client->embed( $chunks );

		if ( is_wp_error( $vectors ) ) {
			WP_AI_Advisor_Store::mark_error( $source['id'], $vectors->get_error_message() );

			return array(
				'title'  => $source['title'],
				'status' => 'error',
				'error'  => $vectors->get_error_message(),
			);
		}

		$prepared = array();

		foreach ( $chunks as $index => $chunk ) {
			if ( empty( $vectors[ $index ] ) ) {
				continue;
			}

			$prepared[] = array(
				'content'   => $chunk,
				'embedding' => $vectors[ $index ],
			);
		}

		WP_AI_Advisor_Store::save_chunks( $source['id'], $prepared );
		WP_AI_Advisor_Store::mark_indexed( $source['id'] );

		return array(
			'title'  => $source['title'],
			'status' => 'indexed',
			'chunks' => count( $prepared ),
		);
	}

	/**
	 * Splits text into overlapping chunks on paragraph boundaries.
	 *
	 * Each chunk is prefixed with the source title so an isolated chunk still
	 * says what it is about.
	 *
	 * @param string $text  Source text.
	 * @param string $title Source title.
	 * @return string[]
	 */
	public function split( $text, $title = '' ) {
		$text = trim( preg_replace( '/\n{3,}/u', "\n\n", (string) $text ) );

		if ( '' === $text ) {
			return array();
		}

		$paragraphs = preg_split( '/\n\s*\n/u', $text );
		$chunks     = array();
		$current    = '';

		foreach ( $paragraphs as $paragraph ) {
			$paragraph = trim( $paragraph );

			if ( '' === $paragraph ) {
				continue;
			}

			// A single oversized paragraph is cut on word boundaries.
			if ( mb_strlen( $paragraph ) > self::CHUNK_CHARS ) {
				if ( '' !== $current ) {
					$chunks[] = $current;
					$current  = '';
				}

				foreach ( $this->split_long( $paragraph ) as $piece ) {
					$chunks[] = $piece;
				}

				continue;
			}

			if ( '' !== $current && mb_strlen( $current ) + mb_strlen( $paragraph ) + 2 > self::CHUNK_CHARS ) {
				$chunks[] = $current;
				$current  = $this->tail( $current );
			}

			$current = '' === $current ? $paragraph : $current . "\n\n" . $paragraph;
		}

		if ( '' !== trim( $current ) ) {
			$chunks[] = $current;
		}

		$chunks = array_slice( $chunks, 0, self::MAX_CHUNKS );

		if ( '' === $title ) {
			return $chunks;
		}

		return array_map(
			static function ( $chunk ) use ( $title ) {
				return $title . "\n\n" . $chunk;
			},
			$chunks
		);
	}

	/**
	 * Splits an oversized paragraph on word boundaries.
	 *
	 * @param string $paragraph Paragraph text.
	 * @return string[]
	 */
	private function split_long( $paragraph ) {
		$words  = preg_split( '/\s+/u', $paragraph );
		$pieces = array();
		$buffer = '';

		foreach ( $words as $word ) {
			// A single unbroken run (a long URL, a base64 blob) has no word
			// boundary to cut on, so it is cut on character count instead.
			foreach ( $this->hard_split( $word ) as $part ) {
				if ( '' !== $buffer && mb_strlen( $buffer ) + mb_strlen( $part ) + 1 > self::CHUNK_CHARS ) {
					$pieces[] = $buffer;
					$buffer   = $this->tail( $buffer );
				}

				$buffer = '' === $buffer ? $part : $buffer . ' ' . $part;
			}
		}

		if ( '' !== trim( $buffer ) ) {
			$pieces[] = $buffer;
		}

		return $pieces;
	}

	/**
	 * Cuts a single oversized token into chunk-sized pieces.
	 *
	 * @param string $word One whitespace-delimited token.
	 * @return string[]
	 */
	private function hard_split( $word ) {
		if ( mb_strlen( $word ) <= self::CHUNK_CHARS ) {
			return array( $word );
		}

		$pieces = array();
		$length = mb_strlen( $word );

		for ( $offset = 0; $offset < $length; $offset += self::CHUNK_CHARS ) {
			$pieces[] = mb_substr( $word, $offset, self::CHUNK_CHARS );
		}

		return $pieces;
	}

	/**
	 * The trailing slice of a chunk, carried into the next one as overlap.
	 *
	 * @param string $chunk Chunk text.
	 * @return string
	 */
	private function tail( $chunk ) {
		if ( mb_strlen( $chunk ) <= self::CHUNK_OVERLAP ) {
			return '';
		}

		return ltrim( mb_substr( $chunk, -self::CHUNK_OVERLAP ) );
	}
}
