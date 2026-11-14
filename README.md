# Content Scheduler

Show posts only inside a date window — schedule seasonal pages, time-limited offers and embargoed posts, plus `[show_between]` / `[hide_between]` shortcodes for inline content. Editors who can edit a post can always preview it, even outside its window.

## Features

- **Visibility window** — per-post "Visible from" / "Visible until" fields in the editor sidebar (site timezone, normalized to `Y-m-d H:i:s` on save).
- **Out-of-window handling** — visitors get a 404, or a redirect to a URL of your choice (Settings → Content Scheduler).
- **Hidden from listings** — out-of-window posts are also excluded from archive and search listings, so a seasonal offer never leaks into the blog roll.
- **Shortcodes** — show or hide any block of content between two dates, either date optional for a one-sided window:
  - `[show_between start="2026-12-01 09:00" end="2026-12-25 23:59"]...[/show_between]`
  - `[hide_between start="2026-12-01" end="2026-12-25"]...[/hide_between]`
- **Safe by design** — an unparsable date is never saved, so a typo can never hide a post; empty fields mean "always visible".
- **Filters** — `wcs_post_types` (which post types get the meta box) and `wcs_out_of_window_action` (override the 404/redirect per post).

## Installation

1. Upload the `wp-content-scheduler` folder to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins** in WordPress admin.
3. Set the visibility window in the post editor sidebar; choose the out-of-window behaviour under **Settings → Content Scheduler**.

## Uninstall

Deleting the plugin removes the `wcs_settings` option and all `_wcs_visible_from` / `_wcs_visible_until` postmeta.

## License

MIT — see [LICENSE](LICENSE).
