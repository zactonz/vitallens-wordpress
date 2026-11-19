<?php
/**
 * Fails any user-facing string that calls a measurement a page.
 *
 * The tracked table holds one row per page PER DEVICE, so a row count and a
 * page count differ on every site that watches more than one device — which is
 * the default. Version 1.0.1 fixed six places that rendered a row count with a
 * page noun. Five of them were found one at a time, after the first was
 * reported from a live site, and the sixth was found by running this rule.
 *
 * So the rule is mechanical now. Every string that puts a number next to the
 * word "page" must take that number from a counter listed below as folded. A
 * counter on neither list fails: a new one has to be classified deliberately,
 * because deciding is the step that kept being skipped.
 *
 * Usage: php tools/lint-nouns.php
 *
 * @package VitalLens
 */

declare( strict_types=1 );

/**
 * Counters that hold a page count. Safe beside a page noun.
 *
 * @var array<int,string>
 */
const FOLDED = array(
	// Dashboard_Page::assess() folds its rows to pages before these are set.
	"\$state['tracked']",
	"\$state['passing']",
	"\$state['failing']",
	"\$state['pending']",
	"\$state['unmeasured']",
	"\$state['lab_only']",
	// Derived from two folded counters.
	'$measured',
	// Tracked_Page::never_checked() counts distinct post ids.
	'$never',
	// Backup::restore() counts distinct post ids.
	'$tracked',
	// COUNT(DISTINCT post_id).
	'VitalLens_Store::count_active_pages()',
);

/**
 * Counters that hold a row count. Never valid beside a page noun.
 *
 * @var array<int,string>
 */
const PER_DEVICE = array(
	// One active row is one API call, which is what it is for.
	'VitalLens_Store::count_active()',
	// Incremented once per row of a row-limited query.
	"\$summary['checked']",
	"\$summary['failed']",
	"\$summary['remaining']",
	// The attention list is deliberately per device.
	"\$state['attention']",
);

$root  = dirname( __DIR__ );
$files = array( $root . '/vitallens.php', $root . '/uninstall.php' );

$dir = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes' ) );
foreach ( $dir as $file ) {
	if ( 'php' === $file->getExtension() ) {
		$files[] = $file->getPathname();
	}
}
sort( $files );

$problems = 0;

foreach ( $files as $path ) {
	$tokens = token_get_all( (string) file_get_contents( $path ) );
	$last   = count( $tokens ) - 1;

	foreach ( $tokens as $i => $token ) {
		if ( ! is_array( $token ) || T_CONSTANT_ENCAPSED_STRING !== $token[0] ) {
			continue;
		}

		$text = $token[1];
		$near = '[^\'"]{0,60}';
		if ( ! preg_match( '/%[0-9]*\$?d' . $near . '\bpages?\b/i', $text )
			&& ! preg_match( '/\bpages?\b' . $near . '%[0-9]*\$?d/i', $text ) ) {
			continue;
		}

		// Collect the argument expressions of the call this string sits in,
		// and of the call that one sits in. The counter is in one of the two:
		// _n() takes it directly, and sprintf( __( … ), $a, $b ) one level up.
		$args  = args_of_enclosing_call( $tokens, $i, $last );
		$found = array();

		foreach ( $args as $arg ) {
			if ( preg_match_all( '/(?:[A-Za-z_][A-Za-z0-9_]*::[a-z_]+\(\))|(?:\$[a-z_][a-z0-9_]*(?:\[[^\]]*\])?)/i', $arg, $m ) ) {
				foreach ( $m[0] as $expr ) {
					$found[ $expr ] = true;
				}
			}
		}

		$unknown = array_diff( array_keys( $found ), FOLDED );

		if ( ! $found ) {
			report( $path, $token[2], $text, 'no counter expression found - rule cannot verify it' );
			++$problems;
			continue;
		}

		foreach ( $unknown as $expr ) {
			$why = in_array( $expr, PER_DEVICE, true )
				? 'counts rows, one per page per device'
				: 'not classified - add it to FOLDED or PER_DEVICE in this file';
			report( $path, $token[2], $text, $expr . ' ' . $why );
			++$problems;
		}
	}
}

