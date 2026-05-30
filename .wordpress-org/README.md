# WordPress.org store-listing assets

Everything in this `.wordpress-org/` directory is synced to the **`/assets/`**
folder of the plugin's SVN repo (NOT to `trunk`), so it never affects the
shipped plugin code. Updating these files only changes the plugin's listing on
WordPress.org (banner, icon, screenshots, live preview).

## Expected files

Drop the brand files directly in this directory using these exact names:

| Purpose            | Filename                          | Dimensions        |
| ------------------ | --------------------------------- | ----------------- |
| Icon (standard)    | `icon-128x128.png`                | 128 × 128         |
| Icon (retina)      | `icon-256x256.png`                | 256 × 256         |
| Icon (vector, opt) | `icon.svg`                        | square            |
| Banner (standard)  | `banner-772x250.png`              | 772 × 250         |
| Banner (retina)    | `banner-1544x500.png`             | 1544 × 500        |
| Screenshots        | `screenshot-1.png`, `screenshot-2.png`, … | any (kept reasonable) |

Notes:
- Screenshots are matched **in order** to the captions under `== Screenshots ==`
  in `readme.txt` (screenshot-1 = first caption, and so on).
- `.png` or `.jpg` are both accepted for icons/banners/screenshots.
- An `icon.svg` is fully supported by WordPress.org and is a natural fit for
  this plugin; provide `icon-256x256.png` as a fallback for older contexts.

## Live preview

`blueprints/blueprint.json` powers the "Live Preview" button on the plugin
listing via WordPress Playground. It boots WordPress, installs SVG Support, and
lands on the settings page.
