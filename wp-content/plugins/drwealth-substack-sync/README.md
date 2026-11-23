# DrWealth Member Tools (Substack migration + site emails)

Imports Substack exports (posts and subscribers) into WordPress, and sends imported subscribers a welcome email. Go to **Tools > Member Tools**, which has four independent tabs — **Posts**, **Subscribers**, **Migration Email** (the Free/Paid move emails) and **Site Emails** (free signup + Premium signup emails) — each with its own form(s), so imports and welcome emails can be run separately, on their own schedule.

Both tabs accept the same kind of Substack export `.zip` (Settings > Exports in Substack); each tab just reads the file inside it that it cares about (`posts.csv` + `posts/*.html`, or `email_list*.csv`).

## Posts tab

1. Pick the **Publication** the export belongs to (Finbite Insights or Growth Dragons).
2. Upload the `.zip` and set how many published posts to import (default 10) — this is the first N published rows of `posts.csv`, top to bottom, not a running total added on top of previous imports.
3. Click **Import Posts**.

For each of those rows, the plugin:

- Finds the matching post HTML in `posts/<post_id>.html` and uses it as the post content (Substack's "Subscribe now" button and paywall marker are stripped since gating happens in WordPress, not in the content).
- Sets the post title and publish date from the export.
- Sets the post **author** to the user chosen in the Author dropdown on the form (the export has no author field; the choice is remembered). Without this, WordPress would credit whoever ran the import.
- **Posts already on the site** (same Substack ID, or same slug as an existing post) are never duplicated: by default they are only linked to their Substack ID and left untouched, categories included. A checkbox on the form overwrites their title/content/date instead; categories are never changed on existing posts.
- New posts get the Substack slug as their address, so links between posts line up. No visible Finbite Insights / Growth Dragons category is added; the source is kept in post meta only.
- Rewrites links to `finbiteinsights.substack.com/p/<slug>` and `growthdragons.substack.com/p/<slug>` to the same slug on this site, and removes Mighty Networks links (`dr-wealth.mn.co`) while keeping their text.
- Strips Substack subscribe, share and "Leave a comment" buttons, subscription boxes and the paywall marker.
- Saves the Substack subtitle as the Jetpack SEO description (the SEO title is left empty) and leaves the "last updated" date empty.
- **Batch import (Posts tab):** pick the export .zip and it is read in the browser (JSZip from cdnjs); nothing is uploaded and each post is sent to the site on its own request (`wp_ajax_drwealth_substack_import_one`), so there are no timeouts, with a progress bar, Stop, and "skip the first N / import N" options. Re-running is safe: posts already imported are recognised and left as they are. The older single-request form below it is only for small tests.
- An author must be chosen; the import refuses to run without one.
- Every post and downloaded image an import **creates** is tagged with that run's ID (`_drwealth_substack_run`, a date-time). **Posts tab > Undo an import run** lists the runs and permanently deletes what a run created (posts marked `created` and the images it downloaded, in short batches; click again if some are left). Posts that were already on the site are never touched. Undo the newest run first, because a later run can reuse an earlier run's images. Deleting posts does not give their IDs back.
- During the import, mail is blocked, Jetpack Publicize is off and calls to Kit/ConvertKit/Mailchimp are blocked, so a post import can't email or post anywhere.
- The uploaded zip and its extracted files live in the server's private temp folder, not in the public uploads folder.
- Assigns categories using the site's existing taxonomy (no new categories are created):
  - **Market**: the existing `United States` category (slug `us`) for Finbite Insights, or `China` (slug `cn`) for Growth Dragons.
  - **Paid gating**: the existing `Premium` category (slug `premium`), only for posts where the export's `audience` is `only_paid`. Free (`everyone`) posts get the market category only.
- Downloads **every image** in the post into the Media Library and rewrites the content to use the local copies (no hot-linking Substack/CDN). Each source image is downloaded once and reused on re-import; the first image becomes the featured image.
- Stores the Substack post ID as post meta (`_drwealth_substack_post_id`, scoped per publication), so re-importing **overwrites** the matching post instead of duplicating it.

## Subscribers tab

1. Upload the `.zip` and set how many subscribers to import (default 10) — this is the first N rows of `email_list*.csv`, top to bottom, not a running total added on top of previous imports.
2. Click **Import Subscribers**.

There's no publication picker on this tab. Each export zip is assumed to contain exactly one `email_list.<publication>.csv` (that's how Substack's per-publication export works), and the publication is auto-detected from that filename, kept only as a historical label. The same person will often get imported twice over time — once from each publication's export — which the "highest tier wins" logic below is built to handle.

For each row, the plugin:

- Matches an existing WordPress user by **email** (the dedupe key), or creates one (role `customer`, random password, no notification emails sent) if none exists.
- Stores the export's `plan`, `active_subscription`, `expiry`, and `created_at` as user meta, scoped per publication (e.g. `_drwealth_substack_plan_finbiteinsights`), so re-importing **overwrites** the same user's data instead of adding a duplicate.
- Works out this publication's own tier from `plan` and `active_subscription`:
  - `plan == lifetime` and active → **`premium-membership`** with no end date (the Premium Lifetime plan is retired)
  - Any other active subscription (`yearly`, `monthly`, `comp`, `other`) → **`premium-membership`**
  - Not active → **`free-membership`**
- The tier shown on the profile is still the highest across publications (Lifetime > Premium > Free), but the membership itself is only created at activation (below).
- `insiders-membership` and `insiders-lifetime-membership` are not assigned — there's no signal in the export to tell an "insider" apart from a regular premium subscriber.
- **Does not create a membership.** Import only creates the user and saves each publication's data, so Finbite Insights and Growth Dragons can both be imported first. The membership is created later, in the same step as the email (see "Activate + email" below).

### Activate + email (membership and email in one step)

For each person the plugin adds up the remaining time on every active paid plan across both publications (expiry minus today, plans already expired add nothing), then rounds the result up to the end of that month (the buffer; filter `drwealth_substack_expiry_buffer` returning `none` turns the rounding off). A lifetime plan gets Premium with no end date; an active plan with no usable expiry date also gets Premium with no end date; nobody with time left gets Free. It then creates the WooCommerce membership with that end date and sends the email, which shows the same date. The result is stored (`_drwealth_substack_activation`), so a retry or "Resend" reuses the same end date. Memberships are granted directly (`wc_memberships_create_user_membership()`), not via a subscription purchase, so the end date set here is what expires them; a later real purchase extends the same record.

### comp / other / yearly / monthly

These aren't split into separate plans yet — it's undecided whether the client wants yearly vs. monthly (or comp) treated differently, so they're all just bucketed as regular active subscribers → `premium-membership`. The raw `plan` value is still recorded per publication, so splitting these out later is a quick logic change, not a re-import.

## Admin-only profile view

On a user's profile screen (Users > [name]), admins see a **Substack Sync** section: the combined membership tier, plus each publication's own plan/active/expiry/created-date/tier. Read-only, historical reference only, not visible to non-admins.

## Welcome Email tab

The migration email, in two versions: **Free** (people who end up on the Free plan) and **Paid** (anyone on a paid plan). Each has an editable subject and rich-text body. Placeholders: `{first_name}`, `{display_name}`, `{email}`, `{plan}`, `{expiry}`, `{expiry_sentence}`, `{reset_link}`, `{login_url}`, `{subscription_url}`, `{site_name}`. Defaults are the client-approved copy, with a password-setup link (WordPress's own reset flow).

The Paid email ships with an `[Insert confirmed renewal price, renewal date ...]` line. **Sending is blocked** (per row and batch) until that line is replaced, so it can't go out unfilled.

Sending is completely separate from importing — no email ever goes out as a side effect of the Posts or Subscribers import. It only happens from the Subscribers tab, two ways:

- **Per row**: a "Send welcome email" / "Resend" button and a status column ("Not sent" or "Sent \<date\>") on the imported-subscribers table.
- **Batch**: a "Send Batch" button that sends to the next N subscribers (default 20, adjustable per run) who have never been emailed, oldest-imported first. Anyone already marked as sent is skipped — so unlike the Posts/Subscribers imports (which reprocess the same top-N every run), each batch run reaches a different set of people, working through the backlog over time. The per-row "Resend" button is the only way to email someone a second time.

## Not yet built

- Assigning `insiders-membership` / `insiders-lifetime-membership`.
- Differentiating comp / yearly / monthly into separate plans.
- Automatic/scheduled sync or batch-send — everything on all three tabs is manually triggered for now.
