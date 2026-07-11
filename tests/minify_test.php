<?php

declare(strict_types=1);

use Maildeno\Minify;

T::group('Minify (parity with minify.ts)');

// Collapses inter-tag whitespace in HTML (JS: result not /> \s{2,}</).
$html = Minify::output('html', "<p>  Hello  </p>  \n  <p>World</p>");
T::ok('no 2+ whitespace between tags', \preg_match('/>\s{2,}</', $html) === 0);
T::eq('exact collapsed HTML', '<p> Hello </p><p>World</p>', $html);

// Does not corrupt CSS inside <style> blocks.
$style = Minify::output('html', '<style> @media (max-width: 600px) { .col { width: 100%; } } </style><p>Hi</p>');
T::ok('keeps @media', \str_contains($style, '@media'));
T::ok('keeps media query value', \str_contains($style, 'max-width: 600px'));

// Does not corrupt CSS inside mjml <mj-style> blocks.
$mj = Minify::output('mjml', '<mjml><mj-head><mj-style> .btn { color: red; } </mj-style></mj-head></mjml>');
T::ok('keeps .btn selector', \str_contains($mj, '.btn'));
T::ok('keeps color value', \str_contains($mj, 'color: red'));

// Strips blank lines from react-email output.
$react = Minify::output('react-email', "line1\n\n\n\nline2");
T::ok('no runs of 3+ newlines', \preg_match('/\n{3,}/', $react) === 0);
T::eq('exact react collapse', "line1\n\nline2", $react);

// Unknown target returns source unchanged.
T::eq('unknown target unchanged', '  some content  ', Minify::output('unknown-target', '  some content  '));

// Empty string is returned unchanged for every target.
T::eq('empty html unchanged', '', Minify::output('html', ''));
T::eq('empty react unchanged', '', Minify::output('react-email', ''));
