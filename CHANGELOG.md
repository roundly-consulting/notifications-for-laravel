# Changelog

All notable changes to `notifications-for-laravel` are documented in this file. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

### Fixed

- A digest whose job waits in the queue longer than `notifications.digest.lease` is no longer
  failed with its entries: the lease now starts when the job starts, not when the sweep queues
  it. A digest still queued after the lease is dispatched again (only one job sends). With a
  backlog of digest jobs, every waiting digest used to be lost and never retried.
- A reminder cancelled because a date edit made its time pass (`passed`) is scheduled again when
  the date moves back. Editing a date earlier and then back used to lose those reminders silently.
- `POST /inbox/{item}/acknowledge` on an item from an escalation level with its own `type:` now
  acknowledges the chain and cancels its next levels. It acknowledged the level's type, matched
  no row (`{"acknowledged": 0}`) and the chain kept escalating.
- The send itself re-reads the subject before the access check, as the docs promise for every
  hop. A soft-deleted subject (unless the type allows it) or one that changed since the caller
  loaded it got an inbox item and mail inline, while the same send through a fan-out delivered
  nothing. Now both answer `no_access`.
- A keyed (`once()`) fan-out to many recipients no longer throws before anything is queued. The
  replay check built one condition per recipient, which broke SQLite at about 1 000 recipients
  and MySQL/PostgreSQL at 32 768; it now checks in chunks of 1 000 per morph type.
- Interop never holds, folds or digests a password reset or sign-in code that matches a mapping of
  one of its ancestor classes (e.g. a vendor base class or `Notification` itself): it passes
  through untouched unless the type is urgent with no window. A second reset mail within the
  window was folded and never sent. `notifications:doctor` warns about such a mapping.
- An ad-hoc reminder on a type without a `subject()` (`remind('report.ready', …)` on any model,
  the documented example) fires, about no subject. Every fire threw and the row ended
  `cancelled/unknown` after three attempts. An anchored one now also follows its anchor when the
  model (with `HasNotificationReminders`) is saved, also when no type names the model as its
  subject.
- `remind()` with `->key()` and several offsets keeps a row per offset (a repeat call replaces
  each one). Each offset replaced the previous one, so only the last offset was ever sent.
- `remind()` or `schedule()` while the same reminder is being fired no longer leaves a second
  pending row behind: the waiting row is replaced by later calls and takes over the key once the
  fire closes. The reminder used to fire twice.

## 1.1.0 - 2026-11-08

### Added

- `DELETE /inbox/{item}` (route `notifications.inbox.destroy`) deletes one of the user's items:
  204, or 404 for another user's item or a malformed id. A published routes file needs the line
  added by hand.
- `Notifications::fake()` gains an assertion for every recorded operation: `assertMarkedUnread()`,
  `assertUnarchived()`, `assertDeleted()`, `assertInboxMessage()`, `assertCategoryPreferenceSet()`,
  `assertPreferencesReset()`, `assertDigestSet()`, `assertQuietHoursSet()`, `assertTimezoneSet()`,
  `assertPruned()`, `assertSubjectForgotten()`, the negative `assertNothingMarkedRead()`,
  `assertNothingArchived()`, `assertNoPreferenceChanged()` and `assertNoRemindersScheduled()`, and
  the generic `assertRecorded(NotificationOperation $operation, ?Closure $where, ?int $times)` /
  `assertNotRecorded()` for the rest (digests, deliveries, reminder fires, backfills).
- `assertNotSent()` takes `to:` and `about:`, and `assertSent()` / `assertNotSent()` accept a type
  class as well as its key.
- `Traits\IsNotificationSubject`: the delete hook alone, for subjects without reminder rules. A
  deleted subject's inbox items follow `notifications.inbox.on_subject_deleted` and its pending
  ad-hoc reminders are cancelled. `HasNotificationReminders` now uses it.
- `php artisan about` lists the `Context key type` beside the other key types.
- `NotificationOperation::SubjectDeleted`, recorded for the inbox cleanup of
  `notifications.inbox.on_subject_deleted`.

### Changed

- Behaviour change: switching off a channel that cannot be switched off now throws
  `LockedNotificationChannelException` (422, code `notification_channel_locked`) instead of
  storing an override that was then ignored. That covers the inbox while
  `notifications.preferences.inbox_mutable` is off, a channel the type does not deliver on, and
  `mute($type)` on a type with no such channel. `PATCH /preferences` answers 422 under
  `channels.<type>.<channel>` where it used to answer 200. Switching such a channel on stores
  nothing.
