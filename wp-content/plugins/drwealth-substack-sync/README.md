# DrWealth Substack Sync

Imports Substack exports (posts and subscribers) into WordPress, and sends imported subscribers a welcome email. Go to **Tools > Substack Sync**, which has three independent tabs — **Posts**, **Subscribers**, and **Welcome Email** — each with its own form(s), so imports and welcome emails can be run separately, on their own schedule.

Both tabs accept the same kind of Substack export `.zip` (Settings > Exports in Substack); each tab just reads the file inside it that it cares about (`posts.csv` + `posts/*.html`, or `email_list*.csv`).

## Posts tab

1. Pick the **Publication** the export belongs to (Finbite Insights or Growth Dragons).
2. Upload the `.zip` and set how many published posts to import (default 10) — this is the first N published rows of `posts.csv`, top to bottom, not a running total added on top of previous imports.
3. Click **Import Posts**.

For each of those rows, the plugin:

- Finds the matching post HTML in `posts/<post_id>.html` and uses it as the post content (Substack's "Subscribe now" button and paywall marker are stripped since gating happens in WordPress, not in the content).
- Sets the post title and publish date from the export.
- Assigns categories using the site's existing taxonomy (no new categories are created):
  - **Market**: the existing `United States` category (slug `us`) for Finbite Insights, or `China` (slug `cn`) for Growth Dragons.
  - **Paid gating**: the existing `Premium` category (slug `premium`), only for posts where the export's `audience` is `only_paid`. Free (`everyone`) posts get the market category only.
- Sets a featured image from the first image found in the post, if any.
- Stores the Substack post ID as post meta (`_drwealth_substack_post_id`, scoped per publication), so re-importing **overwrites** the matching post instead of duplicating it.

## Subscribers tab

1. Upload the `.zip` and set how many subscribers to import (default 10) — this is the first N rows of `email_list*.csv`, top to bottom, not a running total added on top of previous imports.
2. Click **Import Subscribers**.

There's no publication picker on this tab. Each export zip is assumed to contain exactly one `email_list.<publication>.csv` (that's how Substack's per-publication export works), and the publication is auto-detected from that filename, kept only as a historical label. The same person will often get imported twice over time — once from each publication's export — which the "highest tier wins" logic below is built to handle.

For each row, the plugin:

- Matches an existing WordPress user by **email** (the dedupe key), or creates one (role `customer`, random password, no notification emails sent) if none exists.
- Stores the export's `plan`, `active_subscription`, `expiry`, and `created_at` as user meta, scoped per publication (e.g. `_drwealth_substack_plan_finbiteinsights`), so re-importing **overwrites** the same user's data instead of adding a duplicate.
- Works out this publication's own tier from `plan` and `active_subscription`:
  - `plan == lifetime` and active → **`premium-lifetime-membership`**
  - Any other active subscription (`yearly`, `monthly`, `comp`, `other`) → **`premium-membership`**
  - Not active → **`free-membership`**
- **Assigns the highest tier across all publications the person is known from**, not just this one — so if the same email exists under both Finbite Insights and Growth Dragons, whichever one ranks higher wins (Lifetime > Premium > Free). Ties at the same tier (e.g. premium via a monthly plan on one publication, premium via yearly on the other) are broken by whichever has the furthest-out expiry. Existing memberships in lower plans are left in place, not removed.
- `insiders-membership` and `insiders-lifetime-membership` are not assigned — there's no signal in the export to tell an "insider" apart from a regular premium subscriber.
- **Sets the WooCommerce membership's own expiration** to match Substack's `expiry` for `premium-membership` (kept in sync on every re-import, so a Substack renewal pushes the WordPress expiry out too); `premium-lifetime-membership` never expires. This matters because these memberships are granted directly (`wc_memberships_create_user_membership()`), not through a subscription product purchase — nothing else on the site would otherwise expire them. If someone later buys the real subscription product through checkout, WooCommerce just extends/updates this same membership record rather than conflicting with it.

### comp / other / yearly / monthly

These aren't split into separate plans yet — it's undecided whether the client wants yearly vs. monthly (or comp) treated differently, so they're all just bucketed as regular active subscribers → `premium-membership`. The raw `plan` value is still recorded per publication, so splitting these out later is a quick logic change, not a re-import.

## Admin-only profile view

On a user's profile screen (Users > [name]), admins see a **Substack Sync** section: the combined membership tier, plus each publication's own plan/active/expiry/created-date/tier. Read-only, historical reference only, not visible to non-admins.

## Welcome Email tab

Editable subject + rich-text body for the email sent to imported subscribers. Placeholders: `{first_name}`, `{display_name}`, `{email}`, `{plan}`, `{expiry}`, `{expiry_sentence}`, `{reset_link}`, `{login_url}`, `{site_name}`. Defaults cover the two required pieces of content — a password-reset link (WordPress's own reset flow, same as "lost password") and a plan/expiry notice built from the subscriber's combined membership info.

Sending is completely separate from importing — no email ever goes out as a side effect of the Posts or Subscribers import. It only happens from the Subscribers tab, two ways:

- **Per row**: a "Send welcome email" / "Resend" button and a status column ("Not sent" or "Sent \<date\>") on the imported-subscribers table.
- **Batch**: a "Send Batch" button that sends to the next N subscribers (default 20, adjustable per run) who have never been emailed, oldest-imported first. Anyone already marked as sent is skipped — so unlike the Posts/Subscribers imports (which reprocess the same top-N every run), each batch run reaches a different set of people, working through the backlog over time. The per-row "Resend" button is the only way to email someone a second time.

## Not yet built

- Assigning `insiders-membership` / `insiders-lifetime-membership`.
- Differentiating comp / yearly / monthly into separate plans.
- Automatic/scheduled sync or batch-send — everything on all three tabs is manually triggered for now.
