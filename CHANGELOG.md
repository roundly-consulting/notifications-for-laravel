# Changelog

All notable changes to `notifications-for-laravel` are documented in this file. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

Initial public release.

### Added

- Typed notifications: register types as small classes (`NotificationType`) or fluently with
  `Notifications::define()`, each with a key, category, channels, priority, mandatory and
  digestible flags, a collapse/de-dup window, a subject class with an access check, rendering
  hooks and reminder rules. `Notifications::types()` lists them, translated, for a settings UI.
- One send API — `Notifications::send()`, the fluent `Notifications::type()->…->send()` and
  `Notifications::for($subject)` — with pluggable recipients (`Recipients::users()`, `role()`,
  `members()`, `owner()`, `using()`, `merge()`), idempotency keys and large-set fan-out on the queue.
  Access is re-checked at every hop: send, queued mail, held mail, digest, reminder, escalation.
- An in-app inbox: one item per recipient, type, subject and context per window (count and latest
  actor), read/unread/archive/delete, "mark all read" scoped by the inbox handle's filters
  (`Notifications::inbox($user)->in($company)->markAllRead()`; `POST /inbox/read-all` takes
  `context`, `category` and `type`), per-context labels and counts, paginated and cursor queries,
  `revoke()`, events for broadcasting, and JSON resources without class names (actor, subject and
  context resources swappable through `notifications.resources.*`).
- Per-user preferences per type, category and channel, stored through options-for-laravel:
  mandatory types ignore mutes, muting stops mail only by default, defaults come from the type.
- Email through Laravel's mail notifications in each recipient's locale, with leading or trailing
  de-dup within the window, minimal mail for sensitive subjects, at-most-once delivery with retries,
  and an address seam (contacts-for-laravel recipe).
- Quiet hours in the user's timezone (urgent types bypass them) and daily or weekly digests of
  low-priority types at a local time, idempotent per user and period.
- Reminders relative to a date attribute (`Reminder::before('expires_at', 'P60D', 'P30D', 'P7D')`)
  that follow the date, stop on delete or a failed condition, re-arm on renewal, and escalate when
  not acknowledged; ad-hoc reminders with `Notifications::for($subject)->remind()`.
- Interop: map other packages' notification classes to your types (`notifications.interop.map`)
  so preferences, mandatory flags, de-dup, quiet hours and the inbox apply, or suppress them.
- A Laravel `inbox` channel, an opt-in JSON API (`notifications.routes.enabled`), the
  `notifications:sweep`, `prune`, `types`, `doctor`, `install` and `reminders:sync` commands, a
  record-only `Notifications::fake()` with assertions, and Pest expectations.
- English and Slovak translations.
