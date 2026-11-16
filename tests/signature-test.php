<?php
// Run: php tests/signature-test.php
// Checks the plugin signs exactly as the Hub expects. The same vector is asserted in the Hub's ConnectorTest, so if either side changes, one fails.
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../includes/Signature.php';

$got      = WPEasy\HubConnector\Signature::make( 'test-secret', 'POST', '/api/connector/report', 1700000000, 'abc123', '{"a":1}' );
$expected = '2389917a52d532864ef53a89824a9685ddba48fe93b5a72e45148eee9d1f9bb8';
if ( $got !== $expected ) {
	fwrite( STDERR, "FAIL: signature differs from the Hub's\n got      $got\n expected $expected\n" );
	exit( 1 );
}
echo "ok: signature matches the Hub\n";
