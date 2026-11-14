<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Migration;

use OrchardGrove\HeirloomSeo\Settings\Options;

defined( 'ABSPATH' ) || exit;

final class Yoast extends AbstractMetaSource {

	public function key(): string {
		return 'yoast';
	}

	public function label(): string {
		return 'Yoast SEO';
	}

	protected function detectKeys(): array {
		return [
			'_yoast_wpseo_title',
			'_yoast_wpseo_metadesc',
			'_yoast_wpseo_canonical',
			'_yoast_wpseo_meta-robots-noindex',
			'_yoast_wpseo_opengraph-image',
		];
	}

	public function mapPost( int $post_id ): array {
		$out = [];

		$title = $this->translateVars( $this->meta( $post_id, '_yoast_wpseo_title' ) );
		if ( '' !== $title ) {
			$out['_heirloom_seo_title'] = $title;
		}
		$desc = $this->translateVars( $this->meta( $post_id, '_yoast_wpseo_metadesc' ) );
		if ( '' !== $desc ) {
			$out['_heirloom_seo_desc'] = $desc;
		}
		$canonical = $this->meta( $post_id, '_yoast_wpseo_canonical' );
		if ( '' !== $canonical ) {
			$out['_heirloom_seo_canonical'] = $canonical;
		}
		// Yoast: 0 = post-type default, 1 = noindex, 2 = index (class-wpseo-meta.php, get_robots_noindex()).
		if ( '1' === $this->meta( $post_id, '_yoast_wpseo_meta-robots-noindex' ) ) {
			$out['_heirloom_seo_noindex'] = true;
		}
		if ( '1' === $this->meta( $post_id, '_yoast_wpseo_meta-robots-nofollow' ) ) {
			$out['_heirloom_seo_nofollow'] = true;
		}
		$image = $this->meta( $post_id, '_yoast_wpseo_opengraph-image-id' );
		if ( '' === $image ) {
			$image = $this->meta( $post_id, '_yoast_wpseo_opengraph-image' );
		}
		if ( '' !== $image ) {
			$out['_heirloom_seo_og_image'] = $image;
		}
		$schema = $this->mapArticleType( $this->meta( $post_id, '_yoast_wpseo_schema_article_type' ) );
		if ( '' !== $schema ) {
			$out['_heirloom_seo_schema_type'] = $schema;
		}

		return $out;
	}

	/**
	 * Yoast's site verification codes Heirloom has a setting for: Heirloom's
	 * `verification.*` key => [ Yoast option, field ].
	 */
	private const VERIFICATION = [
		'google'    => [ 'wpseo', 'googleverify' ],
		'bing'      => [ 'wpseo', 'msverify' ],
		'pinterest' => [ 'wpseo_social', 'pinterestverify' ],
		'baidu'     => [ 'wpseo', 'baiduverify' ],
	];

	/** Yoast's site verification codes Heirloom has no setting for: label => `wpseo` field. */
	private const VERIFICATION_UNSUPPORTED = [
		'Yandex' => 'yandexverify',
		'Ahrefs' => 'ahrefsverify',
	];

	/**
	 * Heirloom's `titles.*` key => the `wpseo_titles` template Yoast used for those
	 * pages. The front page and the posts page depend on Settings → Reading; see
	 * titleSources(). Heirloom has one template for all post types other than
	 * pages, and one for all taxonomies: posts and categories set them.
	 */
	private const TITLES = [
		'post'     => 'title-post',
		'page'     => 'title-page',
		'term'     => 'title-tax-category',
		'author'   => 'title-author-wpseo',
		'archive'  => 'title-archive-wpseo',
		'search'   => 'title-search-wpseo',
		'notfound' => 'title-404-wpseo',
	];

