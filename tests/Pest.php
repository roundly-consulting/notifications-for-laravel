<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Company;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Contract;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Post;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\PurchaseRequest;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\User;
use RoundlyConsulting\Notifications\Tests\Fixtures\ContactsTestCase;
use RoundlyConsulting\Notifications\Tests\Fixtures\HttpTestCase;
use RoundlyConsulting\Notifications\Tests\Fixtures\InstallTestCase;
use RoundlyConsulting\Notifications\Tests\Fixtures\SwappedModelsTestCase;
use RoundlyConsulting\Notifications\Tests\Fixtures\UlidKeyTestCase;
use RoundlyConsulting\Notifications\Tests\Fixtures\UuidKeyTestCase;
use RoundlyConsulting\Notifications\Tests\TestCase;

// Explicit directories, never a blanket `->in(__DIR__)`: Pest binds one test case per directory.
uses(TestCase::class)->in('Arch', 'Feature', 'Unit', 'Acceptance', 'RealEngine');
uses(SwappedModelsTestCase::class)->in('ModelSwap');
uses(HttpTestCase::class)->in('Http');
uses(InstallTestCase::class)->in('Install');
uses(ContactsTestCase::class)->in('Contacts');
uses(UuidKeyTestCase::class)->in('KeyTypes/UuidKeyTest.php');
uses(UlidKeyTestCase::class)->in('KeyTypes/UlidKeyTest.php');

/**
 * @param  array<string, mixed>  $attributes
 */
function user(array $attributes = []): User
{
    static $n = 0;
    $n++;

    return User::query()->create($attributes + ['name' => 'User '.$n, 'email' => "user{$n}@example.test"]);
}

function company(string $name = 'Acme s.r.o.'): Company
{
    return Company::query()->create(['name' => $name]);
}

/**
 * A purchase request in a company whose members are the given users (approvers by default).
 */
function purchaseRequest(?Company $company = null, ?User $author = null, string $reference = 'PR-1'): PurchaseRequest
{
    $company ??= company();
    $author ??= user();
    $company->addMember($author, 'author');

    return PurchaseRequest::query()->create(['company_id' => $company->id, 'author_id' => $author->id, 'reference' => $reference]);
}

function contract(?Company $company = null, ?User $owner = null, ?CarbonImmutable $expiresAt = null): Contract
{
    $company ??= company();
    $owner ??= user();

    if (! $company->users()->whereKey($owner->id)->exists()) {
        $company->addMember($owner, 'owner');
    }

    return Contract::query()->create([
        'company_id' => $company->id,
        'owner_id' => $owner->id,
        'title' => 'Hosting',
        'expires_at' => $expiresAt ?? CarbonImmutable::now('UTC')->addDays(100),
    ]);
}

function post(?User $author = null, bool $private = false): Post
{
    return Post::query()->create(['author_id' => ($author ?? user())->id, 'title' => 'Hello', 'private' => $private]);
}

/**
 * Freeze the clock (UTC).
 */
function at(string $instant): CarbonImmutable
{
    $now = CarbonImmutable::parse($instant, 'UTC');
    CarbonImmutable::setTestNow($now);
    Carbon::setTestNow($now);

    return $now;
}
