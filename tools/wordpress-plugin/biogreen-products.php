<?php
/**
 * Plugin Name:  BioGreen Products
 * Description:  Product catalogue for the BioGreen static site. Registers the product type, its fields, and rebuilds the site when a product is saved.
 * Version:      1.8.0
 * Requires PHP: 7.4
 *
 * WordPress is the editor here, never the runtime. The published site is static
 * HTML built from this data; nothing on the live site ever calls this install.
 *
 * Install: drop this folder into wp-content/plugins/ and activate it.
 * No ACF required — every field is registered as post meta and exposed to the
 * REST API, which is what tools/pull-from-wp.py reads.
 *
 * The running version is shown on Settings → BioGreen, so an install can be
 * checked against the file that was sent.
 *
 * Changelog
 * 1.8.0  "מאמרים": the knowledge articles, edited here like products. An
 *        article is a title, the text in the block editor, a category and an
 *        image (both in the sidebar), a sentence for its card and its FAQ;
 *        SEO title, meta description and the page address beside them. The
 *        design, buttons and closing call to action come from the site.
 *        Categories ("מאמרים ← קטגוריות") take a background colour from the
 *        site's tints, an icon and an order. The knowledge page lists every
 *        published article and gives a tab to every category that has one.
 *        On first run it creates the three categories and the first article,
 *        which were in the site's code until now.
 * 1.7.0  A link per item in lists that support one (the glossary terms): its
 *        text, and where it goes, picked from the site's pages and their
 *        sections and terms, or any other address. Until now these links
 *        were fixed in the code and the editor could not see or change them.
 * 1.6.0  Lists of title + text (glossary terms, FAQ, stages, cards) are edited
 *        item by item: each one a row with its own title and text fields,
 *        with buttons to add, delete and reorder, and a search box on long
 *        lists. Before, a whole list was one textarea where blank lines
 *        separated the items. What is saved is unchanged, so the build needs
 *        nothing new. Adds the glossary page.
 * 1.5.1 "תוכן העמודים" opens on a list of pages, each edited on its own
 *        screen. The page-texts endpoint is no longer cached: Hostinger's
 *        LiteSpeed kept it for a week, so saved edits never reached the
 *        build; saving also purges it. Adds the import & regulation page.
 * 1.5.0  "תוכן העמודים": the site's page texts, edited here. The field
 *        list is read from data/cms-schema.json in the repository, so a
 *        text marked editable in the HTML appears without a plugin
 *        update. Saving stores the wording and triggers a build, which
 *        reads it from /wp-json/biogreen/v1/content. Home page first.
 * 1.4.2  The opening-paragraph field no longer says an empty value means no
 *        product page: every product now gets one, a basic page built
 *        from its catalogue data until page copy is written.
 * 1.4.1  Says the site updates within about two minutes, not one: the build
 *        now waits a minute after the last save so a run of saves
 *        publishes once.
 * 1.4.0  A product saved with a Hebrew slug gets product-<ID> automatically,
 *        matching what the build now falls back to. The old warning asked the
 *        editor to type an English slug; it was ignored, and every save
 *        failed the build. It is now a hint, not a requirement.
 * 1.3.2  The tagline field no longer claims to render in the serif face; the
 *        product page now sets it in the same family as its other headings.
 * 1.3.1  Force every editing field right to left. The admin runs in English,
 *        so the fields inherited its direction and put the caret and the
 *        punctuation on the wrong side of Hebrew copy. Also drops the
 *        monospace class from the textareas.
 * 1.3.0  Hero icons are picked from a grid of the twelve icons the site ships,
 *        instead of typing a filename blind, and the alt text fills itself in
 *        from the caption drawn in the icon. The label photo uses WordPress's
 *        own media modal rather than a pasted URL.
 * 1.2.1  A "steps title" field per prose tab, and an [icon-name] prefix for a
 *        highlight box's title. 1.2.0 carried the blocks across but dropped
 *        both of these, so the kosher boxes came back without their badges.
 * 1.2.0  Optional "numbered steps" and "highlight boxes" fields on each prose
 *        tab. Without them a tab's designed blocks flattened into plain prose
 *        once its content moved into WordPress.
 * 1.1.0  Blocking rebuild call that records its result; "Build now" button and
 *        last-build status in settings; red notice on every product screen when
 *        a rebuild failed, with GitHub's response translated into an action.
 * 1.0.2  Warn on the edit screen and product list when a slug is not Latin —
 *        Hebrew titles produce percent-encoded slugs that break the build.
 * 1.0.1  Fix: added 'custom-fields' to supports. Without it WordPress omits the
 *        meta object from REST entirely and none of the fields reach the site.
 * 1.0.0  Product type, taxonomy, 49 fields, rebuild webhook.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const BIOGREEN_VERSION = '1.8.0';

const BIOGREEN_TONES = [
	'natural'    => 'ירוק — טבעי',
	'reg'        => 'כחול — רגולציה',
	'commercial' => 'ענבר — מסחרי',
	'neutral'    => 'אפור — ניטרלי',
	'sand'       => 'חול — מוצר',
];

/**
 * Every field, grouped into the boxes they appear in.
 * type: text | textarea | number | wysiwyg | select | media
 */
function biogreen_fields() {
	$tabs = [
		'description' => 'תיאור',
		'audience'    => 'למי מתאים',
		'ingredients' => 'רכיבים ומידע',
		'kosher'      => 'כשרות ואישורים',
	];

	$page = [
		'page_subtitle' => [ 'תת-כותרת (למשל: 60 כמוסות רכות)', 'text' ],
		'page_tagline'  => [ 'משפט פתיחה (מוצג גדול, מתחת לשם המוצר)', 'text' ],
		'page_lede'     => [ 'פסקת פתיחה — ריק = עמוד בסיסי מנתוני הקטלוג (שם, תמונה, יתרונות וטופס)', 'textarea' ],
	];
	for ( $i = 1; $i <= 6; $i++ ) {
		$page[ "hero_icon_$i" ]     = [ "אייקון $i", 'icon' ];
		$page[ "hero_icon_{$i}_alt" ] = [ "אייקון $i — טקסט חלופי", 'text' ];
	}

	$content = [];
	foreach ( $tabs as $id => $label ) {
		$content[ "tab_{$id}_heading" ] = [ "$label — כותרת", 'text' ];
		$content[ "tab_{$id}_body" ]    = [ "$label — תוכן", 'wysiwyg' ];
		// Two blocks carry design that plain editor prose cannot: numbered
		// cards and highlight boxes. Optional, same blank-line convention.
		$content[ "tab_{$id}_steps_title" ] = [ "$label — כותרת לשלבים (אופציונלי)", 'text' ];
		$content[ "tab_{$id}_steps" ] = [ "$label — שלבים ממוספרים (אופציונלי)", 'textarea' ];
		// A title may open with [icon-name] for the badge icon and/or ! for the
		// amber warning variant, e.g. "[stamp] כשר פרווה" or "! אלרגן".
		$content[ "tab_{$id}_notes" ] = [ "$label — תיבות הדגשה (אופציונלי). כותרת יכולה להתחיל ב-[שם-אייקון] ו/או ב-! לאזהרה", 'textarea' ];
	}
	$content['tab_benefits_heading'] = [ 'יתרונות — כותרת', 'text' ];
	$content['tab_benefits_items']   = [ 'יתרונות — פריט לכל בלוק, שורה ראשונה = כותרת, מופרדים בשורה ריקה', 'textarea' ];
	$content['tab_usage_heading']    = [ 'הוראות שימוש — כותרת', 'text' ];
	$content['tab_usage_body']       = [ 'הוראות שימוש — תוכן', 'wysiwyg' ];
	$content['tab_usage_notice_title']  = [ 'אזהרה — כותרת', 'text' ];
	$content['tab_usage_notice_text']   = [ 'אזהרה — טקסט', 'textarea' ];
	$content['tab_usage_notice_items']  = [ 'אזהרה — רשימה, פריט בכל שורה', 'textarea' ];
	$content['tab_usage_notice_footer'] = [ 'אזהרה — שורת סיום', 'text' ];
	$content['tab_label_heading'] = [ 'תווית — כותרת', 'text' ];
	$content['tab_label_image']   = [ 'תווית — תמונה', 'media' ];
	$content['tab_label_alt']     = [ 'תווית — טקסט חלופי', 'text' ];
	$content['tab_faq_heading']   = [ 'שאלות נפוצות — כותרת', 'text' ];
	$content['tab_faq_items']     = [ 'שאלות — שאלה בשורה הראשונה, תשובה אחריה, מופרדות בשורה ריקה', 'textarea' ];
	$content['quote_heading']     = [ 'טופס — כותרת (אופציונלי)', 'text' ];
	$content['quote_sub']         = [ 'טופס — כותרת משנה (אופציונלי)', 'text' ];

	$catalogue = [
		'image_alt'      => [ 'טקסט חלופי לתמונה', 'text' ],
		'benefit_1'      => [ 'יתרון 1', 'text' ],
		'benefit_2'      => [ 'יתרון 2', 'text' ],
		'benefit_3'      => [ 'יתרון 3', 'text' ],
		'featured_order' => [ 'מיקום בדף הבית (1-3, ריק = לא מופיע)', 'number' ],
	];
	for ( $i = 1; $i <= 3; $i++ ) {
		$catalogue[ "label_{$i}_text" ] = [ "תגית $i — טקסט", 'text' ];
		$catalogue[ "label_{$i}_tone" ] = [ "תגית $i — צבע", 'select' ];
	}

	return [
		'biogreen_catalogue' => [ 'כרטיסיית המוצר', $catalogue ],
		'biogreen_page'      => [ 'עמוד המוצר — ראש העמוד', $page ],
		'biogreen_content'   => [ 'עמוד המוצר — הטאבים', $content ],
	];
}

function biogreen_all_fields() {
	$out = [];
	foreach ( biogreen_fields() as $box ) {
		$out += $box[1];
	}
	return $out;
}

/* ------------------------------------------------------------- post type */

add_action( 'init', function () {
	register_post_type( 'product', [
		'label'        => 'מוצרים',
		'public'       => true,
		'show_in_rest' => true,          // required — no REST API without it
		'rest_base'    => 'product',
		// 'custom-fields' is required: without it WordPress omits the `meta`
		// object from the REST response entirely, and registering the fields
		// below has no visible effect.
		'supports'     => [ 'title', 'thumbnail', 'custom-fields' ],
		'menu_icon'    => 'dashicons-products',
		'has_archive'  => false,
	] );

	register_taxonomy( 'product_category', 'product', [
		'label'        => 'קטגוריות מוצר',
		'hierarchical' => true,
		'show_in_rest' => true,
		'rest_base'    => 'product_category',
	] );

	// Exposing the meta to REST is what lets the build script read it.
	foreach ( biogreen_all_fields() as $key => $def ) {
		register_post_meta( 'product', $key, [
			'type'          => $def[1] === 'number' ? 'integer' : 'string',
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		] );
	}
} );

/* ----------------------------------------------------------------- icons */

