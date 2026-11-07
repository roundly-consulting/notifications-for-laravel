<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\Contacts;

use RoundlyConsulting\Contacts\Concerns\HasContacts;
use RoundlyConsulting\Contacts\Concerns\RoutesNotificationsViaContacts;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\User;

/**
 * The contacts recipe: mail goes to the user's primary email contact.
 */
class ContactUser extends User
{
    use HasContacts;
    use RoutesNotificationsViaContacts;

    protected $table = 'users';
}