	/** Yoast's title variables => Heirloom's. Any other `%%…%%` keeps the template from coming over. */
	private const TITLE_VARS = [
		'%%title%%'            => '%title%',
		'%%sitename%%'         => '%sitename%',
		'%%sitedesc%%'         => '%tagline%',
		'%%sep%%'              => '%sep%',
		'%%page%%'             => '%pagination%', // "– Page 2 of 5" from page 2 on, in both.
		'%%pagenumber%%'       => '%page%',
		'%%term_title%%'       => '%term_title%',
		'%%name%%'             => '%author%',
		'%%searchphrase%%'     => '%search%',
		'%%archive_title%%'    => '%archive_title%',
		'%%category%%'         => '%category%',
		'%%primary_category%%' => '%category%',
		'%%excerpt%%'          => '%excerpt%',
	];

	/**
	 * Yoast's title separator choices => the character Yoast printed. (Yoast's
	 * "sc-lt" prints ">" and "sc-gt" prints "<": its own list has them that way.)
	 */
	private const SEPARATORS = [
		'sc-dash'   => '-',
		'sc-ndash'  => '–',
		'sc-mdash'  => '—',
		'sc-colon'  => ':',
		'sc-middot' => '·',
		'sc-bull'   => '•',
		'sc-star'   => '*',
		'sc-smstar' => '⋆',
		'sc-pipe'   => '|',
		'sc-tilde'  => '~',
		'sc-laquo'  => '«',
		'sc-raquo'  => '»',
		'sc-lt'     => '>',
		'sc-gt'     => '<',
	];

	/**
	 * Carry Yoast's site-wide settings over where Heirloom's default would change
	 * what the site puts out:
	 *
	 * - The feed attribution line. Yoast adds its RSS "before" / "after" text to
	 *   every feed item, Heirloom its own line. A site that cleared both texts in
	 *   Yoast had no line, and Heirloom's default would add one to every item,
	 *   and to every newsletter built from the feed. Set only if Heirloom has
	 *   never stored it, so a choice saved on the settings page stands.
	 * - Site verification codes (Google, Bing, Pinterest, Baidu). Yoast printed
	 *   them on the home page; without them a search console that verified the
	 *   site by its meta tag re-checks, finds no tag and eventually drops the
	 *   site's verified ownership. Each fills only an empty Heirloom field, so a
	 *   code entered in Heirloom stands.
	 * - Title formats and the title separator. Heirloom's defaults differ from
	 *   Yoast's (posts get "by <author>", term archives lose "Archives", the
	 *   separator is an en dash rather than a hyphen), so every title on the site
	 *   changed. Each replaces only a Heirloom value still at its default: saving
	 *   a settings tab stores every field, so a stored default records no choice,
	 *   and an edited one stands. A Yoast template with a variable Heirloom
	 *   cannot print is left out (carryOverReport() names it).
	 *
	 * Run on the first activation and with each Yoast import.
	 *
	 * @param bool $dry_run Report only.
	 * @return string[] The settings changed (would be, on a dry run), as dotted
	 *                  paths: `feed.rss_attribution`, `verification.google`,
	 *                  `general.separator`, `titles.post`, …
	 */
	public static function carryOverSettings( bool $dry_run = false ): array {
		$options = new Options();
		$stored  = $options->raw();
		$changed = [];

		$feed = is_array( $stored['feed'] ?? null ) ? $stored['feed'] : [];
		if ( ! array_key_exists( 'rss_attribution', $feed ) && self::rssTextCleared() ) {
			$feed['rss_attribution'] = false;
			$stored['feed']          = $feed;
			$changed[]               = 'feed.rss_attribution';
		}

		$codes = is_array( $stored['verification'] ?? null ) ? $stored['verification'] : [];
		foreach ( self::verificationCodes() as $key => $code ) {
			if ( ! is_string( $codes[ $key ] ?? null ) || '' === trim( $codes[ $key ] ) ) {
				$codes[ $key ]          = $code;
				$stored['verification'] = $codes;
				$changed[]              = "verification.{$key}";
			}
		}

		$defaults  = $options->defaults();
		$general   = is_array( $stored['general'] ?? null ) ? $stored['general'] : [];
		$separator = self::separator();
		if ( null !== $separator && self::atDefault( $general, 'separator', $defaults['general'] ) && $separator !== ( $general['separator'] ?? $defaults['general']['separator'] ) ) {
			$general['separator'] = $separator;
			$stored['general']    = $general;
			$changed[]            = 'general.separator';
		}

		$titles = is_array( $stored['titles'] ?? null ) ? $stored['titles'] : [];
		foreach ( self::titleTemplates() as $key => $template ) {
			if ( null !== $template && self::atDefault( $titles, $key, $defaults['titles'] ) && $template !== ( $titles[ $key ] ?? $defaults['titles'][ $key ] ) ) {
				$titles[ $key ]   = $template;
				$stored['titles'] = $titles;
				$changed[]        = "titles.{$key}";
			}
		}

		if ( $changed && ! $dry_run ) {
			$options->save( $stored );
		}
		return $changed;
	}

