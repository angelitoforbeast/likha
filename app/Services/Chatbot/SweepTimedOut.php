<?php

namespace App\Services\Chatbot;

use RuntimeException;

/** Naubos ang oras ng sweep (SweepLimit) bago ang susunod na query nito. */
final class SweepTimedOut extends RuntimeException
{
}
