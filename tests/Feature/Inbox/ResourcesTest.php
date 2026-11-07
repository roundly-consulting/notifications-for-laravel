<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Notification as LaravelNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Notifications\Context;
use RoundlyConsulting\Notifications\DataTransferObjects\ContextReference;
use RoundlyConsulting\Notifications\Events\InboxItemCreated;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationSubjectException;
use RoundlyConsulting\Notifications\Exceptions\MissingAccessCheckException;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Http\Resources\InboxCountsResource;
use RoundlyConsulting\Notifications\Http\Resources\InboxItemResource;
use RoundlyConsulting\Notifications\Http\Resources\NotificationActorResource;
use RoundlyConsulting\Notifications\Http\Resources\NotificationContextResource;
use RoundlyConsulting\Notifications\Mail\InboxChannel;
use RoundlyConsulting\Notifications\Mail\InboxMessage;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;
use RoundlyConsulting\Notifications\Support\TypeRegistry;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Company;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Post;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * A host's own resource for the actor role: adds the name.
 */
final class ActorWithName extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource->getKey(), 'name' => $this->resource->getAttribute('name')];
    }
}

abstract class AbstractResource extends JsonResource {}

/**
 * A host notification on Laravel's `inbox` channel.
 */
final class CommentedViaInbox extends LaravelNotification
{
    public function __construct(private Post $post, private Model $author) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['inbox'];
    }

    public function toInbox(object $notifiable): InboxMessage
    {
        return (new InboxMessage('comment.posted'))->about($this->post)->by($this->author)->in(Context::key('blog', 'Blog'))->with(['title' => 'Hi']);
    }
}

beforeEach(function (): void {
    Notification::fake();
});

/**
 * Undo Notification::fake(): a fresh channel manager, with the package's `inbox` driver.
 */
function realNotifications(): void
{
    app()->forgetInstance(ChannelManager::class);
    Notification::clearResolvedInstance(ChannelManager::class);
    Notification::swap(app(ChannelManager::class));
}

/**
 * @return array<string, mixed>
 */
function renderItem(InboxItem $item, ?Model $user = null): array
{
    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    return (new InboxItemResource($item))->resolve($request);
}

it('renders an inbox item for a JSON API with no class names anywhere', function (): void {
    $approver = user();
    $request = purchaseRequest();
    $request->company->addMember($approver, 'approver');
    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'PR-1'], actor: $request->author);

    $json = renderItem(InboxItem::query()->sole(), $approver);

    expect($json)->toMatchArray([
        'type' => 'request.awaiting_approval',
        'category' => 'requests',
        'title' => 'Request PR-1 awaits your approval (1)',
        'url' => 'https://app.test/requests/'.$request->id,
        'count' => 1,
        'data' => ['reference' => 'PR-1'],
        'actor' => ['id' => $request->author_id, 'type' => 'User'],
        'subject' => ['id' => $request->id, 'type' => 'PurchaseRequest'],
        'context' => ['key' => 'company:'.$request->company_id, 'label' => 'Acme s.r.o.', 'id' => $request->company_id, 'type' => 'Company'],
        'priority' => ['value' => 'normal', 'label' => 'Normal'],
        'read_at' => null,
        'archived_at' => null,
    ])->and(json_encode($json))->not->toContain('\\\\');
});

it('renders the actor, subject and context through the configured resources', function (string $role, string $key): void {
    $approver = user();
    $request = purchaseRequest();
    $request->company->addMember($approver, 'approver');
    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'PR-1'], actor: $request->author);

    config()->set('notifications.resources.'.$role, ActorWithName::class);

    expect(renderItem(InboxItem::query()->sole(), $approver)[$key])->toHaveKey('name');
})->with([
    'actor' => ['actor', 'actor'],
    'subject' => ['subject', 'subject'],
]);

it('renders the context through the configured resource', function (): void {
    config()->set('notifications.resources.context', ContextLabelOnly::class);

    expect((new NotificationContextResource(new ContextReference('k', 'L')))->resolve())->toBe(['key' => 'k', 'label' => 'L'])
        ->and(app(NotificationsConfig::class)::contextResource())->toBe(ContextLabelOnly::class);
});

it('refuses a resource that is not an instantiable JsonResource, naming the key', function (string $role, mixed $value): void {
    config()->set('notifications.resources.'.$role, $value);

    expect(fn () => match ($role) {
        'actor' => NotificationsConfig::actorResource(),
        'subject' => NotificationsConfig::subjectResource(),
        default => NotificationsConfig::contextResource(),
    })->toThrow(InvalidConfigurationException::class, 'notifications.resources.'.$role);
})->with([
    'actor: not a resource' => ['actor', stdClass::class],
    'subject: the bare base' => ['subject', JsonResource::class],
    'context: abstract' => ['context', AbstractResource::class],
    'actor: not a string' => ['actor', 42],
]);

it('falls back to the packaged resources when the keys are blank', function (?string $value): void {
    config()->set('notifications.resources.actor', $value);

    expect(NotificationsConfig::actorResource())->toBe(NotificationActorResource::class);
})->with(['null' => [null], 'empty' => ['']]);