	/**
	 * What carryOverSettings() changed, and what it could not carry over, as
	 * sentences for the import screen and WP-CLI.
	 *
	 * @param string[] $changed carryOverSettings()'s result.
	 * @return array{notes:string[],warnings:string[]}
	 */
	public static function carryOverReport( array $changed, bool $dry_run = false ): array {
		$notes = [];
		if ( in_array( 'feed.rss_attribution', $changed, true ) ) {
			$notes[] = $dry_run
				? __( 'Would turn off the RSS attribution line: Yoast\'s RSS before/after text is empty, so its feed had none.', 'heirloom-seo' )
				: __( 'Turned off the RSS attribution line: Yoast\'s RSS before/after text is empty, so its feed had none.', 'heirloom-seo' );
		}

		$labels = [
			'google'    => 'Google',
			'bing'      => 'Bing',
			'pinterest' => 'Pinterest',
			'baidu'     => 'Baidu',
		];
		$codes  = [];
		foreach ( $labels as $key => $label ) {
			if ( in_array( "verification.{$key}", $changed, true ) ) {
				$codes[] = $label;
			}
		}
		if ( $codes ) {
			/* translators: %s: search engines, e.g. "Google, Bing". */
			$notes[] = sprintf( $dry_run ? __( 'Would carry over Yoast\'s site verification codes: %s.', 'heirloom-seo' ) : __( 'Carried over Yoast\'s site verification codes: %s.', 'heirloom-seo' ), implode( ', ', $codes ) );
		}

		if ( in_array( 'general.separator', $changed, true ) ) {
			/* translators: %s: the separator character, e.g. "-". */
			$notes[] = sprintf( $dry_run ? __( 'Would carry over Yoast\'s title separator (%s).', 'heirloom-seo' ) : __( 'Carried over Yoast\'s title separator (%s).', 'heirloom-seo' ), (string) self::separator() );
		}

		$labels = self::titleLabels();
		$titles = [];
		foreach ( $labels as $key => $label ) {
			if ( in_array( "titles.{$key}", $changed, true ) ) {
				$titles[] = $label;
			}
		}
		if ( $titles ) {
			/* translators: %s: title settings, e.g. "Posts, Pages, Category / tag". */
			$notes[] = sprintf( $dry_run ? __( 'Would carry over Yoast\'s title formats: %s.', 'heirloom-seo' ) : __( 'Carried over Yoast\'s title formats: %s.', 'heirloom-seo' ), implode( ', ', $titles ) );
		}

		$warnings = [];
		$missing  = self::unsupportedVerification();
		if ( $missing ) {
			/* translators: %s: services, e.g. "Yandex, Ahrefs". */
			$warnings[] = sprintf( __( 'Heirloom SEO has no setting for these Yoast site verification codes: %s. Without Yoast their meta tags are gone, so verify the site with those services another way (a DNS record, for example).', 'heirloom-seo' ), implode( ', ', $missing ) );
		}

		// Formats left out for a variable Heirloom can't print, where Heirloom's
		// own is still the default (those titles change from Yoast's).
		$yoast    = get_option( 'wpseo_titles' );
		$options  = new Options();
		$stored   = $options->raw();
		$stored   = is_array( $stored['titles'] ?? null ) ? $stored['titles'] : [];
		$defaults = $options->defaults()['titles'];
		$left_out = [];
		foreach ( is_array( $yoast ) ? self::titleSources() : [] as $key => $source ) {
			$unknown = self::titleTemplate( $yoast[ $source ] ?? null, 'archive' === $key )['unknown'];
			if ( $unknown && ! in_array( "titles.{$key}", $changed, true ) && self::atDefault( $stored, $key, $defaults ) ) {
				$left_out[] = $labels[ $key ] . ' (' . implode( ' ', $unknown ) . ')';
			}
		}
		if ( $left_out ) {
			/* translators: %s: title settings and the Yoast variables they use, e.g. "Posts (%%currentyear%%)". */
			$warnings[] = sprintf( __( 'Heirloom SEO has no variable for some of Yoast\'s title formats, so these keep Heirloom SEO\'s own: %s. Edit them under Titles if they should match.', 'heirloom-seo' ), implode( ', ', $left_out ) );
		}

		// One format covers every taxonomy; categories set it.
		if ( in_array( 'titles.term', $changed, true ) && is_array( $yoast )
			&& is_string( $yoast['title-tax-post_tag'] ?? null ) && '' !== trim( $yoast['title-tax-post_tag'] )
			&& trim( $yoast['title-tax-post_tag'] ) !== trim( (string) ( $yoast['title-tax-category'] ?? '' ) ) ) {
			$warnings[] = __( 'Heirloom SEO has one title format for category and tag archives. Yoast\'s category format was carried over; Yoast had a different one for tags, so tag archive titles change.', 'heirloom-seo' );
		}

		return [
			'notes'    => $notes,
			'warnings' => $warnings,
		];
	}

