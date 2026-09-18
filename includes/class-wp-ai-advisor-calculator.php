<?php
/**
 * Arithmetic for the advisor.
 *
 * Language models are unreliable at arithmetic: they predict plausible digits
 * rather than computing. So the model writes the expression and this class
 * evaluates it exactly, with a hand-written parser rather than eval() — the
 * expression comes from a model reading visitor input, and eval() on that is
 * arbitrary code execution.
 *
 * @package WP_AI_Advisor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parses and evaluates a restricted arithmetic expression.
 */
class WP_AI_Advisor_Calculator {

	const MAX_LENGTH = 500;
	const MAX_DEPTH  = 32;

	/**
	 * Functions the expression may call, and how many arguments each takes.
	 *
	 * A value of 0 means "one or more".
	 *
	 * @var array
	 */
	private static $functions = array(
		'round' => 0,
		'min'   => 0,
		'max'   => 0,
		'abs'   => 1,
		'ceil'  => 1,
		'floor' => 1,
	);

	/**
	 * Tokens, in order.
	 *
	 * @var array
	 */
	private $tokens = array();

	/**
	 * Current token index.
	 *
	 * @var int
	 */
	private $position = 0;

	/**
	 * Current recursion depth.
	 *
	 * @var int
	 */
	private $depth = 0;

	/**
	 * Evaluates an expression.
	 *
	 * @param string $expression Arithmetic expression, e.g. "50 * 2.5 * 21 * 1.9".
	 * @return float|WP_Error
	 */
	public static function evaluate( $expression ) {
		$calculator = new self();

		return $calculator->run( (string) $expression );
	}

	/**
	 * Tokenises and evaluates.
	 *
	 * @param string $expression Arithmetic expression.
	 * @return float|WP_Error
	 */
	private function run( $expression ) {
		$expression = trim( $expression );

		if ( '' === $expression ) {
			return new WP_Error( 'wp_ai_advisor_empty_expression', __( 'The expression was empty.', 'wp-ai-advisor' ) );
		}

		if ( strlen( $expression ) > self::MAX_LENGTH ) {
			return new WP_Error( 'wp_ai_advisor_expression_too_long', __( 'The expression was too long.', 'wp-ai-advisor' ) );
		}

		$tokens = $this->tokenize( $expression );

		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		$this->tokens   = $tokens;
		$this->position = 0;
		$this->depth    = 0;

		$value = $this->parse_expression();

		if ( is_wp_error( $value ) ) {
			return $value;
		}

		if ( $this->position < count( $this->tokens ) ) {
			return new WP_Error(
				'wp_ai_advisor_trailing_input',
				__( 'The expression had leftover characters.', 'wp-ai-advisor' )
			);
		}

		if ( ! is_finite( $value ) ) {
			return new WP_Error( 'wp_ai_advisor_not_finite', __( 'The result was not a finite number.', 'wp-ai-advisor' ) );
		}

		return $value;
	}

	/**
	 * Splits an expression into tokens.
	 *
	 * @param string $expression Arithmetic expression.
	 * @return array|WP_Error
	 */
	private function tokenize( $expression ) {
		$tokens = array();
		$length = strlen( $expression );
		$i      = 0;

		while ( $i < $length ) {
			$char = $expression[ $i ];

			if ( ' ' === $char || "\t" === $char || "\n" === $char || "\r" === $char ) {
				$i++;
				continue;
			}

			if ( ctype_digit( $char ) || ( '.' === $char && $i + 1 < $length && ctype_digit( $expression[ $i + 1 ] ) ) ) {
				$number = '';

				while ( $i < $length && ( ctype_digit( $expression[ $i ] ) || '.' === $expression[ $i ] ) ) {
					$number .= $expression[ $i ];
					$i++;
				}

				if ( substr_count( $number, '.' ) > 1 ) {
					return new WP_Error(
						'wp_ai_advisor_bad_number',
						sprintf(
							/* translators: %s: the malformed number. */
							__( '"%s" is not a valid number.', 'wp-ai-advisor' ),
							$number
						)
					);
				}

				$tokens[] = array( 'type' => 'number', 'value' => (float) $number );
				continue;
			}

			if ( ctype_alpha( $char ) ) {
				$name = '';

				while ( $i < $length && ctype_alpha( $expression[ $i ] ) ) {
					$name .= $expression[ $i ];
					$i++;
				}

				$name = strtolower( $name );

				if ( ! isset( self::$functions[ $name ] ) ) {
					return new WP_Error(
						'wp_ai_advisor_unknown_function',
						sprintf(
							/* translators: %s: the unsupported function name. */
							__( '"%s" is not a function the calculator knows.', 'wp-ai-advisor' ),
							$name
						)
					);
				}

				$tokens[] = array( 'type' => 'function', 'value' => $name );
				continue;
			}

			if ( false !== strpos( '+-*/%^(),', $char ) ) {
				$tokens[] = array( 'type' => $char, 'value' => $char );
				$i++;
				continue;
			}

			return new WP_Error(
				'wp_ai_advisor_bad_character',
				sprintf(
					/* translators: %s: the unexpected character. */
					__( '"%s" is not allowed in an expression.', 'wp-ai-advisor' ),
					$char
				)
			);
		}

		return $tokens;
	}

