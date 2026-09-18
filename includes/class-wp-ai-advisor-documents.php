<?php
/**
 * Additional document uploads and their text extraction.
 *
 * @package WP_AI_Advisor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Accepts uploads into the media library and turns them into indexable text.
 */
class WP_AI_Advisor_Documents {

	/**
	 * File extensions the extractor can read.
	 *
	 * @return string[]
	 */
	public static function allowed_extensions() {
		/**
		 * Filters the uploadable document extensions.
		 *
		 * @param string[] $extensions Allowed extensions.
		 */
		return (array) apply_filters(
			'wp_ai_advisor_allowed_extensions',
			array( 'txt', 'md', 'markdown', 'csv', 'json', 'html', 'htm', 'docx', 'pdf' )
		);
	}

	/**
	 * MIME types the allowed extensions map to.
	 *
	 * WordPress rejects uploads whose extension is not in its own allow-list, and
	 * plain-text formats such as .md and .json are not there by default.
	 *
	 * @return array Extension pattern => MIME type.
	 */
	private function mime_map() {
		return array(
			'txt|md|markdown' => 'text/plain',
			'csv'             => 'text/csv',
			'json'            => 'application/json',
			'html|htm'        => 'text/html',
			'docx'            => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'pdf'             => 'application/pdf',
		);
	}

