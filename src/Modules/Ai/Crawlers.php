<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Modules\Ai;

use OrchardGrove\HeirloomSeo\ModuleInterface;
use OrchardGrove\HeirloomSeo\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * AI crawler access control via robots.txt. Adds `Disallow: /` for the AI
 * user-agents the admin chose to block. Like all robots.txt features, this
 * only affects the virtual robots.txt (a physical file would override it).
 */
final class Crawlers implements ModuleInterface {

	/**
	 * Known AI user-agents. key => [ label, ua, note, type ]. `type` is one of
	 * 'training' (blocking opts out of model training, low downside), 'search'
	 * (answer engines — blocking cuts AI-search visibility), or 'user'
	 * (user-triggered fetches — blocking cuts AI referral traffic).
	 *
	 * @var array<string,array{label:string,ua:string,note:string,type:string}>
	 */
	public const BOTS = [
		'gptbot'            => [ 'label' => 'OpenAI GPTBot', 'ua' => 'GPTBot', 'note' => 'OpenAI model-training crawler', 'type' => 'training' ],
		'chatgpt_user'      => [ 'label' => 'OpenAI ChatGPT-User', 'ua' => 'ChatGPT-User', 'note' => 'User-triggered browsing in ChatGPT', 'type' => 'user' ],
		'oai_searchbot'     => [ 'label' => 'OpenAI SearchBot', 'ua' => 'OAI-SearchBot', 'note' => 'ChatGPT search index', 'type' => 'search' ],
		'claudebot'         => [ 'label' => 'Anthropic ClaudeBot', 'ua' => 'ClaudeBot', 'note' => 'Anthropic crawler', 'type' => 'training' ],
		'claude_user'       => [ 'label' => 'Anthropic Claude-User', 'ua' => 'Claude-User', 'note' => 'User-triggered browsing in Claude', 'type' => 'user' ],
		'google_extended'   => [ 'label' => 'Google-Extended', 'ua' => 'Google-Extended', 'note' => 'Gemini/Vertex AI training — does NOT affect Google Search', 'type' => 'training' ],
		'applebot_extended' => [ 'label' => 'Applebot-Extended', 'ua' => 'Applebot-Extended', 'note' => 'Apple AI training — does NOT affect Siri/Spotlight', 'type' => 'training' ],
		'ccbot'             => [ 'label' => 'Common Crawl CCBot', 'ua' => 'CCBot', 'note' => 'Common Crawl — feeds many training datasets', 'type' => 'training' ],
		'perplexitybot'     => [ 'label' => 'PerplexityBot', 'ua' => 'PerplexityBot', 'note' => 'Perplexity index', 'type' => 'search' ],
		'perplexity_user'   => [ 'label' => 'Perplexity-User', 'ua' => 'Perplexity-User', 'note' => 'User-triggered fetch in Perplexity', 'type' => 'user' ],
		'bytespider'        => [ 'label' => 'ByteDance Bytespider', 'ua' => 'Bytespider', 'note' => 'ByteDance / TikTok', 'type' => 'training' ],
		'amazonbot'         => [ 'label' => 'Amazonbot', 'ua' => 'Amazonbot', 'note' => 'Amazon', 'type' => 'training' ],
		'meta_external'     => [ 'label' => 'Meta-ExternalAgent', 'ua' => 'Meta-ExternalAgent', 'note' => 'Meta AI', 'type' => 'training' ],
		'cohere'            => [ 'label' => 'cohere-ai', 'ua' => 'cohere-ai', 'note' => 'Cohere', 'type' => 'training' ],
		'diffbot'           => [ 'label' => 'Diffbot', 'ua' => 'Diffbot', 'note' => 'Diffbot', 'type' => 'training' ],
		'youbot'            => [ 'label' => 'YouBot', 'ua' => 'YouBot', 'note' => 'You.com', 'type' => 'search' ],
	];

	public function __construct( private Options $options ) {}

	public function register(): void {
		add_filter( 'robots_txt', [ $this, 'append' ], 20, 2 );
	}

	public function append( string $output, bool $public ): string {
		$blocked = $this->options->arr( 'ai.blocked_bots' );
		if ( ! $blocked ) {
			return $output;
		}

		$lines = [ '', '# AI crawlers blocked via Heirloom SEO' ];
		foreach ( $blocked as $key ) {
			if ( isset( self::BOTS[ $key ] ) ) {
				$lines[] = 'User-agent: ' . self::BOTS[ $key ]['ua'];
				$lines[] = 'Disallow: /';
				$lines[] = '';
			}
		}

		return $output . implode( "\n", $lines ) . "\n";
	}
}
