# Site owner guide

How to run a social learning site on the three plugins in this repository.
It assumes a working WordPress install and nothing else. Developers wanting
to change the code start at [`DEVELOPMENT.md`](DEVELOPMENT.md) instead.

## 1. Install

Requirements: WordPress 6.4 or newer, PHP 8.1 or newer, MySQL 5.7 / MariaDB
10.3 or newer. A block theme is not required; every front-end page is a
classic template a theme may override.

1. Install `odsi-lms` and `odsi-social` (each works alone; install only the
   one you need): upload the zips that `bin/package.sh` produces through
   **Plugins → Add New → Upload Plugin**, or copy `plugins/odsi-lms` and
   `plugins/odsi-social` into `wp-content/plugins/` after running
   `npm install && npm run build` once so the editor bundles exist.
2. Activate **ODSI Learning** and / or **ODSI Community**. Activation creates
   the plugins' tables, the `Instructor` role, and, for the community, five
   pages (Members, Groups, Activity, Notifications, Messages) that hold the
   `[odsi_social_page]` shortcode.
3. If both are active, copy and activate `plugins/odsi-bridge` as well. It
   refuses to activate without both and says so; it deactivates itself with a
   notice if either one is later removed.
4. Visit **Settings → Permalinks** once and save, so the course archive and
   community URLs are registered. The plugins flag a rewrite flush on
   activation, but saving is the reliable way on hosts with aggressive caching.

Multisite: activate per site; the tables are per site.

## 2. Learning settings

**Learning → Settings**

| Setting | Effect |
| --- | --- |
| Course archive slug | The URL under which courses list, `courses` by default. |
| Default access mode | Mode new courses start with (see § 4). |
| Default pass mark | Pass mark for quizzes that do not set their own. |
| Certificates | Issue certificates on course completion (a course still needs a certificate template). |
| Email notifications | Enrollment, completion, assignment and access-expiry emails to learners. |
| Expiry warning days | How many days before access ends the learner is warned; 0 disables. |
| Delete all data on uninstall | Off by default. Uninstall keeps every table and post unless this is on. |

Roles: **Instructor** creates, edits, publishes and deletes their own courses
and steps and grades their own learners; they cannot edit another author's
course. Administrators (and anyone granted `manage_odsi_lms`) manage
everything, including the Reports and Grading screens and enrollment.

## 3. Build a course

A course is a tree: **Course → Lesson → Topic**, with **Quizzes** attached to
the course, a lesson or a topic, and **Questions** inside quizzes. Every node
is a post you edit like any other.

The quickest route is the builder: open a course in the editor and use the
**Course builder** panel in the sidebar to add lessons and topics, reorder
them, attach quizzes and detach anything without deleting it. Nodes can also
be placed from their own edit screens through the **Course placement** box.

