<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Actions\Reminders\AcknowledgeAction;
use RoundlyConsulting\Notifications\Models\InboxItem;

/**
 * Reading an item that came from a reminder declared `acknowledgedOnRead()` acknowledges it — only
 * while the reader can still see the subject (the read itself always succeeds).
 *
 * @internal
 */
final class ReadAcknowledgements
{
    /**
     * @param  iterable<InboxItem>  $items
     */
    public static function process(iterable $items, Model $reader, AcknowledgeAction $acknowledge): void
    {
        $types = app(TypeRegistry::class);

        foreach ($items as $item) {
            if ($item->reminder_id === null) {
                continue;
            }

            $reminder = NotificationModels::reminder()::query()->withTrashed()->find($item->reminder_id);
            $type = $reminder === null ? null : $types->get($reminder->type);

            if ($reminder === null || $type === null) {
                continue;
            }

            $root = $reminder->parent_id === null ? $reminder : NotificationModels::reminder()::query()->withTrashed()->find($reminder->parent_id);
            $rule = $root === null ? null : ReminderRows::rule($type, $root->rule);

            if ($rule === null || ! $rule->isAcknowledgedOnRead()) {
                continue;
            }

            $subject = Subjects::find($type, $reminder->subject_type, $reminder->subject_id);

            if ($subject !== null && Access::allows($type, $reader, $subject, true)) {
                $acknowledge->execute($subject, $reminder->type, $reader);
            }
        }
    }
}
