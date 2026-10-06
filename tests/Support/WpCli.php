<?php
// phpcs:ignoreFile -- test double for WP-CLI: a global class plus a namespaced function.
declare( strict_types=1 );

namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		/** Records what a command reports; error() stops the command like the real one. */
		class WP_CLI {
			/** @var array<int,array{0:string,1:string}> [level, message] */
			public static array $messages = [];

			public static function success( string $message ): void {
				self::$messages[] = [ 'success', $message ];
			}

			public static function warning( string $message ): void {
				self::$messages[] = [ 'warning', $message ];
			}

			public static function log( string $message ): void {
				self::$messages[] = [ 'log', $message ];
			}

			public static function error( string $message ): void {
				self::$messages[] = [ 'error', $message ];
				throw new \RuntimeException( $message );
			}
		}
	}
}

namespace WP_CLI\Utils {
	if ( ! function_exists( 'WP_CLI\Utils\make_progress_bar' ) ) {
		function make_progress_bar( string $message, int $count ): object {
			return new class() {
				public int $ticks = 0;

				public function tick(): void {
					++$this->ticks;
				}

				public function finish(): void {}
			};
		}
	}
}
