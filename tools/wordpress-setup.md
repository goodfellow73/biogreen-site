# הקמת וורדפרס לניהול המוצרים

וורדפרס משמש כאן **כממשק עריכה בלבד**. האתר הציבורי לא פונה אליו אף פעם —
הנתונים נמשכים בזמן הבנייה, התמונות יורדות לריפו, וה‑HTML מיוצר מראש.

לכן ההתקנה לא חייבת להיות ציבורית. היא יכולה לשבת על תת‑דומיין עם `noindex`,
ולשמש רק את הלקוחה.

> אם יום אחד תמשוך מוורדפרס בזמן אמת — תאבד את שלושת הדברים שבשבילם האתר סטטי:
> המהירות, האבטחה, וקטלוג שגוגל ומנועי AI יכולים לקרוא.

---

## 1. סוג תוכן מותאם

```php
add_action( 'init', function () {
	register_post_type( 'product', [
		'label'        => 'מוצרים',
		'public'       => true,
		'show_in_rest' => true,          // חובה — בלי זה אין REST API
		'rest_base'    => 'product',
		'supports'     => [ 'title', 'thumbnail', 'custom-fields' ],
		'menu_icon'    => 'dashicons-products',
	] );

	register_taxonomy( 'product_category', 'product', [
		'label'        => 'קטגוריות מוצר',
		'hierarchical' => true,
		'show_in_rest' => true,
		'rest_base'    => 'product_category',
	] );
} );
```

## 2. השדות

הסקריפט קורא גם מ‑ACF וגם מ‑post meta רגיל, אז כל אחד מהם יעבוד.

| שדה בוורדפרס | סוג | מה זה |
|---|---|---|
| כותרת | — | שם המוצר |
| Slug | — | מזהה קבוע. הופך לכתובת `/products/<slug>/` |
| תמונה ראשית | — | תמונת המוצר. **חובה** |
| קטגוריה | טקסונומיה | אחת בלבד לכל מוצר |
| `image_alt` | טקסט | טקסט חלופי. אם ריק — נלקח מהמדיה |
| `benefit_1/2/3` | טקסט | עד שלושה יתרונות |
| `label_1_text` … `label_3_text` | טקסט | תגיות בפינת התמונה |
| `label_1_tone` … `label_3_tone` | טקסט | `natural` \| `reg` \| `commercial` \| `neutral` \| `sand` |
| `featured_order` | מספר | 1/2/3 = מופיע בדף הבית. ריק = לא |

**למה שדות נפרדים ולא שדה חוזר:** שדה חוזר ב‑ACF הוא תכונה בתשלום. יש מקסימום
שלושה יתרונות ושלוש תגיות, אז שלושה שדות פשוטים חוסכים את הרישיון.

**הסתרת מוצר:** להעביר לטיוטה. הסקריפט מושך רק `status=publish`.

## 3. הפעלת הבנייה בשמירה

צריך GitHub Personal Access Token עם הרשאת `repo`, שמור ב‑`wp-config.php`:

```php
define( 'BIOGREEN_GH_TOKEN', 'ghp_xxxxxxxxxxxx' );
```

```php
add_action( 'save_post_product', function ( $post_id, $post ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	wp_remote_post(
		'https://api.github.com/repos/goodfellow73/biogreen-site/dispatches',
		[
			'headers' => [
				'Accept'        => 'application/vnd.github+json',
				'Authorization' => 'Bearer ' . BIOGREEN_GH_TOKEN,
				'Content-Type'  => 'application/json',
				'User-Agent'    => 'biogreen-wp',
			],
			'body'    => wp_json_encode( [ 'event_type' => 'wp-content-updated' ] ),
			'timeout' => 15,
		]
	);
}, 10, 2 );
```

השמירה לא מחכה לבנייה. האתר מתעדכן תוך כדקה.

## 4. סודות בצד GitHub

ב‑`Settings → Secrets and variables → Actions`:

| Secret | ערך |
|---|---|
| `WP_URL` | `https://cms.biogreen.co.il` — בלי לוכסן בסוף |
| `WP_USER` | שם משתמש עם הרשאת עריכה |
| `WP_APP_PASSWORD` | Application Password מפרופיל המשתמש |

השניים האחרונים נחוצים רק אם ה‑REST API סגור לאנונימיים.

---

## בדיקה

```bash
# מקומית, מול ההתקנה
WP_URL=https://cms.biogreen.co.il python tools/pull-from-wp.py
python tools/build-products.py
```

ב‑GitHub אפשר להריץ ידנית: `Actions → Build site from data → Run workflow`,
ולסמן **Pull products from WordPress**.

## מה הסקריפט לא נוגע בו

`data/product-pages/<slug>.json` — תוכן שמונת הטאבים של עמוד המוצר. הוא עדיין
נכתב ידנית. העברה שלו לוורדפרס היא השלב הבא, ודורשת עוד כ‑20 שדות למוצר.

## שתי הגנות מובנות

- אם וורדפרס מחזיר **אפס** מוצרים, הסקריפט נעצר ולא כותב. אחרת תקלה בהגדרות
  הייתה יכולה למחוק את הקטלוג מהאתר החי.
- כל תמונה שמועלית **יורדת לריפו ומוקטנת** ל‑900px ברוחב. התמונה של 4MB
  שהלקוחה תעלה לא תגיע לאתר, וגם לא תיווצר תלות בשרת הוורדפרס.
