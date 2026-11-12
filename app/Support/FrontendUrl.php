<?php

namespace App\Support;

class FrontendUrl
{
    /**
     * Build an absolute URL to a page of the Vue frontend.
     */
    public static function to(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * Build an absolute URL to a section of a role's workspace, e.g. /owner/inquiries.
     */
    public static function workspace(string $role, string $section): string
    {
        return in_array($role, ['owner', 'developer', 'broker', 'admin'], true)
            ? self::to('/'.$role.'/'.$section)
            : self::to('/buyer/inquiries');
    }
}