	/**
	 * The current token, or null at the end of input.
	 *
	 * @return array|null
	 */
	private function peek() {
		return isset( $this->tokens[ $this->position ] ) ? $this->tokens[ $this->position ] : null;
	}

	/**
	 * Consumes the current token if it matches.
	 *
	 * @param string $type Token type.
	 * @return bool
	 */
	private function accept( $type ) {
		$token = $this->peek();

		if ( $token && $token['type'] === $type ) {
			$this->position++;

			return true;
		}

		return false;
	}

	/**
	 * expression := term ( ( '+' | '-' ) term )*
	 *
	 * @return float|WP_Error
	 */
	private function parse_expression() {
		$left = $this->parse_term();

		if ( is_wp_error( $left ) ) {
			return $left;
		}

		while ( true ) {
			if ( $this->accept( '+' ) ) {
				$right = $this->parse_term();

				if ( is_wp_error( $right ) ) {
					return $right;
				}

				$left += $right;
				continue;
			}

			if ( $this->accept( '-' ) ) {
				$right = $this->parse_term();

				if ( is_wp_error( $right ) ) {
					return $right;
				}

				$left -= $right;
				continue;
			}

			return $left;
		}
	}

	/**
	 * term := power ( ( '*' | '/' | '%' ) power )*
	 *
	 * @return float|WP_Error
	 */
	private function parse_term() {
		$left = $this->parse_power();

		if ( is_wp_error( $left ) ) {
			return $left;
		}

		while ( true ) {
			$operator = null;

			foreach ( array( '*', '/', '%' ) as $candidate ) {
				if ( $this->accept( $candidate ) ) {
					$operator = $candidate;
					break;
				}
			}

			if ( null === $operator ) {
				return $left;
			}

			$right = $this->parse_power();

			if ( is_wp_error( $right ) ) {
				return $right;
			}

			if ( '*' === $operator ) {
				$left *= $right;
				continue;
			}

			if ( 0.0 === (float) $right ) {
				return new WP_Error( 'wp_ai_advisor_division_by_zero', __( 'The expression divided by zero.', 'wp-ai-advisor' ) );
			}

			$left = '/' === $operator ? $left / $right : fmod( $left, $right );
		}
	}

	/**
	 * power := unary ( '^' power )?  — right associative.
	 *
	 * @return float|WP_Error
	 */
	private function parse_power() {
		$base = $this->parse_unary();

		if ( is_wp_error( $base ) ) {
			return $base;
		}

		if ( ! $this->accept( '^' ) ) {
			return $base;
		}

		$exponent = $this->parse_power();

		if ( is_wp_error( $exponent ) ) {
			return $exponent;
		}

		// Keeps a model-written expression from pinning the CPU.
		if ( abs( $exponent ) > 64 ) {
			return new WP_Error( 'wp_ai_advisor_exponent_too_large', __( 'The exponent was too large.', 'wp-ai-advisor' ) );
		}

		return pow( $base, $exponent );
	}

