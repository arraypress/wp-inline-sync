<?php
/**
 * Runtime key tests.
 *
 * @package ArrayPress\InlineSync
 */

declare( strict_types=1 );

namespace ArrayPress\InlineSync\Tests;

use ArrayPress\InlineSync\RestApi;
use ArrayPress\InlineSync\Utils\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Strauss renames classes and namespaces. It does not rename strings.
 *
 * So two plugins bundling this library get two isolated sets of classes and
 * one shared REST namespace, one shared script handle and one shared transient
 * key -- and none of the three collides loudly. The second REST registration
 * wins and the first plugin's routes resolve to the second plugin's callbacks,
 * against a registry that has never heard of the first plugin's syncs.
 *
 * Every one of those keys is derived from this file's namespace, which is the
 * one thing Strauss does rewrite.
 */
final class RuntimeTest extends TestCase {

	/**
	 * Unprefixed, the keys are the library's own.
	 */
	public function test_an_unprefixed_build_uses_the_library_name(): void {
		$this->assertSame( 'inline-sync', Runtime::prefix_for( 'ArrayPress\\InlineSync\\Utils' ) );
	}

	/**
	 * Prefixed, every key carries the consumer's prefix.
	 *
	 * @param string $namespace What the class is compiled under.
	 * @param string $expected  The prefix it should produce.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'namespaceProvider' )]
	public function test_a_prefixed_build_is_namespaced( string $namespace, string $expected ): void {
		$this->assertSame( $expected, Runtime::prefix_for( $namespace ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function namespaceProvider(): array {
		return array(
			'unprefixed'      => array( 'ArrayPress\\InlineSync\\Utils', 'inline-sync' ),
			'a plugin prefix' => array( 'MyPlugin\\ArrayPress\\InlineSync\\Utils', 'myplugin-inline-sync' ),
			'with underscores'=> array( 'EDD_Fraud_Filter\\ArrayPress\\InlineSync\\Utils', 'edd-fraud-filter-inline-sync' ),
			'empty'           => array( '', 'inline-sync' ),
		);
	}

	/**
	 * Two prefixed builds share no key at all.
	 *
	 * The assertion that matters: not that the keys look right, but that they
	 * differ. A shared key is the whole failure.
	 */
	public function test_two_builds_share_nothing(): void {
		$one = Runtime::prefix_for( 'PluginOne\\ArrayPress\\InlineSync\\Utils' );
		$two = Runtime::prefix_for( 'PluginTwo\\ArrayPress\\InlineSync\\Utils' );

		$this->assertNotSame( $one, $two );
	}

	/**
	 * The REST namespace is derived, not a constant.
	 *
	 * A constant is fixed at compile time and identical in every prefixed
	 * copy, which is precisely the collision being avoided. It used to be one.
	 */
	public function test_the_rest_namespace_is_derived(): void {
		$this->assertSame( Runtime::prefix() . '/v1', RestApi::rest_namespace() );
		$this->assertStringEndsWith( '/v1', RestApi::rest_namespace() );

		$reflection = new \ReflectionClass( RestApi::class );

		$this->assertArrayNotHasKey(
			'NAMESPACE',
			$reflection->getConstants(),
			'The REST namespace is a constant again, so every prefixed copy shares it.'
		);
	}

	/**
	 * A transient key carries the prefix and the sync it belongs to.
	 */
	public function test_a_transient_key_is_scoped(): void {
		$key = Runtime::key( 'stripe_prices_1' );

		$this->assertStringStartsWith( str_replace( '-', '_', Runtime::prefix() ), $key );
		$this->assertStringContainsString( 'stripe_prices', $key );

		// Underscores, not hyphens: this is an option key, not a handle.
		$this->assertStringNotContainsString( '-', $key );
	}

	/**
	 * A script handle is a handle, not an option key.
	 */
	public function test_a_handle_is_hyphenated(): void {
		$this->assertSame( 'inline-sync', Runtime::handle() );
		$this->assertSame( 'inline-sync-bar', Runtime::handle( 'bar' ) );
	}

	/**
	 * No runtime key is written as a literal in src/.
	 *
	 * The assertion the value-based tests cannot make. In an unprefixed build
	 * Runtime::prefix() returns "inline-sync", so a hardcoded "inline-sync/v1"
	 * is indistinguishable from a derived one -- every test passes and the
	 * collision comes back the moment two plugins bundle this.
	 *
	 * So the check is on the source: these strings may appear in the CSS class
	 * names and in the shared JavaScript object, both of which are shared on
	 * purpose, but never as a REST namespace or a script handle.
	 */
	public function test_no_runtime_key_is_hardcoded(): void {
		$forbidden = array(
			"'inline-sync/v1'" => 'a REST namespace',
			"'inline_sync_'"   => 'a transient key',
		);

		$files = glob( dirname( __DIR__ ) . '/src/{,*/}*.php', GLOB_BRACE );

		$this->assertNotEmpty( $files );

		foreach ( $files as $file ) {
			$source = (string) file_get_contents( $file );
			$name   = basename( $file );

			// Runtime.php names the library once, which is where the
			// derivation starts.
			if ( 'Runtime.php' === $name ) {
				continue;
			}

			foreach ( $forbidden as $literal => $what ) {
				$this->assertStringNotContainsString(
					$literal,
					$source,
					sprintf( '%s hardcodes %s; every prefixed copy would share it.', $name, $what )
				);
			}

			// A script handle assigned from a literal rather than Runtime.
			$this->assertDoesNotMatchRegularExpression(
				"/\\\$handle\\s*=\\s*'/",
				$source,
				sprintf( '%s assigns a script handle from a literal.', $name )
			);
		}
	}
}
