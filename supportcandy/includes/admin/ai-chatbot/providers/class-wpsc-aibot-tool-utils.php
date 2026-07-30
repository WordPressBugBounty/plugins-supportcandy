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
		 * @param array $body Response body.
		 * @return array|null
		 */
		public static function extract_gemini_tool_call( $body ) {

			if ( ! is_array( $body ) || empty( $body['candidates'] ) || ! is_array( $body['candidates'] ) ) {
				return null;
			}

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

			return null;
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
