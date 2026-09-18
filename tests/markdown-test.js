/**
 * Tests for the widget's Markdown renderer.
 *
 * Run with: node tests/markdown-test.js
 *
 * Builds a minimal DOM so the renderer can run outside a browser, then checks
 * both the formatting and the safety property that matters: model output is
 * never treated as markup, and only http(s) or relative links become anchors.
 */
function Node( tag ) {
	this.tag = tag;
	this.children = [];
	this.attrs = {};
	this._text = '';
	this.classList = {
		self: this,
		add: function ( c ) { this.self.attrs.class = ( this.self.attrs.class ? this.self.attrs.class + ' ' : '' ) + c; }
	};
	Object.defineProperty( this, 'className', {
		set: function ( v ) { this.attrs.class = v; },
		get: function () { return this.attrs.class || ''; }
	} );
	Object.defineProperty( this, 'textContent', {
		set: function ( v ) { this._text = v; this.children = []; },
		get: function () { return this._text + this.children.map( function ( c ) { return c.textContent; } ).join( '' ); }
	} );
	Object.defineProperty( this, 'href', {
		set: function ( v ) { this.attrs.href = v; }, get: function () { return this.attrs.href; }
	} );
	Object.defineProperty( this, 'rel', {
		set: function ( v ) { this.attrs.rel = v; }, get: function () { return this.attrs.rel; }
	} );
	Object.defineProperty( this, 'lastChild', {
		get: function () { return this.children[ this.children.length - 1 ] || null; }
	} );
	this.appendChild = function ( c ) { this.children.push( c ); return c; };
}
function TextNode( t ) { this.tag = '#text'; this._t = t; this.children = [];
	Object.defineProperty( this, 'textContent', { get: function () { return this._t; } } );
	this.appendChild = function () {}; }

global.document = {
	createElement: function ( t ) { return new Node( t ); },
	createTextNode: function ( t ) { return new TextNode( t ); },
	querySelectorAll: function () { return []; },
	addEventListener: function () {},
	readyState: 'complete'
};
global.window = { wpAiAdvisor: { strings: {} }, addEventListener: function () {} };

function serialize( node ) {
	if ( node.tag === '#text' ) { return node.textContent; }
	var attrs = Object.keys( node.attrs ).filter( function ( k ) { return k !== 'class'; } )
		.map( function ( k ) { return ' ' + k + '="' + node.attrs[ k ] + '"'; } ).join( '' );
	var inner = node._text + node.children.map( serialize ).join( '' );
	return '<' + node.tag + attrs + '>' + inner + '</' + node.tag + '>';
}

// Pull renderMarkdown out of the IIFE by evaluating the file with a hook.
var fs = require( 'fs' );
var src = fs.readFileSync( '/home/user/wp-ai-advisor/assets/js/advisor.js', 'utf8' );
src = src.replace( '} )();', '  global.__renderMarkdown = renderMarkdown;\n} )();' );
eval( src );

var pass = 0, fail = 0;
function check( label, got, want ) {
	var ok = got === want;
	ok ? pass++ : fail++;
	console.log( ( ok ? 'PASS ' : 'FAIL ' ) + label );
	if ( ! ok ) { console.log( '   got:  ' + got + '\n   want: ' + want ); }
}
function render( md ) {
	var box = new Node( 'div' );
	global.__renderMarkdown( md, box );
	return box.children.map( serialize ).join( '' );
}

check( 'bold becomes strong',
	render( '- **JURA WE6**: Ideell for kontoret.' ),
	'<ul><li><strong>JURA WE6</strong>: Ideell for kontoret.</li></ul>' );

check( 'multiple bullets',
	render( '- One\n- Two\n- Three' ),
	'<ul><li>One</li><li>Two</li><li>Three</li></ul>' );

check( 'numbered list',
	render( '1. First\n2. Second' ),
	'<ol><li>First</li><li>Second</li></ol>' );

check( 'paragraph then list',
	render( 'For rundt 10 ansatte:\n\n- **A**: x\n- **B**: y' ),
	'<p>For rundt 10 ansatte:</p><ul><li><strong>A</strong>: x</li><li><strong>B</strong>: y</li></ul>' );

check( 'italic and code',
	render( 'Use *care* with `round()`.' ),
	'<p>Use <em>care</em> with <code>round()</code>.</p>' );

check( 'safe link renders',
	render( 'See [menyen](https://example.test/meny).' ),
	'<p>See <a href="https://example.test/meny" rel="noopener">menyen</a>.</p>' );

check( 'relative link allowed',
	render( '[her](/kontakt)' ),
	'<p><a href="/kontakt" rel="noopener">her</a></p>' );

// The label survives, the href does not: no anchor, no javascript: in the tree.
var js = render( '[klikk](javascript:alert(1))' );
check( 'javascript: link produces no anchor', /<a /.test( js ), false );
check( 'javascript: url never reaches the DOM', /javascript:/.test( js ), false );
check( 'javascript: link keeps its label as text', /klikk/.test( js ), true );

var dataUrl = render( '[x](data:text/html;base64,PHM+)' );
check( 'data: link produces no anchor', /<a /.test( dataUrl ), false );
check( 'data: url never reaches the DOM', /data:/.test( dataUrl ), false );

check( 'raw html is escaped as text',
	render( 'Hei <script>alert(1)</script> da' ),
	'<p>Hei <script>alert(1)</script> da</p>' );

check( 'heading becomes subhead',
	render( '### Priser' ),
	'<p>Priser</p>' );

check( 'single newline becomes a break',
	render( 'Linje 1\nLinje 2' ),
	'<p>Linje 1<br></br>Linje 2</p>' );

check( 'wrapped bullet continuation joins its item',
	render( '- Start of item\n  continued here' ),
	'<ul><li>Start of item continued here</li></ul>' );

check( 'plain answer stays one paragraph',
	render( 'Vi har åpent 08-18.' ),
	'<p>Vi har åpent 08-18.</p>' );

var box = new Node( 'div' );
global.__renderMarkdown( 'Vi har åpent 08-18.', box );
check( 'simple answer is not marked rich', box.className, '' );

box = new Node( 'div' );
global.__renderMarkdown( '- a\n- b', box );
check( 'list answer is marked rich', box.className, 'is-rich' );

console.log( '\n' + pass + ' passed, ' + fail + ' failed' );
process.exit( fail ? 1 : 0 );
