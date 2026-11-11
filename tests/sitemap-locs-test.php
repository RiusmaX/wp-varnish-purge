<?php
/**
 * Standalone test for Varnish_Network_Purge::sitemap_locs().
 * Run: php tests/sitemap-locs-test.php (non-zero exit on failure).
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-varnish-network-purge.php';

$ref    = new ReflectionClass( 'Varnish_Network_Purge' );
$obj    = $ref->newInstanceWithoutConstructor();
$method = $ref->getMethod( 'sitemap_locs' );
$method->setAccessible( true );

$cases = array(
	'rank math urlset with image:loc' => array(
		'<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xmlns:xhtml="http://www.w3.org/1999/xhtml">'
		. '<url><loc>https://a.test/</loc><lastmod>2026-01-01</lastmod><image:image><image:loc>https://a.test/1.jpg</image:loc></image:image><image:image><image:loc>https://a.test/2.jpg</image:loc></image:image></url>'
		. '<url><loc>https://a.test/b/</loc><xhtml:link rel="alternate" hreflang="fr" href="https://a.test/fr/b/"/><image:image><image:loc>https://a.test/3.jpg</image:loc></image:image></url></urlset>',
		array( 'https://a.test/', 'https://a.test/b/' ),
	),
	'sitemapindex' => array(
		'<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://a.test/page-sitemap.xml</loc></sitemap><sitemap><loc> https://a.test/post-sitemap.xml </loc></sitemap></sitemapindex>',
		array( 'https://a.test/page-sitemap.xml', 'https://a.test/post-sitemap.xml' ),
	),
	'wordpress native urlset' => array(
		'<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://a.test/x/</loc><lastmod>2026-01-01</lastmod></url><url><loc>https://a.test/y/</loc></url></urlset>',
		array( 'https://a.test/x/', 'https://a.test/y/' ),
	),
	'prefixed namespace' => array(
		'<?xml version="1.0" encoding="UTF-8"?><sm:urlset xmlns:sm="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"><sm:url><sm:loc>https://a.test/p/</sm:loc><image:image><image:loc>https://a.test/p.jpg</image:loc></image:image></sm:url></sm:urlset>',
		array( 'https://a.test/p/' ),
	),
);

$failed = 0;
foreach ( $cases as $name => list( $xml, $expected ) ) {
	$got = $method->invoke( $obj, new SimpleXMLElement( $xml ) );
	if ( $got === $expected ) {
		echo "ok   - $name\n";
	} else {
		$failed++;
		echo "FAIL - $name\n  expected: " . json_encode( $expected ) . "\n  got:      " . json_encode( $got ) . "\n";
	}
}
exit( $failed ? 1 : 0 );
