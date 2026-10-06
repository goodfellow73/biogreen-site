<?php
/**
 * Plugin Name:  BioGreen Products
 * Description:  Product catalogue for the BioGreen static site. Registers the product type, its fields, and rebuilds the site when a product is saved.
 * Version:      1.3.1
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

const BIOGREEN_VERSION = '1.3.1';

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
		'page_tagline'  => [ 'משפט פתיחה (מוצג בפונט סריפי)', 'text' ],
		'page_lede'     => [ 'פסקת פתיחה — ריק = לא נוצר עמוד מוצר', 'textarea' ],
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

	$bad = [];

	if ( $screen->base === 'post' ) {
		$post = get_post();
		if ( $post && $post->post_name && ! preg_match( '/^[a-z0-9-]+$/', urldecode( $post->post_name ) ) ) {
			$bad[] = $post;
		}
	} elseif ( $screen->base === 'edit' ) {
		foreach ( get_posts( [ 'post_type' => 'product', 'numberposts' => 100,
		                       'post_status' => [ 'publish', 'draft' ] ] ) as $post ) {
			if ( $post->post_name && ! preg_match( '/^[a-z0-9-]+$/', urldecode( $post->post_name ) ) ) {
				$bad[] = $post;
			}
		}
	}

	if ( ! $bad ) {
		return;
	}

	echo '<div class="notice notice-warning"><p><strong>הכתובת של המוצר צריכה להיות באנגלית.</strong><br>';
	echo 'בסרגל הצד, תחת <em>קישור → מזהה כתובת</em>, יש לכתוב מזהה באותיות אנגליות קטנות ';
	echo 'עם מקפים — למשל <code>curcumin-185</code>. הכתובת באתר תהיה ';
	echo '<code>/products/curcumin-185/</code>. בלי זה הבנייה נעצרת.</p>';

	if ( count( $bad ) > 1 || ( $screen->base === 'edit' ) ) {
		echo '<p>מוצרים שצריך לתקן: ';
		$links = [];
		foreach ( $bad as $post ) {
			$links[] = '<a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">'
				. esc_html( $post->post_title ) . '</a>';
		}
		echo wp_kses_post( implode( ', ', $links ) ) . '</p>';
	}

	echo '</div>';
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
			? [ 'ok' => true, 'message' => 'הבנייה הופעלה. האתר יתעדכן תוך כדקה.' ]
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

			<p>שמירת מוצר מפעילה בנייה של האתר הסטטי. האתר מתעדכן תוך כדקה.</p>

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
