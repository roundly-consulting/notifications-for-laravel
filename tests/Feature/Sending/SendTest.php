<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Notifications\Contracts\RecipientResolver;
use RoundlyConsulting\Notifications\Contracts\ResolvesGroupMembers;
use RoundlyConsulting\Notifications\Enums\InboxOutcome;
use RoundlyConsulting\Notifications\Enums\MailOutcome;
use RoundlyConsulting\Notifications\Events\RecipientSkipped;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationDataException;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationsConfigurationException;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationSubjectException;
use RoundlyConsulting\Notifications\Exceptions\InvalidRecipientException;
use RoundlyConsulting\Notifications\Exceptions\MissingAccessCheckException;
use RoundlyConsulting\Notifications\Exceptions\NoOwnerException;
use RoundlyConsulting\Notifications\Exceptions\UnresolvableRecipientsException;
use RoundlyConsulting\Notifications\Exceptions\UnstorableRecipientsException;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Jobs\DeliverToRecipientsJob;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\NotificationPayload;
use RoundlyConsulting\Notifications\Recipients;
use RoundlyConsulting\Notifications\Recipients\RecipientSpec;
use RoundlyConsulting\Notifications\Reminders\Reminder;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Company;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Contract;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Post;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\User;

/**
 * A resolver class for Recipients::using(): the company's members at a given level.
 */
final class ApproversOfLevel implements RecipientResolver
{
    public function resolve(?Model $subject, array $args): iterable
    {
        return User::query()->where('name', 'like', 'Level '.$args['level'].'%')->get();
    }
}

beforeEach(function (): void {
    Notification::fake();
});

it('refuses a subject of another class (C-10)', function (): void {
    Notifications::send('request.awaiting_approval', post(), user());
})->throws(InvalidNotificationSubjectException::class, 'is about PurchaseRequest, not Post');

it('refuses a subject for a type without one', function (): void {
    Notifications::send('security.alert', post(), user());
})->throws(InvalidNotificationSubjectException::class, 'has no subject, but Post was given');

it('accepts only plain JSON data under the byte cap (C-11)', function (array $data, string $message): void {
    expect(fn () => Notifications::send('security.alert', null, user(), $data))->toThrow(InvalidNotificationDataException::class, $message);
})->with([
    'a model' => [fn () => ['user' => new User], 'data.user'],
    'an object' => [fn () => ['nested' => ['when' => new DateTimeImmutable]], 'data.nested.when'],
    'infinity' => [['n' => INF], 'data.n'],
    'too large' => [['blob' => str_repeat('x', 20_000)], 'at most 16384'],
]);

it('does not notify the actor of their own action, unless the type says so (C-12)', function (): void {
    $author = user();
    $other = user();
    $post = post($author);

    $result = Notifications::send('comment.posted', $post, [$author, $other], ['title' => 'Hi'], actor: $author);

    expect($result->recipients())->toBe([$other->id]);

    Notifications::define('self.notice')->notifyActor()->title(fn (): string => 'x')->register();

    expect(Notifications::send('self.notice', null, $author, actor: $author)->recipients())->toBe([$author->id]);
});

it('notifies each user once across merged resolvers (C-13)', function (): void {
    $company = company();
    $alice = user();
    $bob = user();
    $company->addMember($alice, 'approver')->addMember($bob, 'manager');

    $result = Notifications::send('security.alert', null, Recipients::merge(
        Recipients::members($company),
        Recipients::role($company, 'approver'),
        Recipients::users($alice, null, [$bob]),
    ));

    expect($result->outcomes)->toHaveCount(2);
});

it('refuses non-model recipients', function (): void {
    Recipients::users(['not a model']);
})->throws(InvalidRecipientException::class, 'must be an Eloquent model, string given');

it('refuses a non-model a resolver returned', function (): void {
    Notifications::send('security.alert', null, fn () => [new stdClass]);
})->throws(InvalidRecipientException::class, 'stdClass');

it('cannot store closure recipients outside a rule (C-14)', function (): void {
    $request = purchaseRequest();

    expect(fn () => Recipients::role(fn (): Company => $request->company, 'approver')->toStored())->toThrow(UnstorableRecipientsException::class)
        ->and(fn () => Notifications::type('request.awaiting_approval')->about($request)->to(user())
            ->escalate('P1D', Recipients::role(fn (): Company => $request->company, 'manager'))->send())
        ->toThrow(UnstorableRecipientsException::class);
});

it('refuses resolver arguments that do not survive JSON', function (): void {
    Recipients::using(ApproversOfLevel::class, ['level' => new stdClass]);
})->throws(UnstorableRecipientsException::class, 'ApproversOfLevel');

