<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_Content_Extractor' ) ) :

	class WPSC_Content_Extractor {

		/**
		 * Fetches the content from a URL and extracts the main text.
		 *
		 * @param string $url The URL to fetch and extract content from.
		 * @return string The extracted main content, or an empty string on failure.
		 */
		public static function fetch_and_extract_content( $url ) {

			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 60,
					'redirection' => 5,
					'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
				)
			);

			if ( is_wp_error( $response ) ) {
				return '';
			}

			if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return '';
			}

			$html = wp_remote_retrieve_body( $response );

			if ( empty( $html ) ) {
				return '';
			}

			return self::extract_from_html( $html );
		}

		/**
		 * Extracts the main content from raw HTML.
		 *
		 * @param string $html The raw HTML to extract content from.
		 * @return string The extracted main content, or an empty string on failure.
		 */
		private static function extract_from_html( $html ) {

			libxml_use_internal_errors( true );

			$dom = new DOMDocument();

			$html = self::prepare_html( $html );

			if ( ! $dom->loadHTML( $html ) ) {
				return '';
			}

			$xpath = new DOMXPath( $dom );

			self::remove_noise( $dom, $xpath );

			$root = self::find_best_container( $dom, $xpath );

			if ( ! $root ) {
				$body = $dom->getElementsByTagName( 'body' );
				$root = $body->length ? $body->item( 0 ) : null;
			}

			if ( ! $root ) {
				return '';
			}

			return self::extract_structured_text( $root );
		}

		/**
		 * Prepares the HTML for parsing by ensuring proper encoding and structure.
		 *
		 * @param string $html The raw HTML to prepare.
		 * @return string The prepared HTML.
		 */
		private static function prepare_html( $html ) {

			$encoding = mb_detect_encoding( $html, array( 'UTF-8', 'ISO-8859-1', 'Windows-1252' ), true );

			if ( $encoding && 'UTF-8' !== strtoupper( $encoding ) ) {
				$html = mb_convert_encoding( $html, 'UTF-8', $encoding );
			}

			if ( ! preg_match( '/<meta\s+charset/i', $html ) ) {
				$html = '<meta charset="UTF-8">' . $html;
			}

			return $html;
		}

		/**
		 * Removes non-content elements from the DOM to reduce noise.
		 *
		 * @param DOMDocument $dom The DOMDocument object representing the HTML.
		 * @param DOMXPath    $xpath The DOMXPath object for querying the DOM.
		 */
		private static function remove_noise( $dom, $xpath ) {

			$tags = array( 'script', 'style', 'noscript', 'iframe', 'svg', 'canvas', 'form', 'button' );

			foreach ( $tags as $tag ) {
				$nodes = $dom->getElementsByTagName( $tag );
				while ( $nodes->length ) {
					$nodes->item( 0 )->parentNode->removeChild( $nodes->item( 0 ) );
				}
			}

			$patterns = array(
				"//*[contains(@class,'sidebar')]",
				"//*[contains(@class,'menu')]",
				"//*[contains(@class,'nav')]",
				"//*[contains(@class,'footer')]",
				"//*[contains(@class,'header')]",
				"//*[contains(@class,'comment')]",
				"//*[contains(@class,'breadcrumb')]",
				"//*[contains(@class,'share')]",
			);

			foreach ( $patterns as $pattern ) {
				$nodes = $xpath->query( $pattern );
				foreach ( $nodes as $node ) {
					if ( $node->parentNode ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$node->parentNode->removeChild( $node ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					}
				}
			}
		}

		/**
		 * Finds the best container element that likely holds the main content.
		 *
		 * @param DOMDocument $dom The DOMDocument object representing the HTML.
		 * @param DOMXPath    $xpath The DOMXPath object for querying the DOM.
		 * @return DOMNode|null The best container node, or null if none found.
		 */
		private static function find_best_container( $dom, $xpath ) {

			$candidates = array();

			foreach ( array( 'main', 'article', 'section', 'div' ) as $tag ) {
				foreach ( $dom->getElementsByTagName( $tag ) as $node ) {
					$text = trim( $node->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					if ( strlen( $text ) > 200 ) {
						$candidates[] = $node;
					}
				}
			}

			if ( empty( $candidates ) ) {
				return null;
			}

			$best = null;
			$max  = 0;

			foreach ( $candidates as $node ) {
				$len = strlen( $node->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( $len > $max ) {
					$max  = $len;
					$best = $node;
				}
			}

			return $best;
		}

		/**
		 * Extracts structured text from the given DOM node, preserving sections, lists, code blocks, and tables.
		 *
		 * @param DOMNode $root The root DOM node to extract content from.
		 * @return string The extracted structured text.
		 */
		private static function extract_structured_text( $root ) {

			$output = '';

			$walker = function ( $node ) use ( &$walker, &$output ) {

				if ( $node->nodeType === XML_TEXT_NODE ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$text = trim( $node->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					if ( $text !== '' ) {
						$output .= $text . "\n";
					}
					return;
				}

				if ( $node->nodeType !== XML_ELEMENT_NODE ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					return;
				}

				$tag = strtolower( $node->nodeName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

				if ( in_array( $tag, array( 'h1', 'h2', 'h3', 'h4' ), true ) ) {
					$output .= "\nSection: " . trim( $node->textContent ) . "\n"; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					return;
				}

				if ( $tag === 'p' ) {
					$output .= trim( $node->textContent ) . "\n"; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					return;
				}

				if ( in_array( $tag, array( 'ul', 'ol' ), true ) ) {
					foreach ( $node->getElementsByTagName( 'li' ) as $li ) {
						$output .= '- ' . trim( $li->textContent ) . "\n"; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					}
					return;
				}

				if ( in_array( $tag, array( 'code', 'pre' ), true ) ) {
					$output .= "\nCode:\n" . trim( $node->textContent ) . "\n"; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					return;
				}

				if ( $tag === 'table' ) {
					foreach ( $node->getElementsByTagName( 'tr' ) as $tr ) {
						$row = array();
						foreach ( $tr->getElementsByTagName( 'td' ) as $td ) {
							$row[] = trim( $td->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						}
						foreach ( $tr->getElementsByTagName( 'th' ) as $th ) {
							$row[] = trim( $th->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						}
						if ( ! empty( $row ) ) {
							$output .= implode( ' | ', $row ) . "\n";
						}
					}
					return;
				}

				foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$walker( $child );
				}
			};

			$walker( $root );

			$output = preg_replace( '/\n{2,}/', "\n\n", $output );
			$output = preg_replace( '/[ \t]+/', ' ', $output );

			return trim( $output );
		}
	}
endif;
