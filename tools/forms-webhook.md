# חיבור הטפסים ל-Make

כל הטפסים באתר שולחים **JSON אחד** ב-`POST` לאותו webhook:

```
https://hook.eu1.make.com/8hnp4v23st7q95r8rmaqwq1qq8arabgm
```

הכתובת מוגדרת במקום אחד — `FORMS.endpoint` בתחילת `js/site.js`.

## איך מזהים מאיזה טופס הגיעה הפנייה

כל פנייה נושאת `form_type`. הערכים כיום:

| `form_type` | הטופס |
|---|---|
| `quote_modal` | טופס הצעת המחיר הראשי (נפתח מכל כפתור באתר) |
| `product_inquiry` | הטופס בתחתית עמוד מוצר |

**הוספת טופס חדש בעתיד** (ידע מקצועי, רגולציה, מאמרים) לא דורשת שינוי קוד:
מוסיפים ל-`<form>` את התכונה `data-form-type="…"` וזהו. הערך נשלח כמו שהוא
וב-Make מנתבים לפיו. למשל:

```html
<form data-form-type="knowledge_article" novalidate> … </form>
```

## שמות השדות

### נשלחים תמיד

| שדה | סוג | הערה |
|---|---|---|
| `form_type` | טקסט | ראו הטבלה למעלה |
| `newsletter_consent` | **true / false** | תמיד נשלח, גם כשלא סומן |
| `page_url` | טקסט | הכתובת המלאה שממנה נשלח |
| `submitted_at` | טקסט | ISO 8601, ‎UTC‎ (למשל `2026-10-06T07:40:14.280Z`) |

### `quote_modal`

| שדה | חובה | ערכים |
|---|---|---|
| `intent` | ✓ | `import` / `products` / `raw` |
| `product` | ✓ | שם המוצר או חומר הגלם |
| `product_link` | | קישור למוצר או לאתר הספק |
| `company` | ✓ | |
| `name` | ✓ | איש קשר |
| `phone` | ✓ | |
| `email` | ✓ | |
| `notes` | | כמות, לוחות זמנים, דרישות |

שדה אחד נוסף, **לפי ה-`intent` שנבחר**. נשלח רק זה שמתאים, כי רק הוא הוצג:

| `intent` | השדה | ערכים |
|---|---|---|
| `import` | `supplier_status` | `yes` / `no` / `checking` |
| `products` | `looking_for` | טקסט חופשי |
| `raw` | `raw_material` | טקסט חופשי |

### `product_inquiry`

| שדה | חובה |
|---|---|
| `product` | ✓ (שם המוצר, אוטומטי מהעמוד) |
| `name` | ✓ |
| `company` | |
| `phone` | ✓ |
| `email` | ✓ |
| `quantity` | |
| `notes` | |

## דוגמה

```json
{
  "form_type": "quote_modal",
  "intent": "import",
  "product": "כורכומין בספיגה גבוהה",
  "product_link": "https://example.com/x",
  "company": "בדיקה בעמ",
  "name": "ישראל ישראלי",
  "phone": "050-1234567",
  "email": "test@example.com",
  "supplier_status": "checking",
  "notes": "בדיקת חיווט",
  "newsletter_consent": true,
  "page_url": "https://goodfellow73.github.io/biogreen-site/",
  "submitted_at": "2026-10-06T07:40:14.280Z"
}
```

## שתי נקודות למיפוי ב-Make

**שדה שלא הוצג לא נשלח.** בטופס הראשי נשלח רק אחד מתוך
`supplier_status` / `looking_for` / `raw_material`, לפי ה-`intent`. ב-Make כדאי
להתייחס לשלושתם כאופציונליים — תשובה לשאלה שלא הוצגה אינה תשובה.

**`newsletter_consent` הוא בוליאני אמיתי**, לא `"yes"` ולא מחרוזת ריקה, והוא
נשלח תמיד. אפשר לנתב עליו ישירות.

## כשהשליחה נכשלת

הטופס **לא** מציג "תודה". הוא נשאר על המסך עם כל מה שהוקלד, מוצגת הודעת
שגיאה אדומה עם הפניה לוואטסאפ, והכפתור חוזר לפעולה. פנייה לא נעלמת בשקט.
