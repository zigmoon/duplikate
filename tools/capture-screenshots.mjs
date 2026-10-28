/**
 * Captures the README screenshots from a running WordPress.
 *
 * The script logs in, hides the WordPress chrome that is not part of the
 * plugin, and screenshots single elements at deviceScaleFactor 2 so the PNGs
 * stay sharp when GitHub scales them down.
 *
 * Run against the local install:
 *   node tools/capture-screenshots.mjs
 *
 * Environment:
 *   WP_URL       defaults to http://127.0.0.1:8080
 *   WP_USER      defaults to admin
 *   WP_PASSWORD  defaults to AdminLocal!2026
 */

import { chromium } from 'playwright';
import { mkdir } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const docs = join( here, '..', 'docs' );

const base = process.env.WP_URL || 'http://127.0.0.1:8080';
const user = process.env.WP_USER || 'admin';
const password = process.env.WP_PASSWORD || 'AdminLocal!2026';

await mkdir( docs, { recursive: true } );

const browser = await chromium.launch();
const context = await browser.newContext( {
	viewport: { width: 1440, height: 1000 },
	deviceScaleFactor: 2,
	reducedMotion: 'reduce',
} );
const page = await context.newPage();

await page.goto( `${ base }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
await page.fill( '#user_login', user );
await page.fill( '#user_pass', password );
await Promise.all( [
	page.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
	page.click( '#wp-submit' ),
] );

// Chrome that is not the plugin: the admin bar, the sidebar, the footer and
// the privacy banner. Removing it keeps the image about the interface.
async function clean() {
	await page.addStyleTag( {
		content: `
			#wpadminbar, #adminmenumain, #wpfooter, #screen-meta-links,
			.wp-heading-inline + .page-title-action, .update-nag, .notice:not(.dup-notice),
			#wp-pointer-0, #wp-toolbar { display: none !important; }
			#wpcontent { margin-left: 0 !important; padding-left: 0 !important; }
			html.wp-toolbar { padding-top: 0 !important; }
			#wpbody-content { padding-bottom: 0 !important; }
			body { background: #fff !important; }
			* { transition: none !important; animation: none !important; }
		`,
	} );
}

async function shoot( selector, file, { pad = 0, rows = 0 } = {} ) {
	const element = page.locator( selector ).first();
	await element.waitFor( { state: 'visible' } );
	await page.waitForTimeout( 250 );

	if ( rows > 0 ) {
		// Clip to the first N rows: the table can be as long as the site is
		// big, and a 40-row screenshot says nothing more than a 5-row one.
		const box = await page.evaluate( ( count ) => {
			const table = document.querySelector( '.wp-list-table' );
			const rows = [ ...document.querySelectorAll( '#the-list tr' ) ].slice( 0, count );
			const first = table.getBoundingClientRect();
			const last = rows[ rows.length - 1 ].getBoundingClientRect();

			return {
				x: first.x,
				y: first.y,
				width: first.width,
				height: last.bottom - first.y,
			};
		}, rows );

		await page.screenshot( { path: join( docs, file ), clip: box, scale: 'device' } );
		console.log( `  docs/${ file }` );

		return;
	}

	await element.screenshot( {
		path: join( docs, file ),
		scale: 'device',
		...( pad ? { omitBackground: false } : {} ),
	} );

	console.log( `  docs/${ file }` );
}

// 1–3. Settings screen, one image per tab.
await page.goto( `${ base }/wp-admin/options-general.php?page=duplikate`, { waitUntil: 'networkidle' } );
await clean();
await page.evaluate( () => {
	const notice = document.querySelector( '.notice' );
	if ( notice && ! notice.classList.contains( 'dup-notice' ) ) {
		notice.remove();
	}
} );

await shoot( '.dup-admin', 'admin-general.png' );

for ( const [ label, file ] of [
	[ 'What is copied', 'admin-content.png' ],
	[ 'Advanced', 'admin-advanced.png' ],
] ) {
	await page.evaluate( ( text ) => {
		const tab = [ ...document.querySelectorAll( '.dup-tab' ) ].find( ( t ) => t.textContent.trim() === text );
		tab?.click();
	}, label );
	await page.waitForTimeout( 200 );
	await shoot( '.dup-admin', file );
}

// 4. The row action in the posts list.
await page.goto( `${ base }/wp-admin/edit.php`, { waitUntil: 'networkidle' } );
await clean();
await page.evaluate( () => {
	// Show the row actions: they are opacity 0 until hover.
	const style = document.createElement( 'style' );
	style.textContent = '.row-actions { position: static !important; left: auto !important; opacity: 1 !important; }';
	document.head.appendChild( style );
	document.querySelectorAll( '.row-actions' ).forEach( ( r ) => {
		r.style.position = 'static';
		r.style.opacity = '1';
	} );
} );
await shoot( '#the-list tr:first-child', 'list-duplicate.png', { rows: 5 } );

// 5. The bulk action, with the report it produces.
await page.goto( `${ base }/wp-admin/edit.php`, { waitUntil: 'networkidle' } );
await page.evaluate( () => {
	const boxes = [ ...document.querySelectorAll( '#the-list input[name="post[]"]' ) ];
	boxes.slice( 0, 2 ).forEach( ( box ) => {
		box.checked = true;
	} );
	document.querySelector( '#bulk-action-selector-top' ).value = 'duplikate';
	document.querySelector( '#doaction' ).click();
} );
await page.waitForLoadState( 'networkidle' );
await clean();
await shoot( '#wpbody-content .wrap', 'notice-bulk.png' );

await browser.close();
console.log( 'Done.' );