# Responsive Test Guide

Use this page as a quick reference when checking layout behavior in browser responsive mode.

## Common device sizes

| Device | Viewport |
|---|---|
| iPhone SE | `375 x 667` |
| iPhone XR | `414 x 896` |
| iPhone 12 Pro | `390 x 844` |
| iPhone 14 Pro Max | `430 x 932` |
| Pixel 7 | `412 x 915` |
| Samsung Galaxy S8+ | `360 x 740` |
| Samsung Galaxy S20 Ultra | `412 x 915` |
| Samsung Galaxy A51/71 | `412 x 914` |
| iPad Mini | `768 x 1024` |
| iPad Air | `820 x 1180` |
| iPad Pro | `1024 x 1366` |
| Surface Pro 7 | `912 x 1368` |
| Surface Duo | `540 x 720` |
| Galaxy Z Fold 5 | `344 x 882` folded |
| Galaxy Z Fold 5 | `882 x 344` folded landscape |
| Asus Zenbook Fold | `853 x 1280` |
| Nest Hub | `1024 x 600` |
| Nest Hub Max | `1280 x 800` |

## Breakpoint guide

| Range | Typical use |
|---|---|
| `<= 430px` | small phones |
| `431px - 767px` | large phones and narrow foldables |
| `768px - 991px` | tablets portrait |
| `992px - 1199px` | tablets landscape, Surface, small laptops |
| `>= 1200px` | desktop |

## Recommended pages to test

- `feed.php`
- `public.php`
- `profile.php`
- `dashboard.php`
- `contacts.php`
- `contact_requests.php`
- `notifications.php`
- `messages.php`

## Quick QA checklist

- Left sidebar or bottom nav does not cover important content.
- No horizontal scrolling appears unless a table needs it.
- Cards, images, video, iframe, and modals stay inside the screen.
- Buttons do not overlap each other.
- Sticky headers still work and do not hide row content.
- Dropdowns open fully inside the viewport.
- Post create and edit modals remain usable on phone and tablet sizes.
- Profile, contacts, and requests pages scroll naturally.

## Notes

- Test both portrait and landscape when possible.
- Foldable and mini-tablet widths often reveal spacing bugs faster than desktop.
- If a table is too wide, horizontal scrolling inside the table area is acceptable.