it('resolves owners, resolver classes and members', function (): void {
    $owner = user();
    $contract = contract(owner: $owner);
    user(['name' => 'Level 2 approver']);

    expect(Recipients::owner()->resolve($contract))->toHaveCount(1)
        ->and(Recipients::owner()->resolve($contract)[0]->is($owner))->toBeTrue()
        ->and(Recipients::owner('author')->resolve($request = purchaseRequest())[0]->is($request->author))->toBeTrue()
        ->and(Recipients::using(ApproversOfLevel::class, ['level' => 2])->resolve(null))->toHaveCount(1)
        ->and(Recipients::members($contract->company)->resolve(null))->toHaveCount(1);
});

it('resolves an owner through a named relation', function (): void {
    $owner = user();
    $contract = contract(owner: $owner);

    $spec = Recipients::owner('owner');

    expect(RecipientSpec::fromStored($spec->toStored())->resolve(Post::query()->make()))->toBe([]);
})->throws(NoOwnerException::class, 'Post does not name an owner.');

it('names the subject that has no owner', function (): void {
    Recipients::owner()->resolve(null);
})->throws(NoOwnerException::class, 'Recipients::owner() needs a subject.');

it('names a group that cannot list its members', function (): void {
    Recipients::members(post())->resolve(null);
})->throws(UnresolvableRecipientsException::class, 'Post cannot list its members');

it('round-trips every storable spec', function (): void {
    $company = company();
    $alice = user();
    $company->addMember($alice, 'approver');
    $contract = contract(company: $company);

    $spec = Recipients::merge(
        Recipients::users($alice),
        Recipients::role($company, 'approver'),
        Recipients::owner(),
        Recipients::using(ApproversOfLevel::class, ['level' => 9]),
    );

    $restored = RecipientSpec::fromStored(json_decode(json_encode($spec->toStored()), true));

    expect($spec->storable())->toBeTrue()
        ->and(array_map(fn (?Model $m) => $m?->getKey(), $restored->resolve($contract)))->toBe([$alice->id, $alice->id, $contract->owner_id])
        ->and(RecipientSpec::fromStored(['kind' => 'unknown'])->resolve(null))->toBe([])
        ->and(RecipientSpec::fromStored(['kind' => 'using', 'resolver' => 'Nope'])->resolve(null))->toBe([]);
});

it('takes a group resolver from config', function (): void {
    $alice = user();
    app()->bind(ResolvesGroupMembers::class, fn () => new class($alice) implements ResolvesGroupMembers
    {
        public function __construct(private User $user) {}

        public function members(Model $group, ?string $role = null): iterable
        {
            return [$this->user];
        }
    });

    expect(Recipients::role(post(), 'any')->resolve(null)[0]->is($alice))->toBeTrue();
});

it('fails loud when a type has no access check at all (C-09)', function (): void {
    Notifications::define('no.policy')->subject(Company::class)->title(fn (): string => 'x')->register();

    Notifications::send('no.policy', company(), user());
})->throws(MissingAccessCheckException::class, 'checks the "view" ability on Company, but Company has no policy');

it('uses a Gate ability when no policy exists', function (): void {
    Gate::define('view', fn (User $user, Company $company): bool => $user->name === 'Allowed');
    Notifications::define('ability.check')->subject(Company::class)->title(fn (): string => 'x')->register();

    $result = Notifications::send('ability.check', company(), [user(['name' => 'Allowed']), user(['name' => 'Denied'])]);

    expect($result->inbox(InboxOutcome::Created))->toHaveCount(1)
        ->and($result->inbox(InboxOutcome::NoAccess))->toHaveCount(1)
        ->and($result->skipped())->toHaveCount(1);
});

it('gives a recipient without access nothing at send (C-15)', function (): void {
    Event::fake([RecipientSkipped::class]);
    $request = purchaseRequest();
    $outsider = user();

    $result = Notifications::send('request.awaiting_approval', $request, $outsider, ['reference' => 'PR-1']);

    expect($result->outcomes[0]->inbox)->toBe(InboxOutcome::NoAccess)
        ->and($result->outcomes[0]->mail)->toBe(MailOutcome::NoAccess)
        ->and(InboxItem::query()->count())->toBe(0)
        ->and(NotificationDelivery::query()->count())->toBe(0);

    Event::assertDispatched(RecipientSkipped::class, fn (RecipientSkipped $event): bool => $event->reason === 'no_access' && $event->type === 'request.awaiting_approval');
});

it('fails fast on a subject key that does not fit the configured key type (C-68)', function (): void {
    config()->set('notifications.subject_key_type', 'uuid');

    Notifications::send('request.awaiting_approval', purchaseRequest(), user());
})->throws(InvalidNotificationsConfigurationException::class, 'The PurchaseRequest key does not match configuration value [notifications.subject_key_type] (uuid)');

