<?php

declare(strict_types=1);

namespace App\Controllers;

use Zephyrus\Http\Response;
use Zephyrus\Routing\Attributes\Get;

final class HomeController
{
    #[Get('/')]
    public function index(): Response
    {
        return Response::json([
            'message' => 'Hello from Zephyrus',
            'docs'    => 'https://github.com/zephyrus-framework/core',
            'mode'    => $_ENV['MODE'] ?? 'WEB',
        ]);
    }

    // The Shared health probe — no #[RequiresEnv], so it registers in EVERY
    // mode. Use this pattern in production to give every container a
    // probe-able endpoint regardless of MODE.
    #[Get('/health')]
    public function health(): Response
    {
        return Response::text('ok');
    }
}