- Behaviour change: a subject has at most one open send-level escalation chain per type.
  Repeated sends with `->escalate()` about the same subject used to arm one chain each (unless
  they shared a `once()` key), so a director could get ten escalations for one request. A pending
  chain now absorbs later sends and keeps its own recipients and due times; a send after the chain
  ended (last level fired, acknowledged or cancelled) arms a new one.
- Behaviour change: under `Notifications::fake()`, a send the real pipeline refuses up front
  throws the same exception instead of being recorded: an unknown type, a subject of the wrong
  class, data that is not plain JSON, an escalation without a subject (also bad keys, contexts and
  unstorable escalation recipients). A green test no longer hides a production exception; a test
  that relied on the fake accepting such a send now fails with the real exception.
- Behaviour change: `InboxItemResource` renders `data` as `[]` for an item whose type is no longer
  registered. It returned the raw stored data, so removing a type exposed what its `publicData()`
  hid.
- Behaviour change: `NotificationsManager` and `NotificationsFake` take no constructor arguments
  any more (they resolve the current container). Code that built either with `new` and a
  container must drop the argument, or get them from the container or `Notifications::fake()`.
- Behaviour change: `PreferencesHandle::muted($type)` is `false` for a type that sends no mail (it
  was `true`).
- Behaviour change: `NotificationPreferenceChanged` fires only when a write changed the stored
  override; a repeated mute, an unmute of an untouched type or a reset of untouched preferences
  fires nothing.
- Performance: with `notifications.inbox.recheck_on_read` on, reading the inbox reuses the
  subjects the query already loaded instead of reading each one again (10 items: 2 queries instead
  of 12).
- Performance: `InboxHandle::counts()` reads every context's label in one query instead of one
  query per context.
- Maintenance: `illuminate/container` is now a direct requirement (`^13.0`).
- Maintenance: delivery, digest and reminder claims use Eloquent's `incrementEach()` again (the
  Laravel 12 workaround is gone); `updated_at` is still stamped on every claim.

### Fixed

- Octane: `NotificationsManager` no longer keeps the container it was built with. It resolves
  every action from the current container, so a per-request binding (an action override, a
  sandboxed config) applies even when a provider used the facade during boot.
- Under `Notifications::fake()`, deleting a subject no longer archives or deletes its inbox items
  (`notifications.inbox.on_subject_deleted`). The cleanup now runs through the manager, so the fake
  records it like every other write.
- Acknowledging a subject no longer silences its later reminders' escalations. It stamped the
  pending later offsets too, so when one fired, its escalation was cancelled as acknowledged and
  the managers were never told. Only reminders that already went out are stamped now.
- Replaying a send with the same `once()` key no longer re-arms its escalation after the chain
  fired or was acknowledged: a replay that reached nobody new arms nothing, so the director is
  not told twice, or after the acknowledgement.
- A send that throws while delivering no longer leaves an escalation armed: the chain is armed
  only after delivery went through.
- A reminder or escalation delivered through a queued fan-out (more recipients than
  `notifications.fan_out.queue_above`) keeps its `ReminderReference`: `$payload->reminder` and
  the mail's reminder metadata were missing.
- A type passed as its class (`RequestAwaitingApproval::class`) is stored as its key. `mute()`,
  `set()` and `unmute()` stored the class name, which no read matched (the mute did nothing), and
  `schedule()` / `remind()` wrote reminder rows under the class name, beside the keyed ones and out
  of reach of `cancel('…')` and `acknowledge('…')`. Reminder filters (`pending()`, `all()`,
  `cancel()`, `sync()`, `acknowledge()`) accept a class too. A `PreferencesUpdate` (and
  `PATCH /preferences`) refuses a type that is not a key.
- With `notifications.inbox.recheck_on_read` on, `InboxHandle::find()` treats an item whose
  subject the reader can no longer access as not found, like the lists. So
  `POST`/`DELETE /inbox/{item}/read|archive` and `DELETE /inbox/{item}` answer 404 for it instead
  of changing it and rendering the live subject to a user who lost access.
- A mail held for quiet hours at delivery time no longer uses up a delivery attempt, so it still
  gets every transport try of `notifications.mail.max_attempts`, with the backoff starting at the
  first step.
