<?php
// Run: php tests/link-editor-test.php
// The part of LinkEditor that rewrites content (no WordPress needed): which links it finds, what unlink and replace do to them.
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../includes/LinkEditor.php';
use WPEasy\HubConnector\LinkEditor as L;

function check( $ok, $what ) { if ( ! $ok ) { fwrite( STDERR, "FAIL: $what\n" ); exit( 1 ); } }
$base = 'https://ex.com/blog/post/';
$url  = 'https://other.com/gone?a=1&b=2';

// A plain link, one written with &amp;, and one that is a different address.
$html = '<p>See <a href="https://other.com/gone?a=1&amp;b=2" class="x">the page</a> and <a href="https://other.com/fine">fine</a>.</p>';
list( $out, $n ) = L::transform( $html, $base, $url, 'unlink' );
check( 1 === $n && '<p>See the page and <a href="https://other.com/fine">fine</a>.</p>' === $out, 'unlink keeps the words and leaves other links' );

list( $out, $n ) = L::transform( $html, $base, $url, 'replace', 'https://new.com/a?x=1&y=2' );
check( 1 === $n && false !== strpos( $out, '<a class="x" href="https://new.com/a?x=1&amp;y=2">the page</a>' ) || false !== strpos( $out, 'href="https://new.com/a?x=1&amp;y=2"' ), 'replace changes only the address' );
check( false !== strpos( $out, 'class="x"' ) && false !== strpos( $out, 'https://other.com/fine' ), 'replace keeps other attributes and links' );

// Written without a scheme: a browser reads it against the page address (the same mistake the Hub's checker saw).
list( $out, $n ) = L::transform( '<a href="shotz.com.au/contact-2">x</a>', 'https://www.shotz.com.au/product-tag/shotzqb/', 'https://www.shotz.com.au/product-tag/shotzqb/shotz.com.au/contact-2', 'replace', 'https://www.shotz.com.au/contact-2' );
check( 1 === $n && false !== strpos( $out, 'href="https://www.shotz.com.au/contact-2"' ), 'a relative link is found by where it points' );

list( $out, $n ) = L::transform( '<a href="../x/">a</a> <a href="/root">b</a> <a href="#top">c</a> <a href="mailto:a@b.c">d</a>', $base, 'https://ex.com/blog/x', 'unlink' );
check( 1 === $n && 0 === strpos( $out, 'a <a href="/root">' ), '../ is followed, anchors and mailto are left alone' );

list( $out, $n ) = L::transform( '<a href="https://other.com/gone?a=1&amp;b=2#frag">t</a>', $base, $url, 'unlink' );
check( 1 === $n && 't' === $out, 'a #fragment does not hide it' );

list( $out, $n ) = L::transform( '<a href="https://nothing.com">t</a>', $base, $url, 'unlink' );
check( 0 === $n && '<a href="https://nothing.com">t</a>' === $out, 'nothing found, nothing changed' );

// Pictures: a plain <img>, and a WordPress picture block (taken out whole).
$pic = 'https://www.eximm.com/files/SERVpng/Old_logo.png';
list( $out, $n ) = L::transform( '<p>Hi</p><img decoding="async" src="' . $pic . '" alt="x" style="width:408px"/><p>Bye</p>', $base, $pic, 'unlink' );
check( 1 === $n && '<p>Hi</p><p>Bye</p>' === $out, 'unlink takes a plain picture out' );
$block = "<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->\n<!-- wp:image {\"id\":5} -->\n<figure class=\"wp-block-image\"><img src=\"$pic\" alt=\"\"/></figure>\n<!-- /wp:image -->\n<!-- wp:paragraph --><p>B</p><!-- /wp:paragraph -->";
list( $out, $n ) = L::transform( $block, $base, $pic, 'unlink' );
check( 1 === $n && false === strpos( $out, 'wp:image' ) && false === strpos( $out, '<figure' ) && false !== strpos( $out, '<p>A</p>' ) && false !== strpos( $out, '<p>B</p>' ), 'a picture block goes whole' );
list( $out, $n ) = L::transform( '<img src="' . $pic . '" srcset="' . $pic . ' 300w" sizes="100vw" alt="x">', $base, $pic, 'replace', 'https://ok.com/new.png' );
check( 1 === $n && false !== strpos( $out, 'src="https://ok.com/new.png"' ) && false === strpos( $out, 'srcset' ) && false !== strpos( $out, 'alt="x"' ), 'replace re-points a picture and drops its old sizes' );
list( $out, $n ) = L::transform( '<img src="https://other.com/a.png"><a href="https://x.com">l</a>', $base, $pic, 'unlink' );
check( 0 === $n, 'another picture is left alone' );

echo "link-editor-test OK\n";
