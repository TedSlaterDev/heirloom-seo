<?php
declare( strict_types=1 );

namespace OrchardGrove\HeirloomSeo\Tests;

use OrchardGrove\HeirloomSeo\Modules\Schema\SchemaType;

final class SchemaTypeTest extends TestCase {

	public function testChoicesIncludeArticleTypes(): void {
		$choices = SchemaType::choices();
		$this->assertArrayHasKey( 'Article', $choices );
		$this->assertArrayHasKey( 'NewsArticle', $choices );
		$this->assertArrayHasKey( 'BlogPosting', $choices );
	}

	public function testBackedValues(): void {
		$this->assertSame( 'NewsArticle', SchemaType::NewsArticle->value );
		$this->assertSame( 'Article', SchemaType::Article->value );
	}
}