	/**
	 * Handles one uploaded file: stores it and queues its text for indexing.
	 *
	 * @param array  $file Entry from $_FILES, used for validation.
	 * @param string $key  The $_FILES key the file arrived under.
	 * @return array|WP_Error {id, title, characters}
	 */
	public function handle_upload( array $file, $key = 'file' ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, self::allowed_extensions(), true ) ) {
			return new WP_Error(
				'wp_ai_advisor_bad_type',
				sprintf(
					/* translators: %s: comma-separated list of file extensions. */
					__( 'Unsupported file type. Allowed: %s.', 'wp-ai-advisor' ),
					implode( ', ', self::allowed_extensions() )
				)
			);
		}

		$this->allow_types();
		$attachment_id = media_handle_upload( $key, 0 );
		$this->restore_types();

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$path = get_attached_file( $attachment_id );
		$text = $this->extract_text( $path, $extension );

		if ( is_wp_error( $text ) ) {
			wp_delete_attachment( $attachment_id, true );

			return $text;
		}

		$title     = sanitize_text_field( pathinfo( $file['name'], PATHINFO_FILENAME ) );
		$source_id = WP_AI_Advisor_Store::put_document( $title, $text, (string) $attachment_id );

		update_post_meta( $attachment_id, '_wp_ai_advisor_source_id', $source_id );

		return array(
			'id'         => $source_id,
			'title'      => $title,
			'characters' => mb_strlen( $text ),
		);
	}

	/**
	 * Temporarily widens the upload allow-list for our document types.
	 *
	 * @return void
	 */
	private function allow_types() {
		add_filter( 'upload_mimes', array( $this, 'filter_upload_mimes' ) );
		add_filter( 'wp_check_filetype_and_ext', array( $this, 'filter_filetype' ), 10, 4 );
	}

	/**
	 * Removes the temporary upload filters.
	 *
	 * @return void
	 */
	private function restore_types() {
		remove_filter( 'upload_mimes', array( $this, 'filter_upload_mimes' ) );
		remove_filter( 'wp_check_filetype_and_ext', array( $this, 'filter_filetype' ), 10 );
	}

	/**
	 * Adds the document MIME types to the upload allow-list.
	 *
	 * @param array $mimes Allowed MIME types.
	 * @return array
	 */
	public function filter_upload_mimes( $mimes ) {
		return array_merge( (array) $mimes, $this->mime_map() );
	}

	/**
	 * Accepts our document types when WordPress cannot sniff them from content.
	 *
	 * Plain-text formats have no distinctive magic bytes, so the real check is the
	 * extension allow-list applied before the upload starts.
	 *
	 * @param array  $checked  Values for ext, type and proper_filename.
	 * @param string $file     Full path to the file.
	 * @param string $filename The name of the file.
	 * @param array  $mimes    Allowed mime types.
	 * @return array
	 */
	public function filter_filetype( $checked, $file, $filename, $mimes ) {
		if ( ! empty( $checked['ext'] ) && ! empty( $checked['type'] ) ) {
			return $checked;
		}

		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		foreach ( $this->mime_map() as $pattern => $mime ) {
			if ( in_array( $extension, explode( '|', $pattern ), true ) ) {
				$checked['ext']  = $extension;
				$checked['type'] = $mime;

				break;
			}
		}

		return $checked;
	}

	/**
	 * Extracts plain text from a file.
	 *
	 * @param string $path      Absolute file path.
	 * @param string $extension Lowercase extension.
	 * @return string|WP_Error
	 */
	public function extract_text( $path, $extension ) {
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'wp_ai_advisor_missing_file', __( 'The uploaded file could not be read.', 'wp-ai-advisor' ) );
		}

		switch ( $extension ) {
			case 'docx':
				$text = $this->from_docx( $path );
				break;

			case 'pdf':
				$text = $this->from_pdf( $path );
				break;

			case 'html':
			case 'htm':
				$text = $this->from_html( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				break;

			default:
				$text = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				break;
		}

		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$text = $this->tidy( $text );

		if ( '' === $text ) {
			return new WP_Error(
				'wp_ai_advisor_no_text',
				__( 'No readable text could be extracted from this file. If it is a scanned PDF, run OCR on it first or paste the text into a .txt file.', 'wp-ai-advisor' )
			);
		}

		return $text;
	}

	/**
	 * Reads the main document body out of a .docx archive.
	 *
	 * @param string $path File path.
	 * @return string|WP_Error
	 */
	private function from_docx( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error(
				'wp_ai_advisor_no_zip',
				__( 'Reading .docx files needs the PHP zip extension, which is not enabled on this server.', 'wp-ai-advisor' )
			);
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'wp_ai_advisor_bad_docx', __( 'The .docx file could not be opened.', 'wp-ai-advisor' ) );
		}

		$xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();

		if ( false === $xml ) {
			return new WP_Error( 'wp_ai_advisor_bad_docx', __( 'The .docx file has no readable document body.', 'wp-ai-advisor' ) );
		}

		// Paragraph and line breaks become newlines before tags are stripped.
		$xml = preg_replace( '#</w:p>#', "\n", $xml );
		$xml = preg_replace( '#<w:br[^>]*/?>#', "\n", $xml );

		return html_entity_decode( wp_strip_all_tags( $xml ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Best-effort text extraction from a PDF.
	 *
	 * Handles text-based PDFs with Flate-compressed or plain content streams.
	 * Scanned PDFs hold images, not text, and will legitimately return nothing.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private function from_pdf( $path ) {
		$raw = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( '' === $raw ) {
			return '';
		}

		$streams = array();

		if ( preg_match_all( '#stream\r?\n(.*?)endstream#s', $raw, $matches ) ) {
			foreach ( $matches[1] as $stream ) {
				$inflated = @gzuncompress( $stream ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

				if ( false === $inflated ) {
					$inflated = @gzinflate( $stream ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}

				$candidate = false === $inflated ? $stream : $inflated;

				if ( false !== strpos( $candidate, 'Tj' ) || false !== strpos( $candidate, 'TJ' ) ) {
					$streams[] = $candidate;
				}
			}
		}

		$out = array();

		foreach ( $streams as $stream ) {
			// Text-showing operators carry their strings in parentheses.
			if ( ! preg_match_all( '#\(((?:\\\\.|[^\\\\()])*)\)#s', $stream, $found ) ) {
				continue;
			}

			$line = '';

			foreach ( $found[1] as $piece ) {
				$line .= str_replace(
					array( '\\(', '\\)', '\\\\', '\\n', '\\r', '\\t' ),
					array( '(', ')', '\\', "\n", "\r", "\t" ),
					$piece
				);
			}

			$line = trim( $line );

			if ( '' !== $line ) {
				$out[] = $line;
			}
		}

		return implode( "\n", $out );
	}

	/**
	 * Flattens an HTML document to text.
	 *
	 * @param string $html HTML source.
	 * @return string
	 */
	private function from_html( $html ) {
		$html = preg_replace( '#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html = preg_replace( '#</(p|div|li|h[1-6]|tr|br)>#i', "\n", $html );

		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Normalises whitespace and drops control characters.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function tidy( $text ) {
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', ' ', (string) $text );
		$text = preg_replace( '/\r\n?/u', "\n", $text );
		$text = preg_replace( '/[ \t]+/u', ' ', $text );
		// Drop the padding either side of a line break before collapsing blank lines.
		$text = preg_replace( '/[ \t]*\n[ \t]*/u', "\n", $text );
		$text = preg_replace( '/\n{3,}/u', "\n\n", $text );

		return trim( (string) $text );
	}
}