	/**
	 * unary := ( '+' | '-' ) unary | primary
	 *
	 * @return float|WP_Error
	 */
	private function parse_unary() {
		if ( $this->accept( '-' ) ) {
			$value = $this->parse_unary();

			return is_wp_error( $value ) ? $value : -$value;
		}

		$this->accept( '+' );

		return $this->parse_primary();
	}

	/**
	 * primary := number | '(' expression ')' | function '(' args ')'
	 *
	 * @return float|WP_Error
	 */
	private function parse_primary() {
		if ( $this->depth >= self::MAX_DEPTH ) {
			return new WP_Error( 'wp_ai_advisor_too_deep', __( 'The expression was nested too deeply.', 'wp-ai-advisor' ) );
		}

		$token = $this->peek();

		if ( null === $token ) {
			return new WP_Error( 'wp_ai_advisor_unexpected_end', __( 'The expression ended unexpectedly.', 'wp-ai-advisor' ) );
		}

		if ( 'number' === $token['type'] ) {
			$this->position++;

			return (float) $token['value'];
		}

		if ( '(' === $token['type'] ) {
			$this->position++;
			$this->depth++;

			$value = $this->parse_expression();

			$this->depth--;

			if ( is_wp_error( $value ) ) {
				return $value;
			}

			if ( ! $this->accept( ')' ) ) {
				return new WP_Error( 'wp_ai_advisor_unclosed_bracket', __( 'A bracket was left open.', 'wp-ai-advisor' ) );
			}

			return $value;
		}

		if ( 'function' === $token['type'] ) {
			return $this->parse_function( $token['value'] );
		}

		return new WP_Error(
			'wp_ai_advisor_unexpected_token',
			sprintf(
				/* translators: %s: the unexpected symbol. */
				__( '"%s" was not expected here.', 'wp-ai-advisor' ),
				$token['value']
			)
		);
	}

	/**
	 * Parses a function call and applies it.
	 *
	 * @param string $name Function name.
	 * @return float|WP_Error
	 */
	private function parse_function( $name ) {
		$this->position++;

		if ( ! $this->accept( '(' ) ) {
			return new WP_Error(
				'wp_ai_advisor_missing_bracket',
				sprintf(
					/* translators: %s: function name. */
					__( '"%s" needs brackets around its arguments.', 'wp-ai-advisor' ),
					$name
				)
			);
		}

		$arguments = array();
		$this->depth++;

		if ( ! $this->accept( ')' ) ) {
			while ( true ) {
				$value = $this->parse_expression();

				if ( is_wp_error( $value ) ) {
					$this->depth--;

					return $value;
				}

				$arguments[] = $value;

				if ( $this->accept( ',' ) ) {
					continue;
				}

				if ( $this->accept( ')' ) ) {
					break;
				}

				$this->depth--;

				return new WP_Error( 'wp_ai_advisor_unclosed_bracket', __( 'A bracket was left open.', 'wp-ai-advisor' ) );
			}
		}

		$this->depth--;

		return $this->apply( $name, $arguments );
	}

	/**
	 * Applies a function to its arguments.
	 *
	 * @param string  $name      Function name.
	 * @param float[] $arguments Evaluated arguments.
	 * @return float|WP_Error
	 */
	private function apply( $name, array $arguments ) {
		$expected = self::$functions[ $name ];

		if ( empty( $arguments ) || ( $expected > 0 && count( $arguments ) !== $expected ) ) {
			return new WP_Error(
				'wp_ai_advisor_bad_arguments',
				sprintf(
					/* translators: %s: function name. */
					__( '"%s" was given the wrong number of arguments.', 'wp-ai-advisor' ),
					$name
				)
			);
		}

		switch ( $name ) {
			case 'round':
				$precision = isset( $arguments[1] ) ? (int) $arguments[1] : 0;

				return round( $arguments[0], max( -10, min( 10, $precision ) ) );

			case 'min':
				return (float) min( $arguments );

			case 'max':
				return (float) max( $arguments );

			case 'abs':
				return abs( $arguments[0] );

			case 'ceil':
				return ceil( $arguments[0] );

			default:
				return floor( $arguments[0] );
		}
	}
}