Settings live in boxes on each edit screen (they are also in the block
editor's sidebar as post meta):

- **Course settings**: access mode, price, access expiry in days, linear
  progression (steps must be completed in order), duration, certificate
  template, prerequisite courses (learners must complete them first), and,
  with WooCommerce active, the product that sells the course.
- **Release and duration** on lessons and topics: available immediately, a
  number of days after enrollment, or on a fixed date in the site timezone.
- **Quiz settings**: pass mark, maximum attempts (0 for unlimited), time limit
  in minutes (0 for none).
- **Answers** on a question: the type (single choice, multiple choice, true or
  false, fill in the blank, essay), points, and the options one per line with
  a leading `*` on the correct ones. Fill-in-the-blank lists every accepted
  answer; essays are graded by hand on **Learning → Grading**.
- **Assignment** on a lesson or topic: require a hand-in before the step
  completes, with optional points and automatic approval.

Certificates are posts under **Learning → Certificates**. Their content is
the certificate text with `{name}`, `{course}`, `{date}` and `{code}`
placeholders; the issued page is print-styled and verifiable at
`/certificate/{code}/` or through the `[odsi_certificate_verify]` shortcode.

Cohorts (**Learning → Cohorts**) enroll a set of learners on a set of courses
at once and cancel those enrollments when a learner leaves the cohort.

**Duplicate** on the courses list copies a course and everything under it as
drafts, ready to edit; learners and certificates stay with the original.

## 4. Who can take a course

Each course has one access mode:

| Mode | Behaviour |
| --- | --- |
| `open` | Anyone may read it; a logged-in visitor is enrolled on first view. |
| `free` | Logged-in learners enroll themselves with the enroll button. |
| `paid` | Enrollment comes from a purchase (§ 5). Visitors see a purchase notice, or a buy button when a product is linked. |
| `closed` | Only an administrator, a cohort or an integration enrolls. |

Administrators enroll and remove learners on **Learning → Reports**, one at
a time or from a pasted list of usernames or emails, and export a CSV of
enrollments and progress. The same screen shows each quiz's attempts, pass
rate and a per-question breakdown, also exportable. Access can expire after
a number of days; a daily job warns learners a configurable number of days
before, closes expired enrollments and emails them that access ended.

## 5. Selling courses

With WooCommerce active, a course's settings box gains a **WooCommerce
product ID** field. Create the product in WooCommerce, enter its ID on the
course, and set the course to `paid`. A paid or processing order enrolls the
customer on every course sold by its products; a refunded or cancelled order
removes that enrollment (progress is kept, so a repurchase resumes). Guest
orders enroll nobody, so require an account at checkout. One product may sell
several courses.

Any other payment system integrates by firing two actions:

```php
do_action( 'odsi_lms_course_purchased', $user_id, $course_id, array( 'order_id' => $order_id, 'gateway' => 'my-gateway' ) );
do_action( 'odsi_lms_course_refunded',  $user_id, $course_id, array( 'order_id' => $order_id, 'gateway' => 'my-gateway' ) );
```

A refund never removes an enrollment an administrator or a cohort created.

## 6. Community settings

**Community → Settings**

| Setting | Effect |
| --- | --- |
| Page slugs | URLs of the five community pages. |
| Public directory | Whether visitors may browse members and profiles. Off makes the whole community members-only. |
| Members can create groups | Off restricts group creation to administrators. |
| Allowed privacy levels, default privacy | Which audiences a post may have (`public`, `members`, `connections`, `only_me`) and the preselected one. Group posts are always group-only. |
| Post and message length, edit window | Limits on what members write and how long they may edit. |
| Feed and directory page size | Items per page. |
| Notification retention | Days before read notifications are pruned. |
| Avatar size and types | Upload limits for avatars and covers. |
| Delete content with user | Whether deleting a WordPress account removes their posts and messages. |
| Delete all community data on uninstall | Off by default. |

**Community → Profile Fields** defines the profile form: text, textarea,
checkbox, select, multiselect, date and URL fields, grouped, each with a
default visibility members may change per field.

Groups are public, private (visible, request to join) or hidden (invisible
except to members and invitees). Organisers manage members, moderators and
settings from the group's **Manage** page; administrators see everything.

Moderation: members report posts, comments, profiles, groups and message
threads (spam, harassment, inappropriate, other) from a Report control, and
block other members from a profile; a block hides the pair from each other
everywhere and stops messages, connections, follows, mentions and
notifications between them. Reports queue on **Community → Moderation**,
where an administrator dismisses them, deletes the content or bans the
author from the group; the reporter is told when their report is reviewed.
Administrators also delete posts and comments straight from the feed, and
organisers ban members from groups. Rate limits on posting, messaging,
reporting and connection requests are on by default and adjustable through
the `odsi_social_rate_limits` filter.

## 7. Put pages together

Shortcodes and blocks render every part of both plugins in any page:

| Learning | Community |
| --- | --- |
| `[odsi_course_grid]` — course list, filterable by category | `[odsi_activity_feed]` — the site feed with tabs |
| `[odsi_my_courses]` — the learner's dashboard | `[odsi_member_directory]` |
| `[odsi_course_outline course_id=…]` | `[odsi_group_directory]` |
| `[odsi_course_progress course_id=…]` | `[odsi_social_page]` — the routed community page |
| `[odsi_enroll_button course_id=…]` | |
| `[odsi_certificate_verify]` | |

The same appear as blocks under the **Learning** and **Community**
categories in the editor. Templates live in each plugin's `templates/`
directory; copy one to `your-theme/odsi-lms/` or `your-theme/odsi-social/`
to override it.

### The ODSI Learn theme

If you do not already have a theme you love, activate **ODSI Learn**
(`themes/odsi-learn`, also built as a zip by `bin/package.sh`). It gives
WordPress, the courses and the community one look:

- The header and footer carry a **Platform menu** that builds itself: the
  courses archive, "My courses" once you publish a page containing
  `[odsi_my_courses]`, Activity / Members / Groups when the community plugin
  is active, and Notifications / Messages / Log out for signed-in members.
- The front page shows a hero, the latest courses and the community feed.
  Edit it under **Appearance → Editor → Templates → Front Page**, or drop the
  **ODSI Learn** patterns into any page.
- Courses, lessons, topics and quizzes have their own templates; the course
  archive is a card grid.
- Colours live in one place: **Appearance → Editor → Styles → Colours**.
  Changing *Accent* recolours every plugin button and progress bar too.

Keeping your own theme? Define six CSS variables once (for example in
**Appearance → Customize → Additional CSS**) and both plugins follow:

```css
:root {
	--odsi-accent: #2f5bea;
	--odsi-accent-contrast: #ffffff;
	--odsi-surface: #f4f6fb;
	--odsi-border: #dfe3ec;
	--odsi-muted: #5b6470;
	--odsi-radius: 8px;
}
```

## 8. The bridge

**Learning → Community bridge** switches three modules:

- **Course activity**: enrollments, completions, quiz passes and certificates
  post to the activity feed as the learner, honouring the learner's default
  privacy and, when a course is linked to a group, the group.
- **Group linkage**: a course (or cohort) is tied to a social group from the
  course's **Community group** box. Enrolling joins the group; unenrolling,
  expiry and cohort removal leave it. Linking an existing course syncs its
  learners in the background in pages.
- **Progress visibility**: group organisers see members' course progress on
  the group page, and the `[odsi_group_progress]` shortcode lists it.

Linking a closed group to an open or free course lets anyone who enrolls
join the group; the box warns about that.

## 9. Maintenance and data

- A daily job (WP-Cron) expires enrollments, prunes old notifications and
  recounts denormalised counters. On sites with WP-Cron disabled, run
  `wp cron event run --due-now` from the system cron.
- Deleting a WordPress user removes their enrollments, progress, attempts,
  submissions and certificates; the community removes their content only when
  **Delete content with user** is on.
- Uninstalling from **Plugins** removes nothing unless the plugin's **Delete
  all data on uninstall** setting was turned on first. Deactivating never
  removes anything.
- Learner uploads (assignments) are hidden from the Media Library for
  everyone but administrators and the uploader; member images are ordinary
  attachments owned by the member.

## 10. Troubleshooting

| Symptom | Check |
| --- | --- |
| Course or community URLs 404 | Save **Settings → Permalinks** once. |
| Bridge will not activate | Both other plugins must be active first. |
| A step shows "not available yet" | Its release rule, linear progression, or the course's expiry. |
| Quiz question shows no options | The question's **Answers** box has no lines, or its quiz is not attached to the course. |
| Buy button missing on a paid course | WooCommerce inactive, no product ID on the course, or the product is not purchasable. |
| Emails never arrive | The site's mail delivery; both plugins send through `wp_mail()`. |
| Hidden group appears in search | It does not: hidden groups are excluded from search, feeds and REST. Clear a page cache that predates the change. |
