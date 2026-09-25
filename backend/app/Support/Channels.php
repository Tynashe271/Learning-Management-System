<?php

namespace App\Support;

/** Which channels a notification uses, so an administrator can switch email notifications off for the whole institution. */
class Channels
{
    /**
     * @param  list<string>  $always  channels used regardless (normally the in-app notification)
     * @return list<string>
     */
    public static function withMail(array $always): array
    {
        return config('lms.notifications.email') ? [...$always, 'mail'] : $always;
    }
}