it('fails fast on a recipient or actor key that does not fit', function (): void {
    config()->set('notifications.key_type', 'ulid');

    Notifications::send('security.alert', null, user());
})->throws(InvalidNotificationsConfigurationException::class, '[notifications.key_type] (ulid)');

it('leaves nothing behind when the host transaction rolls back (C-22)', function (): void {
    $user = user();

    try {
        DB::transaction(function () use ($user): void {
            Notifications::send('security.alert', null, $user);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(InboxItem::query()->count())->toBe(0)
        ->and(NotificationDelivery::query()->count())->toBe(0);

    Notification::assertNothingSent();
});

it('fans a large recipient set out in queued chunks that re-check access (C-69)', function (): void {
    config()->set('notifications.fan_out.queue_above', 2);
    config()->set('notifications.fan_out.chunk', 2);
    config()->set('queue.default', 'database');
    Queue::fake();

    $request = purchaseRequest();
    $members = array_map(fn (): User => user(), range(1, 5));

    foreach ($members as $member) {
        $request->company->addMember($member, 'approver');
    }

    $result = Notifications::send('request.awaiting_approval', $request, Recipients::role($request->company, 'approver'), ['reference' => 'PR-1']);

    expect($result->queued)->toBe(5)->and($result->outcomes)->toBe([]);
    Queue::assertPushed(DeliverToRecipientsJob::class, 3);

    // One member loses access before the jobs run.
    DB::table('company_user')->where('user_id', $members[0]->id)->delete();

    foreach (Queue::pushed(DeliverToRecipientsJob::class) as $job) {
        app()->call([$job, 'handle']);
    }

    expect(InboxItem::query()->count())->toBe(4)
        ->and(InboxItem::query()->where('recipient_id', $members[0]->id)->exists())->toBeFalse();
});

it('ignores a fan-out job whose type is gone, and drops gone recipients', function (): void {
    $job = new DeliverToRecipientsJob('gone.type', null, [], [], null, null, null, 0, null);
    app()->call([$job, 'handle']);

    $job = new DeliverToRecipientsJob('security.alert', null, [['type' => (new User)->getMorphClass(), 'id' => 999]], [], null, null, null, 0, null);
    app()->call([$job, 'handle']);

    expect(InboxItem::query()->count())->toBe(0);
});

it('sends inline above the threshold when queues are off', function (): void {
    config()->set('notifications.fan_out.queue_above', 1);
    config()->set('notifications.queue.enabled', false);

    $result = Notifications::send('security.alert', null, [user(), user()]);

    expect($result->queued)->toBe(0)->and($result->outcomes)->toHaveCount(2);
});

it('builds the payload a type renders from', function (): void {
    $seen = null;
    Notifications::define('payload.probe')->subject(Contract::class)->access(fn (): bool => true)
        ->title(function (NotificationPayload $p) use (&$seen): string {
            $seen = $p;

            return 'x';
        })->register();

    $actor = user();
    $contract = contract();
    Notifications::send('payload.probe', $contract, user(), ['a' => 1], actor: $actor, context: 'billing');

    expect($seen->type)->toBe('payload.probe')
        ->and($seen->subjectKey)->toBe($contract->id)
        ->and($seen->actor->is($actor))->toBeTrue()
        ->and($seen->context->key)->toBe('billing')
        ->and($seen->data)->toBe(['a' => 1])
        ->and($seen->count)->toBe(1)
        ->and($seen->escalated)->toBeFalse();
});

it('keeps the reminder reference through a queued fan-out', function (): void {
    config()->set('notifications.fan_out.queue_above', 1);
    $contract = contract();
    $recipients = [user(), user()];
    $contract->company->addMember($recipients[0], 'manager')->addMember($recipients[1], 'manager');
    [$reminder] = Notifications::for($contract)->remind('contract.expiring', Reminder::at(CarbonImmutable::now('UTC')->addHour()), to: $recipients);

    at(CarbonImmutable::now('UTC')->addHours(2)->toDateTimeString());
    Notifications::sweep();

    $deliveries = NotificationDelivery::query()->get();
    $items = InboxItem::query()->get();

    expect($deliveries)->toHaveCount(2)
        ->and($deliveries->every(fn (NotificationDelivery $delivery): bool => ($delivery->data['_notifications']['reminder']['id'] ?? null) === $reminder->id))->toBeTrue()
        ->and($items->every(fn (InboxItem $item): bool => $item->reminder_id === $reminder->id))->toBeTrue();
});
