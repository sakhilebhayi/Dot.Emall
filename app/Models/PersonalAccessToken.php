<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum's token model, with one guard: a token written "<id>|<secret>" is looked up by that id, and Sanctum passes
 * whatever text came before the "|" straight to a bigint column. SQLite shrugs, PostgreSQL (production) answers
 * "invalid input syntax for type bigint" or "out of range", and an anonymous request with a made-up token becomes a 500.
 * A token whose id cannot be a real id simply does not exist.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * @param  string  $token
     */
    public static function findToken($token)
    {
        if (is_string($token) && str_contains($token, '|')) {
            $id = strstr($token, '|', true);

            if (! ctype_digit($id) || strlen($id) > 18) {
                return null;
            }
        }

        return parent::findToken($token);
    }
}