/**
 * Argument expressions of the call containing a token, plus the call above it.
 *
 * Deliberately shallow: two levels covers every shape this plugin uses, and a
 * string the rule cannot trace is reported rather than passed.
 *
 * @param array<int,mixed> $tokens Token stream.
 * @param int              $at     Index of the string token.
 * @param int              $last   Last index.
 * @return array<int,string>
 */
function args_of_enclosing_call( array $tokens, int $at, int $last ): array {
	$args  = array();
	$start = $at;

	for ( $level = 0; $level < 2; $level++ ) {
		$depth = 0;
		$chunk = '';

		for ( $j = $start; $j <= $last; $j++ ) {
			$text = is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];

			/*
			 * A format string contains "%1$d", which reads as a variable to any
			 * regex looking for one, so a literal's contents must not reach the
			 * caller. An array key is a literal too and has to survive, or
			 * $state['passing'] arrives as $state[''] and nothing matches.
			 */
			if ( is_array( $tokens[ $j ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $j ][0]
				&& '[' !== substr( rtrim( $chunk ), -1 ) ) {
				$text = "''";
			}

			if ( '(' === $text || '[' === $text ) {
				++$depth;
			} elseif ( ')' === $text || ']' === $text ) {
				if ( 0 === $depth ) {
					$args[] = $chunk;
					$start  = $j + 1;
					continue 2;
				}
				--$depth;
			} elseif ( ',' === $text && 0 === $depth ) {
				$args[] = $chunk;
				$chunk  = '';
				continue;
			}

			$chunk .= $text;
		}

		break;
	}

	return $args;
}

/**
 * Prints one failure.
 *
 * @param string $path Absolute file path.
 * @param int    $line Line number.
 * @param string $text The offending string literal.
 * @param string $why  What is wrong.
 * @return void
 */
function report( string $path, int $line, string $text, string $why ): void {
	$short = strlen( $text ) > 72 ? substr( $text, 0, 69 ) . '...' : $text;
	printf( "%s:%d\n  %s\n  %s\n\n", basename( $path ), $line, $short, $why );
}

/*
 * The rule above matches on a counter's NAME, so it trusts the FOLDED list. It
 * would not have caught the defect in assess() itself: $state['lab_only'] was
 * on that list by sight while the code incremented it per row. This second rule
 * is the one that catches that shape - inside assess(), a counter rendered as a
 * page may only be incremented after the fold begins.
 */
$assess = $root . '/includes/admin/class-vitallens-dashboard-page.php';
$lines  = file( $assess );
$fold   = null;

foreach ( $lines as $n => $line ) {
	if ( false !== strpos( $line, 'foreach ( $pages as $page ) {' ) ) {
		$fold = $n;
		break;
	}
}

if ( null === $fold ) {
	print "FAIL  the fold loop in assess() was not found - this rule has stopped checking anything\n";
	exit( 1 );
}

foreach ( $lines as $n => $line ) {
	if ( ! preg_match( '~\+\+\$state\[\s*.([a-z_]+).\s*\]~', $line, $m ) ) {
		continue;
	}

	if ( ! in_array( "\$state['" . $m[1] . "']", FOLDED, true ) ) {
		continue;
	}

	if ( $n < $fold ) {
		printf(
			"%s:%d\n  ++\$state['%s'] is inside the row loop, so it counts rows\n"
			. "  it is rendered with a page noun - increment it in the fold below line %d\n\n",
			basename( $assess ),
			$n + 1,
			$m[1],
			$fold + 1
		);
		++$problems;
	}
}

if ( $problems > 0 ) {
	printf( "FAIL  %d place(s) where a measurement is called a page\n", $problems );
	exit( 1 );
}

print "OK  every page noun is fed by a count folded to pages\n";