	/**
	 * Yoast's title formats in Heirloom's variables, by `titles.*` key: null for
	 * one Yoast didn't store, left empty, or wrote with a variable Heirloom
	 * cannot print. Empty without Yoast's settings.
	 *
	 * @return array<string,?string>
	 */
	public static function titleTemplates(): array {
		$yoast = get_option( 'wpseo_titles' );
		if ( ! is_array( $yoast ) ) {
			return [];
		}
		$out = [];
		foreach ( self::titleSources() as $key => $source ) {
			$out[ $key ] = self::titleTemplate( $yoast[ $source ] ?? null, 'archive' === $key )['template'];
		}
		return $out;
	}

	/**
	 * Which Yoast template each Heirloom title came from: TITLES, plus the front
	 * page and the posts page, which follow Settings → Reading. With a static
	 * front page Yoast titles both as pages (title-page; a page's own Yoast
	 * title comes over with the post import and wins in Heirloom too). With
	 * latest posts on the front page it uses its home template, and there is
	 * no posts page.
	 *
	 * @return array<string,string> Heirloom `titles.*` key => `wpseo_titles` key.
	 */
	private static function titleSources(): array {
		$front = 'page' === get_option( 'show_on_front' )
			? [ 'front' => 'title-page', 'home' => 'title-page' ]
			: [ 'front' => 'title-home-wpseo' ];
		return $front + self::TITLES;
	}

	/**
	 * One Yoast template in Heirloom's variables, whitespace collapsed. In the
	 * date archive template `%%date%%` (the period: "November 2026") becomes
	 * %archive_title%, WordPress's archive title ("Month: November 2026"), the
	 * closest Heirloom has; that template also titles post type archives here.
	 *
	 * @return array{template:?string,unknown:string[]} `unknown`: Yoast variables Heirloom has no match for.
	 */
	private static function titleTemplate( mixed $yoast, bool $archive ): array {
		if ( ! is_string( $yoast ) || '' === trim( $yoast ) ) {
			return [
				'template' => null,
				'unknown'  => [],
			];
		}
		$vars = self::TITLE_VARS + ( $archive ? [ '%%date%%' => '%archive_title%' ] : [] );
		$text = trim( (string) preg_replace( '/\s+/', ' ', strtr( $yoast, $vars ) ) );
		preg_match_all( '/%%[^%\s]+%%/', $text, $match );
		$unknown = array_values( array_unique( $match[0] ) );
		return [
			'template' => $unknown ? null : $text,
			'unknown'  => $unknown,
		];
	}

