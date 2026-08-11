<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_AIBOT_Tool_Utils' ) ) :

	final class WPSC_AIBOT_Tool_Utils {

		/**
		 * Build provider-agnostic tool array for model call.
		 *
		 * @param string $store_id Vector store id for retrieval tool.
		 * @param array  $tools Function tool definitions.
		 * @return array
		 */
		public static function build_openai_tools( $store_id = '', $tools = array() ) {

			$openai_tools = array();

			if ( is_array( $tools ) && ! empty( $tools ) ) {
				foreach ( $tools as $tool ) {
					if ( ! is_array( $tool ) || empty( $tool['name'] ) || empty( $tool['description'] ) ) {
						continue;
					}

					$parameters = $tool['parameters'] ?? ( $tool['input_schema'] ?? array() );
					if ( ! is_array( $parameters ) || empty( $parameters['type'] ) ) {
						continue;
					}

					$openai_tools[] = array(
						'type'        => 'function',
						'name'        => sanitize_key( (string) $tool['name'] ),
						'description' => sanitize_text_field( (string) $tool['description'] ),
						'parameters'  => $parameters,
						'strict'      => false,
					);
				}
			}

			return $openai_tools;
		}

		/**
		 * Extract tool call from OpenAI response output.
		 *
		 * @param array $body Response body.
		 * @return array|null
		 */
		public static function extract_openai_tool_call( $body ) {

			if ( ! is_array( $body ) ) {
				return null;
			}

			foreach ( $body['output'] ?? array() as $output ) {
				if ( empty( $output['type'] ) || 'function_call' !== $output['type'] || empty( $output['name'] ) ) {
					continue;
				}

				$arguments = array();
				if ( isset( $output['arguments'] ) ) {
					if ( is_array( $output['arguments'] ) ) {
						$arguments = $output['arguments'];
					} elseif ( is_string( $output['arguments'] ) && trim( $output['arguments'] ) !== '' ) {
						$arguments = json_decode( $output['arguments'], true );
						if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $arguments ) ) {
							$arguments = array();
						}
					}
				}

				return array(
					'name'      => sanitize_key( (string) $output['name'] ),
					'arguments' => $arguments,
					'call_id'   => ! empty( $output['call_id'] ) ? sanitize_text_field( $output['call_id'] ) : '',
				);
			}

			return null;
		}

		/**
		 * Build Gemini-compatible tools array for model call.
		 *
		 * @param string $store_name File search store name for retrieval tool.
		 * @param array  $tools Function tool definitions.
		 * @return array
		 */
		public static function build_gemini_tools( $store_name = '', $tools = array() ) {

			$gemini_tools = array();
			$declarations = array();

			if ( is_array( $tools ) && ! empty( $tools ) ) {
				foreach ( $tools as $tool ) {
					if ( ! is_array( $tool ) || empty( $tool['name'] ) || empty( $tool['description'] ) ) {
						continue;
					}

					$parameters = $tool['parameters'] ?? ( $tool['input_schema'] ?? array() );
					$parameters = self::sanitize_gemini_schema( $parameters );
					if ( ! is_array( $parameters ) || empty( $parameters['type'] ) ) {
						continue;
					}

					$declarations[] = array(
						'name'        => sanitize_key( (string) $tool['name'] ),
						'description' => sanitize_text_field( (string) $tool['description'] ),
						'parameters'  => $parameters,
					);
				}
			}

			if ( ! empty( $declarations ) ) {
				$gemini_tools[] = array(
					'function_declarations' => $declarations,
				);
			}

			return $gemini_tools;
		}

		/**
		 * Extract tool call from Gemini response body.
		 *
		 * Some Gemini models occasionally ignore the native function-calling
		 * protocol and instead write the intended call out as pseudocode text
		 * (e.g. 'print(default_api.search_knowledge_base(query="..."))'). If no
		 * structured functionCall part is present, fall back to detecting that
		 * pattern in the response text so the call still executes instead of
		 * leaking raw pseudocode to the customer - see parse_pseudocode_tool_call().
		 *
		 * @param array $body Response body.
		 * @param array $known_tool_names Registered tool names for this turn, used
		 *                                to validate a pseudocode-text fallback match.
		 * @return array|null
		 */
		public static function extract_gemini_tool_call( $body, $known_tool_names = array() ) {

			if ( ! is_array( $body ) || empty( $body['candidates'] ) || ! is_array( $body['candidates'] ) ) {
				return null;
			}

			$text_parts = array();

			foreach ( $body['candidates'] as $candidate ) {
				$parts = $candidate['content']['parts'] ?? array();
				if ( ! is_array( $parts ) || empty( $parts ) ) {
					continue;
				}

				foreach ( $parts as $part ) {
					$call = array();
					if ( ! empty( $part['functionCall'] ) && is_array( $part['functionCall'] ) ) {
						$call = $part['functionCall'];
					} elseif ( ! empty( $part['function_call'] ) && is_array( $part['function_call'] ) ) {
						$call = $part['function_call'];
					}

					if ( empty( $call['name'] ) ) {
						if ( ! empty( $part['text'] ) && is_string( $part['text'] ) ) {
							$text_parts[] = $part['text'];
						}
						continue;
					}

					$args = array();
					if ( isset( $call['args'] ) ) {
						if ( is_array( $call['args'] ) ) {
							$args = $call['args'];
						} elseif ( is_string( $call['args'] ) && trim( $call['args'] ) !== '' ) {
							$decoded = json_decode( $call['args'], true );
							if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
								$args = $decoded;
							}
						}
					}

					return array(
						'name'      => sanitize_key( (string) $call['name'] ),
						'arguments' => $args,
						'call_id'   => '',
					);
				}
			}

			if ( ! empty( $text_parts ) ) {
				return self::parse_pseudocode_tool_call( implode( "\n", $text_parts ), $known_tool_names );
			}

			return null;
		}

		/**
		 * Best-effort recovery for a tool call written out as pseudocode text
		 * instead of a native functionCall - e.g.
		 * 'print(default_api.search_knowledge_base(query="trial period"))' or
		 * 'default_api.detect_spam(is_spam=true)'. Only tool arguments matching
		 * the flat string/boolean shape our tools use are supported; anything
		 * else is left unparsed (returns null) rather than guessed at.
		 *
		 * @param string $text Candidate response text.
		 * @param array  $known_tool_names Registered tool names to validate the match against.
		 * @return array|null
		 */
		private static function parse_pseudocode_tool_call( $text, $known_tool_names = array() ) {

			$text = trim( (string) $text );
			if ( '' === $text ) {
				return null;
			}

			// Unwrap a single optional print( ... ) wrapper.
			if ( 1 === preg_match( '/^print\s*\((.*)\)\s*$/s', $text, $unwrapped ) ) {
				$text = trim( $unwrapped[1] );
			}

			if ( 1 !== preg_match( '/^default_api\s*\.\s*([A-Za-z_][A-Za-z0-9_]*)\s*\((.*)\)\s*$/s', $text, $matches ) ) {
				return null;
			}

			$name = sanitize_key( $matches[1] );
			if ( ! empty( $known_tool_names ) && ! in_array( $name, $known_tool_names, true ) ) {
				return null;
			}

			$arguments = array();
			if ( 1 === preg_match_all( '/([A-Za-z_][A-Za-z0-9_]*)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[^,]+)/', $matches[2], $pairs, PREG_SET_ORDER ) ) {

				foreach ( $pairs as $pair ) {
					$key = sanitize_key( $pair[1] );
					$value = trim( $pair[2] );
					if ( strlen( $value ) >= 2 && ( ( '"' === $value[0] && '"' === substr( $value, -1 ) ) || ( "'" === $value[0] && "'" === substr( $value, -1 ) ) ) ) {
						$value = substr( $value, 1, -1 );
					}
					$arguments[ $key ] = stripslashes( $value );
				}
			}

			return array(
				'name'      => $name,
				'arguments' => $arguments,
				'call_id'   => '',
			);
		}

		/**
		 * Sanitize JSON schema for Gemini function declarations.
		 *
		 * Gemini schema rejects some JSON Schema keywords (e.g. additionalProperties).
		 *
		 * @param mixed $schema Schema node.
		 * @return mixed
		 */
		private static function sanitize_gemini_schema( $schema ) {

			if ( ! is_array( $schema ) ) {
				return $schema;
			}

			foreach ( $schema as $key => $value ) {
				if ( 'additionalProperties' === $key ) {
					unset( $schema[ $key ] );
					continue;
				}

				if ( is_array( $value ) ) {
					$schema[ $key ] = self::sanitize_gemini_schema( $value );
				}
			}

			return $schema;
		}

		/**
		 * Build standard tool-call response payload.
		 *
		 * @param array $tool_call Tool call payload.
		 * @param int   $prompt_tokens Prompt tokens.
		 * @param int   $completion_tokens Completion tokens.
		 * @param int   $total_tokens Total tokens.
		 * @return array
		 */
		public static function make_tool_call_response( $tool_call, $prompt_tokens, $completion_tokens, $total_tokens ) {

			return array(
				'success'           => true,
				'response'          => '',
				'create_ticket'     => false,
				'tool_call'         => $tool_call,
				'prompt_tokens'     => (int) $prompt_tokens,
				'completion_tokens' => (int) $completion_tokens,
				'total_tokens'      => (int) $total_tokens,
			);
		}
	}

endif;