/**
 * The product hero icons, with the caption each one carries.
 *
 * These are not interchangeable stock icons: the site ships a fixed set, and
 * every caption is baked into the SVG as vector outlines. That is why the
 * editor picks from this list rather than uploading — an uploaded file would
 * neither match the design nor exist in the repository the site builds from,
 * and the build would reject it.
 *
 * The caption doubles as the default alt text, which is otherwise retyped by
 * hand for every product and is the one thing a screen reader has to go on.
 */
function biogreen_icons() {
	return [
		'01_absorption_pi_185.svg' => 'ספיגה פי 185',
		'02_research_proven.svg'   => 'הוכח מחקרית',
		'03_stability_24h.svg'     => 'יציבות לאורך 24 שעות',
		'04_supergel.svg'          => 'סופטג\'ל',
		'05_kosher.svg'            => 'כשר',
		'06_safe_use.svg'          => 'בטיחות בשימוש',
		'07_made_in_japan.svg'     => 'טכנולוגיה יפנית מקורית',
		'08_sporilife_patent.svg'  => 'רכיב Sporolife® פטנטי',
		'09_night_time.svg'        => 'ניקוי רעלים בזמן השינה',
		'10_100_natural.svg'       => '100% רכיבים טבעיים',
		'11_relief_pain.svg'       => 'הקלה על נפיחות וכאב',
		'12_medical_quality.svg'   => 'דרגת איכות רפואית',
	];
}

/* ----------------------------------------------------------- admin boxes */

add_action( 'add_meta_boxes', function () {
	foreach ( biogreen_fields() as $id => $box ) {
		add_meta_box( $id, $box[0], 'biogreen_render_box', 'product', 'normal', 'high', [ 'id' => $id ] );
	}
} );

// The icon grid and the media picker are the only scripted fields, so this
// loads on the product editor and nowhere else.
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true )
		|| get_post_type() !== 'product' ) {
		return;
	}
	wp_enqueue_media();
	wp_add_inline_style( 'wp-admin', '
		.bg-iconpick { display:grid; gap:8px;
		               grid-template-columns:repeat(auto-fill,minmax(116px,1fr));
		               max-width:760px; }
		.bg-iconpick__opt { display:block; width:100%; cursor:pointer;
		                    background:#fff; border:1px solid #dcdcde;
		                    border-radius:6px; padding:8px 4px; text-align:center;
		                    font:inherit; color:#1d2327; }
		.bg-iconpick__opt:hover { border-color:#8c8f94; }
		.bg-iconpick__opt.is-on { border-color:#1a7f4b; box-shadow:0 0 0 2px #1a7f4b33; }
		.bg-iconpick__opt img { width:100%; height:62px; object-fit:contain; display:block; }
		.bg-iconpick__opt span { display:block; margin-top:4px; font-size:11px;
		                         line-height:1.3; color:#50575e; }
		.bg-iconpick__opt.is-on span { color:#1a7f4b; font-weight:600; }
		.bg-mediapick img { max-width:260px; height:auto; display:block;
		                    border:1px solid #dcdcde; border-radius:6px; margin-bottom:8px; }

		/* Every field here holds Hebrew, whatever language the admin runs in.
		   On an English admin they would otherwise be left to right, which
		   puts the cursor and the punctuation on the wrong side. */
		.bg-fields input[type="text"],
		.bg-fields input[type="url"],
		.bg-fields input[type="number"],
		.bg-fields textarea,
		.bg-fields select { direction:rtl; text-align:right; }
		/* Stated rather than inherited: a textarea does not take the page font
		   on its own, and the browser default for one is monospace. */
		.bg-fields textarea { font-family:inherit; font-size:14px; line-height:1.6; }
		.bg-fields th,
		.bg-fields .description { direction:rtl; text-align:right; }
		/* The editor is an iframe, so its own body has to be told as well. */
		.bg-fields .wp-editor-area { direction:rtl; text-align:right; }
	' );
	wp_add_inline_script( 'jquery-core', <<<'JS'
jQuery(function ($) {
	// Icon grid. Clicking the chosen icon again clears the field, so there is
	// a way back to "no icon" without a separate control.
	$(document).on('click', '.bg-iconpick__opt', function () {
		var $b = $(this), $wrap = $b.closest('.bg-iconpick');
		var $input = $('#' + $wrap.data('for'));
		var on = $b.hasClass('is-on');

		$wrap.find('.bg-iconpick__opt').removeClass('is-on').attr('aria-pressed', 'false');
		$input.val(on ? '' : $b.data('file'));
		if (!on) {
			$b.addClass('is-on').attr('aria-pressed', 'true');

			// Keep the alt in step with the icon. It is replaced when it is
			// empty or still holds some icon's caption, and left alone only
			// once somebody has written their own words — otherwise switching
			// icons would leave the previous caption behind, describing the
			// wrong picture to every screen reader.
			var $alt = $('#' + $wrap.data('for') + '_alt');
			if ($alt.length) {
				var auto = $wrap.find('.bg-iconpick__opt').map(function () {
					return $(this).data('caption');
				}).get();
				if (!$alt.val() || auto.indexOf($alt.val()) !== -1) {
					$alt.val($b.data('caption'));
				}
			}
		}
	});

	// Label photo, through WordPress's own media modal.
	$(document).on('click', '.bg-mediapick__pick', function () {
		var $wrap = $(this).closest('.bg-mediapick');
		var frame = wp.media({
			title: 'בחירת תמונה',
			library: { type: 'image' },
			button: { text: 'השתמשו בתמונה' },
			multiple: false
		});
		frame.on('select', function () {
			var img = frame.state().get('selection').first().toJSON();
			$('#' + $wrap.data('for')).val(img.url);
			$wrap.find('img').attr('src', img.url).prop('hidden', false);
			$wrap.find('.bg-mediapick__clear').prop('hidden', false);
		});
		frame.open();
	});

	$(document).on('click', '.bg-mediapick__clear', function () {
		var $wrap = $(this).closest('.bg-mediapick');
		$('#' + $wrap.data('for')).val('');
		$wrap.find('img').prop('hidden', true).attr('src', '');
		$(this).prop('hidden', true);
	});
});
JS
	);
} );

function biogreen_render_box( $post, $meta ) {
	$boxes = biogreen_fields();
	$fields = $boxes[ $meta['args']['id'] ][1];

	wp_nonce_field( 'biogreen_save', 'biogreen_nonce' );
	echo '<table class="form-table bg-fields"><tbody>';

	foreach ( $fields as $key => $def ) {
		[ $label, $type ] = $def;
		$value = get_post_meta( $post->ID, $key, true );

		echo '<tr><th style="width:260px"><label for="' . esc_attr( $key ) . '">'
			. esc_html( $label ) . '</label></th><td>';

		switch ( $type ) {
			case 'wysiwyg':
				wp_editor( $value, $key, [
					'textarea_name' => $key,
					'textarea_rows' => 10,
					'media_buttons' => false,
					// Everything typed here is Hebrew, whatever language the
					// admin itself is in. Without this the editor inherits the
					// admin's direction and writes the content left to right.
					'tinymce'       => [ 'directionality' => 'rtl' ],
					'quicktags'     => true,
				] );
				break;

			case 'textarea':
				printf(
					// No `code` class: it is monospace, which renders Hebrew
					// badly and buys nothing — these hold prose, not markup.
					'<textarea id="%1$s" name="%1$s" rows="6" class="large-text">%2$s</textarea>',
					esc_attr( $key ), esc_textarea( $value )
				);
				break;

			case 'number':
				printf(
					'<input type="number" id="%1$s" name="%1$s" value="%2$s" min="1" max="3">',
					esc_attr( $key ), esc_attr( $value )
				);
				break;

			case 'select':
				printf( '<select id="%1$s" name="%1$s">', esc_attr( $key ) );
				echo '<option value="">—</option>';
				foreach ( BIOGREEN_TONES as $v => $t ) {
					printf(
						'<option value="%s"%s>%s</option>',
						esc_attr( $v ), selected( $value, $v, false ), esc_html( $t )
					);
				}
				echo '</select>';
				break;

			case 'icon':
				printf(
					'<input type="hidden" id="%1$s" name="%1$s" value="%2$s">
					 <div class="bg-iconpick" data-for="%1$s">',
					esc_attr( $key ), esc_attr( $value )
				);
				foreach ( biogreen_icons() as $file => $caption ) {
					printf(
						'<button type="button" class="bg-iconpick__opt%1$s"
						         data-file="%2$s" data-caption="%3$s"
						         title="%3$s" aria-pressed="%4$s">
						   <img src="%5$s" alt=""><span>%3$s</span>
						 </button>',
						$value === $file ? ' is-on' : '',
						esc_attr( $file ),
						esc_attr( $caption ),
						$value === $file ? 'true' : 'false',
						esc_url( plugins_url( 'icons/' . $file, __FILE__ ) )
					);
				}
				echo '</div><p class="description">
					לחצו על אייקון לבחירה, ושוב כדי לנקות.
					הטקסט החלופי מתמלא לבד ואפשר לשנות אותו.</p>';
				break;

			case 'media':
				printf(
					'<input type="hidden" id="%1$s" name="%1$s" value="%2$s">
					 <div class="bg-mediapick" data-for="%1$s">
					   <img src="%2$s" alt=""%3$s>
					   <p>
					     <button type="button" class="button bg-mediapick__pick">בחרו תמונה מהמדיה</button>
					     <button type="button" class="button-link bg-mediapick__clear"%4$s>הסרה</button>
					   </p>
					 </div>',
					esc_attr( $key ),
					esc_attr( $value ),
					$value ? '' : ' hidden',
					$value ? '' : ' hidden'
				);
				break;

			default:
				printf(
					'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="large-text">',
					esc_attr( $key ), esc_attr( $value )
				);
		}

		echo '</td></tr>';
	}

	echo '</tbody></table>';
}

add_action( 'save_post_product', function ( $post_id ) {
	if ( ! isset( $_POST['biogreen_nonce'] )
		|| ! wp_verify_nonce( sanitize_key( $_POST['biogreen_nonce'] ), 'biogreen_save' )
		|| ! current_user_can( 'edit_post', $post_id )
		|| wp_is_post_autosave( $post_id )
		|| wp_is_post_revision( $post_id ) ) {
		return;
	}

	foreach ( biogreen_all_fields() as $key => $def ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			continue;
		}
		$raw = wp_unslash( $_POST[ $key ] );

		switch ( $def[1] ) {
			case 'wysiwyg':
				$value = wp_kses_post( $raw );
				break;
			case 'textarea':
				$value = sanitize_textarea_field( $raw );
				break;
			case 'number':
				$value = $raw === '' ? '' : (string) max( 1, min( 3, (int) $raw ) );
				break;
			case 'media':
				$value = esc_url_raw( $raw );
				break;
			case 'icon':
				// Only a file the site actually ships. Anything else would pass
				// REST and then fail the build, which is a much later and much
				// less obvious place to find out.
				$value = isset( biogreen_icons()[ $raw ] ) ? $raw : '';
				break;
			case 'select':
				$value = isset( BIOGREEN_TONES[ $raw ] ) ? $raw : '';
				break;
			default:
				$value = sanitize_text_field( $raw );
		}

		$value === '' ? delete_post_meta( $post_id, $key )
			: update_post_meta( $post_id, $key, $value );
	}
}, 10, 1 );

/* ---------------------------------------------------------- automatic slug */

/**
 * Give a product a Latin address when WordPress derived a Hebrew one.
 *
 * Asking the editor to type an English slug did not work: the warning below
 * was there, the product was saved five times anyway, and every save failed
 * the build. So the plugin settles it: a slug that is not plain Latin becomes
 * product-<ID>, which is what the build itself falls back to, so the two
 * always agree. An English slug the editor does write is left alone.
 *
 * Written straight to the row rather than through wp_update_post, which would
 * fire save_post again and send a second rebuild. Runs at priority 5, ahead of
 * the rebuild at 99, so GitHub never sees the Hebrew one.
 */
add_action( 'save_post_product', function ( $post_id, $post ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id )
		|| $post->post_status === 'auto-draft' || ! $post->post_name ) {
		return;
	}
	if ( preg_match( '/^[a-z0-9-]+$/', urldecode( $post->post_name ) ) ) {
		return;
	}

	global $wpdb;
	$wpdb->update( $wpdb->posts, [ 'post_name' => 'product-' . $post_id ],
	               [ 'ID' => $post_id ] );
	clean_post_cache( $post_id );
}, 5, 2 );

/* ------------------------------------------------------------ slug warning */

/**
 * Every product title here is Hebrew, so WordPress derives a Hebrew slug and
 * percent-encodes it — the address would become
 * /products/%d7%aa%d7%95%d7%a1%d7%a3.../ and the build refuses it.
 * Warning at the point of editing beats failing later in CI.
 */
add_action( 'admin_notices', function () {
	$screen = get_current_screen();
	if ( ! $screen || $screen->post_type !== 'product' ) {
		return;
	}

	// Only on the edit screen of one product, and only once its address was
	// assigned for it. Nothing here blocks anything any more — it is a hint.
	if ( $screen->base !== 'post' ) {
		return;
	}
	$post = get_post();
	if ( ! $post || ! preg_match( '/^product-\d+$/', $post->post_name ) ) {
		return;
	}

	printf(
		'<div class="notice notice-info"><p>כתובת העמוד באתר נקבעה אוטומטית: <code>/products/%1$s/</code>.<br>'
		. 'לכתובת ברורה יותר: לחצו <em>Edit</em> ליד הקישור שמתחת לשם המוצר וכתבו שם באנגלית '
		. '(אותיות אנגליות קטנות ומקפים, למשל <code>pineapple-powder</code>). זה לא חובה.</p></div>',
		esc_html( $post->post_name )
	);
} );

/* ------------------------------------------------- rebuild the static site */

/**
 * Ask GitHub to rebuild the static site.
 *
 * Deliberately a blocking request. Fire-and-forget would keep saving a hair
 * faster, but then a dead token or a blocked outbound connection fails in
 * silence: the editor sees "Product updated" and the site never changes. One
 * second of latency buys an honest answer.
 *
 * @return array{ok:bool,message:string}
 */
function biogreen_trigger_build() {
	$repo  = get_option( 'biogreen_repo' );
	$token = get_option( 'biogreen_token' );

	if ( ! $repo || ! $token ) {
		return [ 'ok' => false, 'message' => 'חסרים ריפו או Access Token בהגדרות.' ];
	}

	$res = wp_remote_post( "https://api.github.com/repos/{$repo}/dispatches", [
		'headers' => [
			'Accept'        => 'application/vnd.github+json',
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
			'User-Agent'    => 'biogreen-wp',
		],
		'body'    => wp_json_encode( [ 'event_type' => 'wp-content-updated' ] ),
		'timeout' => 10,
	] );

	if ( is_wp_error( $res ) ) {
		$out = [ 'ok' => false, 'message' => 'לא ניתן להגיע ל-GitHub: ' . $res->get_error_message() ];
	} else {
		$code = (int) wp_remote_retrieve_response_code( $res );
		// GitHub answers 204 No Content on success.
		$errors = [
			401 => 'ה-Access Token שגוי או פג תוקף.',
			403 => 'ל-Token אין הרשאה לריפו. נדרש Contents: Read and write.',
			404 => "הריפו '{$repo}' לא נמצא, או שאין ל-Token גישה אליו.",
			422 => 'GitHub דחה את הבקשה. ודאו ששם הריפו נכון.',
		];
		$out = $code === 204
			? [ 'ok' => true, 'message' => 'הבנייה הופעלה. האתר יתעדכן תוך כשתי דקות.' ]
			: [ 'ok' => false, 'message' => $errors[ $code ] ?? "GitHub החזיר שגיאה {$code}." ];
	}

	update_option( 'biogreen_last_build', [
		'time'    => time(),
		'ok'      => $out['ok'],
		'message' => $out['message'],
	] );

	if ( ! $out['ok'] ) {
		error_log( 'BioGreen rebuild failed: ' . $out['message'] );
	}

	return $out;
}

add_action( 'save_post_product', function ( $post_id, $post ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id )
		|| $post->post_status === 'auto-draft' ) {
		return;
	}
	biogreen_trigger_build();
}, 99, 2 );

