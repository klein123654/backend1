<?php

return [
    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),
    'send_default_pii' => false,
    'before_send' => static function (\Sentry\Event $event): ?\Sentry\Event {
        $request = $event->getRequest();

        if (isset($request['url']) && is_string($request['url'])) {
            $request['url'] = explode('?', $request['url'], 2)[0];
        }

        unset($request['query_string'], $request['data'], $request['cookies']);

        if (isset($request['headers']) && is_array($request['headers'])) {
            foreach (array_keys($request['headers']) as $header) {
                if (in_array(strtolower((string) $header), ['authorization', 'cookie'], true)) {
                    unset($request['headers'][$header]);
                }
            }
        }

        return $event->setRequest($request);
    },
];