it('renders a basename for an unmapped context type and a model with the morph map', function (): void {
    expect((new NotificationContextResource(new ContextReference('team:3', 'Team', 'App\\Models\\Team', 3)))->resolve())
        ->toBe(['key' => 'team:3', 'label' => 'Team', 'id' => 3, 'type' => 'Team']);
});

it('renders the snapshot when the type is gone or its render throws (C-72)', function (): void {
    $approver = user();
    $request = purchaseRequest();
    $request->company->addMember($approver, 'approver');
    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'PR-1']);

    app(TypeRegistry::class)->flush();
    config()->set('notifications.types', []);

    expect(renderItem(InboxItem::query()->sole(), $approver))->toMatchArray([
        'title' => 'Request PR-1 awaits your approval (1)',
        'body' => null,
        'url' => 'https://app.test/requests/'.$request->id,
        'data' => ['reference' => 'PR-1'],
    ]);
});

it('renders the snapshot when the live title throws, and with no reader at all', function (): void {
    $user = user();
    $thrown = false;
    Notifications::define('flaky.title')->title(function () use (&$thrown): string {
        if ($thrown) {
            throw new RuntimeException('broken');
        }

        $thrown = true;

        return 'Stored title';
    })->register();
    Notifications::send('flaky.title', null, $user);

    $item = InboxItem::query()->sole();

    expect(renderItem($item, $user)['title'])->toBe('Stored title')
        ->and(renderItem($item)['title'])->toBe('Stored title');
});

it('renders in the request locale', function (): void {
    $approver = user(['locale' => 'en']);
    $request = purchaseRequest();
    $request->company->addMember($approver, 'approver');
    Notifications::send('request.awaiting_approval', $request, $approver, ['reference' => 'PR-1']);

    app()->setLocale('sk');

    expect(renderItem(InboxItem::query()->sole(), $approver)['title'])->toBe('Žiadosť PR-1 čaká na vaše schválenie (1)')
        ->and(renderItem(InboxItem::query()->sole(), $approver)['priority'])->toBe(['value' => 'normal', 'label' => 'Bežná']);
});

it('eager-loads a collection and renders counts', function (): void {
    $user = user();
    Notifications::send('security.alert', null, $user, context: 'billing');

    $collection = InboxItemResource::collection(Notifications::inbox($user)->get());
    $page = InboxItemResource::collection(Notifications::inbox($user)->paginate());

    expect($collection->resolve())->toHaveCount(1)
        ->and($page->resolve())->toHaveCount(1)
        ->and((new InboxCountsResource(Notifications::inbox($user)->counts()))->resolve())->toBe([
            'unread' => 1,
            'total' => 1,
            'contexts' => [['key' => 'billing', 'label' => 'billing', 'unread' => 1]],
        ]);
});

it('broadcasts no class name in the docs recipe payload (C-34)', function (): void {
    $user = user();
    $payloads = [];

    Event::listen(InboxItemCreated::class, function (InboxItemCreated $event) use (&$payloads): void {
        // The recipe: broadcast InboxItemResource + the unread count on the host's private channel.
        $payloads[] = json_encode([
            'item' => (new InboxItemResource($event->item->loadMissing(['actor', 'subject'])))->resolve(),
            'unread' => Notifications::inbox($event->item->recipient)->unreadCount(),
        ]);
    });

    Notifications::send('comment.posted', post(), $user, ['title' => 'Hi'], actor: user());

    expect($payloads)->toHaveCount(1)
        ->and($payloads[0])->not->toContain('\\\\')
        ->and($payloads[0])->toContain('"type":"User"');
});

it('lands a host notification on the inbox channel and collapses it like a type (C-61)', function (): void {
    realNotifications();
    $reader = user();
    $post = post();

    $reader->notify(new CommentedViaInbox($post, user()));
    $reader->notify(new CommentedViaInbox($post, user()));

    $item = InboxItem::query()->sole();

    expect($item->count)->toBe(2)
        ->and($item->type)->toBe('comment.posted')
        ->and($item->context_key)->toBe('blog')
        ->and($item->title)->toBe('2 new comments on Hi');
});

it('applies the type\'s access check on the inbox channel', function (): void {
    realNotifications();
    $reader = user();
    $private = post(private: true);

    $reader->notify(new CommentedViaInbox($private, user()));

    expect(InboxItem::query()->count())->toBe(0)
        ->and(Notifications::inboxMessage($reader, (new InboxMessage('comment.posted'))->about(post())))->toBeInstanceOf(InboxItem::class);
});

it('ignores an inbox notification without toInbox() and refuses a wrong subject', function (): void {
    $channel = app(InboxChannel::class);
    $channel->send(user(), new LaravelNotification);
    $channel->send(new stdClass, new CommentedViaInbox(post(), user()));

    expect(InboxItem::query()->count())->toBe(0);

    Notifications::inboxMessage(user(), (new InboxMessage('comment.posted'))->type('comment.posted')->about(contract()));
})->throws(InvalidNotificationSubjectException::class);

it('still throws a missing access check through the inbox channel', function (): void {
    Notifications::define('nopolicy.inbox')->subject(Company::class)->register();

    Notifications::inboxMessage(user(), (new InboxMessage('nopolicy.inbox'))->about(company()));
})->throws(MissingAccessCheckException::class);

/**
 * A host context resource that hides the model reference.
 */
final class ContextLabelOnly extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['label' => $this->resource->label];
    }
}
