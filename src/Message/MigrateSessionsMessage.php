<?php

namespace App\Message;

final class MigrateSessionsMessage
{
    public function __construct(
        public readonly array $template,
    ) {}
}
