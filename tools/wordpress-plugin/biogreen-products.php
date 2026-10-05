<?php
/**
 * Plugin Name:  BioGreen Products
 * Description:  Product catalogue for the BioGreen static site. Registers the product type, its fields, and rebuilds the site when a product is saved.
 * Version:      1.0.0
 * Requires PHP: 7.4
 *
 * WordPress is the editor here, never the runtime. The published site is static
 * HTML built from this data; nothing on the live site ever calls this install.
 *
 * Install: drop this folder into wp-content/plugins/ and activate it.
 * No ACF required — every field is registered as post meta and exposed to the
 * REST API, which is what tools/pull-from-wp.py reads.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
		$page[ "hero_icon_$i" ]     = [ "אייקון $i — שם קובץ", 'text' ];
		$page[ "hero_icon_{$i}_alt" ] = [ "אייקון $i — טקסט חלופי", 'text' ];
	}

	$content = [];
	foreach ( $tabs as $id => $label ) {
		$content[ "tab_{$id}_heading" ] = [ "$label — כותרת", 'text' ];
		$content[ "tab_{$id}_body" ]    = [ "$label — תוכן", 'wysiwyg' ];
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

/* ----------------------------------------------------------- admin boxes */

add_action( 'add_meta_boxes', function () {
	foreach ( biogreen_fields() as $id => $box ) {
		add_meta_box( $id, $box[0], 'biogreen_render_box', 'product', 'normal', 'high', [ 'id' => $id ] );
	}
} );

function biogreen_render_box( $post, $meta ) {
	$boxes = biogreen_fields();
	$fields = $boxes[ $meta['args']['id'] ][1];

	wp_nonce_field( 'biogreen_save', 'biogreen_nonce' );
	echo '<table class="form-table"><tbody>';

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
				] );
				break;

			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%1$s" rows="6" class="large-text code">%2$s</textarea>',
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

			case 'media':
				printf(
					'<input type="url" id="%1$s" name="%1$s" value="%2$s" class="large-text">
					 <p class="description">הדביקו כתובת קובץ מספריית המדיה</p>',
					esc_attr( $key ), esc_attr( $value )
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

add_action( 'admin_menu', function () {
	add_options_page( 'BioGreen — בנייה', 'BioGreen', 'manage_options', 'biogreen', function () {
		if ( isset( $_POST['biogreen_settings_nonce'] )
			&& wp_verify_nonce( sanitize_key( $_POST['biogreen_settings_nonce'] ), 'biogreen_settings' )
			&& current_user_can( 'manage_options' ) ) {
			update_option( 'biogreen_repo', sanitize_text_field( wp_unslash( $_POST['biogreen_repo'] ?? '' ) ) );
			update_option( 'biogreen_token', sanitize_text_field( wp_unslash( $_POST['biogreen_token'] ?? '' ) ) );
			echo '<div class="notice notice-success"><p>נשמר.</p></div>';
		}
		?>
		<div class="wrap">
			<h1>BioGreen — בנייה מחדש של האתר</h1>
			<p>שמירת מוצר מפעילה בנייה של האתר הסטטי. האתר מתעדכן תוך כדקה.</p>
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
							<p class="description">Fine-grained token עם הרשאת Contents: write</p></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	} );
} );

/**
 * Fire the rebuild. Runs after the save hook above so the meta is already
 * stored, and never blocks the editor — a failure is logged, not shown.
 */
add_action( 'save_post_product', function ( $post_id, $post ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id )
		|| $post->post_status === 'auto-draft' ) {
		return;
	}

	$repo  = get_option( 'biogreen_repo' );
	$token = get_option( 'biogreen_token' );
	if ( ! $repo || ! $token ) {
		return;
	}

	$res = wp_remote_post( "https://api.github.com/repos/{$repo}/dispatches", [
		'headers' => [
			'Accept'        => 'application/vnd.github+json',
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
			'User-Agent'    => 'biogreen-wp',
		],
		'body'     => wp_json_encode( [ 'event_type' => 'wp-content-updated' ] ),
		'timeout'  => 15,
		'blocking' => false,
	] );

	if ( is_wp_error( $res ) ) {
		error_log( 'BioGreen rebuild failed: ' . $res->get_error_message() );
	}
}, 99, 2 );
