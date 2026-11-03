<?php
/**
 * The one place that talks to Google.
 *
 * PageSpeed Insights v5 returns the Lighthouse run AND the CrUX record for the
 * same URL in a single response, so one call per page per device gets both the
 * lab test and what real visitors measured. Nothing else in the plugin makes
 * an HTTP request; everything downstream consumes the normalised array this
 * returns.
 *
 * @package VitalLens
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetches and normalises a PageSpeed Insights result.
 */
class VitalLens_PSI_Client {

	const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

	/**
	 * Field data was measured on this exact URL.
	 */
	const FIELD_PAGE = 'page';

	/**
	 * Google had nothing for this URL and answered with the whole origin.
	 */
	const FIELD_ORIGIN = 'origin';

	/**
	 * No real-user data at all. The lab run is the only signal.
	 */
	const FIELD_NONE = 'none';

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $api_key Google API key with the PageSpeed Insights API enabled.
	 */
	public function __construct( $api_key ) {
		$this->api_key = (string) $api_key;
	}

	/**
	 * Run one check.
	 *
	 * @param string $url      Absolute URL to test.
	 * @param string $strategy 'mobile' or 'desktop'.
	 * @return array|WP_Error Normalised result, or an error safe to show an admin.
	 */
	public function fetch( $url, $strategy = 'mobile' ) {
		if ( '' === $this->api_key ) {
			return new WP_Error(
				'vitallens_no_key',
				__( 'No PageSpeed API key is saved yet.', 'vitallens' )
			);
		}

		$strategy = in_array( $strategy, array( 'mobile', 'desktop' ), true ) ? $strategy : 'mobile';

		$request_url = add_query_arg(
			array(
				'url'      => rawurlencode( $url ),
				'strategy' => $strategy,
				'key'      => rawurlencode( $this->api_key ),
				// Asking for both categories in one call is the whole point:
				// 'performance' carries the lab metrics, and the CrUX record
				// rides along in loadingExperience regardless.
				'category' => 'performance',
			),
			self::ENDPOINT
		);

		$response = wp_remote_get(
			$request_url,
			array(
				// PSI genuinely takes 10-30s on a slow page, and longer on a
				// cold one. A short timeout here does not protect anything; it
				// just turns a working check into a failed one.
				'timeout'    => 60,
				'user-agent' => 'VitalLens/' . VITALLENS_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'vitallens_http',
				sprintf(
					/* translators: %s: error text from WordPress' HTTP layer. */
					__( 'Could not reach PageSpeed Insights: %s', 'vitallens' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return $this->error_from_response( $code, $body );
		}
		if ( ! is_array( $body ) || empty( $body['lighthouseResult'] ) ) {
			return new WP_Error(
				'vitallens_bad_response',
				__( 'PageSpeed Insights answered, but not with a result we could read.', 'vitallens' )
			);
		}

		return $this->normalise( $body, $url, $strategy );
	}

	/**
	 * Check that a key works, without storing anything.
	 *
	 * @param string $url URL to probe with.
	 * @return true|WP_Error
	 */
	public function test( $url ) {
		$result = $this->fetch( $url, 'mobile' );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Turn Google's error body into something an admin can act on.
	 *
	 * Deliberately does not pass the provider's own wording through: it
	 * arrives carrying key fragments and support URLs, and a product screen is
	 * the wrong place to read somebody else's API documentation.
	 *
	 * @param int        $code HTTP status.
	 * @param array|null $body Decoded body.
	 * @return WP_Error
	 */
	private function error_from_response( $code, $body ) {
		$reason = isset( $body['error']['message'] ) ? strtolower( (string) $body['error']['message'] ) : '';

		/*
		 * Checked before the status code, because Google answers 400 both for
		 * a rejected key and for a page Lighthouse could not render -- and the
		 * second is far more common (a page behind a login, in coming-soon
		 * mode, redirecting, or too slow to paint). Keying only on 400 told
		 * those users their API key was wrong, so they replaced a key that
		 * was never the problem.
		 */
		if ( false !== strpos( $reason, 'unable to process' ) || false !== strpos( $reason, 'lighthouse returned error' ) ) {
			return new WP_Error(
				'vitallens_unreachable',
				__( 'Google could not load this URL. Check it is public and returns a page, not a redirect loop or a login screen.', 'vitallens' )
			);
		}
		if ( 400 === $code || false !== strpos( $reason, 'api key not valid' ) ) {
			return new WP_Error(
				'vitallens_bad_key',
				__( 'Google rejected the API key. Check it is correct and that the PageSpeed Insights API is enabled for it.', 'vitallens' )
			);
		}
		if ( 403 === $code ) {
			return new WP_Error(
				'vitallens_forbidden',
				__( 'Google refused the request. The key is usually restricted to other APIs, other referrers, or another IP.', 'vitallens' )
			);
		}
		if ( 429 === $code ) {
			return new WP_Error(
				'vitallens_rate_limited',
				__( 'Hit the PageSpeed rate limit. The next scheduled run will pick this page up again.', 'vitallens' )
			);
		}
		if ( 404 === $code || false !== strpos( $reason, 'unable to process' ) ) {
			return new WP_Error(
				'vitallens_unreachable',
				__( 'Google could not load this URL. Check it is public and returns a page, not a redirect loop or a login screen.', 'vitallens' )
			);
		}
		if ( $code >= 500 ) {
			// Seen often enough to be worth naming: PSI 500s are usually
			// per-URL and transient, not a sign the key or the site is wrong.
			return new WP_Error(
				'vitallens_upstream',
				__( 'PageSpeed Insights failed on this URL. That is usually temporary — the next run will try again.', 'vitallens' )
			);
		}
		return new WP_Error(
			'vitallens_unknown',
			sprintf(
				/* translators: %d: HTTP status code. */
				__( 'PageSpeed Insights answered with an unexpected status (%d).', 'vitallens' ),
				$code
			)
		);
	}

	/**
	 * Flatten a PSI response into the shape the rest of the plugin uses.
	 *
	 * @param array  $body     Decoded response.
	 * @param string $url      URL that was tested.
	 * @param string $strategy Device.
	 * @return array
	 */
	private function normalise( $body, $url, $strategy ) {
		$lhr = isset( $body['lighthouseResult'] ) ? $body['lighthouseResult'] : array();

		$score = null;
		if ( isset( $lhr['categories']['performance']['score'] ) ) {
			$score = (int) round( ( (float) $lhr['categories']['performance']['score'] ) * 100 );
		}

		list( $field_source, $field ) = $this->extract_field( $body );

		return array(
			'url'          => $url,
			'strategy'     => $strategy,
			'checked_at'   => time(),
			'score'        => $score,
			'field_source' => $field_source,
			'field'        => $field,
			'lab'          => $this->extract_lab( $lhr ),
			'findings'     => $this->extract_findings( $lhr ),
			'diagnoses'    => $this->extract_diagnoses( $lhr ),
		);
	}

	/**
	 * Pull the CrUX record out, and say honestly which one it is.
	 *
	 * Google sets origin_fallback when it had nothing for the URL and answered
	 * with the whole origin instead. Treating that as page-level data is the
	 * single easiest way for a tool like this to lie: it shows a green page
	 * that nobody has ever measured.
	 *
	 * @param array $body Decoded response.
	 * @return array{0: string, 1: array}
	 */
	private function extract_field( $body ) {
		$page   = isset( $body['loadingExperience'] ) ? $body['loadingExperience'] : null;
		$origin = isset( $body['originLoadingExperience'] ) ? $body['originLoadingExperience'] : null;

		$is_fallback = $page && ! empty( $page['origin_fallback'] );

		if ( $page && ! $is_fallback && ! empty( $page['metrics'] ) ) {
			return array( self::FIELD_PAGE, $this->map_crux( $page['metrics'] ) );
		}
		if ( $origin && ! empty( $origin['metrics'] ) ) {
			return array( self::FIELD_ORIGIN, $this->map_crux( $origin['metrics'] ) );
		}
		if ( $page && ! empty( $page['metrics'] ) ) {
			return array( self::FIELD_ORIGIN, $this->map_crux( $page['metrics'] ) );
		}
		return array( self::FIELD_NONE, array() );
	}

	/**
	 * Map CrUX's metric names onto ours.
	 *
	 * Two traps here. CLS arrives as an integer scaled by 100 -- 5 means 0.05 --
	 * and in some responses as a string rather than a number, so a typeof-style
	 * check mis-branches for CLS alone. Both are handled rather than assumed.
	 *
	 * @param array $metrics CrUX metrics block.
	 * @return array<string, float>
	 */
	private function map_crux( $metrics ) {
		$map = array(
			'LCP'  => 'LARGEST_CONTENTFUL_PAINT_MS',
			'INP'  => 'INTERACTION_TO_NEXT_PAINT',
			'CLS'  => 'CUMULATIVE_LAYOUT_SHIFT_SCORE',
			'FCP'  => 'FIRST_CONTENTFUL_PAINT_MS',
			'TTFB' => 'EXPERIMENTAL_TIME_TO_FIRST_BYTE',
		);

		$out = array();
		foreach ( $map as $key => $crux_key ) {
			if ( ! isset( $metrics[ $crux_key ]['percentile'] ) ) {
				continue;
			}
			$value = $metrics[ $crux_key ]['percentile'];
			if ( ! is_numeric( $value ) ) {
				continue;
			}
			$value = (float) $value;
			if ( 'CLS' === $key ) {
				$value = $value / 100;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * The lab metrics, from the audits that carry a numeric value.
	 *
	 * @param array $lhr Lighthouse result.
	 * @return array<string, float>
	 */
	private function extract_lab( $lhr ) {
		$map = array(
			'LCP'  => 'largest-contentful-paint',
			'CLS'  => 'cumulative-layout-shift',
			'TBT'  => 'total-blocking-time',
			'SI'   => 'speed-index',
			'FCP'  => 'first-contentful-paint',
			'TTFB' => 'server-response-time',
		);

		$audits = isset( $lhr['audits'] ) ? $lhr['audits'] : array();
		$out    = array();
		foreach ( $map as $key => $audit_id ) {
			if ( isset( $audits[ $audit_id ]['numericValue'] ) && is_numeric( $audits[ $audit_id ]['numericValue'] ) ) {
				$out[ $key ] = (float) $audits[ $audit_id ]['numericValue'];
			}
		}
		return $out;
	}

	/**
	 * The handful of opportunities worth storing.
	 *
	 * Only the title, the saving and a one-line piece of evidence. The full
	 * Lighthouse payload is roughly 800 KB per run; keeping it is how a table
	 * reaches gigabytes on a site that has done nothing wrong, and nothing in
	 * this plugin ever reads it back.
	 *
	 * @param array $lhr   Lighthouse result.
	 * @param int   $limit How many to keep.
	 * @return array<int, array{title: string, saving_ms: int, evidence: string}>
	 */
	/**
	 * The audits that explain a metric, and which metric each one explains.
	 *
	 * Lighthouse 13 moved almost everything into "insight" audits, which is
	 * why a report built on details.type === 'opportunity' now finds one row
	 * and calls it a diagnosis. These are the ones that name a cause.
	 *
	 * @return array<string, array{metric: string, kind: string}>
	 */
	private function diagnostic_audits() {
		return array(
			// The element that moved, and what moved it. The richest of them.
			'layout-shifts'                   => array( 'metric' => 'CLS', 'kind' => 'shift' ),
			'cls-culprits-insight'            => array( 'metric' => 'CLS', 'kind' => 'shift' ),
			'unsized-images'                  => array( 'metric' => 'CLS', 'kind' => 'element' ),
			'non-composited-animations'       => array( 'metric' => 'CLS', 'kind' => 'element' ),

			'lcp-discovery-insight'           => array( 'metric' => 'LCP', 'kind' => 'element' ),
			'lcp-breakdown-insight'           => array( 'metric' => 'LCP', 'kind' => 'phase' ),
			'render-blocking-insight'         => array( 'metric' => 'LCP', 'kind' => 'request' ),
			'image-delivery-insight'          => array( 'metric' => 'LCP', 'kind' => 'request' ),
			'font-display-insight'            => array( 'metric' => 'LCP', 'kind' => 'request' ),
			'network-dependency-tree-insight' => array( 'metric' => 'LCP', 'kind' => 'request' ),
			'document-latency-insight'        => array( 'metric' => 'TTFB', 'kind' => 'check' ),

			'inp-breakdown-insight'           => array( 'metric' => 'INP', 'kind' => 'phase' ),
			'long-tasks'                      => array( 'metric' => 'TBT', 'kind' => 'request' ),
			'third-parties-insight'           => array( 'metric' => 'TBT', 'kind' => 'party' ),
			'forced-reflow-insight'           => array( 'metric' => 'TBT', 'kind' => 'request' ),
			'duplicated-javascript-insight'   => array( 'metric' => 'TBT', 'kind' => 'request' ),
			'legacy-javascript-insight'       => array( 'metric' => 'TBT', 'kind' => 'request' ),
		);
	}

	/**
	 * Flatten whatever shape an audit's details happen to be.
	 *
	 * A Lighthouse audit's rows may be a table's items, a list whose items
	 * are themselves tables, or a checklist keyed by name. Callers want rows.
	 *
	 * @param array $audit One audit.
	 * @return array<int, array>
	 */
	private function audit_rows( $audit ) {
		$details = isset( $audit['details'] ) && is_array( $audit['details'] ) ? $audit['details'] : array();
		$type    = isset( $details['type'] ) ? $details['type'] : '';
		$items   = isset( $details['items'] ) && is_array( $details['items'] ) ? $details['items'] : array();

		if ( 'checklist' === $type ) {
			$rows = array();
			foreach ( $items as $key => $entry ) {
				if ( is_array( $entry ) && isset( $entry['value'] ) && ! $entry['value'] ) {
					$rows[] = array(
						'label' => isset( $entry['label'] ) ? $entry['label'] : (string) $key,
					);
				}
			}
			return $rows;
		}

		// A list of tables: descend one level and keep the rows.
		if ( 'list' === $type ) {
			$rows = array();
			foreach ( $items as $entry ) {
				if ( isset( $entry['items'] ) && is_array( $entry['items'] ) ) {
					foreach ( $entry['items'] as $row ) {
						$rows[] = $row;
					}
				} else {
					$rows[] = $entry;
				}
			}
			return $rows;
		}

		return $items;
	}

	/**
	 * Pull a node out of a row, wherever this audit happens to keep it.
	 *
	 * @param array $row One details row.
	 * @return array{label: string, selector: string, snippet: string}
	 */
	private function row_node( $row ) {
		$node = null;
		if ( isset( $row['node'] ) && is_array( $row['node'] ) && 'node' === ( isset( $row['node']['type'] ) ? $row['node']['type'] : 'node' ) ) {
			$node = $row['node'];
		} elseif ( isset( $row['extra'] ) && is_array( $row['extra'] ) && 'node' === ( isset( $row['extra']['type'] ) ? $row['extra']['type'] : '' ) ) {
			$node = $row['extra'];
		} elseif ( isset( $row['element'] ) && is_array( $row['element'] ) ) {
			$node = $row['element'];
		}

		if ( ! $node ) {
			return array(
				'label'    => '',
				'selector' => '',
				'snippet'  => '',
			);
		}

		return array(
			'label'    => isset( $node['nodeLabel'] ) ? $this->clip( $node['nodeLabel'], 160 ) : '',
			'selector' => isset( $node['selector'] ) ? $this->clip( $node['selector'], 500 ) : '',
			'snippet'  => isset( $node['snippet'] ) ? $this->clip( $node['snippet'], 500 ) : '',
		);
	}

	/**
	 * The causes Lighthouse attaches under a shift.
	 *
	 * This is the part a site owner can act on: not "CLS is 0.16" but
	 * "an image with no height attribute, and two web fonts loading late".
	 *
	 * @param array $row One details row.
	 * @return array{cause: string, evidence: string}
	 */
	private function row_causes( $row ) {
		$subs = isset( $row['subItems']['items'] ) && is_array( $row['subItems']['items'] )
			? $row['subItems']['items']
			: array();

		$causes   = array();
		$evidence = '';

		foreach ( $subs as $sub ) {
			if ( ! empty( $sub['cause'] ) ) {
				$causes[ (string) $sub['cause'] ] = true;
			}
			if ( '' === $evidence && isset( $sub['extra']['type'] ) ) {
				if ( 'url' === $sub['extra']['type'] && ! empty( $sub['extra']['value'] ) ) {
					$evidence = (string) $sub['extra']['value'];
				} elseif ( 'node' === $sub['extra']['type'] && ! empty( $sub['extra']['selector'] ) ) {
					$evidence = (string) $sub['extra']['selector'];
				}
			}
			if ( '' === $evidence && ! empty( $sub['url'] ) ) {
				$evidence = (string) $sub['url'];
			}
		}

		return array(
			'cause'    => $this->clip( implode( ' · ', array_keys( $causes ) ), 240 ),
			'evidence' => $this->clip( $evidence, 500 ),
		);
	}

	/**
	 * A number out of a row, with the unit it is measured in.
	 *
	 * @param array  $row    One details row.
	 * @param string $metric Which metric this audit explains.
	 * @return array{amount: float|null, unit: string}
	 */
	private function row_amount( $row, $metric ) {
		if ( 'CLS' === $metric && isset( $row['score'] ) && is_numeric( $row['score'] ) ) {
			return array(
				'amount' => (float) $row['score'],
				'unit'   => 'cls',
			);
		}

		foreach ( array( 'duration', 'mainThreadTime', 'wastedMs', 'blockingTime', 'startTime' ) as $key ) {
			if ( isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ) {
				return array(
					'amount' => (float) $row[ $key ],
					'unit'   => 'ms',
				);
			}
		}

		foreach ( array( 'wastedBytes', 'totalBytes', 'transferSize' ) as $key ) {
			if ( isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ) {
				return array(
					'amount' => (float) $row[ $key ],
					'unit'   => 'bytes',
				);
			}
		}

		return array(
			'amount' => null,
			'unit'   => '',
		);
	}

	/**
	 * Everything the lab run can say about *why* a metric is what it is.
	 *
	 * @param array $lhr   Lighthouse result.
	 * @param int   $limit How many to keep.
	 * @return array<int, array>
	 */
	private function extract_diagnoses( $lhr, $limit = 14 ) {
		$audits = isset( $lhr['audits'] ) && is_array( $lhr['audits'] ) ? $lhr['audits'] : array();
		$out    = array();
		$seen   = array();

		foreach ( $this->diagnostic_audits() as $id => $meta ) {
			if ( ! isset( $audits[ $id ] ) ) {
				continue;
			}
			$audit = $audits[ $id ];

			// A passing insight has nothing to explain.
			if ( isset( $audit['score'] ) && is_numeric( $audit['score'] ) && (float) $audit['score'] >= 1 ) {
				continue;
			}

			$title = isset( $audit['title'] ) ? wp_strip_all_tags( (string) $audit['title'] ) : $id;

			foreach ( $this->audit_rows( $audit ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$node   = $this->row_node( $row );
				$causes = $this->row_causes( $row );
				$amount = $this->row_amount( $row, $meta['metric'] );

				$label = $node['label'];
				if ( '' === $label ) {
					foreach ( array( 'entity', 'label', 'url' ) as $key ) {
						if ( ! empty( $row[ $key ] ) && is_string( $row[ $key ] ) ) {
							$label = $this->clip( $row[ $key ], 160 );
							break;
						}
					}
				}

				$evidence = $causes['evidence'];
				if ( '' === $evidence ) {
					$evidence = isset( $row['url'] ) && is_string( $row['url'] ) ? $this->clip( $row['url'], 500 ) : $node['snippet'];
				}

				// "Total" rows and empty rows describe nothing.
				if ( '' === $label && '' === $node['selector'] && '' === $evidence ) {
					continue;
				}
				if ( 'Total' === $label && '' === $node['selector'] ) {
					continue;
				}

				// A shift worth 0.0000 is noise wearing the costume of a
				// finding; CrUX cannot even represent it.
				if ( 'cls' === $amount['unit'] && (float) $amount['amount'] < 0.001 ) {
					continue;
				}

				$key = $meta['metric'] . '|' . $node['selector'] . '|' . $label . '|' . $evidence;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;

				$out[] = array(
					'kind'     => $meta['kind'],
					'metric'   => $meta['metric'],
					'audit'    => $id,
					'title'    => $title,
					'label'    => $label,
					'selector' => $node['selector'],
					'cause'    => $causes['cause'],
					'evidence' => $evidence,
					'amount'   => $amount['amount'],
					'unit'     => $amount['unit'],
				);
			}
		}

		/*
		 * Biggest contribution first, but keep every metric represented: a
		 * page whose CLS rows outrank everything should still say something
		 * about its LCP.
		 */
		usort(
			$out,
			static function ( $a, $b ) {
				$rank = static function ( $row ) {
					if ( 'cls' === $row['unit'] ) {
						return 10000 + (float) $row['amount'] * 1000;
					}
					if ( 'ms' === $row['unit'] ) {
						return (float) $row['amount'];
					}
					if ( 'bytes' === $row['unit'] ) {
						return (float) $row['amount'] / 1024;
					}
					return 0;
				};
				return $rank( $b ) <=> $rank( $a );
			}
		);

		return array_slice( $out, 0, $limit );
	}

	/**
	 * Shorten without splitting a multibyte character.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Characters.
	 * @return string
	 */
	private function clip( $value, $max ) {
		/*
		 * Deliberately not wp_strip_all_tags(): the most useful thing here is
		 * the element's own markup -- <img ... height="62"> is the answer to
		 * "which image shifted" -- and stripping tags leaves an empty string.
		 * It is escaped where it is printed.
		 */
		$value = trim( preg_replace( '/\s+/u', ' ', (string) $value ) );
		if ( '' === $value ) {
			return '';
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}

	private function extract_findings( $lhr, $limit = 5 ) {
		$audits = isset( $lhr['audits'] ) ? $lhr['audits'] : array();
		$items  = array();

		foreach ( $audits as $audit ) {
			if ( ! isset( $audit['details']['type'] ) || 'opportunity' !== $audit['details']['type'] ) {
				continue;
			}
			$saving_ms = isset( $audit['details']['overallSavingsMs'] ) ? (float) $audit['details']['overallSavingsMs'] : 0.0;
			$saving_kb = isset( $audit['details']['overallSavingsBytes'] ) ? ( (float) $audit['details']['overallSavingsBytes'] ) / 1024 : 0.0;
			if ( $saving_ms <= 0 && $saving_kb <= 0 ) {
				continue;
			}

			$evidence = '';
			if ( ! empty( $audit['details']['items'][0]['url'] ) ) {
				$evidence = (string) $audit['details']['items'][0]['url'];
			} elseif ( ! empty( $audit['displayValue'] ) ) {
				$evidence = (string) $audit['displayValue'];
			}

			$items[] = array(
				'title'     => isset( $audit['title'] ) ? wp_strip_all_tags( (string) $audit['title'] ) : '',
				'saving_ms' => (int) round( $saving_ms ),
				'evidence'  => mb_substr( $evidence, 0, 255 ),
				'_rank'     => $saving_ms + $saving_kb,
			);
		}

		usort(
			$items,
			static function ( $a, $b ) {
				return $b['_rank'] <=> $a['_rank'];
			}
		);

		$items = array_slice( $items, 0, $limit );
		foreach ( $items as &$item ) {
			unset( $item['_rank'] );
		}
		unset( $item );

		return $items;
	}
}