- An ad-hoc relative reminder (`remind($type, Reminder::before(...))`) skips offsets that are
  already past, like a type rule does. They were scheduled and then fired all at once on the next
  sweep.
- Month and year reminder offsets no longer overflow: a month before 31 March is 28 February
  (it was 3 March), and a year after 29 February is 28 February.
- `notifications:sweep --only=reminders` fires only reminders and `--only=escalations` only
  escalations; either one fired both.
- Stored subjects are re-read the same way at every hop (queued fan-out, mail, digests, reminders,
  escalations, acknowledgements, `recheck_on_read`): no host global scope applies, for any model,
  as with `Model::fresh()` at send time. A scope used to apply only to subjects without
  `SoftDeletes`, so a request-bound scope (tenancy) cancelled their queued mail as `subject_gone`
  while a soft-deleting subject went through. Soft-deleted subjects are still refused unless the
  type allows them, and access is still the type's check.

## 1.0.0 - 2026-11-07

Initial public release. Requires PHP 8.4 or newer (tested on 8.4, 8.5 and 8.6) and Laravel 13, on
the roundly 2.x companions (crypto, enums, options, package-toolkit).

### Added

- Typed notifications: register types as small classes (`NotificationType`) or fluently with
  `Notifications::define()`, each with a key, category, channels, priority, mandatory and
  digestible flags, a collapse/de-dup window, a subject class with an access check, rendering
  hooks and reminder rules. `Notifications::types()` lists them, translated, for a settings UI.
- One send API — `Notifications::send()`, the fluent `Notifications::type()->…->send()` and
  `Notifications::for($subject)` — with pluggable recipients (`Recipients::users()`, `role()`,
  `members()`, `owner()`, `using()`, `merge()`), idempotency keys and large-set fan-out on the queue.
  Access is re-checked at every hop: send, queued mail, held mail, digest, reminder, escalation,
  and acknowledgement — a user who lost access cannot stop an escalation through an old item.
- An in-app inbox: one item per recipient, type, subject and context per window (count and latest
  actor), read/unread/archive/delete, "mark all read" scoped by the inbox handle's filters
  (`Notifications::inbox($user)->in($company)->markAllRead()`; `POST /inbox/read-all` takes
  `context`, `category` and `type`), per-context labels and counts, paginated and cursor queries,
  `revoke()`, events for broadcasting, and JSON resources without class names (actor, subject and
  context resources swappable through `notifications.resources.*`).
- Per-user preferences per type, category and channel, stored through options-for-laravel:
  mandatory types ignore mutes, muting stops mail only by default, defaults come from the type.
  An unknown type or category is refused (`UnknownNotificationTypeException`,
  `UnknownNotificationCategoryException`), and overrides of types or categories that are no longer
  registered are dropped on the next write. `Notifications::preferences($user)->update(new
  PreferencesUpdate(...))` applies several changes all or nothing — what `PATCH /preferences` uses,
  so a refused part answers 422 under its field and writes nothing.
- Email through Laravel's mail notifications in each recipient's locale, with leading or trailing
  de-dup within the window, minimal mail for sensitive subjects, at-most-once delivery with retries,
  and an address seam (`notifications.mail.address_resolver`, the contacts-for-laravel "verified
  only" recipe) that typed mail and digests both honour.
- Quiet hours in the user's timezone (urgent types bypass them) and daily or weekly digests of
  low-priority types at a local time, idempotent per user and period.
- Reminders relative to a date attribute (`Reminder::before('expires_at', 'P60D', 'P30D', 'P7D')`)
  that follow the date, stop on delete or a failed condition, re-arm on renewal, and escalate when
  not acknowledged; ad-hoc reminders with `Notifications::for($subject)->remind()`. An unreadable or
  non-positive escalation delay throws `InvalidReminderRuleException` where it is declared, and an
  ad-hoc reminder refuses a condition, an escalation or `acknowledgedOnRead()` (declare those on a
  type rule).
- Interop: map other packages' notification classes to your types (`notifications.interop.map`)
  so preferences, mandatory flags, de-dup, quiet hours and the inbox apply, or suppress them.
- A Laravel `inbox` channel, an opt-in JSON API (`notifications.routes.enabled`), the
  `notifications:sweep`, `prune`, `types`, `doctor`, `install` and `reminders:sync` commands, a
  record-only `Notifications::fake()` with assertions, and Pest expectations.
- English and Slovak translations.
