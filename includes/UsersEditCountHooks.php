<?php
class UsersEditCountHooks
{
    public static function onwgQueryPages(&$wgQueryPages): void
    {
        $wgQueryPages[] = ['SpecialUsersEditCount', 'Userseditcount'];
    }
}