	/** The separator Yoast printed; null without Yoast's settings or for a choice it doesn't list. */
	private static function separator(): ?string {
		$yoast = get_option( 'wpseo_titles' );
		$key   = is_array( $yoast ) ? ( $yoast['separator'] ?? null ) : null;
		return is_string( $key ) ? ( self::SEPARATORS[ $key ] ?? null ) : null;
	}

	/**
	 * Whether a stored Heirloom value is unset or still its default.
	 *
	 * @param array<string,mixed> $stored
	 * @param array<string,mixed> $defaults
	 */
	private static function atDefault( array $stored, string $key, array $defaults ): bool {
		return ! array_key_exists( $key, $stored ) || $stored[ $key ] === ( $defaults[ $key ] ?? null );
	}

	/** @return array<string,string> `titles.*` key => its label on the Titles tab. */
	private static function titleLabels(): array {
		return [
			'front'    => __( 'Front page', 'heirloom-seo' ),
			'home'     => __( 'Posts page', 'heirloom-seo' ),
			'post'     => __( 'Posts', 'heirloom-seo' ),
			'page'     => __( 'Pages', 'heirloom-seo' ),
			'term'     => __( 'Category / tag', 'heirloom-seo' ),
			'author'   => __( 'Author', 'heirloom-seo' ),
			'archive'  => __( 'Archives', 'heirloom-seo' ),
			'search'   => __( 'Search', 'heirloom-seo' ),
			'notfound' => __( '404', 'heirloom-seo' ),
		];
	}

	/**
	 * Yoast's site verification codes, by Heirloom's `verification.*` key. Yoast
	 * stores just the code (it strips a pasted meta tag down to its content);
	 * anything else is skipped rather than printed in a meta tag.
	 *
	 * @return array<string,string>
	 */
	public static function verificationCodes(): array {
		$out = [];
		foreach ( self::VERIFICATION as $key => [ $option, $field ] ) {
			$code = self::verificationCode( $option, $field );
			if ( '' !== $code ) {
				$out[ $key ] = $code;
			}
		}
		return $out;
	}

	/** @return string[] Services Yoast has a verification code for that Heirloom cannot print. */
	public static function unsupportedVerification(): array {
		$out = [];
		foreach ( self::VERIFICATION_UNSUPPORTED as $label => $field ) {
			if ( '' !== self::verificationCode( 'wpseo', $field ) ) {
				$out[] = $label;
			}
		}
		return $out;
	}

	private static function verificationCode( string $option, string $field ): string {
		$values = get_option( $option );
		$code   = is_array( $values ) && is_string( $values[ $field ] ?? null ) ? trim( $values[ $field ] ) : '';
		return 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $code ) ? $code : '';
	}

	/**
	 * Whether Yoast's RSS "before" and "after" texts are both empty, so Yoast
	 * added nothing to feed items. Read where Yoast keeps them: `wpseo_titles`
	 * (rssbefore / rssafter, Yoast 7.0 and later), else the `wpseo_rss` option
	 * that Yoast's 7.0 upgrade moved them out of. A text Yoast never stored
	 * has Yoast's default: no "before", and an "after" line much like Heirloom's.
	 */
	public static function rssTextCleared(): bool {
		foreach ( [ 'wpseo_titles', 'wpseo_rss' ] as $name ) {
			$option = get_option( $name );
			if ( is_array( $option ) && ( array_key_exists( 'rssbefore', $option ) || array_key_exists( 'rssafter', $option ) ) ) {
				return self::blank( $option['rssbefore'] ?? '' ) && self::blank( $option['rssafter'] ?? null );
			}
		}
		return false;
	}

	/** An empty or whitespace-only string: Yoast printed nothing visible for it. */
	private static function blank( mixed $text ): bool {
		return is_string( $text ) && '' === trim( $text );
	}

	/** A post's own Yoast title or description in Heirloom's variables (the same as the site-wide formats). */
	protected function translateVars( string $value ): string {
		return strtr( $value, self::TITLE_VARS + [ '%%tag%%' => '%category%' ] );
	}
}