/**
 * If the last attempt failed, say so wherever products are edited. Without
 * this the only symptom is a site that quietly stops updating.
 */
add_action( 'admin_notices', function () {
	$screen = get_current_screen();
	if ( ! $screen || $screen->post_type !== 'product' ) {
		return;
	}

	$last = get_option( 'biogreen_last_build' );
	if ( ! $last || ! empty( $last['ok'] ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p><strong>האתר לא התעדכן.</strong> %s<br>
		 הפריטים נשמרו בוורדפרס, אך לא הועברו לאתר.
		 <a href="%s">בדיקה והפעלה ידנית</a></p></div>',
		esc_html( $last['message'] ),
		esc_url( admin_url( 'options-general.php?page=biogreen' ) )
	);
} );

add_action( 'admin_menu', function () {
	add_options_page( 'BioGreen — בנייה', 'BioGreen', 'manage_options', 'biogreen', function () {
		$notice = '';

		if ( isset( $_POST['biogreen_settings_nonce'] )
			&& wp_verify_nonce( sanitize_key( $_POST['biogreen_settings_nonce'] ), 'biogreen_settings' )
			&& current_user_can( 'manage_options' ) ) {

			if ( isset( $_POST['biogreen_build'] ) ) {
				$r = biogreen_trigger_build();
				$notice = sprintf(
					'<div class="notice notice-%s"><p>%s</p></div>',
					$r['ok'] ? 'success' : 'error',
					esc_html( $r['message'] )
				);
			} else {
				update_option( 'biogreen_repo', sanitize_text_field( wp_unslash( $_POST['biogreen_repo'] ?? '' ) ) );
				update_option( 'biogreen_token', sanitize_text_field( wp_unslash( $_POST['biogreen_token'] ?? '' ) ) );
				$notice = '<div class="notice notice-success"><p>נשמר.</p></div>';
			}
		}

		$last = get_option( 'biogreen_last_build' );
		?>
		<div class="wrap">
			<h1>BioGreen — בנייה מחדש של האתר</h1>
			<p style="color:#646970;margin-top:-6px">
				גרסת התוסף <code><?php echo esc_html( BIOGREEN_VERSION ); ?></code>
			</p>
			<?php echo wp_kses_post( $notice ); ?>

			<p>שמירת מוצר מפעילה בנייה של האתר הסטטי. האתר מתעדכן תוך כשתי דקות — הבנייה ממתינה דקה אחרי השמירה האחרונה, כך שכמה שמירות ברצף מתפרסמות יחד.</p>

			<?php if ( $last ) : ?>
				<p>
					<strong>הבנייה האחרונה:</strong>
					<?php
					echo esc_html( wp_date( 'd/m/Y H:i', $last['time'] ) );
					echo empty( $last['ok'] )
						? ' — <span style="color:#b32d2e">נכשלה</span>'
						: ' — <span style="color:#1a7f37">הצליחה</span>';
					?>
					<br><em><?php echo esc_html( $last['message'] ); ?></em>
				</p>
			<?php endif; ?>

			<form method="post" style="margin-bottom:28px">
				<?php wp_nonce_field( 'biogreen_settings', 'biogreen_settings_nonce' ); ?>
				<input type="hidden" name="biogreen_build" value="1">
				<?php submit_button( 'בנה את האתר עכשיו', 'primary', 'submit', false ); ?>
				<p class="description">שימושי כדי לוודא שהחיבור עובד, או אחרי שינוי שלא הפעיל בנייה.</p>
			</form>

			<hr>

			<form method="post">
				<?php wp_nonce_field( 'biogreen_settings', 'biogreen_settings_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="biogreen_repo">ריפו ב-GitHub</label></th>
						<td><input type="text" id="biogreen_repo" name="biogreen_repo" class="regular-text"
							value="<?php echo esc_attr( get_option( 'biogreen_repo', 'goodfellow73/biogreen-site' ) ); ?>">
							<p class="description">owner/repo</p></td>
					</tr>
					<tr>
						<th><label for="biogreen_token">Access Token</label></th>
						<td><input type="password" id="biogreen_token" name="biogreen_token" class="regular-text"
							value="<?php echo esc_attr( get_option( 'biogreen_token', '' ) ); ?>">
							<p class="description">Fine-grained token עם הרשאת Contents: Read and write</p></td>
					</tr>
				</table>
				<?php submit_button( 'שמירת הגדרות' ); ?>
			</form>
		</div>
		<?php
	} );
} );

/* =========================================================================
   Page texts (1.5.0)

   The editable texts of each page are marked in the site's HTML; the build
   lists them in data/cms-schema.json. This screen reads that list from
   GitHub — so a newly marked text appears here without a plugin update —
   shows one field per text, and stores the client's wording. The build reads
   it back from /wp-json/biogreen/v1/content and writes it into the pages.
   ========================================================================= */

const BIOGREEN_CMS_OPTION = 'biogreen_content';

/**
 * The field list, from the repository. Cached for ten minutes; ?refresh=1 on
 * the screen fetches it again at once.
 */
function biogreen_cms_schema( $refresh = false ) {
	if ( ! $refresh ) {
		$cached = get_transient( 'biogreen_cms_schema' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$repo  = get_option( 'biogreen_repo' );
	$token = get_option( 'biogreen_token' );
	if ( ! $repo || ! $token ) {
		return new WP_Error( 'biogreen_cms', 'חסרים ריפו או Access Token בהגדרות (BioGreen).' );
	}

	$res = wp_remote_get( "https://api.github.com/repos/{$repo}/contents/data/cms-schema.json", [
		'headers' => [
			'Accept'        => 'application/vnd.github.raw+json',
			'Authorization' => 'Bearer ' . $token,
			'User-Agent'    => 'biogreen-wp',
		],
		'timeout' => 15,
	] );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'biogreen_cms', 'לא ניתן להגיע ל-GitHub: ' . $res->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( $code !== 200 ) {
		return new WP_Error( 'biogreen_cms', "לא ניתן לקרוא את רשימת השדות מ-GitHub (שגיאה {$code})." );
	}
	$schema = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( ! is_array( $schema ) || empty( $schema['pages'] ) ) {
		return new WP_Error( 'biogreen_cms', 'רשימת השדות שהתקבלה מ-GitHub אינה תקינה.' );
	}

	set_transient( 'biogreen_cms_schema', $schema, 10 * MINUTE_IN_SECONDS );
	return $schema;
}

/** The fields of one page, flattened: key => field. */
function biogreen_cms_page_fields( $schema, $page_id ) {
	foreach ( $schema['pages'] as $page ) {
		if ( $page['id'] !== $page_id ) {
			continue;
		}
		$out = [];
		foreach ( $page['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$out[ $field['key'] ] = $field;
			}
		}
		return $out;
	}
	return [];
}

// The build reads every page's texts from here. Public: they are what the
// public site shows anyway.
add_action( 'rest_api_init', function () {
	register_rest_route( 'biogreen/v1', '/content', [
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function () {
			// Never cached. Hostinger's LiteSpeed kept this for a week and
			// nothing purged it on save, so the build kept reading the first
			// copy and the client's edits never reached the site.
			do_action( 'litespeed_control_set_nocache', 'BioGreen page texts change on every save' );
			$content  = get_option( BIOGREEN_CMS_OPTION, [] );
			// An object even when empty: the build expects pages by id.
			$response = rest_ensure_response( (object) ( is_array( $content ) ? $content : [] ) );
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
			return $response;
		},
	] );
} );

add_action( 'admin_menu', function () {
	add_menu_page( 'תוכן העמודים', 'תוכן העמודים', 'edit_pages', 'biogreen-content',
		'biogreen_cms_render', 'dashicons-edit-page', 21 );
} );

add_action( 'admin_post_biogreen_cms_save', function () {
	if ( ! current_user_can( 'edit_pages' ) ) {
		wp_die( 'אין הרשאה.' );
	}
	check_admin_referer( 'biogreen_cms_save' );

	$page_id = sanitize_key( wp_unslash( $_POST['cms_page'] ?? '' ) );
	$schema  = biogreen_cms_schema();
	$back    = admin_url( 'admin.php?page=biogreen-content&cms_page=' . rawurlencode( $page_id ) );
	if ( is_wp_error( $schema ) ) {
		wp_safe_redirect( add_query_arg( 'cms_error', rawurlencode( $schema->get_error_message() ), $back ) );
		exit;
	}

	$fields = biogreen_cms_page_fields( $schema, $page_id );
	$posted = isset( $_POST['cms'] ) && is_array( $_POST['cms'] ) ? wp_unslash( $_POST['cms'] ) : [];

	$content = get_option( BIOGREEN_CMS_OPTION, [] );
	if ( ! is_array( $content ) ) {
		$content = [];
	}
	$values = [];
	// Only keys the page actually has: anything else in the request is ignored.
	foreach ( $fields as $key => $field ) {
		if ( ! isset( $posted[ $key ] ) ) {
			continue;
		}
		$values[ $key ] = $field['type'] === 'text'
			? sanitize_text_field( $posted[ $key ] )
			: sanitize_textarea_field( $posted[ $key ] );
	}
	$content[ $page_id ] = $values;
	update_option( BIOGREEN_CMS_OPTION, $content, false );

	// When each page was last saved, for the list of pages.
	$saved_at             = get_option( 'biogreen_content_saved', [] );
	$saved_at             = is_array( $saved_at ) ? $saved_at : [];
	$saved_at[ $page_id ] = time();
	update_option( 'biogreen_content_saved', $saved_at, false );

	// And out of any cached copy, in case one was stored before 1.5.1.
	do_action( 'litespeed_purge_url', rest_url( 'biogreen/v1/content' ) );

	$build = biogreen_trigger_build();
	wp_safe_redirect( add_query_arg( [ 'saved' => 1, 'build_ok' => $build['ok'] ? 1 : 0 ], $back ) );
	exit;
} );

function biogreen_cms_render() {
	if ( ! current_user_can( 'edit_pages' ) ) {
		return;
	}
	$schema = biogreen_cms_schema( ! empty( $_GET['refresh'] ) );
	?>
	<div class="wrap bg-cms">
		<h1>תוכן העמודים</h1>
		<?php biogreen_cms_styles(); ?>
		<?php
		if ( ! empty( $_GET['saved'] ) ) {
			$last = get_option( 'biogreen_last_build' );
			if ( ! empty( $_GET['build_ok'] ) ) {
				echo '<div class="notice notice-success"><p>הטקסטים נשמרו. האתר יתעדכן תוך כשתי דקות.</p></div>';
			} else {
				echo '<div class="notice notice-error"><p>הטקסטים נשמרו, אבל הבנייה לא הופעלה: '
					. esc_html( $last['message'] ?? '' ) . '</p></div>';
			}
		}
		if ( ! empty( $_GET['cms_error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( wp_unslash( $_GET['cms_error'] ) ) . '</p></div>';
		}
		if ( is_wp_error( $schema ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $schema->get_error_message() ) . '</p></div></div>';
			return;
		}

		$pages   = $schema['pages'];
		$page_id = sanitize_key( wp_unslash( $_GET['cms_page'] ?? '' ) );
		$page    = null;
		foreach ( $pages as $p ) {
			if ( $p['id'] === $page_id ) {
				$page = $p;
			}
		}

		// No page chosen: the list of pages to pick from.
		if ( ! $page ) {
			biogreen_cms_render_list( $pages );
			echo '</div>';
			return;
		}

		$stored = get_option( BIOGREEN_CMS_OPTION, [] );
		$stored = is_array( $stored ) && isset( $stored[ $page_id ] ) && is_array( $stored[ $page_id ] )
			? $stored[ $page_id ] : [];

		printf( '<p class="bg-cms__back"><a href="%s">→ כל העמודים</a></p><h2 class="bg-cms__page">%s</h2>',
			esc_url( admin_url( 'admin.php?page=biogreen-content' ) ),
			esc_html( $page['label'] ?: $page['id'] ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="biogreen_cms_save">
			<input type="hidden" name="cms_page" value="<?php echo esc_attr( $page_id ); ?>">
			<?php wp_nonce_field( 'biogreen_cms_save' ); ?>

			<?php foreach ( $page['sections'] as $section ) : ?>
				<div class="postbox">
					<h2><?php echo esc_html( $section['label'] ); ?></h2>
					<table class="form-table"><tbody>
					<?php foreach ( $section['fields'] as $field ) :
						$key   = $field['key'];
						$id    = 'cms-' . preg_replace( '/[^a-z0-9_-]/i', '-', $key );
						$name  = 'cms[' . $key . ']';
						$value = array_key_exists( $key, $stored ) && $stored[ $key ] !== '' ? $stored[ $key ] : $field['value'];
						$rows  = [ 'long' => 3, 'para' => 3, 'pairs' => 12 ][ $field['type'] ] ?? 0;
						if ( $field['type'] === 'lines' ) {
							$rows = max( 4, substr_count( $value, "\n" ) + 2 );
						}
						// Title + text lists get the whole row: the script below
						// turns the textarea into one row per item.
						if ( $field['type'] === 'pairs' ) :
							$labels = ! empty( $field['item_labels'] ) ? $field['item_labels'] : [ 'כותרת', 'טקסט' ];
							?>
							<tr>
								<td colspan="2" class="bg-rep-cell">
									<label class="bg-rep__label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
									<div class="bg-rep" data-fixed="<?php echo (int) ( $field['fixed'] ?? 0 ); ?>"
										data-labels="<?php echo esc_attr( implode( '|', $labels ) ); ?>"<?php echo empty( $field['links'] ) ? '' : ' data-links'; ?>>
										<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
											rows="<?php echo (int) $rows; ?>"><?php echo esc_textarea( $value ); ?></textarea>
										<?php if ( ! empty( $field['help'] ) ) : ?>
											<p class="description bg-rep__help"><?php echo esc_html( $field['help'] ); ?></p>
										<?php endif; ?>
									</div>
								</td>
							</tr>
							<?php
							continue;
						endif;
						?>
						<tr>
							<th><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
							<td>
								<?php if ( $field['type'] === 'text' ) : ?>
									<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
										value="<?php echo esc_attr( $value ); ?>">
								<?php else : ?>
									<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
										rows="<?php echo (int) $rows; ?>"><?php echo esc_textarea( $value ); ?></textarea>
								<?php endif; ?>
								<?php if ( ! empty( $field['help'] ) ) : ?>
									<p class="description"><?php echo esc_html( $field['help'] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody></table>
				</div>
			<?php endforeach; ?>

			<div class="bg-cms__bar">
				<?php submit_button( 'שמירה ופרסום', 'primary', 'submit', false ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=biogreen-content&refresh=1&cms_page=' . rawurlencode( $page_id ) ) ); ?>">רענון רשימת השדות</a>
			</div>
		</form>
	</div>
	<?php // The pages a link may point to. wp_json_encode escapes "/", so no "</script>" can close this early. ?>
	<script type="application/json" id="bg-cms-links"><?php echo wp_json_encode( $schema['links'] ?? [] ); ?></script>
	<?php biogreen_cms_repeater_script(); ?>
	<?php
}

/**
 * Title + text lists as one row per item. The textarea stays the form field
 * and keeps the format the build reads (title line, text lines, a blank line
 * between items); this only edits it more comfortably. Without the script
 * the textarea is simply shown.
 *
 * A list marked data-links also gives each item an optional link: its text,
 * and where it goes, picked from the site's pages and their sections (the
 * "links" list in the schema) or typed. It is saved as the item's last line,
 * "@@ text | /url", which tools/cms.py turns into the card's link.
 */
function biogreen_cms_repeater_script() {
	?>
	<script>
	( function () {
		var OTHER = '__other';
		var siteLinks = [];
		try {
			siteLinks = JSON.parse( ( document.getElementById( 'bg-cms-links' ) || {} ).textContent || '[]' );
		} catch ( e ) {}

		function el( tag, attrs, text ) {
			var node = document.createElement( tag );
			Object.keys( attrs || {} ).forEach( function ( k ) { node.setAttribute( k, attrs[ k ] ); } );
			if ( text ) {
				node.textContent = text;
			}
			return node;
		}

		// One link: its text, a pick of the site's pages, and a free address
		// for anything else.
		function linkField( value, onChange ) {
			var wrap  = el( 'div', { 'class': 'bg-rep__link' } );
			var text  = el( 'input', { type: 'text', 'class': 'bg-rep__link-text', placeholder: 'טקסט הקישור, למשל: למסלול יבואן נאות' } );
			var pick  = el( 'select', { 'class': 'bg-rep__link-pick' } );
			var url   = el( 'input', { type: 'text', 'class': 'bg-rep__link-url', dir: 'ltr', placeholder: 'https://…  או  /import-regulation/' } );
			var clear = el( 'button', { type: 'button', 'class': 'button-link bg-rep__link-clear' }, 'הסרת הקישור' );

			pick.appendChild( el( 'option', { value: '' }, 'לאן הקישור מוביל?' ) );
			siteLinks.forEach( function ( page ) {
				var group = el( 'optgroup', { label: page.label } );
				group.appendChild( el( 'option', { value: page.url }, page.label + ' — ראש העמוד' ) );
				( page.anchors || [] ).forEach( function ( a ) {
					group.appendChild( el( 'option', { value: a.url }, page.label + ' › ' + a.label ) );
				} );
				pick.appendChild( group );
			} );
			pick.appendChild( el( 'option', { value: OTHER }, 'כתובת אחרת…' ) );

			text.value = value.text || '';
			if ( value.url ) {
				var known = Array.prototype.some.call( pick.options, function ( o ) { return o.value === value.url; } );
				pick.value = known ? value.url : OTHER;
				url.value  = known ? '' : value.url;
			}

			function sync() {
				url.hidden = pick.value !== OTHER;
				clear.hidden = ! text.value.trim() && ! pick.value;
			}
			text.addEventListener( 'input', function () { sync(); onChange(); } );
			url.addEventListener( 'input', onChange );
			pick.addEventListener( 'change', function () {
				sync();
				if ( pick.value === OTHER ) {
					url.focus();
				}
				onChange();
			} );
			clear.addEventListener( 'click', function () {
				text.value = '';
				pick.value = '';
				url.value  = '';
				sync();
				onChange();
			} );
			sync();

			wrap.appendChild( el( 'span', { 'class': 'bg-rep__link-label' }, 'קישור (לא חובה):' ) );
			wrap.appendChild( text );
			wrap.appendChild( pick );
			wrap.appendChild( url );
			wrap.appendChild( clear );

			return {
				wrap: wrap,
				label: function ( n ) {
					text.setAttribute( 'aria-label', 'טקסט הקישור ' + n );
					pick.setAttribute( 'aria-label', 'לאן מוביל הקישור ' + n );
					url.setAttribute( 'aria-label', 'כתובת הקישור ' + n );
				},
				get: function () {
					return {
						// "|" separates text from address in the saved line.
						text: text.value.replace( /\s+/g, ' ' ).replace( /\|/g, '/' ).trim(),
						url: ( pick.value === OTHER ? url.value : pick.value ).replace( /\s+/g, '' )
					};
				},
				focus: function () {
					( text.value.trim() ? ( pick.value === OTHER ? url : pick ) : text ).focus();
				}
			};
		}

		document.querySelectorAll( '.bg-rep' ).forEach( function ( rep ) {
			var source = rep.querySelector( 'textarea' );
			var help   = rep.querySelector( '.bg-rep__help' );
			var fixed  = parseInt( rep.getAttribute( 'data-fixed' ), 10 ) || 0;
			var links  = rep.hasAttribute( 'data-links' );
			var labels = ( rep.getAttribute( 'data-labels' ) || 'כותרת|טקסט' ).split( '|' );

			function parse( value ) {
				return value.replace( /\r\n/g, '\n' ).trim().split( /\n\s*\n/ ).filter( function ( chunk ) {
					return chunk.trim();
				} ).map( function ( chunk ) {
					var lines = chunk.split( '\n' ).map( function ( l ) { return l.trim(); } );
					var link  = { text: '', url: '' };
					if ( lines.length > 1 && lines[ lines.length - 1 ].indexOf( '@@' ) === 0 ) {
						var body = lines.pop().slice( 2 );
						var bar  = body.lastIndexOf( '|' );
						link = bar < 0 ? { text: body.trim(), url: '' }
							: { text: body.slice( 0, bar ).trim(), url: body.slice( bar + 1 ).trim() };
					}
					return { title: lines[0], text: lines.slice( 1 ).join( '\n' ), link: link };
				} );
			}

			source.hidden = true;
			if ( help ) {
				help.hidden = true;
			}

			var tools  = el( 'div', { 'class': 'bg-rep__tools' } );
			var search = el( 'input', { type: 'search', placeholder: 'חיפוש ' + labels[0] + '…', 'aria-label': 'חיפוש ' + labels[0] } );
			var count  = el( 'span', { 'class': 'bg-rep__count' } );
			var head   = el( 'div', { 'class': 'bg-rep__head', 'aria-hidden': 'true' } );
			var list   = el( 'div', { 'class': 'bg-rep__list' } );
			var add    = el( 'button', { type: 'button', 'class': 'button' }, '+ הוספת ' + labels[0] );
			[ '#', labels[0], labels[1], '' ].forEach( function ( t ) { head.appendChild( el( 'span', {}, t ) ); } );
			tools.appendChild( search );
			tools.appendChild( count );
			rep.appendChild( tools );
			rep.appendChild( head );
			rep.appendChild( list );
			if ( ! fixed ) {
				rep.appendChild( add );
			}

			function titleOf( r ) {
				return r.querySelector( '.bg-rep__title' );
			}
			function textOf( r ) {
				return r.querySelector( '.bg-rep__text' );
			}

			// Back into the textarea on every change, in the build's format.
			// A blank line inside a text would split the item, so it closes up.
			function save() {
				var items = [];
				list.querySelectorAll( '.bg-rep__item' ).forEach( function ( r, i ) {
					r.querySelector( '.bg-rep__num' ).textContent = i + 1;
					titleOf( r ).setAttribute( 'aria-label', labels[0] + ' ' + ( i + 1 ) );
					textOf( r ).setAttribute( 'aria-label', labels[1] + ' ' + ( i + 1 ) );
					var title = titleOf( r ).value.replace( /\s+/g, ' ' ).trim();
					var text  = textOf( r ).value.replace( /\r\n/g, '\n' )
						.split( '\n' ).map( function ( l ) { return l.trim(); } )
						.filter( function ( l ) { return l && l.indexOf( '@@' ) !== 0; } ).join( '\n' );
					var line  = '';
					if ( r.bgLink ) {
						r.bgLink.label( i + 1 );
						var link = r.bgLink.get();
						if ( link.text && link.url ) {
							line = '\n@@ ' + link.text + ' | ' + link.url;
						}
					}
					if ( title || text ) {
						items.push( title + ( text ? '\n' + text : '' ) + line );
					}
				} );
				source.value = items.join( '\n\n' );
				var n = list.children.length;
				count.textContent = fixed ? n + ' פריטים (מספר קבוע)' : n + ' פריטים';
				tools.hidden = n < 8 && ! fixed;
				search.hidden = n < 8;
			}

			function grow( area ) {
				area.style.height = 'auto';
				area.style.height = ( area.scrollHeight + 2 ) + 'px';
			}

			function row( item ) {
				var r     = el( 'div', { 'class': 'bg-rep__item' } );
				var num   = el( 'span', { 'class': 'bg-rep__num' } );
				var title = el( 'input', { type: 'text', 'class': 'bg-rep__title' } );
				var text  = el( 'textarea', { rows: '2', 'class': 'bg-rep__text' } );
				var acts  = el( 'div', { 'class': 'bg-rep__actions' } );
				title.value = item.title;
				text.value  = item.text;
				title.addEventListener( 'input', function () {
					r.classList.remove( 'bg-rep__item--error' );
					save();
				} );
				text.addEventListener( 'input', function () { grow( text ); save(); } );
				r.appendChild( num );
				r.appendChild( title );
				r.appendChild( text );
				r.appendChild( acts );
				if ( links ) {
					r.bgLink = linkField( item.link || {}, function () {
						r.classList.remove( 'bg-rep__item--error' );
						save();
					} );
					r.appendChild( r.bgLink.wrap );
				}

				// The number of a fixed list's items is set by the page's
				// layout: no adding, removing or reordering there.
				if ( ! fixed ) {
					var up   = el( 'button', { type: 'button', 'class': 'button', title: 'למעלה' }, '↑' );
					var down = el( 'button', { type: 'button', 'class': 'button', title: 'למטה' }, '↓' );
					var del  = el( 'button', { type: 'button', 'class': 'button bg-rep__del', title: 'מחיקה' }, 'מחיקה' );
					up.addEventListener( 'click', function () {
						if ( r.previousElementSibling ) {
							list.insertBefore( r, r.previousElementSibling );
							save();
						}
					} );
					down.addEventListener( 'click', function () {
						if ( r.nextElementSibling ) {
							list.insertBefore( r.nextElementSibling, r );
							save();
						}
					} );
					del.addEventListener( 'click', function () {
						var name = title.value.trim() || 'הפריט';
						if ( window.confirm( 'למחוק את "' + name + '"?' ) ) {
							r.remove();
							save();
						}
					} );
					acts.appendChild( up );
					acts.appendChild( down );
					acts.appendChild( del );
				}
				return r;
			}

			parse( source.value ).forEach( function ( item ) { list.appendChild( row( item ) ); } );
			list.querySelectorAll( '.bg-rep__text' ).forEach( grow );
			save();

			add.addEventListener( 'click', function () {
				search.value = '';
				search.dispatchEvent( new Event( 'input' ) );
				var r = row( { title: '', text: '' } );
				r.classList.add( 'bg-rep__item--new' );
				list.appendChild( r );
				save();
				r.scrollIntoView( { block: 'center' } );
				titleOf( r ).focus();
			} );

			search.addEventListener( 'input', function () {
				var q = search.value.trim();
				list.querySelectorAll( '.bg-rep__item' ).forEach( function ( r ) {
					var hay = titleOf( r ).value + ' ' + textOf( r ).value;
					r.hidden = q !== '' && hay.indexOf( q ) === -1;
				} );
			} );

			// What would be saved wrong, or not at all, for one item.
			// A text without a title would have its first line read as the
			// title. In a fixed list every item needs its title: an empty one
			// would shift the rest. A link needs both its text and where it
			// goes, or it is dropped.
			function problem( r ) {
				var t = titleOf( r ).value.trim();
				var x = textOf( r ).value.trim();
				if ( ! t && ( x || fixed > 0 ) ) {
					return { msg: 'חסר ' + labels[0] + ' בפריט המסומן באדום.', focus: function () { titleOf( r ).focus(); } };
				}
				if ( r.bgLink ) {
					var link = r.bgLink.get();
					if ( ( t || x ) && !! link.text !== !! link.url ) {
						return {
							msg: link.text ? 'לקישור בפריט המסומן באדום חסר לאן הוא מוביל.' : 'לקישור בפריט המסומן באדום חסר טקסט.',
							focus: r.bgLink.focus
						};
					}
				}
				return null;
			}

			// The block editor saves its meta boxes without a submit event, so
			// there this check does not run; the rows still save as typed.
			var form = rep.closest( 'form' );
			if ( ! form ) {
				return;
			}
			form.addEventListener( 'submit', function ( e ) {
				var bad = null;
				list.querySelectorAll( '.bg-rep__item' ).forEach( function ( r ) {
					var p = problem( r );
					r.classList.toggle( 'bg-rep__item--error', !! p );
					if ( p && ! bad ) {
						bad = { row: r, p: p };
					}
				} );
				if ( bad ) {
					e.preventDefault();
					search.value = '';
					search.dispatchEvent( new Event( 'input' ) );
					bad.row.scrollIntoView( { block: 'center' } );
					bad.p.focus();
					window.alert( bad.p.msg );
				}
			} );
		} );
	}() );
	</script>
	<?php
}

/**
 * Styles of the "תוכן העמודים" screens, the row-per-item list editor among
 * them, which the article screen uses too. Scoped to .bg-cms.
 */
function biogreen_cms_styles() {
	?>
	<style>
		.bg-cms { direction: rtl; }
		.bg-cms .postbox { max-width: 980px; margin-top: 18px; }
		.bg-cms .postbox h2 { font-size: 15px; padding: 12px 16px; margin: 0; border-bottom: 1px solid #f0f0f1; }
		.bg-cms .form-table th { width: 220px; text-align: right; padding-right: 16px; }
		.bg-cms input[type="text"], .bg-cms textarea {
			width: 100%; direction: rtl; text-align: right; font-family: inherit;
			font-size: 14px; line-height: 1.6;
		}
		.bg-cms .description { direction: rtl; text-align: right; }
		.bg-cms .nav-tab-wrapper { margin-bottom: 4px; }
		.bg-cms .bg-cms__bar { display: flex; gap: 12px; align-items: center; margin-top: 18px; }
		.bg-cms .bg-cms__back { margin: 14px 0 0; }
		.bg-cms .bg-cms__back a { text-decoration: none; }
		.bg-cms .bg-cms__page { font-size: 20px; margin: 4px 0 0; }
		.bg-cms .bg-cms__list { max-width: 980px; margin-top: 12px; }
		.bg-cms .bg-cms__list th, .bg-cms .bg-cms__list td { text-align: right; vertical-align: middle; }
		/* Title + text lists, one row per item */
		.bg-cms .bg-rep-cell { padding: 15px 16px; }
		.bg-cms .bg-rep__label { display: block; font-weight: 600; font-size: 14px; margin-bottom: 10px; }
		.bg-cms .bg-rep__tools { display: flex; gap: 12px; align-items: center; margin-bottom: 10px; }
		.bg-cms .bg-rep__tools input[type="search"] { width: 260px; direction: rtl; }
		.bg-cms .bg-rep__count { color: #646970; }
		.bg-cms .bg-rep__head, .bg-cms .bg-rep__item {
			display: grid; grid-template-columns: 34px minmax(160px, 260px) 1fr auto; gap: 10px; align-items: start;
		}
		.bg-cms .bg-rep__head { font-weight: 600; color: #50575e; padding: 0 10px 6px; }
		.bg-cms .bg-rep__item { padding: 10px; border: 1px solid #dcdcde; border-radius: 4px; background: #fff; margin-bottom: 8px; }
		.bg-cms .bg-rep__item:nth-child(even) { background: #f6f7f7; }
		.bg-cms .bg-rep__item--new { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; }
		.bg-cms .bg-rep__item--error { border-color: #d63638; box-shadow: 0 0 0 1px #d63638; }
		.bg-cms .bg-rep__num { font-weight: 600; color: #646970; padding-top: 6px; text-align: center; }
		.bg-cms .bg-rep__item .bg-rep__title { font-weight: 600; }
		.bg-cms .bg-rep__link { grid-column: 2 / 4; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
		.bg-cms .bg-rep__link-label { color: #50575e; font-size: 13px; }
		.bg-cms .bg-rep__link input.bg-rep__link-text { width: 260px; }
		.bg-cms .bg-rep__link select { max-width: 340px; }
		.bg-cms .bg-rep__link input.bg-rep__link-url { width: 260px; direction: ltr; text-align: left; }
		.bg-cms .bg-rep__link-clear { color: #b32d2e; }
		.bg-cms .bg-rep__item textarea { min-height: 60px; resize: vertical; }
		.bg-cms .bg-rep__actions { display: flex; gap: 4px; }
		.bg-cms .bg-rep__actions .button { min-width: 32px; padding: 0 6px; }
		.bg-cms .bg-rep__actions .bg-rep__del { color: #b32d2e; border-color: #b32d2e; }
		@media (max-width: 782px) {
			.bg-cms .bg-rep__head { display: none; }
			.bg-cms .bg-rep__item { grid-template-columns: 28px 1fr; }
			.bg-cms .bg-rep__item textarea, .bg-cms .bg-rep__actions, .bg-cms .bg-rep__link { grid-column: 2; }
			.bg-cms .bg-rep__link input.bg-rep__link-text, .bg-cms .bg-rep__link input.bg-rep__link-url,
			.bg-cms .bg-rep__link select { width: 100%; max-width: none; }
			.bg-cms .bg-rep__link { order: 1; }
			.bg-cms .bg-rep__actions { order: 2; }
		}
	</style>
	<?php
}

/** The page list "תוכן העמודים" opens on. */
function biogreen_cms_render_list( $pages ) {
	$stored   = get_option( BIOGREEN_CMS_OPTION, [] );
	$saved_at = get_option( 'biogreen_content_saved', [] );
	?>
	<p>בחרו עמוד לעריכה. כל שמירה מתפרסמת באתר תוך כשתי דקות.</p>
	<table class="widefat striped bg-cms__list">
		<thead><tr><th>עמוד</th><th>טקסטים לעריכה</th><th>נשמר לאחרונה</th><th></th></tr></thead>
		<tbody>
		<?php foreach ( $pages as $p ) :
			$count = 0;
			foreach ( $p['sections'] as $section ) {
				$count += count( $section['fields'] );
			}
			$when = is_array( $saved_at ) && ! empty( $saved_at[ $p['id'] ] )
				? wp_date( 'j.n.Y, H:i', (int) $saved_at[ $p['id'] ] )
				: ( is_array( $stored ) && ! empty( $stored[ $p['id'] ] ) ? 'נשמר' : 'עדיין לא נערך' );
			$url  = admin_url( 'admin.php?page=biogreen-content&cms_page=' . rawurlencode( $p['id'] ) );
			?>
			<tr>
				<td><strong><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $p['label'] ?: $p['id'] ); ?></a></strong></td>
				<td><?php echo (int) $count; ?></td>
				<td><?php echo esc_html( $when ); ?></td>
				<td style="text-align:left"><a class="button" href="<?php echo esc_url( $url ); ?>">עריכה</a></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p class="bg-cms__bar"><a href="<?php echo esc_url( admin_url( 'admin.php?page=biogreen-content&refresh=1' ) ); ?>">רענון רשימת העמודים</a></p>
	<?php
}

/* ==========================================================================
   Knowledge articles (1.8.0)

   "מאמרים" in the admin menu: articles in the block editor, in categories
   that carry the colour and icon of their cards on /knowledge/. The build
   reads both through REST (tools/pull-from-wp.py) and writes the pages
   (tools/build-articles.py). The keys of the colours and icons below are the
   ones the build knows; a new one needs adding there too.
   ========================================================================== */

/** Card colours: the site's own tints, nothing else. */
function biogreen_topic_colors() {
	return [
		'sky'        => [ 'תכלת', '#D7EAF2' ],
		'green'      => [ 'ירוק בהיר', '#E7F3EC' ],
		'sand'       => [ 'חולי', '#EFE6D3' ],
		'amber'      => [ 'כתום בהיר', '#FBEEDD' ],
		'eucalyptus' => [ 'אקליפטוס', '#DDEBE2' ],
	];
}

/** Card icons, from the site's sprite: key => [ name, SVG inner markup ]. */
function biogreen_topic_icons() {
	return [
		'file-check' => [ 'מסמכים', '<path d="M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z"></path> <path d="M14 2v5a1 1 0 0 0 1 1h5"></path> <path d="m9 15 2 2 4-4"></path>' ],
		'shield-check' => [ 'מגן', '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"></path> <path d="m9 12 2 2 4-4"></path>' ],
		'flask-conical' => [ 'מבחנה', '<path d="M14 2v6a2 2 0 0 0 .245.96l5.51 10.08A2 2 0 0 1 18 22H6a2 2 0 0 1-1.755-2.96l5.51-10.08A2 2 0 0 0 10 8V2"></path> <path d="M6.453 15h11.094"></path> <path d="M8.5 2h7"></path>' ],
		'stamp' => [ 'חותמת', '<path d="M14 13V8.5C14 7 15 7 15 5a3 3 0 0 0-6 0c0 2 1 2 1 3.5V13"></path> <path d="M20 15.5a2.5 2.5 0 0 0-2.5-2.5h-11A2.5 2.5 0 0 0 4 15.5V17a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1z"></path> <path d="M5 22h14"></path>' ],
		'microscope' => [ 'מיקרוסקופ', '<path d="M6 18h8"></path> <path d="M3 22h18"></path> <path d="M14 22a7 7 0 1 0 0-14h-1"></path> <path d="M9 14h2"></path> <path d="M9 12a2 2 0 0 1-2-2V6h6v4a2 2 0 0 1-2 2Z"></path> <path d="M12 6V3a1 1 0 0 0-1-1H9a1 1 0 0 0-1 1v3"></path>' ],
		'droplets' => [ 'טיפות', '<path d="M7 16.3c2.2 0 4-1.83 4-4.05 0-1.16-.57-2.26-1.71-3.19S7.29 6.75 7 5.3c-.29 1.45-1.14 2.84-2.29 3.76S3 11.1 3 12.25c0 2.22 1.8 4.05 4 4.05z"></path> <path d="M12.56 6.6A10.97 10.97 0 0 0 14 3.02c.5 2.5 2 4.9 4 6.5s3 3.5 3 5.5a6.98 6.98 0 0 1-11.91 4.97"></path>' ],
		'heart-pulse' => [ 'דופק', '<path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5"></path> <path d="M3.22 13H9.5l.5-1 2 4.5 2-7 1.5 3.5h5.27"></path>' ],
		'boxes' => [ 'קופסאות', '<path d="M2.97 12.92A2 2 0 0 0 2 14.63v3.24a2 2 0 0 0 .97 1.71l3 1.8a2 2 0 0 0 2.06 0L12 19v-5.5l-5-3-4.03 2.42Z"></path> <path d="m7 16.5-4.74-2.85"></path> <path d="m7 16.5 5-3"></path> <path d="M7 16.5v5.17"></path> <path d="M12 13.5V19l3.97 2.38a2 2 0 0 0 2.06 0l3-1.8a2 2 0 0 0 .97-1.71v-3.24a2 2 0 0 0-.97-1.71L17 10.5l-5 3Z"></path> <path d="m17 16.5-5-3"></path> <path d="m17 16.5 4.74-2.85"></path> <path d="M17 16.5v5.17"></path> <path d="M7.97 4.42A2 2 0 0 0 7 6.13v4.37l5 3 5-3V6.13a2 2 0 0 0-.97-1.71l-3-1.8a2 2 0 0 0-2.06 0l-3 1.8Z"></path> <path d="M12 8 7.26 5.15"></path> <path d="m12 8 4.74-2.85"></path> <path d="M12 13.5V8"></path>' ],
		'truck' => [ 'משאית', '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"></path> <path d="M15 18H9"></path> <path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"></path> <circle cx="17" cy="18" r="2"></circle> <circle cx="7" cy="18" r="2"></circle>' ],
		'book-open' => [ 'ספר', '<path d="M12 7v14"></path> <path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"></path>' ],
		'handshake' => [ 'לחיצת יד', '<path d="m11 17 2 2a1 1 0 1 0 3-3"></path> <path d="m14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4"></path> <path d="m21 3 1 11h-2"></path> <path d="M3 3 2 14l6.5 6.5a1 1 0 1 0 3-3"></path> <path d="M3 4h8"></path>' ],
		'building-2' => [ 'בניין', '<path d="M10 12h4"></path> <path d="M10 8h4"></path> <path d="M14 21v-3a2 2 0 0 0-4 0v3"></path> <path d="M6 10H4a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-2"></path> <path d="M6 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16"></path>' ],
	];
}

function biogreen_topic_icon_svg( $key, $size = 22 ) {
	$icons = biogreen_topic_icons();
	if ( empty( $icons[ $key ] ) ) {
		return '';
	}
	return sprintf( '<svg width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" '
		. 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%2$s</svg>', $size, $icons[ $key ][1] );
}

/**
 * The article's own fields, below the editor: key => [ label, type, hint, group ].
 * type: text | textarea | lines | pairs | link. group: main (what every
 * article fills in), seo, more (optional: the article works without them).
 * Title, body, category and image are WordPress's own: the title box, the
 * block editor, and the sidebar's category and featured-image panels.
 */
function biogreen_article_fields() {
	return [
		'card_text'       => [ 'משפט לכרטיסייה', 'textarea', 'משפט אחד שמופיע בכרטיסייה של המאמר בעמוד ידע מקצועי.', 'main' ],
		'faq'             => [ 'שאלות נפוצות', 'pairs', 'מהן נבנה אוטומטית ה-Schema של השאלות הנפוצות לגוגל.', 'main' ],
		'seo_title'       => [ 'SEO Title', 'text', 'ריק = כותרת המאמר | ביוגרין.', 'seo' ],
		'seo_description' => [ 'Meta Description', 'textarea', 'תיאור קצר שמופיע בתוצאות החיפוש.', 'seo' ],
		'card_title'      => [ 'כותרת קצרה לכרטיסייה', 'text', 'ריק = כותרת המאמר.', 'more' ],
		'lead'            => [ 'התשובה הקצרה', 'textarea', 'תיבה מודגשת בראש המאמר.', 'more' ],
		'summary'         => [ 'בקצרה', 'lines', 'תיבה בסוף המאמר. נקודה בכל שורה.', 'more' ],
		'cta_title'       => [ 'פנייה בסוף המאמר — כותרת', 'text', 'ריק = הפנייה הקבועה של האתר.', 'more' ],
		'cta_text'        => [ 'פנייה בסוף המאמר — טקסט', 'textarea', '', 'more' ],
		'cta_button'      => [ 'פנייה בסוף המאמר — טקסט הכפתור', 'text', '', 'more' ],
		'cta_link'        => [ 'פנייה בסוף המאמר — לאן הכפתור מוביל', 'link', 'ריק = פותח את טופס הצעת המחיר.', 'more' ],
	];
}

add_action( 'init', function () {
	register_post_type( 'bg_article', [
		'labels'        => [
			'name'           => 'מאמרים',
			'singular_name'  => 'מאמר',
			'menu_name'      => 'מאמרים',
			'all_items'      => 'כל המאמרים',
			'add_new'        => 'מאמר חדש',
			'add_new_item'   => 'מאמר חדש',
			'edit_item'      => 'עריכת מאמר',
			'new_item'       => 'מאמר חדש',
			'search_items'   => 'חיפוש מאמרים',
			'not_found'      => 'אין מאמרים',
			'featured_image' => 'תמונת המאמר',
			'set_featured_image' => 'בחירת תמונה למאמר',
		],
		'public'        => true,
		'show_in_rest'  => true,      // the block editor and the build both need it
		'rest_base'     => 'articles',
		// 'custom-fields' keeps the meta in the REST response (see product).
		'supports'      => [ 'title', 'editor', 'thumbnail', 'custom-fields', 'revisions' ],
		'menu_icon'     => 'dashicons-welcome-learn-more',
		'menu_position' => 21,
		'has_archive'   => false,
		'rewrite'       => [ 'slug' => 'knowledge' ],
	] );

	register_taxonomy( 'article_topic', 'bg_article', [
		'labels'            => [
			'name'          => 'קטגוריות',
			'singular_name' => 'קטגוריה',
			'menu_name'     => 'קטגוריות',
			'all_items'     => 'כל הקטגוריות',
			'add_new_item'  => 'קטגוריה חדשה',
			'edit_item'     => 'עריכת קטגוריה',
			'search_items'  => 'חיפוש קטגוריות',
			'not_found'     => 'אין קטגוריות',
		],
		// Hierarchical only for the editor's checkbox list; a tag box would
		// invite a new category by typo.
		'hierarchical'      => true,
		'show_in_rest'      => true,
		'rest_base'         => 'article_topics',
		'show_admin_column' => true,
	] );

	foreach ( biogreen_article_fields() as $key => $def ) {
		register_post_meta( 'bg_article', $key, [
			'type'          => 'string',
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		] );
	}
	foreach ( [ 'color' => 'string', 'icon' => 'string', 'order' => 'integer' ] as $key => $type ) {
		register_term_meta( 'article_topic', $key, [ 'type' => $type, 'single' => true, 'show_in_rest' => true ] );
	}
} );

// The site shows headings, paragraphs, lists and quotes; nothing else would
// reach it, so nothing else is offered.
add_filter( 'allowed_block_types_all', function ( $allowed, $context ) {
	if ( ! empty( $context->post ) && $context->post->post_type === 'bg_article' ) {
		return [ 'core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/quote' ];
	}
	return $allowed;
}, 10, 2 );

/* ---------------------------------------------------------- the article */

add_action( 'add_meta_boxes_bg_article', function () {
	add_meta_box( 'bg_article_details', 'פרטי המאמר', 'biogreen_article_box', 'bg_article', 'normal', 'high' );
} );

function biogreen_article_box( $post ) {
	$schema = biogreen_cms_schema();
	$links  = is_wp_error( $schema ) ? [] : ( $schema['links'] ?? [] );
	$groups = [ 'main' => [], 'seo' => [], 'more' => [] ];
	foreach ( biogreen_article_fields() as $key => $def ) {
		$groups[ $def[3] ][ $key ] = $def;
	}
	wp_nonce_field( 'bg_article_save', 'bg_article_nonce' );
	biogreen_cms_styles();
	?>
	<style>
		.bg-article-box .form-table th { width: 180px; }
		.bg-article-box h3 { margin: 22px 0 0; font-size: 14px; }
		.bg-article-box .bg-article-help { margin: 4px 0 0; color: #50575e; }
		.bg-article-box details { margin-top: 22px; border-top: 1px solid #dcdcde; padding-top: 12px; }
		.bg-article-box summary { cursor: pointer; font-weight: 600; }
		.bg-article-box .bg-article-slug { display: flex; align-items: center; gap: 4px; direction: ltr; justify-content: flex-end; }
		.bg-article-box .bg-article-slug input { direction: ltr; text-align: left; max-width: 360px; }
	</style>
	<div class="bg-cms bg-article-box">
		<p class="bg-article-help">ממלאים כותרת, טקסט, קטגוריה ותמונה (שתיהן בצד), ולמטה משפט לכרטיסייה ושאלות נפוצות.
			העיצוב, הכפתורים והפנייה בסוף המאמר מתווספים אוטומטית.</p>
		<?php biogreen_article_rows( $post, $groups['main'] ); ?>

		<h3>SEO</h3>
		<table class="form-table"><tbody>
			<tr>
				<th><label for="bg-article-slug">כתובת העמוד</label></th>
				<td>
					<div class="bg-article-slug"><span>/knowledge/</span>
						<input type="text" id="bg-article-slug" name="bg_article[slug]" value="<?php echo esc_attr( urldecode( $post->post_name ) ); ?>" placeholder="import-supplements-israel"><span>/</span></div>
					<p class="description">באנגלית: אותיות קטנות, ספרות ומקפים. ריק או בעברית = article-<?php echo (int) $post->ID; ?>.</p>
				</td>
			</tr>
		</tbody></table>
		<?php biogreen_article_rows( $post, $groups['seo'] ); ?>

		<details>
			<summary>הגדרות נוספות (לא חובה)</summary>
			<?php biogreen_article_rows( $post, $groups['more'] ); ?>
		</details>

		<datalist id="bg-article-pages">
			<?php foreach ( $links as $page ) : ?>
				<option value="<?php echo esc_attr( $page['url'] ); ?>"><?php echo esc_html( $page['label'] ); ?></option>
				<?php foreach ( $page['anchors'] ?? [] as $anchor ) : ?>
					<option value="<?php echo esc_attr( $anchor['url'] ); ?>"><?php echo esc_html( $page['label'] . ' › ' . $anchor['label'] ); ?></option>
				<?php endforeach; ?>
			<?php endforeach; ?>
		</datalist>
	</div>
	<script type="application/json" id="bg-cms-links"><?php echo wp_json_encode( $links ); ?></script>
	<?php
	biogreen_cms_repeater_script();
}

/** One table of article fields. */
function biogreen_article_rows( $post, $fields ) {
	?>
	<table class="form-table"><tbody>
	<?php foreach ( $fields as $key => $def ) :
		[ $label, $type, $hint ] = $def;
		$id    = 'bg-article-' . $key;
		$name  = 'bg_article[' . $key . ']';
		$value = (string) get_post_meta( $post->ID, $key, true );
		if ( $type === 'pairs' ) : ?>
			<tr><td colspan="2" class="bg-rep-cell">
				<label class="bg-rep__label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
				<?php if ( $hint ) : ?><p class="description"><?php echo esc_html( $hint ); ?></p><?php endif; ?>
				<div class="bg-rep" data-fixed="0" data-labels="שאלה|תשובה" data-links>
					<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="8"><?php echo esc_textarea( $value ); ?></textarea>
				</div>
			</td></tr>
			<?php continue;
		endif; ?>
		<tr>
			<th><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php if ( $type === 'text' ) : ?>
					<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
				<?php elseif ( $type === 'link' ) : ?>
					<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>"
						list="bg-article-pages" dir="ltr" style="text-align:left" placeholder="/import-regulation/#feasibility">
				<?php else : ?>
					<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
						rows="<?php echo $type === 'lines' ? 4 : 3; ?>"><?php echo esc_textarea( $value ); ?></textarea>
				<?php endif; ?>
				<?php if ( $hint ) : ?><p class="description"><?php echo esc_html( $hint ); ?></p><?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody></table>
	<?php
}

add_action( 'save_post_bg_article', function ( $post_id ) {
	if ( empty( $_POST['bg_article_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bg_article_nonce'] ) ), 'bg_article_save' )
		|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
		|| ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$in = isset( $_POST['bg_article'] ) && is_array( $_POST['bg_article'] ) ? wp_unslash( $_POST['bg_article'] ) : [];
	foreach ( biogreen_article_fields() as $key => $def ) {
		if ( ! isset( $in[ $key ] ) ) {
			continue;
		}
		$value = in_array( $def[1], [ 'text', 'link' ], true )
			? sanitize_text_field( $in[ $key ] )
			: sanitize_textarea_field( $in[ $key ] );
		update_post_meta( $post_id, $key, $value );
	}
}, 10 );

/**
 * The address: /knowledge/<slug>/. The "כתובת העמוד" field sets it; a slug
 * that is not plain Latin — WordPress makes a Hebrew one from a Hebrew title —
 * becomes article-<ID>, the same rule as products (see there), which the
 * build applies too. Written to the row directly so save_post does not fire
 * again; priority 5, ahead of the rebuild at 99.
 */
add_action( 'save_post_bg_article', function ( $post_id, $post ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || $post->post_status === 'auto-draft' ) {
		return;
	}
	$current = urldecode( $post->post_name );
	$slug    = $current;
	if ( isset( $_POST['bg_article']['slug'], $_POST['bg_article_nonce'] )
		&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bg_article_nonce'] ) ), 'bg_article_save' ) ) {
		$slug = urldecode( sanitize_title( wp_unslash( $_POST['bg_article']['slug'] ) ) );
	}
	if ( ! preg_match( '/^[a-z0-9-]+$/', $slug ) || in_array( $slug, [ 'glossary', 'guide-thanks' ], true ) ) {
		$slug = 'article-' . $post_id;
	}
	if ( $slug === $current ) {
		return;
	}
	// Two articles cannot share an address.
	$taken = get_posts( [ 'post_type' => 'bg_article', 'name' => $slug, 'post_status' => 'any',
		'exclude' => [ $post_id ], 'numberposts' => 1, 'fields' => 'ids' ] );
	if ( $taken ) {
		$slug .= '-' . $post_id;
	}
	global $wpdb;
	$wpdb->update( $wpdb->posts, [ 'post_name' => $slug ], [ 'ID' => $post_id ] );
	clean_post_cache( $post_id );
}, 5, 2 );

/* ------------------------------------------------- rebuild on every change */

add_action( 'save_post_bg_article', function ( $post_id, $post ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || $post->post_status === 'auto-draft' ) {
		return;
	}
	biogreen_trigger_build();
}, 99, 2 );

add_action( 'deleted_post', function ( $post_id, $post ) {
	if ( $post && $post->post_type === 'bg_article' ) {
		biogreen_trigger_build();
	}
}, 10, 2 );

foreach ( [ 'created_article_topic', 'edited_article_topic', 'delete_article_topic' ] as $hook ) {
	add_action( $hook, function () {
		biogreen_trigger_build();
	}, 99 );
}

/* ------------------------------------------------------------ categories */

/** The colour, icon and order controls, for the add and the edit screen. */
function biogreen_topic_controls( $term = null ) {
	$color = ( $term ? get_term_meta( $term->term_id, 'color', true ) : '' ) ?: 'sky';
	$icon  = ( $term ? get_term_meta( $term->term_id, 'icon', true ) : '' ) ?: 'book-open';
	$order = $term ? (int) get_term_meta( $term->term_id, 'order', true ) : 0;
	ob_start();
	?>
	<style>
		.bg-topic-choices { display: flex; flex-wrap: wrap; gap: 8px; direction: rtl; }
		.bg-topic-choice { position: relative; display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px;
			border: 1px solid #dcdcde; border-radius: 6px; background: #fff; cursor: pointer; }
		.bg-topic-choice input { position: absolute; opacity: 0; width: 1px; height: 1px; }
		.bg-topic-choice:has(input:checked) { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; }
		.bg-topic-choice:has(input:focus-visible) { outline: 2px solid #2271b1; outline-offset: 2px; }
		.bg-topic-dot { width: 22px; height: 22px; border-radius: 50%; border: 1px solid rgba(0, 0, 0, .15); }
		.bg-topic-icon { color: #0B8F5A; padding: 8px; }
	</style>
	<?php
	$style = ob_get_clean();

	ob_start();
	echo '<div class="bg-topic-choices" role="radiogroup" aria-label="צבע רקע">';
	foreach ( biogreen_topic_colors() as $key => $c ) {
		printf( '<label class="bg-topic-choice"><input type="radio" name="bg_topic[color]" value="%1$s"%2$s>'
			. '<span class="bg-topic-dot" style="background:%3$s"></span>%4$s</label>',
			esc_attr( $key ), checked( $color, $key, false ), esc_attr( $c[1] ), esc_html( $c[0] ) );
	}
	echo '</div>';
	$colors = ob_get_clean();

	ob_start();
	echo '<div class="bg-topic-choices" role="radiogroup" aria-label="אייקון">';
	foreach ( biogreen_topic_icons() as $key => $i ) {
		printf( '<label class="bg-topic-choice bg-topic-icon" title="%3$s"><input type="radio" name="bg_topic[icon]" value="%1$s"%2$s>'
			. '%4$s<span class="screen-reader-text">%3$s</span></label>',
			esc_attr( $key ), checked( $icon, $key, false ), esc_attr( $i[0] ), biogreen_topic_icon_svg( $key ) );
	}
	echo '</div>';
	$icons = ob_get_clean();

	$order_input = sprintf( '<input type="number" id="bg-topic-order" name="bg_topic[order]" value="%d" min="0" style="width:90px">', $order );
	return [ $style, $colors, $icons, $order_input ];
}

add_action( 'article_topic_add_form_fields', function () {
	[ $style, $colors, $icons, $order ] = biogreen_topic_controls();
	// All four are markup built above from fixed lists and escaped values.
	echo $style; // phpcs:ignore WordPress.Security.EscapeOutput
	?>
	<div class="form-field"><label>צבע רקע לכרטיסיות</label><?php echo $colors; // phpcs:ignore ?></div>
	<div class="form-field"><label>אייקון</label><?php echo $icons; // phpcs:ignore ?></div>
	<div class="form-field"><label for="bg-topic-order">סדר</label><?php echo $order; // phpcs:ignore ?>
		<p>סדר הלשוניות בעמוד ידע מקצועי: מספר קטן קודם. קטגוריה בלי מאמרים לא מוצגת.</p></div>
	<?php
} );

add_action( 'article_topic_edit_form_fields', function ( $term ) {
	[ $style, $colors, $icons, $order ] = biogreen_topic_controls( $term );
	echo $style; // phpcs:ignore WordPress.Security.EscapeOutput
	?>
	<tr class="form-field"><th>צבע רקע לכרטיסיות</th><td><?php echo $colors; // phpcs:ignore ?></td></tr>
	<tr class="form-field"><th>אייקון</th><td><?php echo $icons; // phpcs:ignore ?></td></tr>
	<tr class="form-field"><th><label for="bg-topic-order">סדר</label></th><td><?php echo $order; // phpcs:ignore ?>
		<p class="description">סדר הלשוניות בעמוד ידע מקצועי: מספר קטן קודם. קטגוריה בלי מאמרים לא מוצגת.</p></td></tr>
	<?php
} );

/** WordPress's own term screens check their nonce before these hooks run. */
function biogreen_save_topic( $term_id ) {
	if ( ! current_user_can( 'manage_categories' ) || empty( $_POST['bg_topic'] ) || ! is_array( $_POST['bg_topic'] ) ) {
		return;
	}
	$in = wp_unslash( $_POST['bg_topic'] );
	if ( isset( $in['color'] ) && isset( biogreen_topic_colors()[ $in['color'] ] ) ) {
		update_term_meta( $term_id, 'color', $in['color'] );
	}
	if ( isset( $in['icon'] ) && isset( biogreen_topic_icons()[ $in['icon'] ] ) ) {
		update_term_meta( $term_id, 'icon', $in['icon'] );
	}
	if ( isset( $in['order'] ) ) {
		update_term_meta( $term_id, 'order', max( 0, (int) $in['order'] ) );
	}
}
// Ahead of the rebuild (99), so the build reads the new colour.
add_action( 'created_article_topic', 'biogreen_save_topic', 10 );
add_action( 'edited_article_topic', 'biogreen_save_topic', 10 );

// The category list shows each one's card colour and icon, and its order.
add_filter( 'manage_edit-article_topic_columns', function ( $cols ) {
	$out = [];
	foreach ( $cols as $key => $label ) {
		if ( $key === 'description' ) {
			continue;
		}
		$out[ $key ] = $label;
		if ( $key === 'name' ) {
			$out['bg_card']  = 'כרטיסייה';
			$out['bg_order'] = 'סדר';
		}
	}
	return $out;
} );
add_filter( 'manage_article_topic_custom_column', function ( $content, $column, $term_id ) {
	if ( $column === 'bg_order' ) {
		return (string) (int) get_term_meta( $term_id, 'order', true );
	}
	if ( $column !== 'bg_card' ) {
		return $content;
	}
	$colors = biogreen_topic_colors();
	$hex    = $colors[ get_term_meta( $term_id, 'color', true ) ][1] ?? $colors['sky'][1];
	return sprintf( '<span style="display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:8px;background:%s;color:#0B8F5A">%s</span>',
		esc_attr( $hex ), biogreen_topic_icon_svg( get_term_meta( $term_id, 'icon', true ) ?: 'book-open', 20 ) );
}, 10, 3 );

// The article list can be narrowed to one category.
add_action( 'restrict_manage_posts', function ( $post_type ) {
	if ( $post_type !== 'bg_article' ) {
		return;
	}
	wp_dropdown_categories( [
		'taxonomy'        => 'article_topic',
		'name'            => 'article_topic',
		'value_field'     => 'slug',
		'show_option_all' => 'כל הקטגוריות',
		'hide_empty'      => false,
		'selected'        => sanitize_text_field( wp_unslash( $_GET['article_topic'] ?? '' ) ),
	] );
} );

/* ---------------------------------------------------------- first install */

/**
 * The categories and the first article were built by hand before articles
 * moved here. On the first admin page load of 1.8.0 they are created from
 * seed/articles.json, so nobody retypes them — once: an option records it, a
 * category that exists is left alone, and no article is created where any
 * article already exists.
 */
add_action( 'admin_init', function () {
	if ( get_option( 'biogreen_articles_seeded' ) || ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	update_option( 'biogreen_articles_seeded', BIOGREEN_VERSION, false );

	$file = __DIR__ . '/seed/articles.json';
	$seed = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
	if ( ! is_array( $seed ) ) {
		return;
	}

	foreach ( $seed['topics'] ?? [] as $t ) {
		if ( term_exists( $t['slug'], 'article_topic' ) ) {
			continue;
		}
		$made = wp_insert_term( $t['name'], 'article_topic', [ 'slug' => $t['slug'] ] );
		if ( ! is_wp_error( $made ) ) {
			update_term_meta( $made['term_id'], 'color', $t['color'] );
			update_term_meta( $made['term_id'], 'icon', $t['icon'] );
			update_term_meta( $made['term_id'], 'order', (int) $t['order'] );
		}
	}

	$existing = get_posts( [ 'post_type' => 'bg_article', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ] );
	if ( ! $existing ) {
		foreach ( $seed['articles'] ?? [] as $a ) {
			$id = wp_insert_post( [
				'post_type'    => 'bg_article',
				'post_status'  => 'publish',
				'post_title'   => $a['title'],
				'post_name'    => $a['slug'],
				'post_content' => $a['content'],
				'post_date'    => $a['date'] ?? current_time( 'mysql' ),
				'post_author'  => get_current_user_id(),
			], true );
			if ( is_wp_error( $id ) ) {
				continue;
			}
			foreach ( biogreen_article_fields() as $key => $def ) {
				$from = $key === 'seo_description' ? 'description' : $key;
				if ( isset( $a[ $from ] ) ) {
					update_post_meta( $id, $key, $a[ $from ] );
				}
			}
			wp_set_object_terms( $id, [ $a['topic'] ], 'article_topic' );
		}
	}
	biogreen_trigger_build();
} );
