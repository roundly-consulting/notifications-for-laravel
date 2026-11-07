<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/**
 * English and Slovak ship the same keys and placeholders, and Slovak renders through the package
 * namespace.
 *
 * @return list<string>
 */
function notificationLangFiles(): array
{
    return array_map(static fn (string $path): string => basename($path, '.php'), glob(__DIR__.'/../../resources/lang/en/*.php') ?: []);
}

/**
 * @return array<string, string>
 */
function notificationLines(string $locale, string $file): array
{
    /** @var array<string, mixed> $lines */
    $lines = require __DIR__."/../../resources/lang/{$locale}/{$file}.php";

    return array_map(static fn (mixed $line): string => (string) $line, Arr::dot($lines));
}

it('ships every English file in Slovak too', function (): void {
    expect(notificationLangFiles())->not->toBeEmpty();

    foreach (notificationLangFiles() as $file) {
        expect(__DIR__."/../../resources/lang/sk/{$file}.php")->toBeFile();
    }
});

it('ships the same keys and placeholders in both languages', function (): void {
    foreach (notificationLangFiles() as $file) {
        $en = notificationLines('en', $file);
        $sk = notificationLines('sk', $file);

        expect(array_keys($sk))->toEqualCanonicalizing(array_keys($en));

        foreach ($en as $key => $line) {
            preg_match_all('/:[a-z_]+/', $line, $enPlaceholders);
            preg_match_all('/:[a-z_]+/', $sk[$key], $skPlaceholders);

            expect(array_unique($skPlaceholders[0]))->toEqualCanonicalizing(array_unique($enPlaceholders[0]), "{$file}.{$key}");
        }
    }
});

it('renders Slovak through the package namespace', function (): void {
    app()->setLocale('sk');

    expect(__('notifications::exceptions.inbox_item_not_found'))->toBe('Upozornenie sa nenašlo.')
        ->and(__('notifications::enums.channel.mail'))->toBe('E-mail');
});

it('covers every count in the plural lines', function (int $count, string $en, string $sk): void {
    app()->setLocale('en');
    expect(trans_choice('notifications::mail.more_updates', $count, ['count' => $count]))->toBe($en);

    app()->setLocale('sk');
    expect(trans_choice('notifications::mail.more_updates', $count, ['count' => $count]))->toBe($sk);
})->with([
    [0, 'And 0 more updates.', 'A ešte 0 aktualizácií.'],
    [1, 'And 1 more update.', 'A ešte 1 aktualizácia.'],
    [3, 'And 3 more updates.', 'A ešte 3 aktualizácie.'],
    [5, 'And 5 more updates.', 'A ešte 5 aktualizácií.'],
]);
