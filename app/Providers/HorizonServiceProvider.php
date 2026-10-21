<?php

namespace App\Providers;

use App\Listeners\RefuseRememberedLoginUnlessCeo;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Horizon (ang mga worker ng chatbot at chatblast sa Redis). Ang /horizon ay CEO lang, sa lahat ng environment (ang
 * default ng Horizon ay bukas sa lahat sa "local").
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    protected function authorization(): void
    {
        Horizon::auth(fn ($request) => RefuseRememberedLoginUnlessCeo::isCeo($request->user()));
    }
}